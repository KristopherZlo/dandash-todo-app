export const DEFAULT_LOCAL_LIST_MUTATION_HOLD_MS = 2000;

export function createListSyncGuards(options = {}) {
    const {
        resolveLinkIdForOwner,
        listCacheKey,
        localMutationHoldMs = DEFAULT_LOCAL_LIST_MUTATION_HOLD_MS,
    } = options;

    if (typeof resolveLinkIdForOwner !== 'function') {
        throw new TypeError('createListSyncGuards requires resolveLinkIdForOwner');
    }

    if (typeof listCacheKey !== 'function') {
        throw new TypeError('createListSyncGuards requires listCacheKey');
    }

    const listSyncVersions = new Map();
    const listServerVersions = new Map();
    const recentLocalMutationsByList = new Map();

    function versionKey(ownerId, type, linkId = undefined) {
        const resolvedLinkId = resolveLinkIdForOwner(ownerId, linkId);
        return listCacheKey(ownerId, type, resolvedLinkId);
    }

    function getListSyncVersion(ownerId, type, linkId = undefined) {
        return Number(listSyncVersions.get(versionKey(ownerId, type, linkId)) ?? 0);
    }

    function bumpListSyncVersion(ownerId, type, linkId = undefined) {
        const key = versionKey(ownerId, type, linkId);
        const nextVersion = getListSyncVersion(ownerId, type, linkId) + 1;
        listSyncVersions.set(key, nextVersion);
        return nextVersion;
    }

    function getKnownServerListVersion(ownerId, type, linkId = undefined) {
        return Number(listServerVersions.get(versionKey(ownerId, type, linkId)) ?? 0);
    }

    function mutationGuardKey(ownerId, type, linkId = undefined) {
        const resolvedLinkId = resolveLinkIdForOwner(ownerId, linkId);
        return listCacheKey(ownerId, type, resolvedLinkId);
    }

    function clearRecentListMutation(ownerId, type, linkId = undefined) {
        recentLocalMutationsByList.delete(mutationGuardKey(ownerId, type, linkId));
    }

    function setKnownServerListVersion(ownerId, type, listVersion, linkId = undefined) {
        const numericVersion = Number(listVersion);
        if (!Number.isFinite(numericVersion) || numericVersion <= 0) {
            return;
        }

        const key = versionKey(ownerId, type, linkId);
        const normalizedVersion = Math.floor(numericVersion);
        const previousVersion = Number(listServerVersions.get(key) ?? 0);
        if (previousVersion >= normalizedVersion) {
            return;
        }

        listServerVersions.set(key, normalizedVersion);
        clearRecentListMutation(ownerId, type, linkId);
    }

    function markListMutated(ownerId, type, linkId = undefined, atMs = Date.now()) {
        const mutationAtMs = Number(atMs);
        if (!Number.isFinite(mutationAtMs)) {
            return;
        }

        const key = mutationGuardKey(ownerId, type, linkId);
        if (mutationAtMs <= 0) {
            recentLocalMutationsByList.delete(key);
            return;
        }

        recentLocalMutationsByList.set(key, mutationAtMs);
    }

    function hasRecentListMutation(ownerId, type, linkId = undefined, nowMs = Date.now()) {
        const key = mutationGuardKey(ownerId, type, linkId);
        const mutationAtMs = Number(recentLocalMutationsByList.get(key) ?? 0);
        if (!Number.isFinite(mutationAtMs) || mutationAtMs <= 0) {
            recentLocalMutationsByList.delete(key);
            return false;
        }

        if ((Number(nowMs) - mutationAtMs) > localMutationHoldMs) {
            recentLocalMutationsByList.delete(key);
            return false;
        }

        return true;
    }

    function reset() {
        listSyncVersions.clear();
        listServerVersions.clear();
        recentLocalMutationsByList.clear();
    }

    return {
        getListSyncVersion,
        bumpListSyncVersion,
        getKnownServerListVersion,
        setKnownServerListVersion,
        markListMutated,
        clearRecentListMutation,
        hasRecentListMutation,
        reset,
    };
}

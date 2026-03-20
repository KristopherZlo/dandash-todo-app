function defaultNormalizeLinkId(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) && parsed > 0 ? Math.floor(parsed) : null;
}

function resolveNormalizer(normalizeLinkId) {
    return typeof normalizeLinkId === 'function' ? normalizeLinkId : defaultNormalizeLinkId;
}

function resolveListOptions(getListOptions) {
    if (typeof getListOptions !== 'function') {
        return [];
    }

    return Array.isArray(getListOptions()) ? getListOptions() : [];
}

export function isSameListScope(leftOwnerId, leftLinkId = undefined, rightOwnerId, rightLinkId = undefined, options = {}) {
    const normalizeLinkId = resolveNormalizer(options.normalizeLinkId);
    const leftNormalizedLinkId = normalizeLinkId(leftLinkId);
    const rightNormalizedLinkId = normalizeLinkId(rightLinkId);

    if (leftNormalizedLinkId || rightNormalizedLinkId) {
        return leftNormalizedLinkId !== null && leftNormalizedLinkId === rightNormalizedLinkId;
    }

    return Number(leftOwnerId) === Number(rightOwnerId);
}

export function matchesScopedOperation(operation, ownerId, type, linkId = undefined, options = {}) {
    return String(operation?.type) === String(type)
        && isSameListScope(operation?.owner_id, operation?.link_id, ownerId, linkId, options);
}

export function findListOptionByOwner(listOptions, ownerId) {
    const normalizedOptions = Array.isArray(listOptions) ? listOptions : [];
    return normalizedOptions.find((option) => Number(option?.owner_id) === Number(ownerId)) ?? null;
}

export function resolveLinkIdForOwner(ownerId, explicitLinkId = undefined, listOptions = [], options = {}) {
    const normalizeLinkId = resolveNormalizer(options.normalizeLinkId);
    const explicit = normalizeLinkId(explicitLinkId);
    if (explicit) {
        return explicit;
    }

    return normalizeLinkId(findListOptionByOwner(listOptions, ownerId)?.link_id);
}

export function listCacheKey(ownerId, type, linkId = null, options = {}) {
    const normalizeLinkId = resolveNormalizer(options.normalizeLinkId);
    const normalizedLinkId = normalizeLinkId(linkId);
    if (normalizedLinkId) {
        return `shared:${normalizedLinkId}:${type}`;
    }

    return `owner:${Number(ownerId)}:personal:${type}`;
}

export function suggestionsCacheKey(ownerId, type, linkId = undefined, listOptions = [], options = {}) {
    return listCacheKey(
        ownerId,
        type,
        resolveLinkIdForOwner(ownerId, linkId, listOptions, options),
        options,
    );
}

export function suggestionStatsCacheKey(ownerId, type, linkId = undefined, listOptions = [], options = {}) {
    const normalizedLinkId = resolveLinkIdForOwner(ownerId, linkId, listOptions, options);
    const normalizedType = String(type ?? '').trim().toLowerCase();
    if (normalizedLinkId) {
        return `shared:${normalizedLinkId}:${normalizedType}`;
    }

    return `owner:${Number(ownerId)}:personal:${normalizedType}`;
}

export function buildListChannelName(ownerId, listOptions = [], options = {}) {
    const linkId = resolveLinkIdForOwner(ownerId, undefined, listOptions, options);
    if (linkId) {
        return `lists.shared.${linkId}`;
    }

    return `lists.personal.${Number(ownerId)}`;
}

export function createListScopeHelpers(options = {}) {
    const normalizeLinkId = resolveNormalizer(options.normalizeLinkId);
    const getListOptions = typeof options.getListOptions === 'function'
        ? options.getListOptions
        : () => [];

    function scopeOptions() {
        return { normalizeLinkId };
    }

    function readListOptions() {
        return resolveListOptions(getListOptions);
    }

    return {
        isSameListScope(leftOwnerId, leftLinkId = undefined, rightOwnerId, rightLinkId = undefined) {
            return isSameListScope(leftOwnerId, leftLinkId, rightOwnerId, rightLinkId, scopeOptions());
        },
        matchesScopedOperation(operation, ownerId, type, linkId = undefined) {
            return matchesScopedOperation(operation, ownerId, type, linkId, scopeOptions());
        },
        findListOptionByOwner(ownerId) {
            return findListOptionByOwner(readListOptions(), ownerId);
        },
        resolveLinkIdForOwner(ownerId, explicitLinkId = undefined) {
            return resolveLinkIdForOwner(ownerId, explicitLinkId, readListOptions(), scopeOptions());
        },
        listCacheKey(ownerId, type, linkId = null) {
            return listCacheKey(ownerId, type, linkId, scopeOptions());
        },
        suggestionsCacheKey(ownerId, type, linkId = undefined) {
            return suggestionsCacheKey(ownerId, type, linkId, readListOptions(), scopeOptions());
        },
        suggestionStatsCacheKey(ownerId, type, linkId = undefined) {
            return suggestionStatsCacheKey(ownerId, type, linkId, readListOptions(), scopeOptions());
        },
        buildListChannelName(ownerId) {
            return buildListChannelName(ownerId, readListOptions(), scopeOptions());
        },
    };
}

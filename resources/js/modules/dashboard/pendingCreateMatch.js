import { findRealtimeMatchForPendingCreate } from './realtimeListMerge';

function normalizeComparableValue(value) {
    if (value === null || value === undefined) {
        return '';
    }

    return String(value).trim();
}

function matchesScope(localPendingItem, incomingItem, options = {}) {
    const {
        ownerId,
        type,
        resolvedLinkId = null,
        normalizeLinkId = (value) => value,
    } = options;

    const localId = Number(localPendingItem?.id);
    const incomingId = Number(incomingItem?.id);
    if (!Number.isFinite(localId) || localId >= 0 || !Number.isFinite(incomingId) || incomingId <= 0) {
        return false;
    }

    if (String(localPendingItem?.type ?? '') !== String(type) || String(incomingItem?.type ?? '') !== String(type)) {
        return false;
    }

    return normalizeLinkId(incomingItem?.list_id ?? incomingItem?.list_link_id ?? incomingItem?.owner_id)
        === normalizeLinkId(resolvedLinkId ?? ownerId);
}

export function findBestPendingCreateMatch(localPendingItem, incomingItems, options = {}) {
    const candidates = Array.isArray(incomingItems) ? incomingItems : [];
    const usedIncomingIds = options?.usedIncomingIds instanceof Set ? options.usedIncomingIds : new Set();
    const localClientRequestId = normalizeComparableValue(localPendingItem?.client_request_id);

    if (localClientRequestId !== '') {
        for (const incomingItem of candidates) {
            const incomingId = Number(incomingItem?.id);
            if (!Number.isFinite(incomingId) || incomingId <= 0 || usedIncomingIds.has(incomingId)) {
                continue;
            }

            if (!matchesScope(localPendingItem, incomingItem, options)) {
                continue;
            }

            if (normalizeComparableValue(incomingItem?.client_request_id) === localClientRequestId) {
                return incomingItem;
            }
        }
    }

    return findRealtimeMatchForPendingCreate(localPendingItem, candidates, options);
}

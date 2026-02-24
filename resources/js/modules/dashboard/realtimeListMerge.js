export const PENDING_CREATE_REALTIME_MATCH_WINDOW_MS = 10 * 60 * 1000;

function normalizeComparableValue(value) {
    if (value === null || value === undefined) {
        return '';
    }

    return String(value);
}

function parseComparableTimestampMs(value) {
    const parsed = Date.parse(normalizeComparableValue(value));
    return Number.isFinite(parsed) ? parsed : null;
}

export function deduplicateItemsById(items) {
    const normalizedItems = Array.isArray(items) ? items : [];
    const deduplicated = [];
    const seenIds = new Set();

    for (const item of normalizedItems) {
        const itemId = Number(item?.id);
        if (!Number.isFinite(itemId)) {
            deduplicated.push(item);
            continue;
        }

        if (seenIds.has(itemId)) {
            continue;
        }

        seenIds.add(itemId);
        deduplicated.push(item);
    }

    return deduplicated;
}

export function canMatchPendingCreateToRealtimeServerItem(localPendingItem, incomingItem, options = {}) {
    if (!localPendingItem || !incomingItem) {
        return false;
    }

    const {
        ownerId,
        type,
        resolvedLinkId = null,
        normalizeLinkId = (value) => value,
    } = options;

    const localId = Number(localPendingItem.id);
    const incomingId = Number(incomingItem.id);
    if (!Number.isFinite(localId) || localId >= 0 || !Number.isFinite(incomingId) || incomingId <= 0) {
        return false;
    }

    if (String(localPendingItem.type ?? '') !== String(type) || String(incomingItem.type ?? '') !== String(type)) {
        return false;
    }

    if (Number(incomingItem.owner_id ?? 0) !== Number(ownerId)) {
        return false;
    }

    if (normalizeLinkId(incomingItem.list_link_id) !== normalizeLinkId(resolvedLinkId)) {
        return false;
    }

    if (String(localPendingItem.text ?? '').trim() !== String(incomingItem.text ?? '').trim()) {
        return false;
    }

    // Sync chunk create may emit an intermediate server snapshot before a follow-up completion update.
    if (normalizeComparableValue(localPendingItem.quantity) !== normalizeComparableValue(incomingItem.quantity)) {
        return false;
    }

    if (normalizeComparableValue(localPendingItem.unit) !== normalizeComparableValue(incomingItem.unit)) {
        return false;
    }

    if (normalizeComparableValue(localPendingItem.due_at) !== normalizeComparableValue(incomingItem.due_at)) {
        return false;
    }

    if (normalizeComparableValue(localPendingItem.priority) !== normalizeComparableValue(incomingItem.priority)) {
        return false;
    }

    const localCreatedAtMs = parseComparableTimestampMs(localPendingItem.created_at);
    const incomingCreatedAtMs = parseComparableTimestampMs(incomingItem.created_at);
    if (localCreatedAtMs !== null && incomingCreatedAtMs !== null) {
        return Math.abs(localCreatedAtMs - incomingCreatedAtMs) <= PENDING_CREATE_REALTIME_MATCH_WINDOW_MS;
    }

    return false;
}

export function findRealtimeMatchForPendingCreate(localPendingItem, incomingItems, options = {}) {
    const {
        ownerId,
        type,
        resolvedLinkId = null,
        usedIncomingIds = new Set(),
        normalizeLinkId = (value) => value,
    } = options;
    const candidates = Array.isArray(incomingItems) ? incomingItems : [];
    let matchedItem = null;
    let bestDistanceMs = Number.POSITIVE_INFINITY;
    const localCreatedAtMs = parseComparableTimestampMs(localPendingItem?.created_at);

    for (const incomingItem of candidates) {
        const incomingId = Number(incomingItem?.id);
        if (!Number.isFinite(incomingId) || incomingId <= 0 || usedIncomingIds.has(incomingId)) {
            continue;
        }

        if (!canMatchPendingCreateToRealtimeServerItem(localPendingItem, incomingItem, {
            ownerId,
            type,
            resolvedLinkId,
            normalizeLinkId,
        })) {
            continue;
        }

        const incomingCreatedAtMs = parseComparableTimestampMs(incomingItem?.created_at);
        const distanceMs = (
            localCreatedAtMs !== null && incomingCreatedAtMs !== null
                ? Math.abs(localCreatedAtMs - incomingCreatedAtMs)
                : 0
        );

        if (distanceMs < bestDistanceMs) {
            bestDistanceMs = distanceMs;
            matchedItem = incomingItem;
        }
    }

    return matchedItem;
}

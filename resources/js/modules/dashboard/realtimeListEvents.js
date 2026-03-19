import { normalizeItems, sortItems } from './listItemCollections';

function normalizeOperation(value) {
    const normalized = String(value ?? '').trim();
    return normalized === '' ? null : normalized;
}

function cloneItems(items) {
    if (!Array.isArray(items)) {
        return [];
    }

    return items.map((item) => ({ ...item }));
}

function normalizeIdList(list) {
    if (!Array.isArray(list)) {
        return [];
    }

    const seen = new Set();
    const normalized = [];

    for (const value of list) {
        const numericId = Number(value);
        if (!Number.isFinite(numericId) || numericId <= 0 || seen.has(numericId)) {
            continue;
        }

        seen.add(numericId);
        normalized.push(numericId);
    }

    return normalized;
}

function applyReorderDelta(previousItems, activeOrder, completedOrder) {
    const orderedItems = sortItems(previousItems);
    const activeItems = orderedItems.filter((item) => !item?.is_completed);
    const completedItems = orderedItems.filter((item) => item?.is_completed);
    const activeById = new Map(activeItems.map((item) => [Number(item?.id), item]));
    const completedById = new Map(completedItems.map((item) => [Number(item?.id), item]));
    const orderedActiveIds = normalizeIdList(activeOrder);
    const orderedCompletedIds = normalizeIdList(completedOrder);

    const finalActiveIds = [
        ...orderedActiveIds.filter((itemId) => activeById.has(itemId)),
        ...activeItems
            .map((item) => Number(item?.id))
            .filter((itemId) => Number.isFinite(itemId) && !orderedActiveIds.includes(itemId)),
    ];
    const finalCompletedIds = [
        ...orderedCompletedIds.filter((itemId) => completedById.has(itemId)),
        ...completedItems
            .map((item) => Number(item?.id))
            .filter((itemId) => Number.isFinite(itemId) && !orderedCompletedIds.includes(itemId)),
    ];

    const nextItems = [];
    let sortOrder = 1000;
    for (const itemId of finalActiveIds) {
        const item = activeById.get(itemId);
        if (!item) {
            continue;
        }

        nextItems.push({
            ...item,
            sort_order: sortOrder,
        });
        sortOrder += 1000;
    }

    sortOrder = 1000;
    for (const itemId of finalCompletedIds) {
        const item = completedById.get(itemId);
        if (!item) {
            continue;
        }

        nextItems.push({
            ...item,
            sort_order: sortOrder,
        });
        sortOrder += 1000;
    }

    return sortItems(nextItems);
}

export function readRealtimeChangedAtToken(eventPayload) {
    const changedAtToken = String(eventPayload?.changed_at ?? '').trim();
    return changedAtToken === '' ? null : changedAtToken;
}

export function readRealtimeListVersion(eventPayload) {
    const numericVersion = Number(eventPayload?.list_version ?? 0);
    if (!Number.isFinite(numericVersion) || numericVersion <= 0) {
        return null;
    }

    return Math.floor(numericVersion);
}

export function buildIncomingItemsFromRealtimeEvent(previousItems, eventPayload, options = {}) {
    const mode = String(eventPayload?.mode ?? 'snapshot').trim();
    if (mode !== 'delta') {
        return Array.isArray(eventPayload?.items) ? eventPayload.items : null;
    }

    const operation = normalizeOperation(eventPayload?.operation);
    const currentItems = cloneItems(previousItems);

    if (operation === 'created' || operation === 'updated') {
        const incomingItem = eventPayload?.item;
        if (!incomingItem || typeof incomingItem !== 'object') {
            return currentItems;
        }

        const normalizedIncoming = normalizeItems([incomingItem], currentItems, options)[0] ?? null;
        if (!normalizedIncoming) {
            return currentItems;
        }

        const nextItems = currentItems.filter((entry) => Number(entry?.id) !== Number(normalizedIncoming.id));
        nextItems.push(normalizedIncoming);
        return sortItems(nextItems);
    }

    if (operation === 'deleted') {
        const removedItemId = Number(eventPayload?.removed_item_id);
        if (!Number.isFinite(removedItemId) || removedItemId <= 0) {
            return currentItems;
        }

        return currentItems.filter((entry) => Number(entry?.id) !== removedItemId);
    }

    if (operation === 'reordered') {
        return applyReorderDelta(currentItems, eventPayload?.active_order, eventPayload?.completed_order);
    }

    return Array.isArray(eventPayload?.items) ? eventPayload.items : currentItems;
}

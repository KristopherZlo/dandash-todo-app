import { deduplicateItemsById } from './realtimeListMerge';

export function normalizeComparableValue(value) {
    if (value === null || value === undefined) {
        return '';
    }

    return String(value);
}

export function parseComparableTimestampMs(value) {
    const parsed = Date.parse(normalizeComparableValue(value));
    return Number.isFinite(parsed) ? parsed : null;
}

export function normalizeSortOrderValue(value, fallback = 1000) {
    const parsed = Number(value);
    if (!Number.isFinite(parsed)) {
        return fallback;
    }

    return Math.round(parsed);
}

function cloneItems(items) {
    if (!Array.isArray(items)) {
        return [];
    }

    return items.map((item) => ({ ...item }));
}

export function sortItems(items) {
    return cloneItems(items).sort((left, right) => {
        const completedSort = Number(left.is_completed) - Number(right.is_completed);
        if (completedSort !== 0) {
            return completedSort;
        }

        const leftSortOrder = normalizeSortOrderValue(left.sort_order, 1000);
        const rightSortOrder = normalizeSortOrderValue(right.sort_order, 1000);
        if (leftSortOrder !== rightSortOrder) {
            return leftSortOrder - rightSortOrder;
        }

        return String(right.created_at ?? '').localeCompare(String(left.created_at ?? ''));
    });
}

export function areItemsEquivalent(leftItems, rightItems, options = {}) {
    const { normalizeLinkId = (value) => value } = options;
    const left = Array.isArray(leftItems) ? leftItems : [];
    const right = Array.isArray(rightItems) ? rightItems : [];

    if (left.length !== right.length) {
        return false;
    }

    for (let index = 0; index < left.length; index += 1) {
        const leftItem = left[index] ?? {};
        const rightItem = right[index] ?? {};

        if (Number(leftItem.id) !== Number(rightItem.id)) {
            return false;
        }

        if (normalizeComparableValue(leftItem.local_id) !== normalizeComparableValue(rightItem.local_id)) {
            return false;
        }

        if (normalizeComparableValue(leftItem.client_request_id) !== normalizeComparableValue(rightItem.client_request_id)) {
            return false;
        }

        if (Number(leftItem.owner_id) !== Number(rightItem.owner_id)) {
            return false;
        }

        if (normalizeLinkId(leftItem.list_id ?? leftItem.list_link_id) !== normalizeLinkId(rightItem.list_id ?? rightItem.list_link_id)) {
            return false;
        }

        if (normalizeComparableValue(leftItem.type) !== normalizeComparableValue(rightItem.type)) {
            return false;
        }

        if (normalizeComparableValue(leftItem.text) !== normalizeComparableValue(rightItem.text)) {
            return false;
        }

        if (normalizeComparableValue(leftItem.sort_order) !== normalizeComparableValue(rightItem.sort_order)) {
            return false;
        }

        if (normalizeComparableValue(leftItem.quantity) !== normalizeComparableValue(rightItem.quantity)) {
            return false;
        }

        if (normalizeComparableValue(leftItem.unit) !== normalizeComparableValue(rightItem.unit)) {
            return false;
        }

        if (normalizeComparableValue(leftItem.due_at) !== normalizeComparableValue(rightItem.due_at)) {
            return false;
        }

        if (normalizeComparableValue(leftItem.priority) !== normalizeComparableValue(rightItem.priority)) {
            return false;
        }

        if (Boolean(leftItem.is_completed) !== Boolean(rightItem.is_completed)) {
            return false;
        }

        if (normalizeComparableValue(leftItem.completed_at) !== normalizeComparableValue(rightItem.completed_at)) {
            return false;
        }

        if (normalizeComparableValue(leftItem.created_at) !== normalizeComparableValue(rightItem.created_at)) {
            return false;
        }

        if (normalizeComparableValue(leftItem.updated_at) !== normalizeComparableValue(rightItem.updated_at)) {
            return false;
        }

        if (Boolean(leftItem.pending_sync) !== Boolean(rightItem.pending_sync)) {
            return false;
        }
    }

    return true;
}

export function normalizeItem(item, options = {}) {
    const {
        ownerIdOverride = null,
        linkIdOverride = null,
        normalizeLinkId = (value) => value,
        normalizeTodoPriority = (value) => value,
    } = options;
    const normalizedListId = normalizeLinkId(
        linkIdOverride ?? item?.list_id ?? item?.list_link_id ?? ownerIdOverride ?? item?.owner_id,
    );

    return {
        ...item,
        owner_id: Number(ownerIdOverride ?? item?.list_id ?? item?.owner_id ?? 0),
        list_id: normalizedListId,
        list_link_id: normalizedListId,
        sort_order: normalizeSortOrderValue(item?.sort_order, 1000),
        local_id: item?.local_id ?? `srv-${item?.id}`,
        priority: item?.type === 'todo' ? normalizeTodoPriority(item?.priority) : null,
        client_request_id: normalizeComparableValue(item?.client_request_id) || null,
        pending_sync: false,
    };
}

export function normalizeItems(items, previousItems = [], options = {}) {
    const {
        ownerIdOverride = null,
        linkIdOverride = null,
        normalizeLinkId = (value) => value,
        normalizeTodoPriority = (value) => value,
    } = options;
    const previousList = Array.isArray(previousItems) ? previousItems : [];
    const previousLocalIdById = new Map(
        previousList
            .map((item) => [Number(item?.id), String(item?.local_id ?? '').trim()])
            .filter(([id, localId]) => Number.isFinite(id) && localId !== ''),
    );
    const previousItemById = new Map(
        previousList
            .map((item) => [Number(item?.id), item])
            .filter(([id]) => Number.isFinite(id)),
    );

    const normalizedItems = (items ?? []).map((item) => {
        const normalized = normalizeItem(item, {
            ownerIdOverride,
            linkIdOverride,
            normalizeLinkId,
            normalizeTodoPriority,
        });
        const itemId = Number(normalized.id);
        const preservedLocalId = previousLocalIdById.get(itemId);

        if (preservedLocalId) {
            normalized.local_id = preservedLocalId;
        }

        const previousItem = previousItemById.get(itemId);
        if (!previousItem) {
            return normalized;
        }

        const previousUpdatedAtMs = parseComparableTimestampMs(previousItem.updated_at);
        const nextUpdatedAtMs = parseComparableTimestampMs(normalized.updated_at);
        const shouldKeepPrevious = previousUpdatedAtMs !== null
            && (nextUpdatedAtMs === null || previousUpdatedAtMs >= nextUpdatedAtMs);

        if (!shouldKeepPrevious) {
            return normalized;
        }

        return {
            ...normalized,
            ...previousItem,
            owner_id: normalized.owner_id,
            list_id: normalized.list_id,
            list_link_id: normalized.list_link_id,
            local_id: preservedLocalId || String(previousItem?.local_id ?? '').trim() || normalized.local_id,
            client_request_id: normalized.client_request_id || previousItem?.client_request_id || null,
        };
    });

    return sortItems(deduplicateItemsById(normalizedItems));
}

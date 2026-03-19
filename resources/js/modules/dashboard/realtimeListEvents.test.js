import { describe, expect, it } from 'vitest';
import { buildIncomingItemsFromRealtimeEvent, readRealtimeListVersion } from './realtimeListEvents';

describe('realtimeListEvents', () => {
    const options = {
        ownerIdOverride: 1,
        linkIdOverride: null,
        normalizeLinkId: (value) => value,
        normalizeTodoPriority: (value) => value,
    };

    it('applies delta create events without requiring full snapshot', () => {
        const previousItems = [{
            id: 1,
            owner_id: 1,
            list_link_id: null,
            type: 'product',
            text: 'Milk',
            sort_order: 1000,
            is_completed: false,
            created_at: '2026-03-19T10:00:00.000Z',
        }];

        const result = buildIncomingItemsFromRealtimeEvent(previousItems, {
            mode: 'delta',
            operation: 'created',
            item: {
                id: 2,
                owner_id: 1,
                list_link_id: null,
                type: 'product',
                text: 'Bread',
                sort_order: 0,
                is_completed: false,
                created_at: '2026-03-19T11:00:00.000Z',
            },
        }, options);

        expect(result.map((item) => item.id)).toEqual([2, 1]);
    });

    it('applies delta reorder events to current items', () => {
        const previousItems = [
            { id: 1, owner_id: 1, list_link_id: null, type: 'product', text: 'Milk', sort_order: 1000, is_completed: false },
            { id: 2, owner_id: 1, list_link_id: null, type: 'product', text: 'Bread', sort_order: 2000, is_completed: false },
            { id: 3, owner_id: 1, list_link_id: null, type: 'product', text: 'Done', sort_order: 1000, is_completed: true },
        ];

        const result = buildIncomingItemsFromRealtimeEvent(previousItems, {
            mode: 'delta',
            operation: 'reordered',
            active_order: [2, 1],
            completed_order: [3],
        }, options);

        expect(result.map((item) => item.id)).toEqual([2, 1, 3]);
        expect(result[0].sort_order).toBe(1000);
        expect(result[1].sort_order).toBe(2000);
    });

    it('reads realtime list version safely', () => {
        expect(readRealtimeListVersion({ list_version: 5.8 })).toBe(5);
        expect(readRealtimeListVersion({ list_version: 0 })).toBeNull();
    });
});

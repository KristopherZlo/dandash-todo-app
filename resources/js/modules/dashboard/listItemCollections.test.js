import { describe, expect, it } from 'vitest';
import {
    areItemsEquivalent,
    normalizeItems,
    parseComparableTimestampMs,
    sortItems,
} from './listItemCollections';

describe('listItemCollections', () => {
    it('preserves newer previous item state while keeping server request id', () => {
        const previousItems = [{
            id: 5,
            owner_id: 1,
            list_link_id: null,
            type: 'product',
            text: 'Milk',
            local_id: 'tmp-5',
            client_request_id: 'req-5',
            updated_at: '2026-03-19T12:01:00.000Z',
            pending_sync: true,
        }];

        const normalized = normalizeItems([{
            id: 5,
            owner_id: 1,
            list_link_id: null,
            type: 'product',
            text: 'Milk',
            client_request_id: 'req-5',
            updated_at: '2026-03-19T12:00:00.000Z',
        }], previousItems, {
            normalizeLinkId: (value) => value,
            normalizeTodoPriority: (value) => value,
        });

        expect(normalized[0].local_id).toBe('tmp-5');
        expect(normalized[0].client_request_id).toBe('req-5');
        expect(normalized[0].pending_sync).toBe(true);
        expect(normalized[0].updated_at).toBe('2026-03-19T12:01:00.000Z');
    });

    it('includes client request id in equivalence checks', () => {
        const left = [{ id: 1, client_request_id: 'req-a', list_link_id: null }];
        const right = [{ id: 1, client_request_id: 'req-b', list_link_id: null }];

        expect(areItemsEquivalent(left, right, { normalizeLinkId: (value) => value })).toBe(false);
    });

    it('sorts active items before completed items', () => {
        const result = sortItems([
            { id: 2, is_completed: true, sort_order: 1000, created_at: '2026-03-19T10:00:00Z' },
            { id: 1, is_completed: false, sort_order: 2000, created_at: '2026-03-19T10:00:00Z' },
            { id: 3, is_completed: false, sort_order: 1000, created_at: '2026-03-19T10:00:00Z' },
        ]);

        expect(result.map((item) => item.id)).toEqual([3, 1, 2]);
    });

    it('parses comparable timestamps safely', () => {
        expect(parseComparableTimestampMs('2026-03-19T10:00:00.000Z')).toBeTypeOf('number');
        expect(parseComparableTimestampMs('not-a-date')).toBeNull();
    });
});

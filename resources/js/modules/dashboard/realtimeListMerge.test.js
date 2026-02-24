import { describe, expect, it } from 'vitest';
import {
    PENDING_CREATE_REALTIME_MATCH_WINDOW_MS,
    canMatchPendingCreateToRealtimeServerItem,
    deduplicateItemsById,
    findRealtimeMatchForPendingCreate,
} from './realtimeListMerge';

function normalizeLinkId(value) {
    const numericValue = Number(value);
    return Number.isFinite(numericValue) && numericValue > 0
        ? Math.floor(numericValue)
        : null;
}

describe('realtimeListMerge', () => {
    it('deduplicates items by numeric id while preserving first occurrence', () => {
        const first = { id: 10, text: 'Milk' };
        const duplicate = { id: 10, text: 'Milk duplicate' };
        const noId = { text: 'No id' };
        const second = { id: 11, text: 'Bread' };

        const result = deduplicateItemsById([first, duplicate, noId, second]);

        expect(result).toEqual([first, noId, second]);
    });

    it('matches pending create temp item with realtime server item', () => {
        const localPending = {
            id: -101,
            owner_id: 5,
            list_link_id: 9,
            type: 'product',
            text: 'Форма для льда',
            quantity: null,
            unit: null,
            due_at: null,
            priority: null,
            is_completed: false,
            created_at: '2026-02-23T20:00:00.000Z',
        };
        const incoming = {
            id: 301,
            owner_id: 5,
            list_link_id: 9,
            type: 'product',
            text: 'Форма для льда',
            quantity: null,
            unit: null,
            due_at: null,
            priority: null,
            is_completed: false,
            created_at: '2026-02-23T20:00:03.000Z',
        };

        const match = findRealtimeMatchForPendingCreate(localPending, [incoming], {
            ownerId: 5,
            type: 'product',
            resolvedLinkId: 9,
            normalizeLinkId,
        });

        expect(match).toEqual(incoming);
    });

    it('allows completion mismatch for fast create then complete sync-chunk sequence', () => {
        const localPending = {
            id: -7,
            owner_id: 1,
            list_link_id: null,
            type: 'product',
            text: 'Mayonnaise',
            quantity: null,
            unit: null,
            due_at: null,
            priority: null,
            is_completed: true,
            created_at: '2026-02-24T12:00:00.000Z',
        };
        const incomingIntermediateSnapshot = {
            id: 88,
            owner_id: 1,
            list_link_id: null,
            type: 'product',
            text: 'Mayonnaise',
            quantity: null,
            unit: null,
            due_at: null,
            priority: null,
            is_completed: false,
            created_at: '2026-02-24T12:00:01.000Z',
        };

        const canMatch = canMatchPendingCreateToRealtimeServerItem(
            localPending,
            incomingIntermediateSnapshot,
            {
                ownerId: 1,
                type: 'product',
                resolvedLinkId: null,
                normalizeLinkId,
            },
        );

        expect(canMatch).toBe(true);
    });

    it('does not match when created_at is outside allowed window', () => {
        const localPending = {
            id: -9,
            owner_id: 3,
            list_link_id: null,
            type: 'todo',
            text: 'Pay bills',
            quantity: null,
            unit: null,
            due_at: null,
            priority: 'today',
            created_at: '2026-02-24T10:00:00.000Z',
        };
        const incoming = {
            id: 777,
            owner_id: 3,
            list_link_id: null,
            type: 'todo',
            text: 'Pay bills',
            quantity: null,
            unit: null,
            due_at: null,
            priority: 'today',
            created_at: new Date(
                Date.parse(localPending.created_at) + PENDING_CREATE_REALTIME_MATCH_WINDOW_MS + 1,
            ).toISOString(),
        };

        const match = findRealtimeMatchForPendingCreate(localPending, [incoming], {
            ownerId: 3,
            type: 'todo',
            resolvedLinkId: null,
            normalizeLinkId,
        });

        expect(match).toBeNull();
    });

    it('skips incoming ids that were already matched', () => {
        const localPending = {
            id: -22,
            owner_id: 8,
            list_link_id: null,
            type: 'product',
            text: 'Soy sauce',
            quantity: null,
            unit: null,
            due_at: null,
            priority: null,
            created_at: '2026-02-24T14:00:00.000Z',
        };
        const incoming = {
            id: 500,
            owner_id: 8,
            list_link_id: null,
            type: 'product',
            text: 'Soy sauce',
            quantity: null,
            unit: null,
            due_at: null,
            priority: null,
            created_at: '2026-02-24T14:00:02.000Z',
        };

        const match = findRealtimeMatchForPendingCreate(localPending, [incoming], {
            ownerId: 8,
            type: 'product',
            resolvedLinkId: null,
            normalizeLinkId,
            usedIncomingIds: new Set([500]),
        });

        expect(match).toBeNull();
    });
});

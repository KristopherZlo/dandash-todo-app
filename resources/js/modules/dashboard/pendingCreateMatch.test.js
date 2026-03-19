import { describe, expect, it } from 'vitest';
import { findBestPendingCreateMatch } from './pendingCreateMatch';

describe('findBestPendingCreateMatch', () => {
    it('prefers exact client request id over fuzzy timestamp matching', () => {
        const localPending = {
            id: -1,
            owner_id: 5,
            list_link_id: null,
            type: 'product',
            text: 'Milk',
            client_request_id: 'req-123',
            created_at: '2026-03-19T10:00:00.000Z',
        };
        const exactMatch = {
            id: 11,
            owner_id: 5,
            list_link_id: null,
            type: 'product',
            text: 'Milk',
            client_request_id: 'req-123',
            created_at: '2026-03-19T10:30:00.000Z',
        };
        const fuzzyOnlyMatch = {
            id: 12,
            owner_id: 5,
            list_link_id: null,
            type: 'product',
            text: 'Milk',
            created_at: '2026-03-19T10:00:01.000Z',
        };

        const match = findBestPendingCreateMatch(localPending, [fuzzyOnlyMatch, exactMatch], {
            ownerId: 5,
            type: 'product',
            resolvedLinkId: null,
            normalizeLinkId: (value) => value,
        });

        expect(match?.id).toBe(11);
    });
});

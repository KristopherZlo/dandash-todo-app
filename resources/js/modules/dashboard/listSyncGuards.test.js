import { describe, expect, it } from 'vitest';
import { createListSyncGuards } from './listSyncGuards';

function createGuards(localMutationHoldMs = 2000) {
    return createListSyncGuards({
        localMutationHoldMs,
        resolveLinkIdForOwner: (_ownerId, linkId) => (linkId == null ? null : Number(linkId)),
        listCacheKey: (ownerId, type, linkId) => `${ownerId}:${type}:${linkId ?? 'personal'}`,
    });
}

describe('listSyncGuards', () => {
    it('tracks sync versions per list scope', () => {
        const guards = createGuards();

        expect(guards.getListSyncVersion(1, 'product')).toBe(0);
        expect(guards.bumpListSyncVersion(1, 'product')).toBe(1);
        expect(guards.bumpListSyncVersion(1, 'product')).toBe(2);
        expect(guards.getListSyncVersion(1, 'product')).toBe(2);
        expect(guards.getListSyncVersion(1, 'todo')).toBe(0);
    });

    it('clears recent local mutations when newer server version arrives', () => {
        const guards = createGuards();

        guards.markListMutated(7, 'product', 12, 100);
        expect(guards.hasRecentListMutation(7, 'product', 12, 200)).toBe(true);

        guards.setKnownServerListVersion(7, 'product', 3, 12);
        expect(guards.getKnownServerListVersion(7, 'product', 12)).toBe(3);
        expect(guards.hasRecentListMutation(7, 'product', 12, 200)).toBe(false);
    });

    it('expires stale mutation guards after the configured hold window', () => {
        const guards = createGuards(250);

        guards.markListMutated(1, 'todo', null, 1000);
        expect(guards.hasRecentListMutation(1, 'todo', null, 1200)).toBe(true);
        expect(guards.hasRecentListMutation(1, 'todo', null, 1301)).toBe(false);
    });

    it('supports explicit mutation guard clearing through zero timestamps', () => {
        const guards = createGuards();

        guards.markListMutated(3, 'product', null, 500);
        expect(guards.hasRecentListMutation(3, 'product', null, 550)).toBe(true);

        guards.markListMutated(3, 'product', null, 0);
        expect(guards.hasRecentListMutation(3, 'product', null, 550)).toBe(false);
    });
});

import { describe, expect, it } from 'vitest';
import {
    buildListChannelName,
    createListScopeHelpers,
    findListOptionByOwner,
    isSameListScope,
    listCacheKey,
    matchesScopedOperation,
    resolveLinkIdForOwner,
    suggestionStatsCacheKey,
    suggestionsCacheKey,
} from './listScopes';

function normalizeLinkId(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) && parsed > 0 ? Math.floor(parsed) : null;
}

const options = { normalizeLinkId };
const listOptions = [
    { owner_id: 7, list_id: 7, link_id: 7, label: 'Personal' },
    { owner_id: 22, list_id: 22, link_id: 22, label: 'Trip' },
];

describe('listScopes', () => {
    it('compares scope by list id before fallback owner id', () => {
        expect(isSameListScope(7, 22, 9, 22, options)).toBe(true);
        expect(isSameListScope(7, 22, 7, 7, options)).toBe(false);
        expect(isSameListScope(7, null, 7, null, options)).toBe(true);
    });

    it('matches queued operations against scoped list context', () => {
        expect(matchesScopedOperation({
            owner_id: 22,
            list_id: 22,
            type: 'product',
        }, 22, 'product', 22, options)).toBe(true);

        expect(matchesScopedOperation({
            owner_id: 7,
            list_id: 7,
            type: 'todo',
        }, 7, 'product', null, options)).toBe(false);
    });

    it('resolves list ids and cache keys from list options', () => {
        expect(findListOptionByOwner(listOptions, 22)?.list_id).toBe(22);
        expect(resolveLinkIdForOwner(22, undefined, listOptions, options)).toBe(22);
        expect(resolveLinkIdForOwner(7, undefined, listOptions, options)).toBe(7);
        expect(listCacheKey(22, 'product', 22, options)).toBe('list:22:product');
        expect(suggestionsCacheKey(22, 'product', undefined, listOptions, options)).toBe('list:22:product');
        expect(suggestionStatsCacheKey(7, 'Product', undefined, listOptions, options)).toBe('list:7:product');
    });

    it('builds realtime channel names from resolved scope', () => {
        expect(buildListChannelName(22, listOptions, options)).toBe('lists.22');
        expect(buildListChannelName(7, listOptions, options)).toBe('lists.7');
    });

    it('binds list options and normalizer through helper factory', () => {
        const helpers = createListScopeHelpers({
            getListOptions: () => listOptions,
            normalizeLinkId,
        });

        expect(helpers.findListOptionByOwner(22)?.list_id).toBe(22);
        expect(helpers.resolveLinkIdForOwner(22)).toBe(22);
        expect(helpers.listCacheKey(22, 'todo', 22)).toBe('list:22:todo');
        expect(helpers.suggestionStatsCacheKey(7, 'Product')).toBe('list:7:product');
        expect(helpers.buildListChannelName(22)).toBe('lists.22');
    });
});

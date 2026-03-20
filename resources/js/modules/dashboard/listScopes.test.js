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
    { owner_id: 7, link_id: null, label: 'Личный' },
    { owner_id: 9, link_id: 22, label: 'Вы и Alex' },
];

describe('listScopes', () => {
    it('compares shared scope by link id before owner id', () => {
        expect(isSameListScope(7, 22, 9, 22, options)).toBe(true);
        expect(isSameListScope(7, 22, 7, null, options)).toBe(false);
        expect(isSameListScope(7, null, 7, null, options)).toBe(true);
    });

    it('matches queued operations against scoped list context', () => {
        expect(matchesScopedOperation({
            owner_id: 9,
            link_id: 22,
            type: 'product',
        }, 7, 'product', 22, options)).toBe(true);

        expect(matchesScopedOperation({
            owner_id: 7,
            link_id: null,
            type: 'todo',
        }, 7, 'product', null, options)).toBe(false);
    });

    it('resolves link ids and cache keys from list options', () => {
        expect(findListOptionByOwner(listOptions, 9)?.link_id).toBe(22);
        expect(resolveLinkIdForOwner(9, undefined, listOptions, options)).toBe(22);
        expect(resolveLinkIdForOwner(7, undefined, listOptions, options)).toBeNull();
        expect(listCacheKey(9, 'product', 22, options)).toBe('shared:22:product');
        expect(suggestionsCacheKey(9, 'product', undefined, listOptions, options)).toBe('shared:22:product');
        expect(suggestionStatsCacheKey(7, 'Product', undefined, listOptions, options)).toBe('owner:7:personal:product');
    });

    it('builds realtime channel names from resolved scope', () => {
        expect(buildListChannelName(9, listOptions, options)).toBe('lists.shared.22');
        expect(buildListChannelName(7, listOptions, options)).toBe('lists.personal.7');
    });

    it('binds list options and normalizer through helper factory', () => {
        const helpers = createListScopeHelpers({
            getListOptions: () => listOptions,
            normalizeLinkId,
        });

        expect(helpers.findListOptionByOwner(9)?.link_id).toBe(22);
        expect(helpers.resolveLinkIdForOwner(9)).toBe(22);
        expect(helpers.listCacheKey(9, 'todo', 22)).toBe('shared:22:todo');
        expect(helpers.suggestionStatsCacheKey(7, 'Product')).toBe('owner:7:personal:product');
        expect(helpers.buildListChannelName(9)).toBe('lists.shared.22');
    });
});

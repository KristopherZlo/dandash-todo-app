import { nextTick, ref } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useSuggestionStats } from './useSuggestionStats';

function createComposable(initialStats = []) {
    const selectedOwnerId = ref(7);
    const selectedListLinkId = ref(12);
    const cache = {
        '7:product:12': {
            stats: initialStats.map((entry) => ({ ...entry })),
        },
    };

    const api = useSuggestionStats({
        formatIntervalSeconds: (value) => `interval:${value ?? 'auto'}`,
        normalizeSuggestionStatsType: (value) => (String(value) === 'todo' ? 'todo' : 'product'),
        normalizeLinkId: (value) => (value == null ? null : Number(value)),
        readSuggestionStatsFromCache: (ownerId, type, linkId) => ({
            stats: [...(cache[`${ownerId}:${type}:${linkId ?? 'personal'}`]?.stats ?? [])],
        }),
        writeSuggestionStatsToCache: (ownerId, type, payload, linkId) => {
            cache[`${ownerId}:${type}:${linkId ?? 'personal'}`] = {
                stats: [...(payload?.stats ?? [])],
            };
        },
        selectedOwnerId,
        selectedListLinkId,
    });

    return {
        api,
        cache,
        selectedOwnerId,
        selectedListLinkId,
    };
}

describe('useSuggestionStats', () => {
    afterEach(() => {
        vi.useRealTimers();
    });

    it('resets modal search and view state when opening', () => {
        const { api } = createComposable();

        api.suggestionStatsSearchQuery.value = 'milk';
        api.suggestionStatsView.value = 'ignored';
        api.suggestionStatsPage.value = 3;

        const type = api.openSuggestionStatsModal('todo');

        expect(type).toBe('todo');
        expect(api.productStatsModalOpen.value).toBe(true);
        expect(api.suggestionStatsType.value).toBe('todo');
        expect(api.suggestionStatsSearchQuery.value).toBe('');
        expect(api.suggestionStatsView.value).toBe('active');
        expect(api.pagedSuggestionStats.value.page).toBe(1);
    });

    it('filters entries and keeps pagination in range', async () => {
        const { api } = createComposable([
            { suggestion_key: 'milk', text: 'Milk', retired_at: null },
            { suggestion_key: 'bread', text: 'Bread', retired_at: null },
            { suggestion_key: 'jam', text: 'Jam', retired_at: '2026-03-19T12:00:00Z' },
        ]);

        api.productSuggestionStats.value = [
            { suggestion_key: 'milk', text: 'Milk', retired_at: null },
            { suggestion_key: 'bread', text: 'Bread', retired_at: null },
            { suggestion_key: 'jam', text: 'Jam', retired_at: '2026-03-19T12:00:00Z' },
        ];

        api.suggestionStatsPage.value = 2;
        api.suggestionStatsSearchQuery.value = 'milk';
        await nextTick();

        expect(api.filteredSuggestionStats.value.map((entry) => entry.suggestion_key)).toEqual(['milk']);
        expect(api.pagedSuggestionStats.value.page).toBe(1);

        api.changeSuggestionStatsView('ignored');
        await nextTick();

        expect(api.filteredSuggestionStats.value.map((entry) => entry.suggestion_key)).toEqual([]);
        expect(api.suggestionStatsViewCount('ignored')).toBe(1);
    });

    it('removes reset rows from state and cache after the timeout', () => {
        vi.useFakeTimers();
        const { api, cache } = createComposable([
            { suggestion_key: 'milk', text: 'Milk', retired_at: null },
            { suggestion_key: 'bread', text: 'Bread', retired_at: null },
        ]);

        api.productSuggestionStats.value = [
            { suggestion_key: 'milk', text: 'Milk', retired_at: null },
            { suggestion_key: 'bread', text: 'Bread', retired_at: null },
        ];

        api.removeSuggestionStatsRowAfterReset('milk', 300);

        vi.advanceTimersByTime(299);
        expect(api.productSuggestionStats.value.map((entry) => entry.suggestion_key)).toEqual(['milk', 'bread']);

        vi.advanceTimersByTime(1);
        expect(api.productSuggestionStats.value.map((entry) => entry.suggestion_key)).toEqual(['bread']);
        expect(cache['7:product:12'].stats.map((entry) => entry.suggestion_key)).toEqual(['bread']);
    });
});

import { nextTick } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useSuggestionStats } from './useSuggestionStats';

function createComposable(initialStats = []) {
    const api = useSuggestionStats({
        formatIntervalSeconds: (value) => `interval:${value ?? 'auto'}`,
        normalizeSuggestionStatsType: (value) => (String(value) === 'todo' ? 'todo' : 'product'),
    });

    api.productSuggestionStats.value = initialStats.map((entry) => ({ ...entry }));

    return {
        api,
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

    it('removes reset rows from state after the timeout and runs removal callback', () => {
        vi.useFakeTimers();
        const onRemove = vi.fn();
        const { api } = createComposable([
            { suggestion_key: 'milk', text: 'Milk', retired_at: null },
            { suggestion_key: 'bread', text: 'Bread', retired_at: null },
        ]);

        api.scheduleSuggestionStatsRowRemoval('milk', {
            delayMs: 300,
            onRemove,
        });

        vi.advanceTimersByTime(299);
        expect(api.productSuggestionStats.value.map((entry) => entry.suggestion_key)).toEqual(['milk', 'bread']);

        vi.advanceTimersByTime(1);
        expect(api.productSuggestionStats.value.map((entry) => entry.suggestion_key)).toEqual(['bread']);
        expect(onRemove).toHaveBeenCalledWith('milk');
    });

    it('does not remove local rows after context type changes before timeout', () => {
        vi.useFakeTimers();
        const { api } = createComposable([
            { suggestion_key: 'milk', text: 'Milk', retired_at: null },
            { suggestion_key: 'bread', text: 'Bread', retired_at: null },
        ]);

        api.scheduleSuggestionStatsRowRemoval('milk', { delayMs: 300 });
        api.suggestionStatsType.value = 'todo';

        vi.advanceTimersByTime(300);
        expect(api.productSuggestionStats.value.map((entry) => entry.suggestion_key)).toEqual(['milk', 'bread']);
    });
});

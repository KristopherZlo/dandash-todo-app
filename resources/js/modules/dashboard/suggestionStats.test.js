import { describe, expect, it } from 'vitest';
import {
    filterSuggestionStatsEntries,
    isIgnoredSuggestionStatsEntry,
    paginateSuggestionStatsEntries,
    parseSuggestionIntervalPresetValue,
    suggestionIntervalPresetValueForEntry,
} from './suggestionStats';

describe('suggestionStats', () => {
    it('filters active and ignored entries separately', () => {
        const entries = [
            { suggestion_key: 'milk', text: 'Milk', retired_at: null },
            { suggestion_key: 'tea', text: 'Tea', retired_at: '2026-03-01T10:00:00.000Z' },
        ];

        expect(filterSuggestionStatsEntries(entries, { view: 'active' })).toEqual([
            entries[0],
        ]);
        expect(filterSuggestionStatsEntries(entries, { view: 'ignored' })).toEqual([
            entries[1],
        ]);
        expect(isIgnoredSuggestionStatsEntry(entries[1])).toBe(true);
    });

    it('matches search against text, key and variants', () => {
        const entries = [
            {
                suggestion_key: 'dish-soap',
                text: 'Dish soap',
                variants: ['Soap for dishes'],
                retired_at: null,
            },
            {
                suggestion_key: 'coffee',
                text: 'Coffee',
                variants: ['Arabica'],
                retired_at: null,
            },
        ];

        expect(filterSuggestionStatsEntries(entries, { query: 'dish', view: 'active' })).toEqual([
            entries[0],
        ]);
        expect(filterSuggestionStatsEntries(entries, { query: 'arab', view: 'active' })).toEqual([
            entries[1],
        ]);
    });

    it('paginates and clamps page numbers', () => {
        const entries = Array.from({ length: 10 }, (_, index) => ({
            suggestion_key: `item-${index + 1}`,
        }));

        const result = paginateSuggestionStatsEntries(entries, 3, 4);

        expect(result.totalPages).toBe(3);
        expect(result.page).toBe(3);
        expect(result.items).toEqual(entries.slice(8, 10));
    });

    it('parses interval presets consistently', () => {
        expect(parseSuggestionIntervalPresetValue('auto')).toBeNull();
        expect(parseSuggestionIntervalPresetValue('86400')).toBe(86400);
        expect(suggestionIntervalPresetValueForEntry({ custom_interval_seconds: 172800 })).toBe('172800');
        expect(suggestionIntervalPresetValueForEntry({ custom_interval_seconds: null })).toBe('auto');
    });
});

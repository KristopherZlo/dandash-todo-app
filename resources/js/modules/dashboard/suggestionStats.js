export const SUGGESTION_STATS_PAGE_SIZE = 8;

export const SUGGESTION_INTERVAL_PRESETS = Object.freeze([
    { value: 'auto', seconds: null, label: 'Авто' },
    { value: '86400', seconds: 86400, label: '1 день' },
    { value: '172800', seconds: 172800, label: '2 дня' },
    { value: '259200', seconds: 259200, label: '3 дня' },
    { value: '604800', seconds: 604800, label: '1 неделя' },
    { value: '1209600', seconds: 1209600, label: '2 недели' },
    { value: '2592000', seconds: 2592000, label: '1 месяц' },
]);

export function normalizeSuggestionStatsView(value) {
    return String(value ?? '').trim().toLowerCase() === 'ignored'
        ? 'ignored'
        : 'active';
}

export function normalizeSuggestionStatsSearchQuery(value) {
    return String(value ?? '').trim().toLowerCase();
}

export function isIgnoredSuggestionStatsEntry(entry) {
    return String(entry?.retired_at ?? '').trim() !== '';
}

export function parseSuggestionIntervalPresetValue(value) {
    const normalized = String(value ?? 'auto').trim().toLowerCase();
    if (normalized === '' || normalized === 'auto') {
        return null;
    }

    const numericValue = Number(normalized);
    if (!Number.isFinite(numericValue) || numericValue <= 0) {
        return null;
    }

    return Math.max(1, Math.floor(numericValue));
}

export function suggestionIntervalPresetValueForEntry(entry) {
    const customIntervalSeconds = Number(entry?.custom_interval_seconds);
    if (!Number.isFinite(customIntervalSeconds) || customIntervalSeconds <= 0) {
        return 'auto';
    }

    return String(Math.floor(customIntervalSeconds));
}

export function filterSuggestionStatsEntries(entries, options = {}) {
    const source = Array.isArray(entries) ? entries : [];
    const normalizedView = normalizeSuggestionStatsView(options.view);
    const normalizedQuery = normalizeSuggestionStatsSearchQuery(options.query);

    return source.filter((entry) => {
        const matchesView = normalizedView === 'ignored'
            ? isIgnoredSuggestionStatsEntry(entry)
            : !isIgnoredSuggestionStatsEntry(entry);

        if (!matchesView) {
            return false;
        }

        if (normalizedQuery === '') {
            return true;
        }

        const haystack = [
            entry?.text,
            entry?.suggestion_key,
            ...(Array.isArray(entry?.variants) ? entry.variants : []),
        ]
            .map((value) => String(value ?? '').trim().toLowerCase())
            .filter((value) => value !== '');

        return haystack.some((value) => value.includes(normalizedQuery));
    });
}

export function paginateSuggestionStatsEntries(entries, page, pageSize = SUGGESTION_STATS_PAGE_SIZE) {
    const source = Array.isArray(entries) ? entries : [];
    const normalizedPageSize = Math.max(1, Math.floor(Number(pageSize) || SUGGESTION_STATS_PAGE_SIZE));
    const totalItems = source.length;
    const totalPages = Math.max(1, Math.ceil(totalItems / normalizedPageSize));
    const normalizedPage = Math.min(
        totalPages,
        Math.max(1, Math.floor(Number(page) || 1)),
    );
    const startIndex = (normalizedPage - 1) * normalizedPageSize;

    return {
        page: normalizedPage,
        pageSize: normalizedPageSize,
        totalItems,
        totalPages,
        items: source.slice(startIndex, startIndex + normalizedPageSize),
    };
}

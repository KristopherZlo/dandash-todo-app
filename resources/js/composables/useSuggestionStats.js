import { computed, ref, watch } from 'vue';
import {
    filterSuggestionStatsEntries,
    paginateSuggestionStatsEntries,
} from '@/modules/dashboard/suggestionStats';

function createDefaultSuggestionStatsSummary() {
    return {
        total_added: 0,
        total_completed: 0,
        unique_items: 0,
        unique_products: 0,
        due_suggestions: 0,
        upcoming_suggestions: 0,
        last_activity_at: null,
    };
}

export function useSuggestionStats(options = {}) {
    const {
        formatIntervalSeconds,
        normalizeSuggestionStatsType,
    } = options;

    const productStatsModalOpen = ref(false);
    const suggestionStatsType = ref('product');
    const productSuggestionStats = ref([]);
    const productStatsSummary = ref(createDefaultSuggestionStatsSummary());
    const productSuggestionStatsLoading = ref(false);
    const resettingSuggestionKeys = ref([]);
    const recentlyResetSuggestionKeys = ref([]);
    const savingSuggestionSettingsKeys = ref([]);
    const suggestionStatsSearchQuery = ref('');
    const suggestionStatsView = ref('active');
    const suggestionStatsPage = ref(1);

    const suggestionResetSuccessTimers = new Map();
    const suggestionResetRemovalTimers = new Map();

    const filteredSuggestionStats = computed(() => (
        filterSuggestionStatsEntries(productSuggestionStats.value, {
            view: suggestionStatsView.value,
            query: suggestionStatsSearchQuery.value,
        })
    ));

    const pagedSuggestionStats = computed(() => (
        paginateSuggestionStatsEntries(filteredSuggestionStats.value, suggestionStatsPage.value)
    ));

    function suggestionSettingsSuccessKey(suggestionKey, mode = 'settings') {
        return `suggestion-settings:${String(mode ?? 'settings').trim()}:${String(suggestionKey ?? '').trim()}`;
    }

    function isSavingSuggestionSettings(suggestionKey) {
        return savingSuggestionSettingsKeys.value.includes(String(suggestionKey ?? '').trim());
    }

    function setSuggestionSettingsSaving(suggestionKey, active) {
        const normalizedKey = String(suggestionKey ?? '').trim();
        if (normalizedKey === '') {
            return;
        }

        if (active) {
            if (!savingSuggestionSettingsKeys.value.includes(normalizedKey)) {
                savingSuggestionSettingsKeys.value = [...savingSuggestionSettingsKeys.value, normalizedKey];
            }
            return;
        }

        savingSuggestionSettingsKeys.value = savingSuggestionSettingsKeys.value.filter((key) => key !== normalizedKey);
    }

    function suggestionStatsViewCount(view) {
        return filterSuggestionStatsEntries(productSuggestionStats.value, {
            view,
            query: '',
        }).length;
    }

    function changeSuggestionStatsView(view) {
        suggestionStatsView.value = view;
        suggestionStatsPage.value = 1;
    }

    function goToSuggestionStatsPage(page) {
        const totalPages = Math.max(1, Number(pagedSuggestionStats.value?.totalPages) || 1);
        suggestionStatsPage.value = Math.min(
            totalPages,
            Math.max(1, Math.floor(Number(page) || 1)),
        );
    }

    function formatSuggestionStatsEffectiveInterval(entry) {
        return formatIntervalSeconds(
            entry?.custom_interval_seconds
                ?? entry?.effective_interval_seconds
                ?? entry?.average_interval_seconds
                ?? null,
        );
    }

    function isResettingSuggestionKey(suggestionKey) {
        return resettingSuggestionKeys.value.includes(String(suggestionKey ?? ''));
    }

    function isSuggestionResetDone(suggestionKey) {
        return recentlyResetSuggestionKeys.value.includes(String(suggestionKey ?? ''));
    }

    function markSuggestionResetDone(suggestionKey, resetAfterMs = 1800) {
        const normalizedKey = String(suggestionKey ?? '').trim();
        if (normalizedKey === '') {
            return;
        }

        if (!recentlyResetSuggestionKeys.value.includes(normalizedKey)) {
            recentlyResetSuggestionKeys.value = [...recentlyResetSuggestionKeys.value, normalizedKey];
        }

        const existingTimer = suggestionResetSuccessTimers.get(normalizedKey);
        if (existingTimer) {
            globalThis.clearTimeout(existingTimer);
        }

        const timerId = globalThis.setTimeout(() => {
            suggestionResetSuccessTimers.delete(normalizedKey);
            recentlyResetSuggestionKeys.value = recentlyResetSuggestionKeys.value.filter((key) => key !== normalizedKey);
        }, Math.max(600, Number(resetAfterMs) || 1800));

        suggestionResetSuccessTimers.set(normalizedKey, timerId);
    }

    function removeSuggestionStatsRow(suggestionKey) {
        const normalizedKey = String(suggestionKey ?? '').trim();
        if (normalizedKey === '') {
            return;
        }

        productSuggestionStats.value = productSuggestionStats.value.filter(
            (statsEntry) => String(statsEntry?.suggestion_key ?? '') !== normalizedKey,
        );
    }

    function scheduleSuggestionStatsRowRemoval(suggestionKey, options = {}) {
        const normalizedKey = String(suggestionKey ?? '').trim();
        if (normalizedKey === '') {
            return;
        }

        const {
            delayMs = 1000,
            onRemove = null,
        } = options;
        const sourceType = normalizeSuggestionStatsType(suggestionStatsType.value);
        const timerKey = `${sourceType}:${normalizedKey}`;

        const existingTimer = suggestionResetRemovalTimers.get(timerKey);
        if (existingTimer) {
            globalThis.clearTimeout(existingTimer);
        }

        const timerId = globalThis.setTimeout(() => {
            suggestionResetRemovalTimers.delete(timerKey);
            if (typeof onRemove === 'function') {
                onRemove(normalizedKey);
            }

            if (normalizeSuggestionStatsType(suggestionStatsType.value) === sourceType) {
                removeSuggestionStatsRow(normalizedKey);
            }
        }, Math.max(300, Number(delayMs) || 1000));

        suggestionResetRemovalTimers.set(timerKey, timerId);
    }

    function openSuggestionStatsModal(type = 'product') {
        suggestionStatsSearchQuery.value = '';
        suggestionStatsView.value = 'active';
        suggestionStatsPage.value = 1;
        suggestionStatsType.value = normalizeSuggestionStatsType(type);
        productStatsModalOpen.value = true;
        return suggestionStatsType.value;
    }

    function closeSuggestionStatsModal() {
        productStatsModalOpen.value = false;
    }

    function resetSuggestionStatsSummary() {
        productStatsSummary.value = createDefaultSuggestionStatsSummary();
    }

    function disposeSuggestionStats() {
        for (const timerId of suggestionResetSuccessTimers.values()) {
            globalThis.clearTimeout(timerId);
        }
        suggestionResetSuccessTimers.clear();

        for (const timerId of suggestionResetRemovalTimers.values()) {
            globalThis.clearTimeout(timerId);
        }
        suggestionResetRemovalTimers.clear();
    }

    watch([suggestionStatsSearchQuery, suggestionStatsView, suggestionStatsType], () => {
        suggestionStatsPage.value = 1;
    });

    watch(filteredSuggestionStats, (entries) => {
        const totalPages = Math.max(1, Math.ceil(entries.length / pagedSuggestionStats.value.pageSize));
        if (suggestionStatsPage.value > totalPages) {
            suggestionStatsPage.value = totalPages;
        }
    });

    return {
        productStatsModalOpen,
        suggestionStatsType,
        productSuggestionStats,
        productStatsSummary,
        productSuggestionStatsLoading,
        resettingSuggestionKeys,
        recentlyResetSuggestionKeys,
        savingSuggestionSettingsKeys,
        suggestionStatsSearchQuery,
        suggestionStatsView,
        suggestionStatsPage,
        filteredSuggestionStats,
        pagedSuggestionStats,
        suggestionSettingsSuccessKey,
        isSavingSuggestionSettings,
        setSuggestionSettingsSaving,
        suggestionStatsViewCount,
        changeSuggestionStatsView,
        goToSuggestionStatsPage,
        formatSuggestionStatsEffectiveInterval,
        isResettingSuggestionKey,
        isSuggestionResetDone,
        markSuggestionResetDone,
        scheduleSuggestionStatsRowRemoval,
        openSuggestionStatsModal,
        closeSuggestionStatsModal,
        resetSuggestionStatsSummary,
        disposeSuggestionStats,
    };
}

import { ref } from 'vue';
import {
    buildAdBannerPaths,
    buildDashboardChromeStorageKeys,
    normalizeDashboardTab,
    normalizeThemeMode,
    pickRandomAdBannerPath,
    readPersistedAdsEnabled,
    readPersistedDashboardTab,
    readPersistedThemeMode,
    resolveAdBannerPath,
    resolveThemeByMode,
    writeStorageValueSafely,
} from '@/modules/dashboard/dashboardChrome';

const AD_MODAL_SHOW_CHANCE = 0.2;

export function useDashboardChrome({ userId, adBannersConfig, resolvePublicAssetUrl }) {
    const storageKeys = buildDashboardChromeStorageKeys(userId);
    const adBannerPaths = buildAdBannerPaths(adBannersConfig);

    const activeTab = ref(readPersistedDashboardTab(storageKeys.activeTab));
    const themeMode = ref(readPersistedThemeMode(storageKeys.themeMode));
    const adsEnabled = ref(readPersistedAdsEnabled(storageKeys.adsEnabled));
    const resolvedTheme = ref(resolveThemeByMode(themeMode.value));
    const adModalOpen = ref(false);
    const activeAdBannerPath = ref('');

    let systemThemeMediaQuery = null;
    let handleSystemThemeChange = null;

    function persistActiveTabToStorage(tab) {
        writeStorageValueSafely(storageKeys.activeTab, normalizeDashboardTab(tab));
    }

    function applyThemeMode(mode, { persist = true } = {}) {
        const normalizedMode = normalizeThemeMode(mode);
        const resolved = resolveThemeByMode(normalizedMode);
        resolvedTheme.value = resolved;

        if (typeof document !== 'undefined') {
            const root = document.documentElement;
            root.dataset.theme = resolved;
            root.style.colorScheme = resolved;

            if (document.body) {
                document.body.dataset.theme = resolved;
                document.body.style.colorScheme = resolved;
            }
        }

        if (!persist) {
            return;
        }

        writeStorageValueSafely(storageKeys.themeMode, normalizedMode);
    }

    function setThemeMode(nextMode) {
        themeMode.value = normalizeThemeMode(nextMode);
    }

    function closeAdModal() {
        adModalOpen.value = false;
    }

    function maybeOpenRandomAdModal() {
        if (!adsEnabled.value || adBannerPaths.length === 0 || Math.random() >= AD_MODAL_SHOW_CHANCE) {
            return;
        }

        const nextBannerPath = resolveAdBannerPath(
            pickRandomAdBannerPath(adBannerPaths),
            resolvePublicAssetUrl
        );
        if (!nextBannerPath) {
            return;
        }

        activeAdBannerPath.value = nextBannerPath;
        adModalOpen.value = true;
    }

    function toggleAdsEnabled() {
        adsEnabled.value = !adsEnabled.value;

        if (!adsEnabled.value) {
            closeAdModal();
        }

        writeStorageValueSafely(storageKeys.adsEnabled, adsEnabled.value ? '1' : '0');
    }

    function mountDashboardChrome() {
        applyThemeMode(themeMode.value, { persist: false });
        maybeOpenRandomAdModal();

        if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
            return;
        }

        systemThemeMediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        handleSystemThemeChange = () => {
            if (themeMode.value === 'system') {
                applyThemeMode('system', { persist: false });
            }
        };

        if (typeof systemThemeMediaQuery.addEventListener === 'function') {
            systemThemeMediaQuery.addEventListener('change', handleSystemThemeChange);
        } else if (typeof systemThemeMediaQuery.addListener === 'function') {
            systemThemeMediaQuery.addListener(handleSystemThemeChange);
        }
    }

    function unmountDashboardChrome() {
        if (systemThemeMediaQuery && handleSystemThemeChange) {
            if (typeof systemThemeMediaQuery.removeEventListener === 'function') {
                systemThemeMediaQuery.removeEventListener('change', handleSystemThemeChange);
            } else if (typeof systemThemeMediaQuery.removeListener === 'function') {
                systemThemeMediaQuery.removeListener(handleSystemThemeChange);
            }
        }

        systemThemeMediaQuery = null;
        handleSystemThemeChange = null;
    }

    return {
        activeTab,
        themeMode,
        adsEnabled,
        resolvedTheme,
        adModalOpen,
        activeAdBannerPath,
        persistActiveTabToStorage,
        applyThemeMode,
        setThemeMode,
        closeAdModal,
        maybeOpenRandomAdModal,
        toggleAdsEnabled,
        mountDashboardChrome,
        unmountDashboardChrome,
    };
}

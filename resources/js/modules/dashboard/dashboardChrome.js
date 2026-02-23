export const DASHBOARD_TAB_VALUES = ['products', 'todos', 'mood', 'profile'];
const DASHBOARD_TAB_SET = new Set(DASHBOARD_TAB_VALUES);

export const THEME_MODE_VALUES = ['system', 'light', 'dark'];
const THEME_MODE_SET = new Set(THEME_MODE_VALUES);

export function buildDashboardChromeStorageKeys(userId) {
    return {
        activeTab: `dandash:active-tab:v1:user-${userId}`,
        themeMode: 'dandash:theme-mode:v1',
        adsEnabled: `dandash:ads-enabled:v1:user-${userId}`,
    };
}

export function buildAdBannerPaths(adBannersConfig) {
    return (Array.isArray(adBannersConfig?.banners) ? adBannersConfig.banners : [])
        .map((entry) => (typeof entry === 'string' ? entry : entry?.path))
        .map((entry) => String(entry ?? '').trim())
        .filter((entry) => entry !== '');
}

export function normalizeDashboardTab(value) {
    const normalized = String(value ?? '').trim().toLowerCase();
    return DASHBOARD_TAB_SET.has(normalized) ? normalized : 'products';
}

export function normalizeThemeMode(value) {
    const normalized = String(value ?? '').trim().toLowerCase();
    return THEME_MODE_SET.has(normalized) ? normalized : 'system';
}

export function readPersistedDashboardTab(storageKey) {
    if (typeof window === 'undefined') {
        return 'products';
    }

    try {
        const saved = window.localStorage.getItem(storageKey);
        return normalizeDashboardTab(saved);
    } catch (error) {
        return 'products';
    }
}

export function readPersistedThemeMode(storageKey) {
    if (typeof window === 'undefined') {
        return 'system';
    }

    try {
        const saved = window.localStorage.getItem(storageKey);
        return normalizeThemeMode(saved);
    } catch (error) {
        return 'system';
    }
}

export function readPersistedAdsEnabled(storageKey) {
    if (typeof window === 'undefined') {
        return true;
    }

    try {
        const saved = window.localStorage.getItem(storageKey);
        if (saved === null) {
            return true;
        }

        return saved !== '0' && saved !== 'false';
    } catch (error) {
        return true;
    }
}

export function resolveThemeByMode(mode) {
    const normalized = normalizeThemeMode(mode);
    if (normalized === 'light' || normalized === 'dark') {
        return normalized;
    }

    if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    return 'dark';
}

export function writeStorageValueSafely(storageKey, value) {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.setItem(storageKey, value);
    } catch (error) {
        // Ignore storage write errors (private mode / quota exceeded).
    }
}

export function pickRandomAdBannerPath(paths) {
    if (!Array.isArray(paths) || paths.length === 0) {
        return '';
    }

    const randomIndex = Math.floor(Math.random() * paths.length);
    return paths[randomIndex] ?? '';
}

export function resolveAdBannerPath(path, resolvePublicAssetUrl) {
    const rawPath = String(path ?? '').trim();
    if (rawPath === '') {
        return '';
    }

    if (/^https?:\/\//i.test(rawPath) || rawPath.startsWith('data:')) {
        return rawPath;
    }

    if (typeof resolvePublicAssetUrl !== 'function') {
        return rawPath.replace(/^\/+/, '');
    }

    return resolvePublicAssetUrl(rawPath.replace(/^\/+/, ''));
}

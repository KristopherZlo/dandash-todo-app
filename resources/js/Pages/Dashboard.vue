<script setup>
import SwipeListItem from '@/Components/SwipeListItem.vue';
import { useToasts } from '@/composables/useToasts';
import {
    buildProductEditableText,
    formatProductMeasure,
    getProductDisplayText,
    normalizeProductComparableText,
    normalizeQuantityInput,
    normalizeUnitInput,
    parseProductTextPayload,
} from '@/modules/dashboard/productText';
import { formatIntervalSeconds, suggestionStatusText } from '@/modules/dashboard/suggestionFormat';
import {
    getTodoPriority,
    inferTodoPriorityFromDueAt,
    nextTodoPriority,
    normalizeTodoPriority,
    todoPriorityClass,
    todoPriorityLabel,
} from '@/modules/dashboard/todoPriority';
import { normalizeTodoComparableText } from '@/modules/dashboard/textNormalize';
import { Head, usePage } from '@inertiajs/vue3';
import {
    CalendarDays,
    Check,
    ChevronDown,
    Plus,
    RotateCcw,
    Search,
    Share2,
    ShoppingCart,
    Trash2,
    UserRound,
    WifiOff,
    X,
} from 'lucide-vue-next';
import draggable from 'vuedraggable';
import { onBeforeUnmount, onMounted, reactive, ref, computed, watch, nextTick } from 'vue';

const props = defineProps({
    initialState: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const localUser = reactive({
    id: page.props.auth.user.id,
    name: page.props.auth.user.name,
    tag: page.props.auth.user.tag ?? '',
    email: page.props.auth.user.email,
});

const activeTab = ref('products');
const listDropdownOpen = ref(false);
const selectedOwnerId = ref(props.initialState.default_owner_id ?? localUser.id);

const listOptions = ref(props.initialState.list_options ?? []);
const invitations = ref(props.initialState.invitations ?? []);
const links = ref(props.initialState.links ?? []);
const pendingInvitationsCount = ref(props.initialState.pending_invitations_count ?? 0);

const productItems = ref([]);
const todoItems = ref([]);
const productSuggestions = ref([]);
const todoSuggestions = ref([]);

const newProductText = ref('');
const newTodoText = ref('');
const newTodoDueAt = ref('');
const todoItemDuePickerRef = ref(null);
const todoItemDuePickerValue = ref('');
const todoItemDueTarget = ref(null);
const todoDueModalOpen = ref(false);
const todoDueModalValue = ref('');

const editingItemId = ref(null);
const editingText = ref('');
const editingDueAt = ref('');

const shareModalOpen = ref(false);
const inviteModalOpen = ref(false);
const inviteModalTab = ref('invitations');

const searchQuery = ref('');
const searchResults = ref([]);
const searchBusy = ref(false);

const productStatsModalOpen = ref(false);
const productSuggestionsOpen = ref(true);
const todoSuggestionsOpen = ref(true);
const batchRemovingItemKeys = ref([]);
const batchRemovalAnimating = ref(false);
const batchCollapseHiddenItemKeys = ref([]);
const batchCollapseScene = ref(null);
const deleteFeedbackBursts = ref([]);
const xpStars = ref([]);
const progressBarRef = ref(null);
const megaCardRef = ref(null);
const xpProgress = ref(0);
const xpVisualProgress = ref(0);
const productivityScore = ref(0);
const xpProgressInstant = ref(false);
const xpProgressFillDurationMs = ref(240);
const xpLevelUpBackdropVisible = ref(false);
const xpLevelUpRaised = ref(false);
const xpLevelUpImpact = ref(false);
const xpRewardVisible = ref(false);
const xpRewardAmount = ref(0);
const xpColorSeed = ref(1);
const xpProgressLevel = ref(1);
const animationSkipEpoch = ref(0);
const soundEnabled = ref(true);
const productSuggestionStats = ref([]);
const productStatsSummary = ref({
    total_added: 0,
    total_completed: 0,
    unique_products: 0,
    due_suggestions: 0,
    upcoming_suggestions: 0,
    last_activity_at: null,
});
const productSuggestionStatsLoading = ref(false);
const resettingSuggestionKeys = ref([]);

const {
    toasts,
    resetMessages,
    showStatus,
    showError,
    onToastPointerDown,
    onToastPointerMove,
    onToastPointerUp,
    onToastPointerCancel,
    disposeToasts,
} = useToasts();

const profileForm = reactive({
    name: localUser.name,
    tag: localUser.tag,
    email: localUser.email,
    loading: false,
});

const passwordForm = reactive({
    current_password: '',
    password: '',
    password_confirmation: '',
    loading: false,
});

const suggestionsLoading = reactive({
    product: false,
    todo: false,
});

const itemsLoading = reactive({
    product: false,
    todo: false,
});

const isBrowserOnline = ref(typeof window !== 'undefined' ? window.navigator.onLine : true);
const serverReachable = ref(true);
const offlineQueue = ref([]);
const cachedItemsByList = ref({});
const cachedSuggestionsByList = ref({});
const cachedProductStatsByList = ref({});
const cachedUserSearchByQuery = ref({});
const cachedSyncState = ref(null);

const CACHE_VERSION = 'v1';
const OFFLINE_QUEUE_STORAGE_KEY = `dandash:offline-queue:${CACHE_VERSION}:user-${localUser.id}`;
const ITEMS_CACHE_STORAGE_KEY = `dandash:items-cache:${CACHE_VERSION}:user-${localUser.id}`;
const SUGGESTIONS_CACHE_STORAGE_KEY = `dandash:suggestions-cache:${CACHE_VERSION}:user-${localUser.id}`;
const PRODUCT_STATS_CACHE_STORAGE_KEY = `dandash:product-stats-cache:${CACHE_VERSION}:user-${localUser.id}`;
const USER_SEARCH_CACHE_STORAGE_KEY = `dandash:user-search-cache:${CACHE_VERSION}:user-${localUser.id}`;
const SYNC_STATE_CACHE_STORAGE_KEY = `dandash:sync-state:${CACHE_VERSION}:user-${localUser.id}`;
const LOCAL_DEFAULT_OWNER_KEY = `dandash:default-owner:${CACHE_VERSION}:user-${localUser.id}`;
const XP_PROGRESS_STORAGE_KEY = `dandash:xp-progress:${CACHE_VERSION}:user-${localUser.id}`;
const PRODUCTIVITY_STORAGE_KEY = `dandash:productivity:${CACHE_VERSION}:user-${localUser.id}`;
const PRODUCTIVITY_REWARD_HISTORY_STORAGE_KEY = `dandash:productivity-reward-history:${CACHE_VERSION}:user-${localUser.id}`;
const XP_COLOR_SEED_STORAGE_KEY = `dandash:xp-color-seed:${CACHE_VERSION}:user-${localUser.id}`;
const GAMIFICATION_UPDATED_AT_STORAGE_KEY = `dandash:gamification-updated-at:${CACHE_VERSION}:user-${localUser.id}`;

let itemsPollTimer = null;
let statePollTimer = null;
let suggestionsPollTimer = null;
let queueSyncTimer = null;
let listChannelName = null;
let userChannelName = null;
let swipeUndoTimer = null;
let lastPersistedOwnerId = Number(props.initialState.default_owner_id ?? localUser.id);
let queueSyncInProgress = false;
let nextTempId = -1;
let handleOnlineEvent = null;
let handleOfflineEvent = null;
let nextDeleteFeedbackBurstId = 1;
let nextXpStarId = 1;
let nextXpGainSourceId = 1;
let nextBatchCollapseSceneId = 1;
let nextBatchCollapseIncomingId = 1;
let skipTodoBlurSaveUntil = 0;
let queuedUpdateTouchedAt = 0;
let queuedUpdateSyncTimer = null;
let queueRetryAt = 0;
let syncInFlightOperationIds = new Set();
let xpGainProcessing = false;
let pendingXpGain = 0;
let lastXpGainSoundAt = 0;
let localGamificationUpdatedAtMs = 0;
let gamificationSyncEnabled = false;
let suppressGamificationQueueDepth = 0;
let gamificationSyncTimer = null;
let dashboardSoundMutedUntil = 0;
let scrollLockActive = false;
let previousBodyOverflowStyle = '';
let previousBodyTouchActionStyle = '';
let previousHtmlOverflowStyle = '';
let previousHtmlOverscrollBehaviorStyle = '';
let productivityRewardHistory = [];
const effectTimeouts = new Set();
const itemCardElements = new Map();
const soundPools = new Map();
const listSyncVersions = new Map();
const deletedItemTombstones = new Map();
const xpGainSources = new Map();
const xpGainSourceCleanupTimeouts = new Map();
let megaCardImpactAnimation = null;

function normalizeLinkId(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
}

function findListOptionByOwner(ownerId) {
    return listOptions.value.find((option) => Number(option.owner_id) === Number(ownerId)) ?? null;
}

function resolveLinkIdForOwner(ownerId, explicitLinkId = undefined) {
    const explicit = normalizeLinkId(explicitLinkId);
    if (explicit) {
        return explicit;
    }

    return normalizeLinkId(findListOptionByOwner(ownerId)?.link_id);
}

const selectedListOption = computed(() => findListOptionByOwner(selectedOwnerId.value));
const selectedListLinkId = computed(() => normalizeLinkId(selectedListOption.value?.link_id));
const selectedListLabel = computed(() => selectedListOption.value?.label ?? 'Личный');

function isPendingCompletionMark(item) {
    const state = swipeUndoState.value;
    if (!state || state.action !== 'toggle' || !state.nextCompleted) {
        return false;
    }

    return Number(state.ownerId) === Number(item?.owner_id)
        && normalizeLinkId(state.linkId) === normalizeLinkId(item?.list_link_id)
        && String(state.type) === String(item?.type)
        && Number(state.item?.id) === Number(item?.id);
}

function buildCompletedStats(items) {
    const normalizedItems = Array.isArray(items) ? items : [];
    const total = normalizedItems.length;
    const completed = normalizedItems.filter(
        (item) => item?.is_completed && !isPendingCompletionMark(item),
    ).length;

    return {
        completed,
        total,
    };
}

const productPurchasedStats = computed(() => buildCompletedStats(productItems.value));
const todoCompletedStats = computed(() => buildCompletedStats(todoItems.value));
const activeListStats = computed(() => (
    activeTab.value === 'products'
        ? productPurchasedStats.value
        : todoCompletedStats.value
));
const visibleProductSuggestions = computed(() => {
    if (!Array.isArray(productSuggestions.value) || productSuggestions.value.length === 0) {
        return [];
    }

    const existingProducts = new Set(
        productItems.value
            .map((item) => normalizeProductComparableText(item?.text))
            .filter((value) => value !== ''),
    );
    const seenSuggestions = new Set();

    return productSuggestions.value.filter((suggestion) => {
        const comparableSuggestion = normalizeProductComparableText(suggestion?.suggested_text);

        if (comparableSuggestion !== '' && seenSuggestions.has(comparableSuggestion)) {
            return false;
        }

        if (comparableSuggestion !== '') {
            seenSuggestions.add(comparableSuggestion);
        }

        if (comparableSuggestion === '') {
            return true;
        }

        return !existingProducts.has(comparableSuggestion);
    });
});
const visibleTodoSuggestions = computed(() => {
    if (!Array.isArray(todoSuggestions.value) || todoSuggestions.value.length === 0) {
        return [];
    }

    const existingTodos = new Set(
        todoItems.value
            .map((item) => normalizeTodoComparableText(item?.text))
            .filter((value) => value !== ''),
    );
    const seenSuggestions = new Set();

    return todoSuggestions.value.filter((suggestion) => {
        const comparableSuggestion = normalizeTodoComparableText(suggestion?.suggested_text);

        if (comparableSuggestion !== '' && seenSuggestions.has(comparableSuggestion)) {
            return false;
        }

        if (comparableSuggestion !== '') {
            seenSuggestions.add(comparableSuggestion);
        }

        if (comparableSuggestion === '') {
            return true;
        }

        return !existingTodos.has(comparableSuggestion);
    });
});

const offlineMode = computed(() => !isBrowserOnline.value || !serverReachable.value);
const browserOffline = computed(() => !isBrowserOnline.value);
const queuedChangesCount = computed(() => offlineQueue.value.length);
const offlineStatusText = computed(() => (isBrowserOnline.value ? 'Нет доступа к серверу' : 'Нет интернета'));
const swipeUndoState = ref(null);
const swipeUndoMessage = computed(() => {
    if (!swipeUndoState.value) {
        return '';
    }

    if (swipeUndoState.value.action === 'remove_completed_batch') {
        return 'Удалены выполненные элементы';
    }

    if (swipeUndoState.value.action === 'remove') {
        return 'Элемент удален';
    }

    return swipeUndoState.value.nextCompleted
        ? 'Элемент отмечен выполненным'
        : 'Элемент возвращен в активные';
});
const canShowRemoveCompletedButton = computed(() => {
    const state = swipeUndoState.value;

    return Boolean(
        state
        && state.action === 'remove'
        && state.canRemoveCompletedBatch
        && !batchRemovalAnimating.value,
    );
});
const xpProgressPercent = computed(() => Math.round(Math.max(0, Math.min(1, Number(xpVisualProgress.value))) * 1000) / 10);
const xpProgressFillPalette = computed(() => computeXpProgressPalette(xpProgressLevel.value));
const isSkippableAnimationPlaying = computed(() => Boolean(
    batchCollapseScene.value
    || xpLevelUpBackdropVisible.value
    || xpLevelUpRaised.value
    || xpLevelUpImpact.value
    || xpRewardVisible.value,
));

const SWIPE_UNDO_WINDOW_MS = 4500;
const BATCH_REMOVE_CARD_ANIMATION_MS = 190;
const BATCH_CINEMATIC_REMOVE_THRESHOLD = 4;
const BATCH_CINEMATIC_WHITEN_MS = 1000;
const BATCH_CINEMATIC_TRAVEL_MS = 640;
const BATCH_CINEMATIC_GLOW_MS = 420;
const BATCH_CINEMATIC_BURST_MS = 170;
const BATCH_CINEMATIC_INCOMING_TARGET_SCALE = 0.42;
const BATCH_CINEMATIC_INCOMING_EASING = Object.freeze({
    x1: 0.2,
    y1: 0.88,
    x2: 0.34,
    y2: 1,
});
const BATCH_CINEMATIC_IMPACT_SAMPLE_COUNT = 132;
const BATCH_CINEMATIC_IMPACT_REFINE_STEPS = 10;
const XP_REWARD_SCALE_DIVISOR = 3;
const XP_PROGRESS_PER_TOGGLE = 0.018 / XP_REWARD_SCALE_DIVISOR;
const XP_BATCH_TOTAL_GAIN_PER_ITEM = 0.06 / XP_REWARD_SCALE_DIVISOR;
const XP_BATCH_TOTAL_GAIN_MIN = 0.18 / XP_REWARD_SCALE_DIVISOR;
const XP_BATCH_TOTAL_GAIN_MAX = 0.45 / XP_REWARD_SCALE_DIVISOR;
const XP_STAR_MIN_DURATION_MS = 360;
const XP_STAR_MAX_DURATION_MS = 980;
const XP_PROGRESS_FILL_NORMAL_MS = 240;
const XP_LEVELUP_PREPARE_MS = 180;
const XP_LEVELUP_MIN_FILL_MS = 260;
const XP_LEVELUP_MAX_FILL_MS = 780;
const XP_LEVELUP_REWARD_MS = 2000;
const XP_LEVELUP_SETTLE_MS = 260;
const PRODUCTIVITY_REWARD_BASE_PER_LEVEL = 8;
const PRODUCTIVITY_REWARD_RANDOM_MAX_BONUS = 5;
const XP_STAR_SOURCE_RETENTION_MS = SWIPE_UNDO_WINDOW_MS + 1200;
const XP_GAIN_SOUND_THROTTLE_MS = 90;
const SOUND_POOL_LIMIT_PER_KEY = 6;
const SOUND_SKIP_MUTE_MS = 3000;
const ITEM_DELETE_TOMBSTONE_TTL_MS = 180000;
const DASHBOARD_SOUND_PATHS = Object.freeze({
    // Put your custom files into: public/sounds/dashboard/
    white_card_appear: 'sounds/dashboard/white-card-appear.mp3',
    white_card_impact: 'sounds/dashboard/white-card-impact.mp3',
    level_up: 'sounds/dashboard/level-up.mp3',
    xp_gain: 'sounds/dashboard/xp-gain.mp3',
});
const QUEUE_RETRY_DELAY_MS = 4000;
const SYNC_CHUNK_MAX_OPERATIONS = 24;
const GAMIFICATION_SYNC_DEBOUNCE_MS = 320;
const UPDATE_SYNC_COALESCE_MS = 1000;
const COALESCED_UPDATE_KEYS = new Set([
    'text',
    'quantity',
    'unit',
    'due_at',
    'priority',
]);
const resolvedSoundUrlCache = new Map();

function getSuggestionKey(suggestion) {
    if (typeof suggestion?.suggestion_key === 'string' && suggestion.suggestion_key.trim() !== '') {
        return suggestion.suggestion_key.trim();
    }

    return String(suggestion?.suggested_text ?? '').trim().toLowerCase();
}

function parseJson(value, fallback) {
    if (typeof value !== 'string' || value.trim() === '') {
        return fallback;
    }

    try {
        const parsed = JSON.parse(value);
        return parsed ?? fallback;
    } catch {
        return fallback;
    }
}

function asPlainObject(value) {
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        return {};
    }

    return value;
}

function cloneEntries(entries) {
    if (!Array.isArray(entries)) {
        return [];
    }

    return entries
        .filter((entry) => entry && typeof entry === 'object')
        .map((entry) => ({ ...entry }));
}

function persistSuggestionsCache() {
    if (typeof window === 'undefined') {
        return;
    }

    window.localStorage.setItem(SUGGESTIONS_CACHE_STORAGE_KEY, JSON.stringify(cachedSuggestionsByList.value));
}

function persistProductStatsCache() {
    if (typeof window === 'undefined') {
        return;
    }

    window.localStorage.setItem(PRODUCT_STATS_CACHE_STORAGE_KEY, JSON.stringify(cachedProductStatsByList.value));
}

function persistUserSearchCache() {
    if (typeof window === 'undefined') {
        return;
    }

    window.localStorage.setItem(USER_SEARCH_CACHE_STORAGE_KEY, JSON.stringify(cachedUserSearchByQuery.value));
}

function persistSyncStateCache(state) {
    if (typeof window === 'undefined') {
        return;
    }

    window.localStorage.setItem(SYNC_STATE_CACHE_STORAGE_KEY, JSON.stringify(state));
}

function cloneItems(items) {
    if (!Array.isArray(items)) {
        return [];
    }

    return items.map((item) => ({ ...item }));
}

function listCacheKey(ownerId, type, linkId = null) {
    const normalizedLinkId = normalizeLinkId(linkId);
    return `${Number(ownerId)}:${normalizedLinkId ? `link-${normalizedLinkId}` : 'personal'}:${type}`;
}

function suggestionsCacheKey(ownerId, type, linkId = undefined) {
    return listCacheKey(ownerId, type, resolveLinkIdForOwner(ownerId, linkId));
}

function productStatsCacheKey(ownerId, linkId = undefined) {
    const normalizedLinkId = resolveLinkIdForOwner(ownerId, linkId);
    return `${Number(ownerId)}:${normalizedLinkId ? `link-${normalizedLinkId}` : 'personal'}`;
}

function suggestionDeduplicationKey(suggestion) {
    const bySuggestionKey = String(suggestion?.suggestion_key ?? '').trim().toLowerCase();
    if (bySuggestionKey !== '') {
        return `key:${bySuggestionKey}`;
    }

    const byText = String(suggestion?.suggested_text ?? '').trim().toLowerCase();
    if (byText !== '') {
        return `text:${byText}`;
    }

    return '';
}

function normalizeSuggestions(entries) {
    const source = cloneEntries(entries);
    if (source.length === 0) {
        return [];
    }

    const seen = new Set();
    const deduplicated = [];

    for (const suggestion of source) {
        const dedupKey = suggestionDeduplicationKey(suggestion);
        if (dedupKey !== '' && seen.has(dedupKey)) {
            continue;
        }

        if (dedupKey !== '') {
            seen.add(dedupKey);
        }
        deduplicated.push(suggestion);
    }

    return deduplicated;
}

function normalizeProductStatsPayload(payload) {
    const source = asPlainObject(payload);
    const rawSummary = asPlainObject(source.summary);

    return {
        stats: cloneEntries(source.stats),
        summary: {
            total_added: Math.max(0, Number(rawSummary.total_added) || 0),
            total_completed: Math.max(0, Number(rawSummary.total_completed) || 0),
            unique_products: Math.max(0, Number(rawSummary.unique_products) || 0),
            due_suggestions: Math.max(0, Number(rawSummary.due_suggestions) || 0),
            upcoming_suggestions: Math.max(0, Number(rawSummary.upcoming_suggestions) || 0),
            last_activity_at: typeof rawSummary.last_activity_at === 'string' ? rawSummary.last_activity_at : null,
        },
    };
}

function setVisibleSuggestions(type, suggestions) {
    const nextSuggestions = normalizeSuggestions(suggestions);
    if (type === 'product') {
        productSuggestions.value = nextSuggestions;
        return;
    }

    todoSuggestions.value = nextSuggestions;
}

function readSuggestionsFromCache(ownerId, type, linkId = undefined) {
    const key = suggestionsCacheKey(ownerId, type, linkId);
    return normalizeSuggestions(cachedSuggestionsByList.value[key]);
}

function writeSuggestionsToCache(ownerId, type, suggestions, linkId = undefined) {
    const key = suggestionsCacheKey(ownerId, type, linkId);
    const normalized = normalizeSuggestions(suggestions);
    const previous = normalizeSuggestions(cachedSuggestionsByList.value[key]);
    const changed = JSON.stringify(previous) !== JSON.stringify(normalized);

    if (changed) {
        cachedSuggestionsByList.value[key] = normalized;
        persistSuggestionsCache();
    }

    if (isCurrentListContext(ownerId, resolveLinkIdForOwner(ownerId, linkId))) {
        setVisibleSuggestions(type, normalized);
    }
}

function readProductStatsFromCache(ownerId, linkId = undefined) {
    const key = productStatsCacheKey(ownerId, linkId);
    return normalizeProductStatsPayload(cachedProductStatsByList.value[key]);
}

function writeProductStatsToCache(ownerId, payload, linkId = undefined) {
    const key = productStatsCacheKey(ownerId, linkId);
    const normalized = normalizeProductStatsPayload(payload);
    const previous = normalizeProductStatsPayload(cachedProductStatsByList.value[key]);
    const changed = JSON.stringify(previous) !== JSON.stringify(normalized);

    if (changed) {
        cachedProductStatsByList.value[key] = normalized;
        persistProductStatsCache();
    }

    if (isCurrentListContext(ownerId, resolveLinkIdForOwner(ownerId, linkId))) {
        productSuggestionStats.value = normalized.stats;
        productStatsSummary.value = normalized.summary;
    }
}

function normalizeSearchQuery(query) {
    return String(query ?? '')
        .trim()
        .toLowerCase()
        .replace(/^@+/, '');
}

function readUserSearchFromCache(query) {
    const normalizedQuery = normalizeSearchQuery(query);
    if (normalizedQuery === '') {
        return [];
    }

    return cloneEntries(cachedUserSearchByQuery.value[normalizedQuery]);
}

function writeUserSearchToCache(query, users) {
    const normalizedQuery = normalizeSearchQuery(query);
    if (normalizedQuery === '') {
        return;
    }

    cachedUserSearchByQuery.value[normalizedQuery] = cloneEntries(users);
    persistUserSearchCache();
}

function persistGamificationUpdatedAtMs() {
    if (typeof window === 'undefined') {
        return;
    }

    if (!Number.isFinite(localGamificationUpdatedAtMs) || localGamificationUpdatedAtMs <= 0) {
        window.localStorage.removeItem(GAMIFICATION_UPDATED_AT_STORAGE_KEY);
        return;
    }

    window.localStorage.setItem(
        GAMIFICATION_UPDATED_AT_STORAGE_KEY,
        String(Math.round(localGamificationUpdatedAtMs)),
    );
}

function normalizeGamificationStatePayload(payload) {
    const source = asPlainObject(payload);
    const xpProgress = clampValue(Number(source.xp_progress) || 0, 0, 0.999999);
    const productivityScore = Math.max(0, Math.round(Number(source.productivity_score) || 0));
    const rewardHistory = normalizeProductivityRewardHistory(source.productivity_reward_history);
    const xpColorSeedValue = Number(source.xp_color_seed);
    const xpColorSeedNormalized = Number.isFinite(xpColorSeedValue) && xpColorSeedValue > 0
        ? ((Math.floor(xpColorSeedValue) >>> 0) || 1)
        : 1;
    const updatedAtMsRaw = Number(source.updated_at_ms);
    const updatedAtMs = Number.isFinite(updatedAtMsRaw) && updatedAtMsRaw > 0
        ? Math.round(updatedAtMsRaw)
        : null;

    return {
        xp_progress: xpProgress,
        productivity_score: productivityScore,
        productivity_reward_history: rewardHistory,
        xp_color_seed: xpColorSeedNormalized,
        updated_at_ms: updatedAtMs,
    };
}

function buildCurrentGamificationStatePayload() {
    return normalizeGamificationStatePayload({
        xp_progress: xpProgress.value,
        productivity_score: productivityScore.value,
        productivity_reward_history: productivityRewardHistory,
        xp_color_seed: xpColorSeed.value,
        updated_at_ms: localGamificationUpdatedAtMs || null,
    });
}

function withGamificationSyncSuppressed(callback) {
    suppressGamificationQueueDepth += 1;
    try {
        return callback();
    } finally {
        suppressGamificationQueueDepth = Math.max(0, suppressGamificationQueueDepth - 1);
    }
}

function shouldQueueGamificationSync() {
    return gamificationSyncEnabled && suppressGamificationQueueDepth === 0;
}

function hasPendingGamificationOperation(excludeOpId = null) {
    return offlineQueue.value.some((operation) => (
        operation.action === 'sync_gamification'
        && (
            !excludeOpId
            || String(operation?.op_id ?? '') !== String(excludeOpId)
        )
    ));
}

function scheduleGamificationSync() {
    if (typeof window === 'undefined') {
        return;
    }

    if (gamificationSyncTimer) {
        clearTimeout(gamificationSyncTimer);
    }

    gamificationSyncTimer = window.setTimeout(() => {
        gamificationSyncTimer = null;
        syncOfflineQueue().catch(() => {});
    }, GAMIFICATION_SYNC_DEBOUNCE_MS);
}

function queueGamificationState(payload) {
    const normalizedPayload = normalizeGamificationStatePayload(payload);
    if (!Number.isFinite(normalizedPayload.updated_at_ms) || normalizedPayload.updated_at_ms <= 0) {
        return;
    }

    const existingIndex = findQueueIndexFromEnd(
        (operation) => operation.action === 'sync_gamification',
    );

    if (existingIndex !== -1 && !isOperationBeingSynced(offlineQueue.value[existingIndex]?.op_id)) {
        offlineQueue.value[existingIndex] = {
            ...offlineQueue.value[existingIndex],
            payload: normalizedPayload,
        };
        persistQueue();
        return;
    }

    enqueueOperation({
        action: 'sync_gamification',
        payload: normalizedPayload,
    });
}

function notifyGamificationStateChanged() {
    if (!shouldQueueGamificationSync()) {
        return;
    }

    localGamificationUpdatedAtMs = Date.now();
    persistGamificationUpdatedAtMs();

    queueGamificationState({
        ...buildCurrentGamificationStatePayload(),
        updated_at_ms: localGamificationUpdatedAtMs,
    });
    scheduleGamificationSync();
}

function applyGamificationStateFromServer(payload, options = {}) {
    const { force = false } = options;
    const normalized = normalizeGamificationStatePayload(payload);
    const serverUpdatedAtMs = Number(normalized.updated_at_ms || 0);

    if (!force && serverUpdatedAtMs > 0 && serverUpdatedAtMs < localGamificationUpdatedAtMs) {
        return;
    }

    if (!force && hasPendingGamificationOperation() && serverUpdatedAtMs <= localGamificationUpdatedAtMs) {
        return;
    }

    withGamificationSyncSuppressed(() => {
        xpProgress.value = normalized.xp_progress;
        xpVisualProgress.value = normalized.xp_progress;
        productivityScore.value = normalized.productivity_score;
        productivityRewardHistory = normalized.productivity_reward_history;
        xpColorSeed.value = normalized.xp_color_seed;

        if (typeof window !== 'undefined') {
            window.localStorage.setItem(XP_PROGRESS_STORAGE_KEY, String(xpProgress.value));
            window.localStorage.setItem(PRODUCTIVITY_STORAGE_KEY, String(productivityScore.value));
            window.localStorage.setItem(
                PRODUCTIVITY_REWARD_HISTORY_STORAGE_KEY,
                JSON.stringify(productivityRewardHistory),
            );
            window.localStorage.setItem(XP_COLOR_SEED_STORAGE_KEY, String(xpColorSeed.value));
        }

        syncXpProgressLevel();
    });

    if (serverUpdatedAtMs > 0) {
        localGamificationUpdatedAtMs = serverUpdatedAtMs;
        persistGamificationUpdatedAtMs();
    }
}

function normalizeSyncStatePayload(state) {
    const source = asPlainObject(state);
    const invitations = cloneEntries(source.invitations);
    const links = cloneEntries(source.links);
    const listOptions = cloneEntries(source.list_options);
    const gamificationSource = source.gamification && typeof source.gamification === 'object'
        ? source.gamification
        : null;

    return {
        pending_invitations_count: Math.max(0, Number(source.pending_invitations_count) || 0),
        invitations,
        links,
        list_options: listOptions,
        default_owner_id: Number(source.default_owner_id) || Number(localUser.id),
        gamification: gamificationSource ? normalizeGamificationStatePayload(gamificationSource) : null,
    };
}

function buildCurrentSyncStatePayload() {
    return normalizeSyncStatePayload({
        pending_invitations_count: pendingInvitationsCount.value,
        invitations: invitations.value,
        links: links.value,
        list_options: listOptions.value,
        default_owner_id: selectedOwnerId.value,
        gamification: buildCurrentGamificationStatePayload(),
    });
}

function persistCurrentSyncStateCache() {
    const snapshot = buildCurrentSyncStatePayload();
    cachedSyncState.value = snapshot;
    persistSyncStateCache(snapshot);
}

function applyCachedSyncState(syncSelection = false) {
    if (!cachedSyncState.value || typeof cachedSyncState.value !== 'object') {
        return;
    }

    applyState(cachedSyncState.value, { syncSelection });
}

function listSyncVersionKey(ownerId, type, linkId = undefined) {
    const resolvedLinkId = resolveLinkIdForOwner(ownerId, linkId);
    return listCacheKey(ownerId, type, resolvedLinkId);
}

function getListSyncVersion(ownerId, type, linkId = undefined) {
    return Number(listSyncVersions.get(listSyncVersionKey(ownerId, type, linkId)) ?? 0);
}

function bumpListSyncVersion(ownerId, type, linkId = undefined) {
    const versionKey = listSyncVersionKey(ownerId, type, linkId);
    const nextVersion = getListSyncVersion(ownerId, type, linkId) + 1;
    listSyncVersions.set(versionKey, nextVersion);
    return nextVersion;
}

function deletedItemTombstoneKey(ownerId, type, itemId, linkId = undefined) {
    const resolvedLinkId = resolveLinkIdForOwner(ownerId, linkId);
    return `${listCacheKey(ownerId, type, resolvedLinkId)}::${Number(itemId)}`;
}

function cleanupDeletedItemTombstones(nowMs = Date.now()) {
    for (const [tombstoneKey, tombstone] of deletedItemTombstones.entries()) {
        const expiresAt = Number(
            typeof tombstone === 'number'
                ? tombstone
                : tombstone?.expiresAt,
        );
        if (expiresAt <= nowMs) {
            deletedItemTombstones.delete(tombstoneKey);
        }
    }
}

function markItemsAsDeleted(ownerId, type, itemIds, linkId = undefined, options = {}) {
    if (!Array.isArray(itemIds) || itemIds.length === 0) {
        return;
    }

    cleanupDeletedItemTombstones();
    const deletedAtMs = Number(options?.deletedAtMs);
    const deletedAt = Number.isFinite(deletedAtMs) ? deletedAtMs : Date.now();
    const expiresAt = deletedAt + ITEM_DELETE_TOMBSTONE_TTL_MS;
    for (const itemId of itemIds) {
        const numericItemId = Number(itemId);
        if (!Number.isFinite(numericItemId) || numericItemId <= 0) {
            continue;
        }

        deletedItemTombstones.set(
            deletedItemTombstoneKey(ownerId, type, numericItemId, linkId),
            {
                expiresAt,
                deletedAt,
            },
        );
    }
}

function getDeletedItemTombstone(ownerId, type, itemId, linkId = undefined) {
    cleanupDeletedItemTombstones();
    const numericItemId = Number(itemId);
    if (!Number.isFinite(numericItemId) || numericItemId <= 0) {
        return null;
    }

    const tombstoneKey = deletedItemTombstoneKey(ownerId, type, numericItemId, linkId);
    const rawTombstone = deletedItemTombstones.get(tombstoneKey);
    if (!rawTombstone) {
        return null;
    }

    const expiresAt = Number(
        typeof rawTombstone === 'number'
            ? rawTombstone
            : rawTombstone?.expiresAt,
    );
    if (expiresAt <= Date.now()) {
        deletedItemTombstones.delete(tombstoneKey);
        return null;
    }

    if (typeof rawTombstone === 'number') {
        return {
            expiresAt,
            deletedAt: expiresAt - ITEM_DELETE_TOMBSTONE_TTL_MS,
        };
    }

    return {
        expiresAt,
        deletedAt: Number(rawTombstone?.deletedAt) || (expiresAt - ITEM_DELETE_TOMBSTONE_TTL_MS),
    };
}

function clearDeletedItemTombstone(ownerId, type, itemId, linkId = undefined) {
    const numericItemId = Number(itemId);
    if (!Number.isFinite(numericItemId) || numericItemId <= 0) {
        return;
    }

    deletedItemTombstones.delete(deletedItemTombstoneKey(ownerId, type, numericItemId, linkId));
}

function clearDeletedTombstonesForItems(ownerId, type, items, linkId = undefined) {
    for (const item of Array.isArray(items) ? items : []) {
        clearDeletedItemTombstone(ownerId, type, item?.id, linkId);
    }
}

function isItemRecentlyDeleted(ownerId, type, itemId, linkId = undefined) {
    return Boolean(getDeletedItemTombstone(ownerId, type, itemId, linkId));
}

function isCurrentListContext(ownerId, linkId = null) {
    return Number(ownerId) === Number(selectedOwnerId.value)
        && normalizeLinkId(linkId) === normalizeLinkId(selectedListLinkId.value);
}

function itemViewKey(item) {
    return String(item?.local_id ?? item?.id ?? '');
}

function clampValue(value, min, max) {
    return Math.min(max, Math.max(min, value));
}

function itemCardRefKey(type, item) {
    return `${String(type)}:${itemViewKey(item)}`;
}

function isHtmlElement(value) {
    return typeof HTMLElement !== 'undefined' && value instanceof HTMLElement;
}

function setItemCardRef(type, item, element) {
    const key = itemCardRefKey(type, item);
    if (isHtmlElement(element)) {
        itemCardElements.set(key, element);
        return;
    }

    itemCardElements.delete(key);
}

function isBatchRemovingItem(item) {
    return batchRemovingItemKeys.value.includes(itemViewKey(item));
}

function isBatchCollapseHiddenItem(item) {
    return batchCollapseHiddenItemKeys.value.includes(itemViewKey(item));
}

function scheduleEffectTimeout(callback, delayMs) {
    const timeoutId = window.setTimeout(() => {
        effectTimeouts.delete(timeoutId);
        callback();
    }, Math.max(0, Math.round(delayMs)));
    effectTimeouts.add(timeoutId);
    return timeoutId;
}

function clearEffectTimeouts() {
    for (const timeoutId of effectTimeouts) {
        clearTimeout(timeoutId);
    }
    effectTimeouts.clear();
}

function randomPlaybackRateBySemitone(maxSemitoneDelta = 1.2) {
    const boundedDelta = Math.max(0, Number(maxSemitoneDelta) || 0);
    const semitoneDelta = ((Math.random() * 2) - 1) * boundedDelta;
    return 2 ** (semitoneDelta / 12);
}

function resolvePublicAssetUrl(relativePath) {
    const normalizedPath = String(relativePath ?? '').replace(/^\/+/, '');
    if (normalizedPath === '' || typeof window === 'undefined') {
        return normalizedPath;
    }

    const { origin, pathname } = window.location;
    const publicToken = '/public';
    const publicIndex = pathname.indexOf(publicToken);
    if (publicIndex !== -1) {
        const basePath = pathname.slice(0, publicIndex + publicToken.length);
        return new URL(normalizedPath, `${origin}${basePath}/`).toString();
    }

    return new URL(`/${normalizedPath}`, origin).toString();
}

function getDashboardSoundSource(soundKey) {
    if (resolvedSoundUrlCache.has(soundKey)) {
        return resolvedSoundUrlCache.get(soundKey);
    }

    const configuredPath = DASHBOARD_SOUND_PATHS[soundKey];
    if (!configuredPath) {
        return null;
    }

    const resolvedPath = resolvePublicAssetUrl(configuredPath);
    resolvedSoundUrlCache.set(soundKey, resolvedPath);
    return resolvedPath;
}

function getSoundPool(soundKey) {
    const existingPool = soundPools.get(soundKey);
    if (Array.isArray(existingPool)) {
        return existingPool;
    }

    const nextPool = [];
    soundPools.set(soundKey, nextPool);
    return nextPool;
}

function createSoundInstance(source) {
    if (typeof Audio === 'undefined') {
        return null;
    }

    const audio = new Audio(source);
    audio.preload = 'auto';
    return audio;
}

function getSoundInstance(soundKey, source) {
    const pool = getSoundPool(soundKey);
    const available = pool.find((audio) => audio && (audio.paused || audio.ended));
    if (available) {
        return available;
    }

    if (pool.length < SOUND_POOL_LIMIT_PER_KEY) {
        const created = createSoundInstance(source);
        if (created) {
            pool.push(created);
        }
        return created;
    }

    return pool[0] ?? null;
}

function playDashboardSound(soundKey, options = {}) {
    if (!soundEnabled.value || typeof window === 'undefined') {
        return;
    }
    if (Date.now() < dashboardSoundMutedUntil) {
        return;
    }

    const source = getDashboardSoundSource(soundKey);
    if (!source) {
        return;
    }

    const audio = getSoundInstance(soundKey, source);
    if (!audio) {
        return;
    }

    const { volume = 0.7, playbackRate = 1 } = options;

    audio.volume = clampValue(Number(volume) || 0, 0, 1);
    audio.playbackRate = clampValue(Number(playbackRate) || 1, 0.5, 2);

    try {
        audio.currentTime = 0;
    } catch {
        // ignore seek errors for not-yet-loaded media
    }

    const maybePromise = audio.play();
    if (maybePromise && typeof maybePromise.catch === 'function') {
        maybePromise.catch(() => {});
    }
}

function playXpGainSound() {
    const now = Date.now();
    if (now - lastXpGainSoundAt < XP_GAIN_SOUND_THROTTLE_MS) {
        return;
    }

    lastXpGainSoundAt = now;
    playDashboardSound('xp_gain', {
        volume: 0.46,
        playbackRate: randomPlaybackRateBySemitone(0.45),
    });
}

function stopDashboardSounds() {
    for (const pool of soundPools.values()) {
        for (const audio of pool) {
            if (!audio) {
                continue;
            }

            try {
                audio.pause();
                audio.currentTime = 0;
            } catch {
                // ignore media cleanup errors
            }
        }
    }
}

function megaCardShadowStyle(scene) {
    if (!scene?.glowing) {
        return null;
    }

    const glowBoost = clampValue(Number(scene?.glowBoost) || 0, 0, 1.5);
    const ringOpacity = clampValue(0.2 + (glowBoost * 0.2), 0.2, 0.72);
    const haloOpacity = clampValue(0.18 + (glowBoost * 0.24), 0.18, 0.82);
    const haloSize = Math.round(24 + (glowBoost * 20));

    return [
        `0 0 0 1px rgb(252 252 250 / ${Math.round(ringOpacity * 100)}%)`,
        `0 0 ${haloSize}px rgb(252 252 250 / ${Math.round(haloOpacity * 100)}%)`,
        '0 16px 40px rgb(0 0 0 / 34%)',
    ].join(', ');
}

function megaCardFilterStyle(scene) {
    if (!scene?.glowing) {
        return null;
    }

    const glowBoost = clampValue(Number(scene?.glowBoost) || 0, 0, 1.5);
    return `brightness(${(1 + (glowBoost * 0.16)).toFixed(3)})`;
}

function intensifyMegaCardGlow(sceneId, amount = 0.2) {
    if (!batchCollapseScene.value || batchCollapseScene.value.id !== sceneId) {
        return;
    }

    batchCollapseScene.value.glowBoost = clampValue(
        Number(batchCollapseScene.value.glowBoost ?? 0) + amount,
        0,
        1.5,
    );

    scheduleEffectTimeout(() => {
        if (!batchCollapseScene.value || batchCollapseScene.value.id !== sceneId) {
            return;
        }

        batchCollapseScene.value.glowBoost = clampValue(
            Number(batchCollapseScene.value.glowBoost ?? 0) - (amount * 0.68),
            0,
            1.5,
        );
    }, 210);
}

function clearSoundPools() {
    stopDashboardSounds();
    soundPools.clear();
    resolvedSoundUrlCache.clear();
}

function randomInteger(min, max) {
    const normalizedMin = Math.ceil(Number(min) || 0);
    const normalizedMax = Math.floor(Number(max) || 0);
    if (normalizedMax <= normalizedMin) {
        return normalizedMin;
    }

    return Math.floor(Math.random() * (normalizedMax - normalizedMin + 1)) + normalizedMin;
}

function hashStringToUInt32(input) {
    const source = String(input ?? '');
    let hash = 2166136261;

    for (let index = 0; index < source.length; index += 1) {
        hash ^= source.charCodeAt(index);
        hash = Math.imul(hash, 16777619);
    }

    return hash >>> 0;
}

function createSeededRandom(seed) {
    let state = (Number(seed) >>> 0) || 1;

    return () => {
        state = (state + 0x6D2B79F5) >>> 0;
        let mixed = Math.imul(state ^ (state >>> 15), 1 | state);
        mixed ^= mixed + Math.imul(mixed ^ (mixed >>> 7), 61 | mixed);
        return ((mixed ^ (mixed >>> 14)) >>> 0) / 4294967296;
    };
}

function generateXpColorSeed() {
    if (typeof window !== 'undefined' && window.crypto?.getRandomValues) {
        const buffer = new Uint32Array(1);
        window.crypto.getRandomValues(buffer);
        return (Number(buffer[0]) >>> 0) || 1;
    }

    return (Math.floor(Math.random() * 0xFFFFFFFF) >>> 0) || 1;
}

function persistXpColorSeed() {
    if (typeof window === 'undefined') {
        return;
    }

    window.localStorage.setItem(XP_COLOR_SEED_STORAGE_KEY, String((Number(xpColorSeed.value) >>> 0) || 1));
    notifyGamificationStateChanged();
}

function syncXpProgressLevel() {
    xpProgressLevel.value = Math.max(1, productivityRewardHistory.length + 1);
}

function computeXpProgressPalette(level) {
    const safeLevel = Math.max(1, Math.round(Number(level) || 1));
    const levelSeed = (Number(xpColorSeed.value) ^ hashStringToUInt32(`xp-level-${safeLevel}`)) >>> 0;
    const random = createSeededRandom(levelSeed);

    const hue = Math.round(random() * 359);
    const saturation = 72 + Math.round(random() * 22);
    const lightness = 46 + Math.round(random() * 14);

    return {
        start: `hsl(${hue} ${saturation}% ${lightness}%)`,
        ring: `hsl(${hue} ${Math.max(58, saturation - 16)}% ${Math.min(80, lightness + 20)}% / 0.45)`,
        glow: `hsl(${hue} ${Math.max(64, saturation - 8)}% ${Math.min(78, lightness + 14)}% / 0.68)`,
    };
}

function normalizeProductivityRewardHistory(value) {
    if (!Array.isArray(value)) {
        return [];
    }

    return value
        .map((entry) => Math.round(Number(entry) || 0))
        .filter((entry) => Number.isFinite(entry) && entry > 0);
}

function persistProductivityScore() {
    if (typeof window === 'undefined') {
        return;
    }

    window.localStorage.setItem(PRODUCTIVITY_STORAGE_KEY, String(productivityScore.value));
    notifyGamificationStateChanged();
}

function persistProductivityRewardHistory() {
    if (typeof window === 'undefined') {
        return;
    }

    window.localStorage.setItem(
        PRODUCTIVITY_REWARD_HISTORY_STORAGE_KEY,
        JSON.stringify(productivityRewardHistory),
    );
    notifyGamificationStateChanged();
}

function grantProductivityRewardForLevelUp() {
    const reward = PRODUCTIVITY_REWARD_BASE_PER_LEVEL
        + randomInteger(0, PRODUCTIVITY_REWARD_RANDOM_MAX_BONUS);

    productivityScore.value = Math.max(0, Math.round(productivityScore.value + reward));
    productivityRewardHistory.push(reward);
    xpRewardAmount.value = reward;
    persistProductivityScore();
    persistProductivityRewardHistory();

    return reward;
}

function revokeLatestProductivityReward() {
    if (productivityRewardHistory.length === 0) {
        syncXpProgressLevel();
        return 0;
    }

    const reward = Math.max(0, Math.round(Number(productivityRewardHistory.pop()) || 0));
    if (reward > 0) {
        productivityScore.value = Math.max(0, Math.round(productivityScore.value - reward));
        persistProductivityScore();
    }
    persistProductivityRewardHistory();
    syncXpProgressLevel();

    return reward;
}

function persistXpProgress() {
    if (typeof window === 'undefined') {
        return;
    }

    window.localStorage.setItem(XP_PROGRESS_STORAGE_KEY, String(xpProgress.value));
    notifyGamificationStateChanged();
}

function calculateBatchRemovalXpGain(removedCount) {
    const totalRemoved = Math.max(1, Number(removedCount) || 1);
    return clampValue(
        totalRemoved * XP_BATCH_TOTAL_GAIN_PER_ITEM,
        XP_BATCH_TOTAL_GAIN_MIN,
        XP_BATCH_TOTAL_GAIN_MAX,
    );
}

function buildXpGainSourceId() {
    const sourceId = `xp-source-${Date.now()}-${nextXpGainSourceId}`;
    nextXpGainSourceId += 1;
    return sourceId;
}

function clearXpGainSourceCleanupTimer(sourceId) {
    const normalizedSourceId = String(sourceId ?? '').trim();
    if (normalizedSourceId === '') {
        return;
    }

    const cleanupTimerId = xpGainSourceCleanupTimeouts.get(normalizedSourceId);
    if (!cleanupTimerId) {
        return;
    }

    clearTimeout(cleanupTimerId);
    xpGainSourceCleanupTimeouts.delete(normalizedSourceId);
}

function scheduleXpGainSourceCleanup(sourceId) {
    const normalizedSourceId = String(sourceId ?? '').trim();
    if (normalizedSourceId === '') {
        return;
    }

    clearXpGainSourceCleanupTimer(normalizedSourceId);
    const cleanupTimerId = window.setTimeout(() => {
        xpGainSourceCleanupTimeouts.delete(normalizedSourceId);
        xpGainSources.delete(normalizedSourceId);
    }, XP_STAR_SOURCE_RETENTION_MS);
    xpGainSourceCleanupTimeouts.set(normalizedSourceId, cleanupTimerId);
}

function ensureXpGainSource(sourceId, totalGain) {
    const normalizedSourceId = String(sourceId ?? '').trim();
    if (normalizedSourceId === '') {
        return null;
    }

    const safeTotalGain = Math.max(0, Number(totalGain) || 0);
    clearXpGainSourceCleanupTimer(normalizedSourceId);

    const existing = xpGainSources.get(normalizedSourceId);
    if (existing) {
        existing.totalGain = Math.max(existing.totalGain, safeTotalGain);
        return existing;
    }

    const source = {
        id: normalizedSourceId,
        totalGain: safeTotalGain,
        grantedGain: 0,
        pendingStarIds: new Set(),
        pendingTimeoutIds: new Set(),
    };
    xpGainSources.set(normalizedSourceId, source);

    return source;
}

function rollbackXpGainSource(sourceId, fallbackTotalGain = 0) {
    const normalizedSourceId = String(sourceId ?? '').trim();

    if (normalizedSourceId !== '') {
        const source = xpGainSources.get(normalizedSourceId);
        if (source) {
            clearXpGainSourceCleanupTimer(normalizedSourceId);

            for (const timeoutId of source.pendingTimeoutIds) {
                clearTimeout(timeoutId);
                effectTimeouts.delete(timeoutId);
            }
            source.pendingTimeoutIds.clear();

            if (source.pendingStarIds.size > 0) {
                const pendingStarIds = new Set(source.pendingStarIds);
                xpStars.value = xpStars.value.filter((star) => !pendingStarIds.has(star.id));
                source.pendingStarIds.clear();
            }

            const grantedGain = Math.max(0, Number(source.grantedGain) || 0);
            xpGainSources.delete(normalizedSourceId);

            if (grantedGain > 0) {
                adjustXpProgress(-grantedGain);
            }
            return;
        }
    }

    const fallbackGain = Math.max(0, Number(fallbackTotalGain) || 0);
    if (fallbackGain > 0) {
        adjustXpProgress(-fallbackGain);
    }
}

function clearXpGainSources() {
    for (const cleanupTimerId of xpGainSourceCleanupTimeouts.values()) {
        clearTimeout(cleanupTimerId);
    }
    xpGainSourceCleanupTimeouts.clear();

    for (const source of xpGainSources.values()) {
        for (const timeoutId of source.pendingTimeoutIds) {
            clearTimeout(timeoutId);
            effectTimeouts.delete(timeoutId);
        }
    }

    xpGainSources.clear();
}

function setXpVisualProgress(value, options = {}) {
    const { instant = false, durationMs = XP_PROGRESS_FILL_NORMAL_MS } = options;
    xpProgressFillDurationMs.value = Math.max(0, Math.round(Number(durationMs) || XP_PROGRESS_FILL_NORMAL_MS));
    xpVisualProgress.value = clampValue(Number(value) || 0, 0, 1);

    if (!instant) {
        return;
    }

    xpProgressInstant.value = true;
    if (typeof window !== 'undefined' && typeof window.requestAnimationFrame === 'function') {
        window.requestAnimationFrame(() => {
            xpProgressInstant.value = false;
        });
        return;
    }

    xpProgressInstant.value = false;
}

function applyScrollLockState(locked) {
    if (typeof document === 'undefined') {
        return;
    }

    if (locked) {
        if (scrollLockActive) {
            return;
        }

        previousBodyOverflowStyle = document.body.style.overflow;
        previousBodyTouchActionStyle = document.body.style.touchAction;
        previousHtmlOverflowStyle = document.documentElement.style.overflow;
        previousHtmlOverscrollBehaviorStyle = document.documentElement.style.overscrollBehavior;

        document.body.style.overflow = 'hidden';
        document.body.style.touchAction = 'none';
        document.documentElement.style.overflow = 'hidden';
        document.documentElement.style.overscrollBehavior = 'none';
        scrollLockActive = true;
        return;
    }

    if (!scrollLockActive) {
        return;
    }

    document.body.style.overflow = previousBodyOverflowStyle;
    document.body.style.touchAction = previousBodyTouchActionStyle;
    document.documentElement.style.overflow = previousHtmlOverflowStyle;
    document.documentElement.style.overscrollBehavior = previousHtmlOverscrollBehaviorStyle;
    scrollLockActive = false;
}

function requestAnimationSkip() {
    dashboardSoundMutedUntil = Date.now() + SOUND_SKIP_MUTE_MS;
    stopDashboardSounds();
    animationSkipEpoch.value += 1;
}

function skipActiveAnimations() {
    if (!isSkippableAnimationPlaying.value) {
        return;
    }

    requestAnimationSkip();
}

function handleAnimationSkipTap() {
    skipActiveAnimations();
}

function waitForMsOrAnimationSkip(milliseconds, skipEpoch) {
    const safeDelay = Math.max(0, Math.round(Number(milliseconds) || 0));
    if (animationSkipEpoch.value !== skipEpoch) {
        return Promise.resolve(true);
    }

    return new Promise((resolve) => {
        let settled = false;
        let timeoutId = null;
        const stopWatch = watch(animationSkipEpoch, (nextEpoch) => {
            if (settled || nextEpoch === skipEpoch) {
                return;
            }

            settled = true;
            if (timeoutId !== null) {
                clearTimeout(timeoutId);
            }
            stopWatch();
            resolve(true);
        });

        timeoutId = window.setTimeout(() => {
            if (settled) {
                return;
            }

            settled = true;
            stopWatch();
            resolve(false);
        }, safeDelay);
    });
}

async function playXpLevelUpAnimation(fillDurationMs) {
    const skipEpoch = animationSkipEpoch.value;
    xpLevelUpBackdropVisible.value = true;
    xpLevelUpRaised.value = true;
    xpRewardAmount.value = 0;
    playDashboardSound('level_up', {
        volume: 0.82,
    });

    const skippedBeforeFill = await waitForMsOrAnimationSkip(XP_LEVELUP_PREPARE_MS, skipEpoch);
    if (skippedBeforeFill) {
        setXpVisualProgress(1, { instant: true });
    } else {
        setXpVisualProgress(1, { durationMs: fillDurationMs });
        const skippedFill = await waitForMsOrAnimationSkip(fillDurationMs, skipEpoch);
        if (skippedFill) {
            setXpVisualProgress(1, { instant: true });
        }
    }

    grantProductivityRewardForLevelUp();

    xpLevelUpImpact.value = true;
    xpRewardVisible.value = true;
    const skippedReward = await waitForMsOrAnimationSkip(XP_LEVELUP_REWARD_MS, skipEpoch);

    xpRewardVisible.value = false;
    xpRewardAmount.value = 0;
    xpLevelUpImpact.value = false;
    setXpVisualProgress(0, { instant: true });

    if (!skippedReward) {
        await waitForMsOrAnimationSkip(XP_LEVELUP_SETTLE_MS, skipEpoch);
    }
    xpLevelUpRaised.value = false;
    xpLevelUpBackdropVisible.value = false;
    syncXpProgressLevel();
}

async function processPendingXpGain() {
    if (xpGainProcessing) {
        return;
    }

    xpGainProcessing = true;

    try {
        while (pendingXpGain > 0) {
            const safeProgress = clampValue(xpProgress.value, 0, 0.999999);
            const remainingToLevel = Math.max(0.000001, 1 - safeProgress);

            if (pendingXpGain < remainingToLevel) {
                xpProgress.value = clampValue(safeProgress + pendingXpGain, 0, 0.999999);
                pendingXpGain = 0;
                persistXpProgress();
                setXpVisualProgress(xpProgress.value, { durationMs: XP_PROGRESS_FILL_NORMAL_MS });
                break;
            }

            pendingXpGain -= remainingToLevel;
            const normalizedFill = clampValue(remainingToLevel, 0, 1);
            const fillDurationMs = clampValue(
                Math.round(XP_LEVELUP_MAX_FILL_MS * normalizedFill),
                XP_LEVELUP_MIN_FILL_MS,
                XP_LEVELUP_MAX_FILL_MS,
            );

            await playXpLevelUpAnimation(fillDurationMs);
            xpProgress.value = 0;
            persistXpProgress();
        }

        if (pendingXpGain <= 0) {
            setXpVisualProgress(xpProgress.value, { durationMs: XP_PROGRESS_FILL_NORMAL_MS });
        }
    } finally {
        xpGainProcessing = false;
        if (pendingXpGain > 0) {
            processPendingXpGain().catch(() => {});
        }
    }
}

function adjustXpProgress(delta) {
    const numericDelta = Number(delta);
    if (!Number.isFinite(numericDelta) || numericDelta === 0) {
        return;
    }

    if (numericDelta < 0) {
        let remainingLoss = Math.abs(numericDelta);
        const pendingReduction = Math.min(pendingXpGain, remainingLoss);
        pendingXpGain -= pendingReduction;
        remainingLoss -= pendingReduction;

        let guard = 0;
        while (remainingLoss > 0.000001 && guard < 512) {
            guard += 1;
            const currentProgress = clampValue(xpProgress.value, 0, 0.999999);

            if (currentProgress >= remainingLoss) {
                xpProgress.value = clampValue(currentProgress - remainingLoss, 0, 0.999999);
                remainingLoss = 0;
                break;
            }

            remainingLoss -= currentProgress;
            xpProgress.value = 0;

            const revertedReward = revokeLatestProductivityReward();
            if (revertedReward <= 0) {
                remainingLoss = 0;
                break;
            }

            xpProgress.value = 0.999999;
        }

        persistXpProgress();

        if (!xpGainProcessing) {
            setXpVisualProgress(xpProgress.value, { durationMs: XP_PROGRESS_FILL_NORMAL_MS });
        }
        return;
    }

    pendingXpGain += numericDelta;
    processPendingXpGain().catch(() => {});
}

function waitForMs(milliseconds) {
    return new Promise((resolve) => {
        window.setTimeout(resolve, milliseconds);
    });
}

function normalizeSortOrderValue(value, fallback = 1000) {
    const parsed = Number(value);
    if (!Number.isFinite(parsed)) {
        return fallback;
    }

    return Math.round(parsed);
}

function sortItems(items) {
    return cloneItems(items).sort((left, right) => {
        const completedSort = Number(left.is_completed) - Number(right.is_completed);
        if (completedSort !== 0) {
            return completedSort;
        }

        const leftSortOrder = normalizeSortOrderValue(left.sort_order, 1000);
        const rightSortOrder = normalizeSortOrderValue(right.sort_order, 1000);
        if (leftSortOrder !== rightSortOrder) {
            return leftSortOrder - rightSortOrder;
        }

        return String(right.created_at ?? '').localeCompare(String(left.created_at ?? ''));
    });
}

function normalizeComparableValue(value) {
    if (value === null || value === undefined) {
        return '';
    }

    return String(value);
}

function parseComparableTimestampMs(value) {
    const parsed = Date.parse(normalizeComparableValue(value));
    return Number.isFinite(parsed) ? parsed : null;
}

function areItemsEquivalent(leftItems, rightItems) {
    const left = Array.isArray(leftItems) ? leftItems : [];
    const right = Array.isArray(rightItems) ? rightItems : [];

    if (left.length !== right.length) {
        return false;
    }

    for (let index = 0; index < left.length; index += 1) {
        const leftItem = left[index] ?? {};
        const rightItem = right[index] ?? {};

        if (Number(leftItem.id) !== Number(rightItem.id)) {
            return false;
        }

        if (normalizeComparableValue(leftItem.local_id) !== normalizeComparableValue(rightItem.local_id)) {
            return false;
        }

        if (Number(leftItem.owner_id) !== Number(rightItem.owner_id)) {
            return false;
        }

        if (normalizeLinkId(leftItem.list_link_id) !== normalizeLinkId(rightItem.list_link_id)) {
            return false;
        }

        if (normalizeComparableValue(leftItem.type) !== normalizeComparableValue(rightItem.type)) {
            return false;
        }

        if (normalizeComparableValue(leftItem.text) !== normalizeComparableValue(rightItem.text)) {
            return false;
        }

        if (normalizeComparableValue(leftItem.sort_order) !== normalizeComparableValue(rightItem.sort_order)) {
            return false;
        }

        if (normalizeComparableValue(leftItem.quantity) !== normalizeComparableValue(rightItem.quantity)) {
            return false;
        }

        if (normalizeComparableValue(leftItem.unit) !== normalizeComparableValue(rightItem.unit)) {
            return false;
        }

        if (normalizeComparableValue(leftItem.due_at) !== normalizeComparableValue(rightItem.due_at)) {
            return false;
        }

        if (normalizeComparableValue(leftItem.priority) !== normalizeComparableValue(rightItem.priority)) {
            return false;
        }

        if (Boolean(leftItem.is_completed) !== Boolean(rightItem.is_completed)) {
            return false;
        }

        if (normalizeComparableValue(leftItem.completed_at) !== normalizeComparableValue(rightItem.completed_at)) {
            return false;
        }

        if (normalizeComparableValue(leftItem.created_at) !== normalizeComparableValue(rightItem.created_at)) {
            return false;
        }

        if (normalizeComparableValue(leftItem.updated_at) !== normalizeComparableValue(rightItem.updated_at)) {
            return false;
        }

        if (Boolean(leftItem.pending_sync) !== Boolean(rightItem.pending_sync)) {
            return false;
        }
    }

    return true;
}

function setVisibleItems(type, items) {
    const nextItems = cloneItems(items);

    if (type === 'product') {
        if (areItemsEquivalent(productItems.value, nextItems)) {
            return;
        }

        productItems.value = nextItems;
        return;
    }

    if (areItemsEquivalent(todoItems.value, nextItems)) {
        return;
    }

    todoItems.value = nextItems;
}

function normalizeItem(item, localIdOverride = null, context = {}) {
    const ownerIdOverride = context?.ownerIdOverride;
    const linkIdOverride = context?.linkIdOverride;

    return {
        ...item,
        owner_id: Number(ownerIdOverride ?? item?.owner_id ?? 0),
        list_link_id: normalizeLinkId(linkIdOverride ?? item?.list_link_id),
        sort_order: normalizeSortOrderValue(item?.sort_order, 1000),
        local_id: localIdOverride ?? item.local_id ?? `srv-${item.id}`,
        priority: item?.type === 'todo' ? normalizeTodoPriority(item?.priority) : null,
        pending_sync: false,
    };
}

function normalizeItems(items, previousItems = [], context = {}) {
    const previousList = Array.isArray(previousItems) ? previousItems : [];
    const previousLocalIdById = new Map(
        previousList
            .map((item) => [Number(item?.id), String(item?.local_id ?? '').trim()])
            .filter(([id, localId]) => Number.isFinite(id) && localId !== ''),
    );
    const previousItemById = new Map(
        previousList
            .map((item) => [Number(item?.id), item])
            .filter(([id]) => Number.isFinite(id)),
    );

    return sortItems((items ?? []).map((item) => {
        const normalized = normalizeItem(item, null, context);
        const itemId = Number(normalized.id);
        const preservedLocalId = previousLocalIdById.get(itemId);

        if (preservedLocalId) {
            normalized.local_id = preservedLocalId;
        }

        const previousItem = previousItemById.get(itemId);
        if (!previousItem) {
            return normalized;
        }

        const previousUpdatedAtMs = parseComparableTimestampMs(previousItem.updated_at);
        const nextUpdatedAtMs = parseComparableTimestampMs(normalized.updated_at);
        const shouldKeepPrevious = previousUpdatedAtMs !== null
            && (nextUpdatedAtMs === null || previousUpdatedAtMs > nextUpdatedAtMs);

        if (!shouldKeepPrevious) {
            return normalized;
        }

        return {
            ...normalized,
            ...previousItem,
            owner_id: normalized.owner_id,
            list_link_id: normalized.list_link_id,
            local_id: preservedLocalId || String(previousItem?.local_id ?? '').trim() || normalized.local_id,
        };
    }));
}

function hasListSyncConflict(ownerId, type, linkId = undefined, expectedVersion = null) {
    const hasVersionDrift = expectedVersion !== null
        && getListSyncVersion(ownerId, type, linkId) !== Number(expectedVersion);

    return hasVersionDrift
        || hasPendingOperations(ownerId, type, linkId)
        || hasPendingSwipeAction(ownerId, type, linkId);
}

function readFilteredServerItems(ownerId, type, items, linkId = undefined) {
    return (Array.isArray(items) ? items : []).filter((entry) => {
        const tombstone = getDeletedItemTombstone(ownerId, type, entry?.id, linkId);
        if (!tombstone) {
            return true;
        }

        const serverUpdatedAtMs = parseComparableTimestampMs(entry?.updated_at);
        if (serverUpdatedAtMs === null) {
            return false;
        }

        // If server item state is not newer than local deletion moment, keep it deleted locally.
        return serverUpdatedAtMs > Number(tombstone.deletedAt ?? 0);
    });
}

function writeListToCache(ownerId, type, items, linkId = undefined) {
    const resolvedLinkId = resolveLinkIdForOwner(ownerId, linkId);
    const key = listCacheKey(ownerId, type, resolvedLinkId);
    const normalized = sortItems(items);
    const previous = Array.isArray(cachedItemsByList.value[key]) ? cachedItemsByList.value[key] : [];
    const cacheChanged = !areItemsEquivalent(previous, normalized);

    clearDeletedTombstonesForItems(ownerId, type, normalized, resolvedLinkId);

    if (cacheChanged) {
        cachedItemsByList.value[key] = normalized;
        bumpListSyncVersion(ownerId, type, resolvedLinkId);
    }

    if (type === 'product' && isCurrentListContext(ownerId, resolvedLinkId)) {
        setVisibleItems('product', normalized);
    }

    if (type === 'todo' && isCurrentListContext(ownerId, resolvedLinkId)) {
        setVisibleItems('todo', normalized);
    }

    if (typeof window !== 'undefined' && cacheChanged) {
        window.localStorage.setItem(ITEMS_CACHE_STORAGE_KEY, JSON.stringify(cachedItemsByList.value));
    }
}

function readListFromCache(ownerId, type, linkId = undefined) {
    const key = listCacheKey(ownerId, type, resolveLinkIdForOwner(ownerId, linkId));
    return cloneItems(cachedItemsByList.value[key]);
}

function applyLocalUpdate(ownerId, type, updater, linkId = undefined) {
    const current = readListFromCache(ownerId, type, linkId);
    const nextItems = updater(current);
    writeListToCache(ownerId, type, nextItems, linkId);
}

function upsertLocalItem(ownerId, type, item, { atTop = false, linkId = undefined } = {}) {
    applyLocalUpdate(ownerId, type, (items) => {
        const next = cloneItems(items);
        const existingIndex = next.findIndex((entry) => Number(entry.id) === Number(item.id));

        if (existingIndex === -1) {
            if (atTop) {
                next.unshift({ ...item });
            } else {
                next.push({ ...item });
            }
        } else {
            next[existingIndex] = {
                ...next[existingIndex],
                ...item,
            };
        }

        return next;
    }, linkId);
}

function hydrateSelectedListsFromCache() {
    setVisibleItems('product', readListFromCache(selectedOwnerId.value, 'product', selectedListLinkId.value));
    setVisibleItems('todo', readListFromCache(selectedOwnerId.value, 'todo', selectedListLinkId.value));
}

function persistQueue() {
    if (typeof window === 'undefined') {
        return;
    }

    const persistableQueue = offlineQueue.value.filter((operation) => !operation?.volatile);
    window.localStorage.setItem(OFFLINE_QUEUE_STORAGE_KEY, JSON.stringify(persistableQueue));
}

function loadOfflineStateFromStorage() {
    if (typeof window === 'undefined') {
        return;
    }

    const parsedQueue = parseJson(window.localStorage.getItem(OFFLINE_QUEUE_STORAGE_KEY), []);
    const parsedCache = parseJson(window.localStorage.getItem(ITEMS_CACHE_STORAGE_KEY), {});
    const parsedSuggestionsCache = parseJson(window.localStorage.getItem(SUGGESTIONS_CACHE_STORAGE_KEY), {});
    const parsedProductStatsCache = parseJson(window.localStorage.getItem(PRODUCT_STATS_CACHE_STORAGE_KEY), {});
    const parsedUserSearchCache = parseJson(window.localStorage.getItem(USER_SEARCH_CACHE_STORAGE_KEY), {});
    const parsedSyncState = parseJson(window.localStorage.getItem(SYNC_STATE_CACHE_STORAGE_KEY), null);

    offlineQueue.value = Array.isArray(parsedQueue) ? parsedQueue : [];
    cachedItemsByList.value = parsedCache && typeof parsedCache === 'object' && !Array.isArray(parsedCache)
        ? parsedCache
        : {};
    cachedSuggestionsByList.value = asPlainObject(parsedSuggestionsCache);
    cachedProductStatsByList.value = asPlainObject(parsedProductStatsCache);
    cachedUserSearchByQuery.value = asPlainObject(parsedUserSearchCache);
    cachedSyncState.value = parsedSyncState && typeof parsedSyncState === 'object'
        ? normalizeSyncStatePayload(parsedSyncState)
        : null;
    const storedGamificationUpdatedAtMs = Number(
        window.localStorage.getItem(GAMIFICATION_UPDATED_AT_STORAGE_KEY),
    );
    localGamificationUpdatedAtMs = Number.isFinite(storedGamificationUpdatedAtMs) && storedGamificationUpdatedAtMs > 0
        ? Math.round(storedGamificationUpdatedAtMs)
        : 0;

    const cachedTempIds = Object.values(cachedItemsByList.value)
        .flatMap((items) => (Array.isArray(items) ? items : []))
        .map((item) => Number(item?.id))
        .filter((id) => Number.isFinite(id) && id < 0);
    const queuedTempIds = offlineQueue.value
        .map((operation) => Number(operation?.item_id))
        .filter((id) => Number.isFinite(id) && id < 0);
    const minTempId = Math.min(-1, ...cachedTempIds, ...queuedTempIds);
    nextTempId = Number.isFinite(minTempId) ? minTempId : -1;

    const storedXpProgress = Number(window.localStorage.getItem(XP_PROGRESS_STORAGE_KEY));
    if (Number.isFinite(storedXpProgress)) {
        xpProgress.value = clampValue(storedXpProgress, 0, 0.999999);
        xpVisualProgress.value = xpProgress.value;
    }

    const storedProductivityScore = Number(window.localStorage.getItem(PRODUCTIVITY_STORAGE_KEY));
    if (Number.isFinite(storedProductivityScore)) {
        productivityScore.value = Math.max(0, Math.round(storedProductivityScore));
    }

    const storedRewardHistory = parseJson(
        window.localStorage.getItem(PRODUCTIVITY_REWARD_HISTORY_STORAGE_KEY),
        [],
    );
    productivityRewardHistory = normalizeProductivityRewardHistory(storedRewardHistory);

    if (productivityRewardHistory.length === 0 && productivityScore.value > 0) {
        const estimatedLegacyLevels = Math.floor(productivityScore.value / 10);
        if (estimatedLegacyLevels > 0) {
            productivityRewardHistory = Array.from({ length: estimatedLegacyLevels }, () => 10);
            persistProductivityRewardHistory();
        }
    }
    syncXpProgressLevel();

    const storedXpColorSeed = Number(window.localStorage.getItem(XP_COLOR_SEED_STORAGE_KEY));
    if (Number.isFinite(storedXpColorSeed) && storedXpColorSeed > 0) {
        xpColorSeed.value = (Math.floor(storedXpColorSeed) >>> 0) || 1;
    } else {
        xpColorSeed.value = generateXpColorSeed();
        persistXpColorSeed();
    }

    if (localGamificationUpdatedAtMs <= 0 && cachedSyncState.value?.gamification?.updated_at_ms) {
        localGamificationUpdatedAtMs = Number(cachedSyncState.value.gamification.updated_at_ms) || 0;
        persistGamificationUpdatedAtMs();
    }

    const storedOwnerId = Number(window.localStorage.getItem(LOCAL_DEFAULT_OWNER_KEY));
    if (Number.isFinite(storedOwnerId) && storedOwnerId > 0) {
        selectedOwnerId.value = storedOwnerId;
    }
}

function persistLocalDefaultOwner(ownerId) {
    if (typeof window === 'undefined') {
        return;
    }

    const normalizedOwnerId = Number(ownerId);
    if (!Number.isFinite(normalizedOwnerId) || normalizedOwnerId <= 0) {
        return;
    }

    window.localStorage.setItem(LOCAL_DEFAULT_OWNER_KEY, String(normalizedOwnerId));
}

function isConnectivityError(error) {
    const statusCode = Number(error?.response?.status ?? 0);
    return !error?.response || statusCode === 0 || statusCode === 502 || statusCode === 503 || statusCode === 504;
}

function isRetriableRequestError(error) {
    const statusCode = Number(error?.response?.status ?? 0);
    return isConnectivityError(error) || statusCode >= 500 || statusCode === 429;
}

function markRequestSuccess() {
    serverReachable.value = true;
    if (typeof window !== 'undefined') {
        isBrowserOnline.value = window.navigator.onLine;
    }
}

function markRequestFailure() {
    serverReachable.value = false;
    if (typeof window !== 'undefined') {
        isBrowserOnline.value = window.navigator.onLine;
    }
}

async function requestApi(executor) {
    try {
        const response = await executor();
        markRequestSuccess();
        return response;
    } catch (error) {
        if (isConnectivityError(error)) {
            markRequestFailure();
        } else if (error?.response) {
            // Server answered (even with 4xx/5xx), so transport is reachable.
            markRequestSuccess();
        }

        throw error;
    }
}

function generateTempId() {
    nextTempId -= 1;
    return nextTempId;
}

function nextSortOrderForLocalList(ownerId, type, isCompleted = false, linkId = undefined) {
    const currentItems = readListFromCache(ownerId, type, linkId)
        .filter((item) => Boolean(item.is_completed) === Boolean(isCompleted));

    if (currentItems.length === 0) {
        return 1000;
    }

    const minSortOrder = currentItems.reduce((minValue, item) => (
        Math.min(minValue, normalizeSortOrderValue(item.sort_order, 1000))
    ), Number.POSITIVE_INFINITY);

    if (!Number.isFinite(minSortOrder)) {
        return 1000;
    }

    return minSortOrder - 1000;
}

function createOptimisticItem({
    ownerId,
    type,
    text,
    dueAt = null,
    priority = null,
    quantity = null,
    unit = null,
    sortOrder = 1000,
    linkId = null,
}) {
    const now = new Date().toISOString();
    const tempId = generateTempId();

    return {
        id: tempId,
        local_id: `tmp-${Math.abs(tempId)}`,
        owner_id: Number(ownerId),
        list_link_id: normalizeLinkId(linkId),
        type,
        text,
        sort_order: normalizeSortOrderValue(sortOrder, 1000),
        quantity: type === 'product' ? quantity : null,
        unit: type === 'product' ? unit : null,
        due_at: dueAt,
        priority: type === 'todo' ? normalizeTodoPriority(priority, inferTodoPriorityFromDueAt(dueAt)) : null,
        is_completed: false,
        completed_at: null,
        created_at: now,
        updated_at: now,
        pending_sync: true,
    };
}

function removeLocalItem(ownerId, type, itemId, linkId = undefined) {
    markItemsAsDeleted(ownerId, type, [itemId], linkId);
    applyLocalUpdate(
        ownerId,
        type,
        (items) => items.filter((entry) => Number(entry.id) !== Number(itemId)),
        linkId,
    );
}

function findQueueIndexFromEnd(predicate) {
    for (let index = offlineQueue.value.length - 1; index >= 0; index -= 1) {
        if (predicate(offlineQueue.value[index])) {
            return index;
        }
    }

    return -1;
}

function hasPendingOperations(ownerId, type, linkId = undefined) {
    const normalizedLinkId = resolveLinkIdForOwner(ownerId, linkId);

    return offlineQueue.value.some(
        (operation) =>
            Number(operation.owner_id) === Number(ownerId)
            && String(operation.type) === String(type)
            && normalizeLinkId(operation.link_id) === normalizedLinkId,
    );
}

function isOperationBeingSynced(opId) {
    return syncInFlightOperationIds.has(String(opId ?? ''));
}


function getQueuedUpdateQuietRemainingMs() {
    if (queuedUpdateTouchedAt <= 0) {
        return 0;
    }

    const elapsedMs = Date.now() - queuedUpdateTouchedAt;
    if (elapsedMs >= UPDATE_SYNC_COALESCE_MS) {
        return 0;
    }

    return UPDATE_SYNC_COALESCE_MS - elapsedMs;
}

function shouldCoalesceUpdatePayload(payload) {
    if (!payload || typeof payload !== 'object') {
        return false;
    }

    const payloadKeys = Object.keys(payload);
    if (payloadKeys.length === 0) {
        return false;
    }

    return payloadKeys.every((key) => COALESCED_UPDATE_KEYS.has(key));
}

function scheduleQueuedUpdateSync(delayMs = UPDATE_SYNC_COALESCE_MS) {
    if (typeof window === 'undefined') {
        return;
    }

    if (queuedUpdateSyncTimer) {
        clearTimeout(queuedUpdateSyncTimer);
    }

    const safeDelay = Math.max(0, Number(delayMs) || 0);
    queuedUpdateSyncTimer = window.setTimeout(() => {
        queuedUpdateSyncTimer = null;
        syncOfflineQueue().catch(() => {});
    }, safeDelay);
}

function markQueuedUpdateTouched(payload) {
    if (!shouldCoalesceUpdatePayload(payload)) {
        return;
    }

    queuedUpdateTouchedAt = Date.now();
    scheduleQueuedUpdateSync(UPDATE_SYNC_COALESCE_MS);
}

function enqueueOperation(operation) {
    offlineQueue.value.push({
        op_id: `op-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
        ...operation,
    });
    persistQueue();
}

function rewriteQueuedItemId(previousId, nextId) {
    offlineQueue.value = offlineQueue.value.map((operation) => {
        const rewrittenOperation = { ...operation };

        if (Number(rewrittenOperation.item_id) === Number(previousId)) {
            rewrittenOperation.item_id = Number(nextId);
        }

        if (rewrittenOperation.action === 'reorder' && Array.isArray(rewrittenOperation.payload?.order)) {
            rewrittenOperation.payload = {
                ...rewrittenOperation.payload,
                order: rewrittenOperation.payload.order.map((entry) => (
                    Number(entry) === Number(previousId)
                        ? Number(nextId)
                        : Number(entry)
                )),
            };
        }

        return rewrittenOperation;
    });

    persistQueue();
}

function dropQueueOperation(opId) {
    const nextQueue = offlineQueue.value.filter((operation) => operation.op_id !== opId);
    if (nextQueue.length === offlineQueue.value.length) {
        return;
    }

    offlineQueue.value = nextQueue;
    persistQueue();
}

function queueCreate(ownerId, type, item, linkId = undefined) {
    const resolvedLinkId = resolveLinkIdForOwner(ownerId, linkId);

    enqueueOperation({
        action: 'create',
        owner_id: Number(ownerId),
        link_id: resolvedLinkId,
        type,
        item_id: Number(item.id),
        payload: {
            text: item.text,
            sort_order: normalizeSortOrderValue(item.sort_order, 1000),
            quantity: item.quantity ?? null,
            unit: item.unit ?? null,
            due_at: item.due_at ?? null,
            priority: item.type === 'todo' ? normalizeTodoPriority(item.priority) : null,
            is_completed: !!item.is_completed,
        },
    });
}

function queueReorder(ownerId, type, order, linkId = undefined) {
    const normalizedOrder = Array.isArray(order)
        ? Array.from(new Set(order.map((itemId) => Number(itemId)).filter((itemId) => Number.isFinite(itemId))))
        : [];

    if (normalizedOrder.length === 0) {
        return;
    }

    const resolvedLinkId = resolveLinkIdForOwner(ownerId, linkId);

    const existingIndex = findQueueIndexFromEnd(
        (operation) =>
            operation.action === 'reorder'
            && Number(operation.owner_id) === Number(ownerId)
            && String(operation.type) === String(type)
            && normalizeLinkId(operation.link_id) === resolvedLinkId,
    );

    if (existingIndex !== -1) {
        offlineQueue.value[existingIndex] = {
            ...offlineQueue.value[existingIndex],
            payload: {
                order: normalizedOrder,
            },
        };
        persistQueue();
        return;
    }

    enqueueOperation({
        action: 'reorder',
        owner_id: Number(ownerId),
        link_id: resolvedLinkId,
        type,
        payload: {
            order: normalizedOrder,
        },
    });
}

function queueUpdate(ownerId, type, itemId, payload, linkId = undefined) {
    const numericItemId = Number(itemId);
    const resolvedLinkId = resolveLinkIdForOwner(ownerId, linkId);
    const createIndex = findQueueIndexFromEnd(
        (operation) =>
            operation.action === 'create'
            && Number(operation.item_id) === numericItemId
            && normalizeLinkId(operation.link_id) === resolvedLinkId,
    );

    if (createIndex !== -1 && !isOperationBeingSynced(offlineQueue.value[createIndex]?.op_id)) {
        const nextPayload = {
            ...offlineQueue.value[createIndex].payload,
            ...payload,
        };

        offlineQueue.value[createIndex] = {
            ...offlineQueue.value[createIndex],
            payload: nextPayload,
        };
        persistQueue();
        markQueuedUpdateTouched(nextPayload);
        return;
    }

    const updateIndex = findQueueIndexFromEnd(
        (operation) =>
            operation.action === 'update'
            && Number(operation.item_id) === numericItemId
            && normalizeLinkId(operation.link_id) === resolvedLinkId,
    );

    if (updateIndex !== -1 && !isOperationBeingSynced(offlineQueue.value[updateIndex]?.op_id)) {
        const nextPayload = {
            ...offlineQueue.value[updateIndex].payload,
            ...payload,
        };

        offlineQueue.value[updateIndex] = {
            ...offlineQueue.value[updateIndex],
            payload: nextPayload,
        };
        persistQueue();
        markQueuedUpdateTouched(nextPayload);
        return;
    }

    const nextPayload = { ...payload };

    enqueueOperation({
        action: 'update',
        owner_id: Number(ownerId),
        link_id: resolvedLinkId,
        type,
        item_id: numericItemId,
        payload: nextPayload,
    });
    markQueuedUpdateTouched(nextPayload);
}

function queueDelete(ownerId, type, itemId, linkId = undefined) {
    const numericItemId = Number(itemId);
    const resolvedLinkId = resolveLinkIdForOwner(ownerId, linkId);
    const createIndex = findQueueIndexFromEnd(
        (operation) =>
            operation.action === 'create'
            && Number(operation.item_id) === numericItemId
            && normalizeLinkId(operation.link_id) === resolvedLinkId,
    );

    if (createIndex !== -1 && !isOperationBeingSynced(offlineQueue.value[createIndex]?.op_id)) {
        offlineQueue.value = offlineQueue.value.filter(
            (operation) => !(
                Number(operation.item_id) === numericItemId
                && normalizeLinkId(operation.link_id) === resolvedLinkId
            ),
        );
        persistQueue();
        return;
    }

    offlineQueue.value = offlineQueue.value.filter(
        (operation) => !(
            operation.action === 'update'
            && Number(operation.item_id) === numericItemId
            && normalizeLinkId(operation.link_id) === resolvedLinkId
        ),
    );
    offlineQueue.value = offlineQueue.value
        .map((operation) => {
            if (operation.action !== 'reorder') {
                return operation;
            }

            if (normalizeLinkId(operation.link_id) !== resolvedLinkId) {
                return operation;
            }

            const nextOrder = Array.isArray(operation.payload?.order)
                ? operation.payload.order
                    .map((entry) => Number(entry))
                    .filter((entry) => Number.isFinite(entry) && entry !== numericItemId)
                : [];

            return {
                ...operation,
                payload: {
                    ...operation.payload,
                    order: nextOrder,
                },
            };
        })
        .filter(
            (operation) => operation.action !== 'reorder'
                || (Array.isArray(operation.payload?.order) && operation.payload.order.length > 0),
        );

    const queuedDeleteIndex = findQueueIndexFromEnd(
        (operation) =>
            operation.action === 'delete'
            && Number(operation.owner_id) === Number(ownerId)
            && String(operation.type) === String(type)
            && Number(operation.item_id) === numericItemId
            && normalizeLinkId(operation.link_id) === resolvedLinkId,
    );

    if (queuedDeleteIndex !== -1) {
        persistQueue();
        return;
    }

    persistQueue();

    enqueueOperation({
        action: 'delete',
        owner_id: Number(ownerId),
        link_id: resolvedLinkId,
        type,
        item_id: numericItemId,
    });
}

function queueDefaultOwner(ownerId) {
    const normalizedOwnerId = Number(ownerId);
    if (!Number.isFinite(normalizedOwnerId) || normalizedOwnerId <= 0) {
        return;
    }

    offlineQueue.value = offlineQueue.value.filter((operation) => operation.action !== 'set_default_owner');
    enqueueOperation({
        action: 'set_default_owner',
        payload: {
            owner_id: normalizedOwnerId,
        },
    });
}

function queueSuggestionDismiss(ownerId, type, suggestionKey, averageIntervalSeconds, linkId = undefined) {
    const normalizedKey = String(suggestionKey ?? '').trim();
    if (normalizedKey === '') {
        return;
    }

    enqueueOperation({
        action: 'dismiss_suggestion',
        owner_id: Number(ownerId),
        link_id: resolveLinkIdForOwner(ownerId, linkId),
        type: String(type),
        payload: {
            suggestion_key: normalizedKey,
            average_interval_seconds: Math.max(0, Number(averageIntervalSeconds) || 0),
        },
    });
}

function queueSuggestionReset(ownerId, type, suggestionKey, linkId = undefined) {
    const normalizedKey = String(suggestionKey ?? '').trim();
    if (normalizedKey === '') {
        return;
    }

    const resolvedLinkId = resolveLinkIdForOwner(ownerId, linkId);
    const existingIndex = findQueueIndexFromEnd(
        (operation) =>
            operation.action === 'reset_suggestion'
            && Number(operation.owner_id) === Number(ownerId)
            && String(operation.type) === String(type)
            && String(operation?.payload?.suggestion_key ?? '') === normalizedKey
            && normalizeLinkId(operation.link_id) === resolvedLinkId,
    );

    if (existingIndex !== -1) {
        return;
    }

    enqueueOperation({
        action: 'reset_suggestion',
        owner_id: Number(ownerId),
        link_id: resolvedLinkId,
        type: String(type),
        payload: {
            suggestion_key: normalizedKey,
        },
    });
}

function queueSendInvitation(userId) {
    const numericUserId = Number(userId);
    if (!Number.isFinite(numericUserId) || numericUserId <= 0) {
        return;
    }

    const existingIndex = findQueueIndexFromEnd(
        (operation) =>
            operation.action === 'send_invitation'
            && Number(operation?.payload?.user_id) === numericUserId,
    );

    if (existingIndex !== -1) {
        return;
    }

    enqueueOperation({
        action: 'send_invitation',
        payload: {
            user_id: numericUserId,
        },
    });
}

function queueInvitationResponse(action, invitationId) {
    const numericInvitationId = Number(invitationId);
    if (!Number.isFinite(numericInvitationId) || numericInvitationId <= 0) {
        return;
    }

    const oppositeAction = action === 'accept_invitation'
        ? 'decline_invitation'
        : 'accept_invitation';

    offlineQueue.value = offlineQueue.value.filter((operation) => !(
        (operation.action === action || operation.action === oppositeAction)
        && Number(operation?.payload?.invitation_id) === numericInvitationId
    ));
    persistQueue();

    enqueueOperation({
        action,
        payload: {
            invitation_id: numericInvitationId,
        },
    });
}

function queueSetMine(linkId) {
    const numericLinkId = Number(linkId);
    if (!Number.isFinite(numericLinkId) || numericLinkId <= 0) {
        return;
    }

    offlineQueue.value = offlineQueue.value.filter((operation) => !(
        operation.action === 'set_mine'
        && Number(operation?.payload?.link_id) === numericLinkId
    ));
    persistQueue();

    enqueueOperation({
        action: 'set_mine',
        payload: {
            link_id: numericLinkId,
        },
    });
}

function queueBreakLink(linkId) {
    const numericLinkId = Number(linkId);
    if (!Number.isFinite(numericLinkId) || numericLinkId <= 0) {
        return;
    }

    offlineQueue.value = offlineQueue.value.filter((operation) => !(
        operation.action === 'break_link'
        && Number(operation?.payload?.link_id) === numericLinkId
    ));
    persistQueue();

    enqueueOperation({
        action: 'break_link',
        payload: {
            link_id: numericLinkId,
        },
    });
}

function normalizeProfileTagInput(tag) {
    return String(tag ?? '').trim().replace(/^@+/, '').toLowerCase();
}

function queueProfileUpdate(payload) {
    const normalizedPayload = {
        name: String(payload?.name ?? '').trim(),
        tag: normalizeProfileTagInput(payload?.tag),
        email: String(payload?.email ?? '').trim(),
    };

    if (!normalizedPayload.name || !normalizedPayload.tag || !normalizedPayload.email) {
        return;
    }

    const existingIndex = findQueueIndexFromEnd(
        (operation) => operation.action === 'update_profile',
    );

    if (existingIndex !== -1 && !isOperationBeingSynced(offlineQueue.value[existingIndex]?.op_id)) {
        offlineQueue.value[existingIndex] = {
            ...offlineQueue.value[existingIndex],
            payload: {
                ...offlineQueue.value[existingIndex].payload,
                ...normalizedPayload,
            },
        };
        persistQueue();
        return;
    }

    enqueueOperation({
        action: 'update_profile',
        payload: normalizedPayload,
    });
}

function queuePasswordUpdate(payload) {
    const normalizedPayload = {
        current_password: String(payload?.current_password ?? ''),
        password: String(payload?.password ?? ''),
        password_confirmation: String(payload?.password_confirmation ?? ''),
    };

    if (!normalizedPayload.current_password || !normalizedPayload.password || !normalizedPayload.password_confirmation) {
        return;
    }

    const existingIndex = findQueueIndexFromEnd(
        (operation) => operation.action === 'update_password',
    );

    if (existingIndex !== -1 && !isOperationBeingSynced(offlineQueue.value[existingIndex]?.op_id)) {
        offlineQueue.value[existingIndex] = {
            ...offlineQueue.value[existingIndex],
            payload: normalizedPayload,
            volatile: true,
        };
        persistQueue();
        return;
    }

    enqueueOperation({
        action: 'update_password',
        payload: normalizedPayload,
        // Passwords are kept only in memory for this session.
        volatile: true,
    });
}

function clearSwipeUndoTimer() {
    if (!swipeUndoTimer) {
        return;
    }

    clearTimeout(swipeUndoTimer);
    swipeUndoTimer = null;
}

function hasPendingSwipeAction(ownerId, type, linkId = undefined) {
    if (!swipeUndoState.value) {
        return false;
    }

    const normalizedLinkId = resolveLinkIdForOwner(ownerId, linkId);

    return Number(swipeUndoState.value.ownerId) === Number(ownerId)
        && String(swipeUndoState.value.type) === String(type)
        && normalizeLinkId(swipeUndoState.value.linkId) === normalizedLinkId;
}

function isSwipeStateForItem(state, ownerId, type, itemId, linkId = undefined) {
    if (!state) {
        return false;
    }

    const normalizedLinkId = resolveLinkIdForOwner(ownerId, linkId);
    if (
        Number(state.ownerId) !== Number(ownerId)
        || String(state.type) !== String(type)
        || normalizeLinkId(state.linkId) !== normalizedLinkId
    ) {
        return false;
    }

    if (state.action === 'toggle' || state.action === 'remove') {
        return Number(state.item?.id) === Number(itemId);
    }

    if (state.action === 'remove_completed_batch') {
        if (Number(state.primary?.item?.id) === Number(itemId)) {
            return true;
        }

        return (state.removed ?? []).some((entry) => Number(entry?.item?.id) === Number(itemId));
    }

    return false;
}

function hasPendingItemOperation(ownerId, type, itemId, linkId = undefined, excludeOpId = null) {
    const normalizedLinkId = resolveLinkIdForOwner(ownerId, linkId);
    const numericItemId = Number(itemId);

    return offlineQueue.value.some((operation) => {
        if (excludeOpId && String(operation.op_id) === String(excludeOpId)) {
            return false;
        }

        return Number(operation.owner_id) === Number(ownerId)
            && String(operation.type) === String(type)
            && normalizeLinkId(operation.link_id) === normalizedLinkId
            && Number(operation.item_id) === numericItemId
            && (operation.action === 'create' || operation.action === 'update' || operation.action === 'delete');
    });
}

function rewriteSwipeUndoItemId(previousId, nextId, ownerId, type, linkId = undefined) {
    const state = swipeUndoState.value;
    if (!state) {
        return;
    }

    const normalizedLinkId = resolveLinkIdForOwner(ownerId, linkId);
    if (
        Number(state.ownerId) !== Number(ownerId)
        || String(state.type) !== String(type)
        || normalizeLinkId(state.linkId) !== normalizedLinkId
    ) {
        return;
    }

    const oldId = Number(previousId);
    const newId = Number(nextId);
    if (!Number.isFinite(oldId) || !Number.isFinite(newId) || oldId === newId) {
        return;
    }

    if (state.action === 'toggle' || state.action === 'remove') {
        if (Number(state.item?.id) === oldId) {
            state.item = {
                ...state.item,
                id: newId,
            };
        }
        return;
    }

    if (state.action === 'remove_completed_batch') {
        if (Number(state.primary?.item?.id) === oldId) {
            state.primary = {
                ...state.primary,
                item: {
                    ...state.primary.item,
                    id: newId,
                },
            };
        }

        state.removed = (state.removed ?? []).map((entry) => (
            Number(entry?.item?.id) === oldId
                ? {
                    ...entry,
                    item: {
                        ...entry.item,
                        id: newId,
                    },
                }
                : entry
        ));
    }
}

function stageSwipeAction(state) {
    if (!state) {
        return;
    }

    if (state.action === 'toggle') {
        const togglePayload = {
            is_completed: !!state.nextCompleted,
        };

        if (state.nextSortOrder !== undefined && state.nextSortOrder !== null) {
            togglePayload.sort_order = normalizeSortOrderValue(state.nextSortOrder, 1000);
        }

        queueUpdate(state.ownerId, state.type, state.item.id, togglePayload, state.linkId);
        return;
    }

    if (state.action === 'remove') {
        queueDelete(state.ownerId, state.type, state.item.id, state.linkId);
        return;
    }

    if (state.action === 'remove_completed_batch') {
        queueDelete(state.ownerId, state.type, state.primary.item.id, state.linkId);

        for (const removedEntry of state.removed ?? []) {
            queueDelete(state.ownerId, state.type, removedEntry.item.id, state.linkId);
        }
    }
}

async function finalizeSwipeAction(state, options = {}) {
    if (!state) {
        return;
    }

    const { background = false } = options;

    stageSwipeAction(state);
    const syncAndReloadSuggestions = async () => {
        await syncOfflineQueue();

        if (!hasPendingOperations(state.ownerId, state.type, state.linkId)) {
            await loadSuggestions(state.type);
        }
    };

    if (background) {
        syncAndReloadSuggestions().catch(() => {});
        return;
    }

    await syncAndReloadSuggestions();
}

async function flushSwipeUndoState(options = {}) {
    if (!swipeUndoState.value) {
        return;
    }

    const { background = true } = options;
    const pendingState = swipeUndoState.value;
    swipeUndoState.value = null;
    clearSwipeUndoTimer();
    await finalizeSwipeAction(pendingState, { background });
}

function startSwipeUndoState(state) {
    swipeUndoState.value = state;
    clearSwipeUndoTimer();

    swipeUndoTimer = window.setTimeout(() => {
        const pendingState = swipeUndoState.value;
        swipeUndoState.value = null;
        clearSwipeUndoTimer();

        if (!pendingState) {
            return;
        }

        finalizeSwipeAction(pendingState, { background: true }).catch(() => {});
    }, SWIPE_UNDO_WINDOW_MS);
}

function undoSwipeAction() {
    if (!swipeUndoState.value) {
        return;
    }

    const pendingState = swipeUndoState.value;
    swipeUndoState.value = null;
    clearSwipeUndoTimer();

    if (pendingState.action === 'toggle') {
        adjustXpProgress(
            pendingState.nextCompleted
                ? -XP_PROGRESS_PER_TOGGLE
                : XP_PROGRESS_PER_TOGGLE,
        );

        upsertLocalItem(pendingState.ownerId, pendingState.type, {
            ...pendingState.item,
            pending_sync: false,
        }, {
            linkId: pendingState.linkId,
        });
        return;
    }

    if (pendingState.action === 'remove') {
        applyLocalUpdate(pendingState.ownerId, pendingState.type, (items) => {
            const next = cloneItems(items);
            const insertIndex = Math.max(0, Math.min(Number(pendingState.previousIndex ?? 0), next.length));
            next.splice(insertIndex, 0, {
                ...pendingState.item,
                pending_sync: false,
            });
            return next;
        }, pendingState.linkId);
        return;
    }

    if (pendingState.action === 'remove_completed_batch') {
        applyLocalUpdate(pendingState.ownerId, pendingState.type, (items) => {
            const next = cloneItems(items);
            const removedEntries = [
                pendingState.primary,
                ...(pendingState.removed ?? []),
            ]
                .filter((entry) => entry && entry.item)
                .sort((left, right) => Number(right.previousIndex ?? 0) - Number(left.previousIndex ?? 0));

            for (const entry of removedEntries) {
                if (next.some((item) => Number(item.id) === Number(entry.item.id))) {
                    continue;
                }

                const insertIndex = Math.max(0, Math.min(Number(entry.previousIndex ?? 0), next.length));
                next.splice(insertIndex, 0, {
                    ...entry.item,
                    pending_sync: false,
                });
            }

            return next;
        }, pendingState.linkId);

        rollbackXpGainSource(
            pendingState.xpGainSourceId,
            pendingState.xpGainTotal,
        );
    }
}

function resolveEventPosition(event) {
    if (!event) {
        return null;
    }

    if ('touches' in event && event.touches?.length > 0) {
        return {
            x: Number(event.touches[0].clientX),
            y: Number(event.touches[0].clientY),
        };
    }

    if ('changedTouches' in event && event.changedTouches?.length > 0) {
        return {
            x: Number(event.changedTouches[0].clientX),
            y: Number(event.changedTouches[0].clientY),
        };
    }

    if (typeof event.clientX === 'number' && typeof event.clientY === 'number') {
        return {
            x: event.clientX,
            y: event.clientY,
        };
    }

    if (event.target instanceof Element) {
        const rect = event.target.getBoundingClientRect();
        return {
            x: rect.left + (rect.width / 2),
            y: rect.top + (rect.height / 2),
        };
    }

    return null;
}

function triggerDeleteBurst(event) {
    const point = resolveEventPosition(event);
    if (!point) {
        return;
    }

    const particles = Array.from({ length: 14 }, (_, index) => {
        const angle = ((Math.PI * 2) / 14) * index + ((Math.random() - 0.5) * 0.25);
        const distance = 10 + (Math.random() * 22);

        return {
            id: `${index}-${Math.random().toString(36).slice(2, 6)}`,
            dx: Math.cos(angle) * distance,
            dy: Math.sin(angle) * distance,
            delay: Math.round(Math.random() * 34),
        };
    });

    const burstId = nextDeleteFeedbackBurstId;
    nextDeleteFeedbackBurstId += 1;

    deleteFeedbackBursts.value = [
        ...deleteFeedbackBursts.value,
        {
            id: burstId,
            x: point.x,
            y: point.y,
            particles,
        },
    ];

    window.setTimeout(() => {
        deleteFeedbackBursts.value = deleteFeedbackBursts.value.filter((burst) => burst.id !== burstId);
    }, 420);
}

function getProgressBarTargetPoint() {
    if (isHtmlElement(progressBarRef.value)) {
        const rect = progressBarRef.value.getBoundingClientRect();
        const horizontalPadding = Math.min(12, rect.width * 0.18);
        const targetWidth = Math.max(8, rect.width - (horizontalPadding * 2));

        return {
            x: rect.left + horizontalPadding + (Math.random() * targetWidth),
            y: rect.top + (rect.height / 2),
        };
    }

    if (typeof window === 'undefined') {
        return { x: 0, y: 0 };
    }

    return {
        x: window.innerWidth / 2,
        y: Math.max(20, window.innerHeight - 92),
    };
}

function activateXpStars(starIds) {
    const idSet = new Set(starIds);
    xpStars.value = xpStars.value.map((star) => (idSet.has(star.id) ? { ...star, active: true } : star));
}

function spawnXpStars(originX, originY, removedCount, options = {}) {
    const totalRemoved = Math.max(1, Number(removedCount) || 1);
    const starCount = clampValue(Math.round(totalRemoved * 8), 28, 84);
    const totalGain = calculateBatchRemovalXpGain(totalRemoved);
    const gainPerStar = totalGain / starCount;
    const source = ensureXpGainSource(options?.sourceId, totalGain);
    const nextStars = [];

    for (let index = 0; index < starCount; index += 1) {
        const startX = originX + ((Math.random() - 0.5) * 42);
        const startY = originY + ((Math.random() - 0.5) * 28);
        const target = getProgressBarTargetPoint();
        const dx = target.x - startX;
        const dy = target.y - startY;
        const distance = Math.hypot(dx, dy);
        const duration = clampValue(Math.round(distance * 1.25), XP_STAR_MIN_DURATION_MS, XP_STAR_MAX_DURATION_MS);
        const delay = Math.round(Math.random() * 210);
        const starId = nextXpStarId;
        nextXpStarId += 1;

        const star = {
            id: starId,
            x: startX,
            y: startY,
            dx,
            dy,
            duration,
            delay,
            rotate: Math.round((Math.random() - 0.5) * 140),
            size: 4 + Math.round(Math.random() * 5),
            active: false,
        };

        nextStars.push(star);

        const timeoutId = scheduleEffectTimeout(() => {
            if (source) {
                source.pendingTimeoutIds.delete(timeoutId);
                source.pendingStarIds.delete(starId);
            }

            xpStars.value = xpStars.value.filter((entry) => entry.id !== starId);
            playXpGainSound();
            adjustXpProgress(gainPerStar);

            if (source) {
                source.grantedGain = Math.min(source.totalGain, source.grantedGain + gainPerStar);
                if (source.pendingTimeoutIds.size === 0) {
                    scheduleXpGainSourceCleanup(source.id);
                }
            }
        }, delay + duration);

        if (source) {
            source.pendingTimeoutIds.add(timeoutId);
            source.pendingStarIds.add(starId);
        }
    }

    xpStars.value = [...xpStars.value, ...nextStars];

    const activate = () => activateXpStars(nextStars.map((star) => star.id));
    if (typeof window !== 'undefined' && typeof window.requestAnimationFrame === 'function') {
        window.requestAnimationFrame(activate);
        return;
    }

    activate();
}

function resetMegaCardImpactAnimation() {
    if (!megaCardImpactAnimation || typeof megaCardImpactAnimation.cancel !== 'function') {
        megaCardImpactAnimation = null;
        return;
    }

    try {
        megaCardImpactAnimation.cancel();
    } catch {
        // ignore cleanup errors
    }

    megaCardImpactAnimation = null;
}

function cubicBezierPoint(progress, point1, point2) {
    const clampedProgress = clampValue(Number(progress) || 0, 0, 1);
    const inverse = 1 - clampedProgress;

    return (
        (3 * inverse * inverse * clampedProgress * point1)
        + (3 * inverse * clampedProgress * clampedProgress * point2)
        + (clampedProgress * clampedProgress * clampedProgress)
    );
}

function incomingTravelEasedProgress(timeFraction) {
    const normalizedTime = clampValue(Number(timeFraction) || 0, 0, 1);
    if (normalizedTime <= 0 || normalizedTime >= 1) {
        return normalizedTime;
    }

    let min = 0;
    let max = 1;
    let curveProgress = normalizedTime;

    for (let step = 0; step < 12; step += 1) {
        curveProgress = (min + max) / 2;
        const x = cubicBezierPoint(
            curveProgress,
            BATCH_CINEMATIC_INCOMING_EASING.x1,
            BATCH_CINEMATIC_INCOMING_EASING.x2,
        );

        if (x < normalizedTime) {
            min = curveProgress;
        } else {
            max = curveProgress;
        }
    }

    return cubicBezierPoint(
        curveProgress,
        BATCH_CINEMATIC_INCOMING_EASING.y1,
        BATCH_CINEMATIC_INCOMING_EASING.y2,
    );
}

function incomingCollisionState(scene, incomingCard, timeFraction) {
    const easedProgress = incomingTravelEasedProgress(timeFraction);
    const targetScale = clampValue(
        Number(incomingCard.targetScale ?? BATCH_CINEMATIC_INCOMING_TARGET_SCALE),
        0.2,
        1,
    );
    const scale = 1 - ((1 - targetScale) * easedProgress);

    const incomingCenterX = incomingCard.x + (incomingCard.width / 2) + (incomingCard.dx * easedProgress);
    const incomingCenterY = incomingCard.y + (incomingCard.height / 2) + (incomingCard.dy * easedProgress);
    const megaCenterX = scene.x + (scene.width / 2);
    const megaCenterY = scene.y + (scene.height / 2);

    const halfIncomingWidth = (incomingCard.width * scale) / 2;
    const halfIncomingHeight = (incomingCard.height * scale) / 2;
    const halfMegaWidth = scene.width / 2;
    const halfMegaHeight = scene.height / 2;

    const deltaX = Math.abs(incomingCenterX - megaCenterX);
    const deltaY = Math.abs(incomingCenterY - megaCenterY);
    const overlap = deltaX <= (halfIncomingWidth + halfMegaWidth)
        && deltaY <= (halfIncomingHeight + halfMegaHeight);

    return {
        overlap,
        distanceSquared: (deltaX * deltaX) + (deltaY * deltaY),
    };
}

function calculateIncomingImpactOffsetMs(scene, incomingCard) {
    const sampleCount = Math.max(24, Number(BATCH_CINEMATIC_IMPACT_SAMPLE_COUNT) || 24);
    const refineSteps = Math.max(4, Number(BATCH_CINEMATIC_IMPACT_REFINE_STEPS) || 4);
    const cardDuration = Math.max(1, Number(incomingCard.duration) || 1);

    let previousTimeFraction = 0;
    let previousState = incomingCollisionState(scene, incomingCard, 0);
    let nearestTimeFraction = 0;
    let nearestDistanceSquared = previousState.distanceSquared;

    if (previousState.overlap) {
        return 0;
    }

    for (let sampleIndex = 1; sampleIndex <= sampleCount; sampleIndex += 1) {
        const timeFraction = sampleIndex / sampleCount;
        const state = incomingCollisionState(scene, incomingCard, timeFraction);

        if (state.distanceSquared < nearestDistanceSquared) {
            nearestDistanceSquared = state.distanceSquared;
            nearestTimeFraction = timeFraction;
        }

        if (!previousState.overlap && state.overlap) {
            let left = previousTimeFraction;
            let right = timeFraction;

            for (let refineIndex = 0; refineIndex < refineSteps; refineIndex += 1) {
                const middle = (left + right) / 2;
                const middleState = incomingCollisionState(scene, incomingCard, middle);
                if (middleState.overlap) {
                    right = middle;
                } else {
                    left = middle;
                }
            }

            return clampValue(Math.round(right * cardDuration), 0, cardDuration);
        }

        previousTimeFraction = timeFraction;
        previousState = state;
    }

    return clampValue(Math.round(nearestTimeFraction * cardDuration), 0, cardDuration);
}

function playMegaCardImpact(sceneId = null) {
    playDashboardSound('white_card_impact', {
        volume: 0.68,
        playbackRate: randomPlaybackRateBySemitone(1.35),
    });
    if (sceneId !== null) {
        intensifyMegaCardGlow(sceneId, 0.2);
    }

    const element = megaCardRef.value;
    if (!isHtmlElement(element) || typeof element.animate !== 'function') {
        return;
    }

    resetMegaCardImpactAnimation();
    const impactAnimation = element.animate(
        [
            { transform: 'translate3d(0, 0, 0) scale(1)' },
            { transform: 'translate3d(0, -13px, 0) scale(1.02)' },
            { transform: 'translate3d(0, 0, 0) scale(1)' },
        ],
        {
            duration: 190,
            easing: 'cubic-bezier(0.2, 0.88, 0.35, 1)',
        },
    );

    megaCardImpactAnimation = impactAnimation;
    impactAnimation.onfinish = () => {
        if (megaCardImpactAnimation === impactAnimation) {
            megaCardImpactAnimation = null;
        }
    };
    impactAnimation.oncancel = () => {
        if (megaCardImpactAnimation === impactAnimation) {
            megaCardImpactAnimation = null;
        }
    };
}

function buildBatchCollapseScene(type, completedEntries) {
    const positionedEntries = completedEntries
        .map((entry) => {
            const element = itemCardElements.get(itemCardRefKey(type, entry.item));
            if (!isHtmlElement(element)) {
                return null;
            }

            const rect = element.getBoundingClientRect();
            if (rect.width < 1 || rect.height < 1) {
                return null;
            }

            return {
                entry,
                rect,
            };
        })
        .filter(Boolean)
        .sort((left, right) => {
            const topDelta = left.rect.top - right.rect.top;
            if (topDelta !== 0) {
                return topDelta;
            }

            return left.rect.left - right.rect.left;
        });

    if (positionedEntries.length < 2) {
        return null;
    }

    const topCard = positionedEntries[0];

    const scene = {
        id: nextBatchCollapseSceneId++,
        x: topCard.rect.left,
        y: topCard.rect.top,
        width: topCard.rect.width,
        height: topCard.rect.height,
        whitening: false,
        incomingActive: false,
        glowing: false,
        glowBoost: 0,
        bursting: false,
        incoming: positionedEntries.slice(1).map((entry, index) => ({
            id: nextBatchCollapseIncomingId++,
            x: entry.rect.left,
            y: entry.rect.top,
            width: entry.rect.width,
            height: entry.rect.height,
            dx: (topCard.rect.left - entry.rect.left) + ((Math.random() - 0.5) * 10),
            dy: (topCard.rect.top - entry.rect.top) + ((Math.random() - 0.5) * 8),
            delay: index * 72,
            targetScale: BATCH_CINEMATIC_INCOMING_TARGET_SCALE,
            duration: clampValue(
                Math.round(
                    420
                    + (
                        Math.hypot(
                            topCard.rect.left - entry.rect.left,
                            topCard.rect.top - entry.rect.top,
                        ) * 0.85
                    ),
                ),
                460,
                780,
            ),
        })),
    };

    scene.incoming = scene.incoming.map((incomingCard) => ({
        ...incomingCard,
        impactAt: incomingCard.delay + calculateIncomingImpactOffsetMs(scene, incomingCard),
    }));

    return scene;
}

async function playBatchCollapseAnimation(type, completedEntries, options = {}) {
    const scene = buildBatchCollapseScene(type, completedEntries);
    if (!scene) {
        return false;
    }

    const xpGainSourceId = String(options?.xpGainSourceId ?? '').trim();
    const skipEpoch = animationSkipEpoch.value;
    let starsSpawned = false;
    const spawnSceneXpStars = () => {
        if (starsSpawned) {
            return;
        }

        starsSpawned = true;
        spawnXpStars(scene.x + (scene.width / 2), scene.y + (scene.height / 2), completedEntries.length, {
            sourceId: xpGainSourceId || null,
        });
    };
    const completeSceneImmediately = () => {
        if (!batchCollapseScene.value || batchCollapseScene.value.id !== scene.id) {
            resetMegaCardImpactAnimation();
            return;
        }

        batchCollapseScene.value.whitening = true;
        batchCollapseScene.value.glowing = true;
        batchCollapseScene.value.incomingActive = true;
        batchCollapseScene.value.bursting = true;
        spawnSceneXpStars();
        batchCollapseScene.value = null;
        resetMegaCardImpactAnimation();
    };

    resetMegaCardImpactAnimation();
    batchCollapseScene.value = scene;
    await nextTick();

    if (!batchCollapseScene.value || batchCollapseScene.value.id !== scene.id) {
        resetMegaCardImpactAnimation();
        return true;
    }

    batchCollapseScene.value.whitening = true;
    batchCollapseScene.value.glowing = true;
    batchCollapseScene.value.glowBoost = 0.08;
    playDashboardSound('white_card_appear', {
        volume: 0.76,
    });
    const skippedWhiten = await waitForMsOrAnimationSkip(BATCH_CINEMATIC_WHITEN_MS, skipEpoch);
    if (skippedWhiten) {
        completeSceneImmediately();
        return true;
    }

    if (!batchCollapseScene.value || batchCollapseScene.value.id !== scene.id) {
        resetMegaCardImpactAnimation();
        return true;
    }

    batchCollapseScene.value.incomingActive = true;
    for (const incomingCard of scene.incoming) {
        scheduleEffectTimeout(() => {
            if (!batchCollapseScene.value || batchCollapseScene.value.id !== scene.id) {
                return;
            }

            playMegaCardImpact(scene.id);
        }, incomingCard.impactAt);
    }

    const longestIncomingTravelMs = scene.incoming.reduce(
        (maxDelay, incomingCard) => Math.max(maxDelay, incomingCard.delay + incomingCard.duration),
        0,
    );
    const skippedTravel = await waitForMsOrAnimationSkip(longestIncomingTravelMs, skipEpoch);
    if (skippedTravel) {
        completeSceneImmediately();
        return true;
    }

    if (!batchCollapseScene.value || batchCollapseScene.value.id !== scene.id) {
        resetMegaCardImpactAnimation();
        return true;
    }

    const skippedGlow = await waitForMsOrAnimationSkip(BATCH_CINEMATIC_GLOW_MS, skipEpoch);
    if (skippedGlow) {
        completeSceneImmediately();
        return true;
    }

    if (!batchCollapseScene.value || batchCollapseScene.value.id !== scene.id) {
        resetMegaCardImpactAnimation();
        return true;
    }

    batchCollapseScene.value.bursting = true;
    spawnSceneXpStars();
    const skippedBurst = await waitForMsOrAnimationSkip(BATCH_CINEMATIC_BURST_MS, skipEpoch);
    if (skippedBurst) {
        completeSceneImmediately();
        return true;
    }

    if (batchCollapseScene.value && batchCollapseScene.value.id === scene.id) {
        batchCollapseScene.value = null;
    }
    resetMegaCardImpactAnimation();

    return true;
}

async function removeCompletedAfterSwipe(event = null) {
    if (!swipeUndoState.value || swipeUndoState.value.action !== 'remove') {
        return;
    }

    const activeState = swipeUndoState.value;
    if (!activeState.canRemoveCompletedBatch || batchRemovalAnimating.value) {
        return;
    }

    // Prevent undo timeout from finalizing single-item remove while cinematic batch remove is running.
    clearSwipeUndoTimer();

    const ownerId = Number(activeState.ownerId);
    const linkId = resolveLinkIdForOwner(ownerId, activeState.linkId);
    const type = String(activeState.type);
    const currentItems = readListFromCache(ownerId, type, linkId);

    const completedEntries = currentItems
        .map((entry, index) => ({
            item: { ...entry },
            previousIndex: index,
        }))
        .filter((entry) => entry.item.is_completed);

    if (completedEntries.length === 0) {
        return;
    }

    batchRemovalAnimating.value = true;
    const completedKeys = completedEntries.map((entry) => itemViewKey(entry.item));
    const useCinematicRemoval = completedEntries.length >= BATCH_CINEMATIC_REMOVE_THRESHOLD;
    let xpGainSourceId = null;
    let xpGainTotal = 0;

    if (useCinematicRemoval) {
        batchCollapseHiddenItemKeys.value = completedKeys;
        const candidateXpGainSourceId = buildXpGainSourceId();
        const played = await playBatchCollapseAnimation(type, completedEntries, {
            xpGainSourceId: candidateXpGainSourceId,
        });

        if (played) {
            xpGainSourceId = candidateXpGainSourceId;
            xpGainTotal = calculateBatchRemovalXpGain(completedEntries.length);
        } else {
            batchCollapseHiddenItemKeys.value = [];
            triggerDeleteBurst(event);
            batchRemovingItemKeys.value = completedKeys;
            await waitForMs(BATCH_REMOVE_CARD_ANIMATION_MS);
        }
    } else {
        triggerDeleteBurst(event);
        batchRemovingItemKeys.value = completedKeys;
        await waitForMs(BATCH_REMOVE_CARD_ANIMATION_MS);
    }

    if (swipeUndoState.value !== activeState || swipeUndoState.value?.action !== 'remove') {
        batchRemovingItemKeys.value = [];
        batchCollapseHiddenItemKeys.value = [];
        batchCollapseScene.value = null;
        resetMegaCardImpactAnimation();
        batchRemovalAnimating.value = false;
        return;
    }

    markItemsAsDeleted(ownerId, type, completedEntries.map((entry) => entry.item?.id), linkId);
    applyLocalUpdate(ownerId, type, (items) => items.filter((entry) => !entry.is_completed), linkId);
    batchRemovingItemKeys.value = [];
    batchCollapseHiddenItemKeys.value = [];
    batchCollapseScene.value = null;
    resetMegaCardImpactAnimation();
    batchRemovalAnimating.value = false;

    startSwipeUndoState({
        action: 'remove_completed_batch',
        ownerId,
        linkId,
        type,
        primary: {
            item: { ...activeState.item },
            previousIndex: Number(activeState.previousIndex ?? 0),
        },
        removed: completedEntries,
        xpGainSourceId,
        xpGainTotal,
    });
}

function shouldDropOperationOnClientError(operation, statusCode) {
    const action = String(operation?.action ?? '');
    if (statusCode < 400 || statusCode >= 500 || statusCode === 429) {
        return false;
    }

    if (action === 'delete') {
        return statusCode === 404;
    }

    if (action === 'update') {
        return statusCode === 404;
    }

    return [
        'dismiss_suggestion',
        'reset_suggestion',
        'set_default_owner',
        'send_invitation',
        'accept_invitation',
        'decline_invitation',
        'set_mine',
        'break_link',
        'update_profile',
        'update_password',
        'sync_gamification',
    ].includes(action);
}

function shouldRefreshStateAfterDroppedOperation(action) {
    return [
        'set_default_owner',
        'accept_invitation',
        'decline_invitation',
        'set_mine',
        'break_link',
    ].includes(String(action ?? ''));
}

function isTempItemMutationOperation(operation) {
    const action = String(operation?.action ?? '');
    if (action !== 'update' && action !== 'delete') {
        return false;
    }

    const itemId = Number(operation?.item_id);
    return Number.isFinite(itemId) && itemId < 0;
}

function hasQueuedCreateForOperation(operation) {
    const tempItemId = Number(operation?.item_id);
    if (!Number.isFinite(tempItemId) || tempItemId >= 0) {
        return false;
    }

    return offlineQueue.value.some((queuedOperation) => (
        queuedOperation.action === 'create'
        && Number(queuedOperation.owner_id) === Number(operation?.owner_id)
        && String(queuedOperation.type) === String(operation?.type)
        && normalizeLinkId(queuedOperation.link_id) === normalizeLinkId(operation?.link_id)
        && Number(queuedOperation.item_id) === tempItemId
    ));
}

function collectSyncChunkOperations() {
    const chunkOperations = [];
    const staleTempMutationOpIds = [];

    for (const operation of offlineQueue.value) {
        if (chunkOperations.length >= SYNC_CHUNK_MAX_OPERATIONS) {
            break;
        }

        if (isTempItemMutationOperation(operation)) {
            if (!hasQueuedCreateForOperation(operation)) {
                staleTempMutationOpIds.push(String(operation?.op_id ?? ''));
            }

            continue;
        }

        if (
            operation.action === 'update'
            && shouldCoalesceUpdatePayload(operation.payload)
        ) {
            const quietRemainingMs = getQueuedUpdateQuietRemainingMs();
            if (quietRemainingMs > 0) {
                scheduleQueuedUpdateSync(quietRemainingMs);
                if (chunkOperations.length === 0) {
                    return [];
                }
                break;
            }
        }

        chunkOperations.push(operation);
    }

    for (const staleOpId of staleTempMutationOpIds) {
        dropQueueOperation(staleOpId);
    }

    return chunkOperations;
}

function buildSyncChunkPayload(chunkOperations) {
    return chunkOperations.map((operation) => ({
        op_id: String(operation?.op_id ?? ''),
        action: String(operation?.action ?? ''),
        owner_id: operation?.owner_id ?? null,
        link_id: normalizeLinkId(operation?.link_id),
        type: operation?.type ?? null,
        item_id: operation?.item_id ?? null,
        payload: operation?.payload ?? {},
    }));
}

function applySuccessfulSyncedOperation(operation, resultData) {
    const action = String(operation?.action ?? '');

    if (action === 'create') {
        const syncedItemResponse = resultData?.item;
        if (!syncedItemResponse || typeof syncedItemResponse !== 'object') {
            return;
        }

        const syncedItem = normalizeItem(syncedItemResponse, `srv-${syncedItemResponse.id}`, {
            ownerIdOverride: operation.owner_id,
            linkIdOverride: operation.link_id,
        });
        const previousTempId = Number(operation.item_id);
        const normalizedLinkId = normalizeLinkId(operation.link_id);
        const hasQueuedDeleteIntent = offlineQueue.value.some((queuedOperation) => (
            queuedOperation.action === 'delete'
            && Number(queuedOperation.owner_id) === Number(operation.owner_id)
            && String(queuedOperation.type) === String(operation.type)
            && normalizeLinkId(queuedOperation.link_id) === normalizedLinkId
            && Number(queuedOperation.item_id) === previousTempId
        ));
        const hasSwipeDeleteIntent = Boolean(
            swipeUndoState.value?.action === 'remove'
            && isSwipeStateForItem(
                swipeUndoState.value,
                operation.owner_id,
                operation.type,
                previousTempId,
                operation.link_id,
            ),
        );

        if (hasQueuedDeleteIntent || hasSwipeDeleteIntent) {
            markItemsAsDeleted(
                operation.owner_id,
                operation.type,
                [syncedItem.id],
                operation.link_id,
                { deletedAtMs: Date.now() },
            );
        }

        if (!hasQueuedDeleteIntent && !hasSwipeDeleteIntent) {
            applyLocalUpdate(operation.owner_id, operation.type, (items) => {
                const next = cloneItems(items);
                const index = next.findIndex((entry) => Number(entry.id) === previousTempId);

                if (index === -1) {
                    next.unshift({ ...syncedItem });
                } else {
                    const preservedLocalId = String(next[index]?.local_id ?? '').trim();
                    next[index] = {
                        ...syncedItem,
                        local_id: preservedLocalId || syncedItem.local_id,
                    };
                }

                return next;
            }, operation.link_id);
        }

        rewriteQueuedItemId(previousTempId, syncedItem.id);
        rewriteSwipeUndoItemId(
            previousTempId,
            syncedItem.id,
            operation.owner_id,
            operation.type,
            operation.link_id,
        );
        return;
    }

    if (action === 'update') {
        if (Number(operation.item_id) <= 0) {
            return;
        }

        const updatedItemResponse = resultData?.item;
        if (!updatedItemResponse || typeof updatedItemResponse !== 'object') {
            return;
        }

        const updatedItem = normalizeItem(updatedItemResponse, `srv-${updatedItemResponse.id}`, {
            ownerIdOverride: operation.owner_id,
            linkIdOverride: operation.link_id,
        });
        const hasNewerLocalIntent = hasPendingItemOperation(
            operation.owner_id,
            operation.type,
            operation.item_id,
            operation.link_id,
            operation.op_id,
        ) || isSwipeStateForItem(
            swipeUndoState.value,
            operation.owner_id,
            operation.type,
            operation.item_id,
            operation.link_id,
        );

        if (!hasNewerLocalIntent) {
            upsertLocalItem(operation.owner_id, operation.type, updatedItem, {
                linkId: operation.link_id,
            });
        }
        return;
    }

    if (action === 'delete') {
        if (Number(operation.item_id) > 0) {
            markItemsAsDeleted(
                operation.owner_id,
                operation.type,
                [operation.item_id],
                operation.link_id,
            );
        }
        return;
    }

    if (
        [
            'set_default_owner',
            'accept_invitation',
            'decline_invitation',
            'set_mine',
            'break_link',
        ].includes(action)
    ) {
        if (resultData && typeof resultData === 'object') {
            applyState(resultData, { syncSelection: true });
        }
        return;
    }

    if (action === 'update_profile') {
        localUser.name = resultData?.user?.name ?? localUser.name;
        localUser.tag = resultData?.user?.tag ?? localUser.tag;
        localUser.email = resultData?.user?.email ?? localUser.email;
        profileForm.name = localUser.name;
        profileForm.tag = localUser.tag;
        profileForm.email = localUser.email;
        return;
    }

    if (action === 'sync_gamification') {
        if (resultData?.gamification && typeof resultData.gamification === 'object') {
            applyGamificationStateFromServer(resultData.gamification, { force: true });
        }
    }
}

async function syncOfflineQueue() {
    if (
        queueSyncInProgress
        || offlineQueue.value.length === 0
        || browserOffline.value
        || Date.now() < queueRetryAt
    ) {
        return;
    }

    queueSyncInProgress = true;

    try {
        while (
            offlineQueue.value.length > 0
            && !browserOffline.value
            && Date.now() >= queueRetryAt
        ) {
            const chunkOperations = collectSyncChunkOperations();
            if (chunkOperations.length === 0) {
                break;
            }

            syncInFlightOperationIds = new Set(chunkOperations.map((operation) => String(operation.op_id)));

            try {
                const response = await requestApi(() => window.axios.post('api/sync/chunk', {
                    operations: buildSyncChunkPayload(chunkOperations),
                }));
                const chunkResults = Array.isArray(response.data?.results) ? response.data.results : [];
                const chunkResultByOpId = new Map(
                    chunkResults.map((result) => [String(result?.op_id ?? ''), result]),
                );

                let shouldStopSync = false;

                for (const operation of chunkOperations) {
                    const opId = String(operation?.op_id ?? '');
                    const result = chunkResultByOpId.get(opId);

                    if (!result) {
                        queueRetryAt = Date.now() + QUEUE_RETRY_DELAY_MS;
                        shouldStopSync = true;
                        break;
                    }

                    if (String(result?.status ?? '') === 'ok') {
                        applySuccessfulSyncedOperation(operation, result?.data ?? {});
                        dropQueueOperation(opId);
                        queueRetryAt = 0;
                        continue;
                    }

                    const statusCode = Number(result?.http_status ?? 0);
                    if (shouldDropOperationOnClientError(operation, statusCode)) {
                        dropQueueOperation(opId);
                        queueRetryAt = 0;

                        if (shouldRefreshStateAfterDroppedOperation(operation?.action)) {
                            refreshState(false, true).catch(() => {});
                        }

                        if (
                            statusCode === 422
                            && ['update_profile', 'update_password'].includes(String(operation?.action ?? ''))
                        ) {
                            showError({
                                response: {
                                    status: statusCode,
                                    data: {
                                        message: String(result?.message ?? ''),
                                        errors: result?.errors ?? {},
                                    },
                                },
                            });
                        }

                        continue;
                    }

                    queueRetryAt = Date.now() + QUEUE_RETRY_DELAY_MS;
                    shouldStopSync = true;
                    break;
                }

                if (shouldStopSync) {
                    break;
                }
            } catch (error) {
                if (isRetriableRequestError(error)) {
                    queueRetryAt = Date.now() + QUEUE_RETRY_DELAY_MS;
                    break;
                }

                const statusCode = Number(error?.response?.status ?? 0);
                const firstOperation = chunkOperations[0];
                if (firstOperation && shouldDropOperationOnClientError(firstOperation, statusCode)) {
                    dropQueueOperation(firstOperation.op_id);
                    queueRetryAt = 0;

                    if (shouldRefreshStateAfterDroppedOperation(firstOperation?.action)) {
                        refreshState(false, true).catch(() => {});
                    }

                    continue;
                }

                queueRetryAt = Date.now() + QUEUE_RETRY_DELAY_MS;
                break;
            } finally {
                syncInFlightOperationIds.clear();
            }
        }
    } finally {
        syncInFlightOperationIds.clear();
        queueSyncInProgress = false;
    }
}

function formatDueAt(isoValue) {
    if (!isoValue) {
        return 'Без дедлайна';
    }

    return new Intl.DateTimeFormat('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(isoValue));
}

async function cycleTodoPriority(item) {
    if (item?.type !== 'todo' || item?.is_completed) {
        return;
    }

    const currentPriority = getTodoPriority(item);
    const targetPriority = nextTodoPriority(currentPriority);
    const { ownerId, linkId } = resolveItemContext(item);

    resetMessages();

    upsertLocalItem(ownerId, 'todo', {
        ...item,
        priority: targetPriority,
        pending_sync: true,
        updated_at: new Date().toISOString(),
    }, {
        linkId,
    });
    queueUpdate(ownerId, 'todo', item.id, {
        priority: targetPriority,
    }, linkId);

    await syncOfflineQueue();

    if (!hasPendingOperations(ownerId, 'todo', linkId)) {
        await loadSuggestions('todo');
    }
}

async function applySuggestionToList(type, suggestion) {
    const text = String(suggestion?.suggested_text ?? '').trim();
    if (!text) {
        return;
    }

    resetMessages();

    await createItemOptimistically(type, text);
    removeSuggestionFromView(type, suggestion);

    if (!hasPendingOperations(selectedOwnerId.value, type, selectedListLinkId.value)) {
        await loadSuggestions(type);
    }
}

async function dismissSuggestion(type, suggestion) {
    const suggestionKey = getSuggestionKey(suggestion);
    if (!suggestionKey) {
        return;
    }

    resetMessages();
    removeSuggestionFromView(type, suggestion);

    queueSuggestionDismiss(
        selectedOwnerId.value,
        type,
        suggestionKey,
        Number(suggestion?.average_interval_seconds ?? 0),
        selectedListLinkId.value,
    );
    syncOfflineQueue().catch(() => {});
}

function toInputDatetime(isoValue) {
    if (!isoValue) {
        return '';
    }

    const date = new Date(isoValue);
    const pad = (value) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function toIsoDatetime(localDatetime) {
    if (!localDatetime) {
        return null;
    }

    return new Date(localDatetime).toISOString();
}

function resolveItemContext(item, fallbackOwnerId = selectedOwnerId.value) {
    const ownerId = Number(item?.owner_id ?? fallbackOwnerId);
    return {
        ownerId,
        linkId: resolveLinkIdForOwner(ownerId, item?.list_link_id),
    };
}


function openTodoDueModal(target, initialValue = '') {
    todoItemDueTarget.value = target;
    todoDueModalValue.value = initialValue;
    todoDueModalOpen.value = true;
}

function closeTodoDueModal() {
    todoDueModalOpen.value = false;
    todoDueModalValue.value = '';
    todoItemDueTarget.value = null;
}


function openTodoItemDuePicker(item) {
    const { ownerId, linkId } = resolveItemContext(item);
    const itemId = Number(item?.id);
    if (!Number.isFinite(ownerId) || !Number.isFinite(itemId)) {
        return;
    }

    const target = {
        ownerId,
        linkId,
        itemId,
    };
    const initialValue = toInputDatetime(item?.due_at ?? null);

    // Always use modal picker for reliable behavior across desktop/mobile browsers.
    openTodoDueModal(target, initialValue);
}

async function applyTodoDueValue(target, localDatetime) {
    if (!target) {
        return;
    }

    const ownerId = Number(target.ownerId);
    const linkId = resolveLinkIdForOwner(ownerId, target.linkId);
    const itemId = Number(target.itemId);
    if (!Number.isFinite(ownerId) || !Number.isFinite(itemId)) {
        return;
    }

    const currentItem = readListFromCache(ownerId, 'todo', linkId)
        .find((entry) => Number(entry.id) === itemId);

    if (!currentItem) {
        return;
    }

    const nextDueAt = toIsoDatetime(localDatetime);
    const currentDueAt = currentItem.due_at ? new Date(currentItem.due_at).toISOString() : null;

    if (normalizeComparableValue(currentDueAt) === normalizeComparableValue(nextDueAt)) {
        return;
    }

    resetMessages();

    upsertLocalItem(ownerId, 'todo', {
        ...currentItem,
        due_at: nextDueAt,
        pending_sync: true,
        updated_at: new Date().toISOString(),
    }, {
        linkId,
    });
    queueUpdate(ownerId, 'todo', currentItem.id, {
        due_at: nextDueAt,
    }, linkId);

    await syncOfflineQueue();

    if (!hasPendingOperations(ownerId, 'todo', linkId)) {
        await loadSuggestions('todo');
    }
}

async function applyTodoItemDuePickerValue() {
    const target = todoItemDueTarget.value;
    const value = todoItemDuePickerValue.value;
    todoItemDueTarget.value = null;
    await applyTodoDueValue(target, value);
}

async function saveTodoDueModal() {
    const target = todoItemDueTarget.value;
    const value = todoDueModalValue.value;
    closeTodoDueModal();
    await applyTodoDueValue(target, value);
}

async function clearTodoDueModal() {
    const target = todoItemDueTarget.value;
    closeTodoDueModal();
    await applyTodoDueValue(target, '');
}

function removeSuggestionFromView(type, suggestion) {
    const suggestionKey = getSuggestionKey(suggestion);
    if (!suggestionKey) {
        return;
    }

    const ownerId = Number(selectedOwnerId.value);
    const linkId = selectedListLinkId.value;

    if (type === 'product') {
        const nextSuggestions = productSuggestions.value.filter(
            (entry) => getSuggestionKey(entry) !== suggestionKey,
        );
        writeSuggestionsToCache(ownerId, type, nextSuggestions, linkId);
        return;
    }

    const nextSuggestions = todoSuggestions.value.filter(
        (entry) => getSuggestionKey(entry) !== suggestionKey,
    );
    writeSuggestionsToCache(ownerId, type, nextSuggestions, linkId);
}

async function createItemOptimistically(type, text, dueAt = null, options = {}) {
    const trimmed = String(text ?? '').trim();
    if (!trimmed) {
        return;
    }

    const ownerId = Number(selectedOwnerId.value);
    const linkId = selectedListLinkId.value;
    const parsedProduct = type === 'product'
        ? parseProductTextPayload(trimmed)
        : null;
    const normalizedText = type === 'product'
        ? String(parsedProduct?.text ?? trimmed).trim()
        : trimmed;
    const normalizedQuantity = type === 'product'
        ? normalizeQuantityInput(
            options.quantity !== undefined
                ? options.quantity
                : parsedProduct?.quantity
        )
        : null;
    const normalizedUnit = type === 'product' && normalizedQuantity !== null
        ? normalizeUnitInput(
            options.unit !== undefined
                ? options.unit
                : parsedProduct?.unit
        )
        : null;

    if (normalizedText === '') {
        return;
    }

    const nextSortOrder = nextSortOrderForLocalList(ownerId, type, false, linkId);
    const optimisticItem = createOptimisticItem({
        ownerId,
        type,
        text: normalizedText,
        dueAt: type === 'todo' ? (dueAt ?? null) : null,
        priority: type === 'todo' ? normalizeTodoPriority(options.priority, inferTodoPriorityFromDueAt(dueAt)) : null,
        quantity: normalizedQuantity,
        unit: normalizedUnit,
        sortOrder: nextSortOrder,
        linkId,
    });

    upsertLocalItem(ownerId, type, optimisticItem, { atTop: true, linkId });
    queueCreate(ownerId, type, optimisticItem, linkId);
    await syncOfflineQueue();
}

function applyState(state, options = {}) {
    const { syncSelection = false } = options;
    const normalizedState = normalizeSyncStatePayload(state);

    pendingInvitationsCount.value = normalizedState.pending_invitations_count;
    invitations.value = normalizedState.invitations;
    links.value = normalizedState.links;
    listOptions.value = normalizedState.list_options;

    const defaultOwnerId = Number(normalizedState.default_owner_id ?? localUser.id);
    const defaultExists = listOptions.value.some((option) => Number(option.owner_id) === defaultOwnerId);
    const selectedExists = listOptions.value.some((option) => Number(option.owner_id) === Number(selectedOwnerId.value));

    if (defaultExists) {
        lastPersistedOwnerId = defaultOwnerId;
        persistLocalDefaultOwner(defaultOwnerId);
    }

    if (syncSelection && defaultExists) {
        selectedOwnerId.value = defaultOwnerId;
    } else if (!selectedExists && defaultExists) {
        selectedOwnerId.value = defaultOwnerId;
    } else if (!selectedExists) {
        selectedOwnerId.value = localUser.id;
    }

    if (normalizedState.gamification) {
        applyGamificationStateFromServer(normalizedState.gamification);
    }
    persistCurrentSyncStateCache();
}

async function refreshState(showErrors = false, syncSelection = false) {
    if (browserOffline.value) {
        applyCachedSyncState(syncSelection);
        return;
    }

    try {
        const response = await requestApi(() => window.axios.get('api/sync/state'));
        applyState(response.data, { syncSelection });
    } catch (error) {
        if (isConnectivityError(error)) {
            applyCachedSyncState(syncSelection);
            return;
        }

        if (showErrors) {
            showError(error);
        }
    }
}

async function loadItems(type, showErrors = false, ownerIdOverride = null, linkIdOverride = undefined) {
    if (itemsLoading[type]) {
        return;
    }

    itemsLoading[type] = true;
    const ownerId = Number(ownerIdOverride ?? selectedOwnerId.value);
    const linkId = resolveLinkIdForOwner(ownerId, linkIdOverride);
    const cachedBeforeRequest = readListFromCache(ownerId, type, linkId);
    const requestSyncVersion = getListSyncVersion(ownerId, type, linkId);
    const assignCached = () => {
        if (!isCurrentListContext(ownerId, linkId)) {
            return;
        }

        const cached = readListFromCache(ownerId, type, linkId);
        setVisibleItems(type, cached);
    };

    try {
        if (browserOffline.value || !serverReachable.value || hasListSyncConflict(ownerId, type, linkId)) {
            assignCached();
            return;
        }

        const response = await requestApi(() => window.axios.get('api/items', {
            params: {
                owner_id: ownerId,
                link_id: linkId,
                type,
            },
        }));

        if (hasListSyncConflict(ownerId, type, linkId, requestSyncVersion)) {
            assignCached();
            return;
        }

        const normalizedItems = normalizeItems(response.data.items ?? [], cachedBeforeRequest, {
            ownerIdOverride: ownerId,
            linkIdOverride: linkId,
        });
        const filteredItems = readFilteredServerItems(ownerId, type, normalizedItems, linkId);

        if (hasListSyncConflict(ownerId, type, linkId, requestSyncVersion)) {
            assignCached();
            return;
        }

        writeListToCache(ownerId, type, filteredItems, linkId);
    } catch (error) {
        if (isConnectivityError(error)) {
            assignCached();
            return;
        }

        if (showErrors) {
            showError(error);
        }
    } finally {
        itemsLoading[type] = false;
    }
}

async function loadSuggestions(type, showErrors = false) {
    if (suggestionsLoading[type]) {
        return;
    }

    const ownerId = Number(selectedOwnerId.value);
    const linkId = selectedListLinkId.value;
    const assignCached = () => {
        const cachedSuggestions = readSuggestionsFromCache(ownerId, type, linkId);
        setVisibleSuggestions(type, cachedSuggestions);
    };

    assignCached();

    if (
        browserOffline.value
        || !serverReachable.value
        || hasPendingOperations(ownerId, type, linkId)
        || hasPendingSwipeAction(ownerId, type, linkId)
    ) {
        return;
    }

    suggestionsLoading[type] = true;

    try {
        const response = await requestApi(() => window.axios.get('api/items/suggestions', {
            params: {
                owner_id: ownerId,
                link_id: linkId,
                type,
                limit: 6,
            },
        }));
        writeSuggestionsToCache(ownerId, type, response.data.suggestions ?? [], linkId);
    } catch (error) {
        if (isConnectivityError(error)) {
            assignCached();
            return;
        }

        if (showErrors) {
            showError(error);
        }
    } finally {
        suggestionsLoading[type] = false;
    }
}

async function loadActiveTabItems() {
    if (activeTab.value === 'products') {
        await loadItems('product');
        return;
    }

    if (activeTab.value === 'todos') {
        await loadItems('todo');
    }
}

async function loadActiveTabSuggestions() {
    if (activeTab.value === 'products') {
        await loadSuggestions('product');
        return;
    }

    if (activeTab.value === 'todos') {
        await loadSuggestions('todo');
    }
}

async function loadAllItems() {
    await Promise.all([loadItems('product'), loadItems('todo')]);
}

async function loadAllSuggestions() {
    await Promise.all([loadSuggestions('product'), loadSuggestions('todo')]);
}

async function loadProductSuggestionStats(showErrors = false) {
    if (productSuggestionStatsLoading.value) {
        return;
    }

    const ownerId = Number(selectedOwnerId.value);
    const linkId = selectedListLinkId.value;
    const assignCached = () => {
        const cachedPayload = readProductStatsFromCache(ownerId, linkId);
        productSuggestionStats.value = cachedPayload.stats;
        productStatsSummary.value = cachedPayload.summary;
    };

    assignCached();

    if (browserOffline.value || !serverReachable.value) {
        return;
    }

    productSuggestionStatsLoading.value = true;

    try {
        const response = await requestApi(() => window.axios.get('api/items/suggestions/stats', {
            params: {
                owner_id: ownerId,
                link_id: linkId,
                limit: 50,
            },
        }));
        writeProductStatsToCache(ownerId, response.data ?? {}, linkId);
    } catch (error) {
        if (isConnectivityError(error)) {
            assignCached();
            return;
        }

        if (showErrors) {
            showError(error);
        }
    } finally {
        productSuggestionStatsLoading.value = false;
    }
}

function formatProductStatsInterval(seconds) {
    const numeric = Number(seconds);
    if (!Number.isFinite(numeric) || numeric <= 0) {
        return '\u2014';
    }

    return formatIntervalSeconds(numeric);
}

function formatProductStatsDate(isoValue) {
    if (!isoValue) {
        return '\u2014';
    }

    const parsed = new Date(isoValue);
    if (Number.isNaN(parsed.getTime())) {
        return '\u2014';
    }

    return new Intl.DateTimeFormat('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(parsed);
}

function formatProductStatsSummaryNumber(value) {
    const numeric = Number(value);
    if (!Number.isFinite(numeric) || numeric <= 0) {
        return '0';
    }

    return new Intl.NumberFormat('ru-RU').format(Math.round(numeric));
}

function isResettingSuggestionKey(suggestionKey) {
    return resettingSuggestionKeys.value.includes(String(suggestionKey ?? ''));
}

async function openProductStatsModal() {
    productStatsModalOpen.value = true;
    await loadProductSuggestionStats(false);
}

async function resetProductSuggestionStatsRow(entry) {
    const suggestionKey = String(entry?.suggestion_key ?? '').trim();
    if (!suggestionKey || isResettingSuggestionKey(suggestionKey)) {
        return;
    }

    resettingSuggestionKeys.value = [...resettingSuggestionKeys.value, suggestionKey];
    try {
        const ownerId = Number(selectedOwnerId.value);
        const linkId = selectedListLinkId.value;
        const cachedPayload = readProductStatsFromCache(ownerId, linkId);

        cachedPayload.stats = cachedPayload.stats.map((statsEntry) => (
            String(statsEntry?.suggestion_key ?? '') === suggestionKey
                ? {
                    ...statsEntry,
                    dismissed_count: 0,
                    hidden_until: null,
                    retired_at: null,
                    reset_at: new Date().toISOString(),
                }
                : statsEntry
        ));
        writeProductStatsToCache(ownerId, cachedPayload, linkId);

        queueSuggestionReset(ownerId, 'product', suggestionKey, linkId);
        syncOfflineQueue().catch(() => {});

        showStatus('\u0414\u0430\u043d\u043d\u044b\u0435 \u043f\u043e\u0434\u0441\u043a\u0430\u0437\u043e\u043a \u0441\u0431\u0440\u043e\u0448\u0435\u043d\u044b.');
    } finally {
        resettingSuggestionKeys.value = resettingSuggestionKeys.value.filter((key) => key !== suggestionKey);
    }
}

function beginEdit(item) {
    editingItemId.value = item.id;
    editingText.value = item.type === 'product'
        ? buildProductEditableText(item)
        : item.text;
    editingDueAt.value = item.type === 'todo' ? toInputDatetime(item.due_at) : '';

    nextTick(() => {
        const input = document.getElementById(`edit-item-${item.id}`);
        if (input instanceof HTMLInputElement) {
            input.focus();
            input.select();
        }
    });
}

function cancelEdit() {
    editingItemId.value = null;
    editingText.value = '';
    editingDueAt.value = '';
}

async function saveEdit(item) {
    if (editingItemId.value !== item.id) {
        return;
    }

    const parsedProduct = item.type === 'product'
        ? parseProductTextPayload(editingText.value)
        : null;
    const nextText = item.type === 'product'
        ? String(parsedProduct?.text ?? '').trim()
        : editingText.value.trim();
    const nextQuantity = item.type === 'product' ? (parsedProduct?.quantity ?? null) : null;
    const nextUnit = item.type === 'product' ? (parsedProduct?.unit ?? null) : null;
    const textChanged = nextText !== String(item.text ?? '').trim();
    const dueChanged = item.type === 'todo'
        ? toInputDatetime(item.due_at) !== editingDueAt.value
        : false;
    const quantityChanged = item.type === 'product'
        ? normalizeComparableValue(normalizeQuantityInput(item.quantity)) !== normalizeComparableValue(nextQuantity)
            || normalizeComparableValue(normalizeUnitInput(item.unit)) !== normalizeComparableValue(nextUnit)
        : false;

    if (!nextText) {
        cancelEdit();
        return;
    }

    if (!textChanged && !dueChanged && !quantityChanged) {
        cancelEdit();
        return;
    }

    resetMessages();
    const { ownerId, linkId } = resolveItemContext(item);
    const nextDueAt = item.type === 'todo' ? toIsoDatetime(editingDueAt.value) : (item.due_at ?? null);
    const updatePayload = {
        text: nextText,
    };

    if (item.type === 'todo') {
        updatePayload.due_at = nextDueAt;
    }

    if (item.type === 'product') {
        updatePayload.quantity = nextQuantity;
        updatePayload.unit = nextUnit;
    }

    upsertLocalItem(ownerId, item.type, {
        ...item,
        text: nextText,
        quantity: item.type === 'product' ? nextQuantity : null,
        unit: item.type === 'product' ? nextUnit : null,
        due_at: nextDueAt,
        pending_sync: true,
        updated_at: new Date().toISOString(),
    }, {
        linkId,
    });
    queueUpdate(ownerId, item.type, item.id, updatePayload, linkId);

    cancelEdit();

    await syncOfflineQueue();

    if (!hasPendingOperations(ownerId, item.type, linkId)) {
        await loadSuggestions(item.type);
    }
}

function saveProductEditOnBlur(item) {
    window.setTimeout(() => {
        if (editingItemId.value === item.id) {
            saveEdit(item);
        }
    }, 0);
}

function saveTodoEditOnBlur(item) {
    window.setTimeout(() => {
        if (Date.now() < skipTodoBlurSaveUntil) {
            return;
        }

        if (editingItemId.value === item.id) {
            saveEdit(item);
        }
    }, 0);
}

function markTodoControlInteraction() {
    skipTodoBlurSaveUntil = Date.now() + 420;
}

async function persistDefaultOwner(ownerId) {
    const normalizedOwnerId = Number(ownerId);
    if (!Number.isFinite(normalizedOwnerId) || normalizedOwnerId <= 0) {
        return;
    }

    if (normalizedOwnerId === lastPersistedOwnerId) {
        return;
    }

    lastPersistedOwnerId = normalizedOwnerId;
    persistLocalDefaultOwner(normalizedOwnerId);
    queueDefaultOwner(normalizedOwnerId);
    persistCurrentSyncStateCache();
    syncOfflineQueue().catch(() => {});
}

async function toggleCompleted(item) {
    resetMessages();
    await flushSwipeUndoState();

    const { ownerId, linkId } = resolveItemContext(item);
    const nextCompleted = !item.is_completed;
    const nextSortOrder = nextSortOrderForLocalList(ownerId, item.type, nextCompleted, linkId);

    adjustXpProgress(nextCompleted ? XP_PROGRESS_PER_TOGGLE : -XP_PROGRESS_PER_TOGGLE);

    upsertLocalItem(ownerId, item.type, {
        ...item,
        is_completed: nextCompleted,
        sort_order: nextSortOrder,
        completed_at: nextCompleted ? new Date().toISOString() : null,
        pending_sync: true,
        updated_at: new Date().toISOString(),
    }, {
        linkId,
    });

    queueUpdate(ownerId, item.type, item.id, {
        is_completed: nextCompleted,
        sort_order: nextSortOrder,
    }, linkId);

    syncOfflineQueue().catch(() => {});
}

async function removeItem(item) {
    resetMessages();
    await flushSwipeUndoState();

    const { ownerId, linkId } = resolveItemContext(item);
    const currentItems = readListFromCache(ownerId, item.type, linkId);
    const previousIndex = currentItems.findIndex((entry) => Number(entry.id) === Number(item.id));
    const completedItemsCount = currentItems.filter((entry) => entry.is_completed).length;
    const canRemoveCompletedBatch = Boolean(item.is_completed && completedItemsCount >= BATCH_CINEMATIC_REMOVE_THRESHOLD);

    removeLocalItem(ownerId, item.type, item.id, linkId);

    startSwipeUndoState({
        action: 'remove',
        ownerId,
        linkId,
        type: item.type,
        item: { ...item },
        previousIndex,
        canRemoveCompletedBatch,
    });
}

async function onItemsReorder(type, event) {
    if (!event || event.oldIndex === event.newIndex) {
        return;
    }

    resetMessages();
    await flushSwipeUndoState();

    const ownerId = Number(selectedOwnerId.value);
    const linkId = selectedListLinkId.value;
    const sourceItems = type === 'product'
        ? productItems.value
        : todoItems.value;
    const reorderedItems = cloneItems(sourceItems).map((item, index) => ({
        ...item,
        sort_order: (index + 1) * 1000,
    }));

    writeListToCache(ownerId, type, reorderedItems, linkId);
    queueReorder(ownerId, type, reorderedItems.map((item) => Number(item.id)), linkId);
    await syncOfflineQueue();
}

async function addProduct() {
    const text = newProductText.value.trim();
    if (!text) {
        return;
    }

    resetMessages();

    newProductText.value = '';

    await createItemOptimistically('product', text);

    if (!hasPendingOperations(selectedOwnerId.value, 'product', selectedListLinkId.value)) {
        await loadSuggestions('product');
    }
}

async function addTodo() {
    const text = newTodoText.value.trim();
    if (!text) {
        return;
    }

    resetMessages();
    const dueAt = toIsoDatetime(newTodoDueAt.value);

    newTodoText.value = '';
    newTodoDueAt.value = '';

    await createItemOptimistically('todo', text, dueAt);

    if (!hasPendingOperations(selectedOwnerId.value, 'todo', selectedListLinkId.value)) {
        await loadSuggestions('todo');
    }
}

async function findUsers() {
    const query = searchQuery.value.trim();
    const assignCached = () => {
        searchResults.value = readUserSearchFromCache(query);
    };

    if (query.length < 2) {
        searchResults.value = [];
        return;
    }

    if (browserOffline.value) {
        assignCached();
        return;
    }

    assignCached();

    searchBusy.value = true;

    try {
        const response = await requestApi(() => window.axios.get('api/users/search', {
            params: { query },
        }));

        const users = response.data.users ?? [];
        searchResults.value = users;
        writeUserSearchToCache(query, users);
    } catch (error) {
        if (isConnectivityError(error)) {
            assignCached();
            return;
        }

        showError(error);
    } finally {
        searchBusy.value = false;
    }
}

async function sendInvite(userId) {
    resetMessages();
    queueSendInvitation(userId);
    syncOfflineQueue().catch(() => {});
    showStatus('\u041f\u0440\u0438\u0433\u043b\u0430\u0448\u0435\u043d\u0438\u0435 \u043e\u0442\u043f\u0440\u0430\u0432\u043b\u0435\u043d\u043e.');
}

async function acceptInvitation(invitationId) {
    resetMessages();
    invitations.value = invitations.value.filter((entry) => Number(entry?.id) !== Number(invitationId));
    pendingInvitationsCount.value = invitations.value.length;
    persistCurrentSyncStateCache();
    queueInvitationResponse('accept_invitation', invitationId);
    syncOfflineQueue().catch(() => {});
    showStatus('\u041f\u0440\u0438\u0433\u043b\u0430\u0448\u0435\u043d\u0438\u0435 \u043f\u0440\u0438\u043d\u044f\u0442\u043e.');
}

async function declineInvitation(invitationId) {
    resetMessages();
    invitations.value = invitations.value.filter((entry) => Number(entry?.id) !== Number(invitationId));
    pendingInvitationsCount.value = invitations.value.length;
    persistCurrentSyncStateCache();
    queueInvitationResponse('decline_invitation', invitationId);
    syncOfflineQueue().catch(() => {});
    showStatus('\u041f\u0440\u0438\u0433\u043b\u0430\u0448\u0435\u043d\u0438\u0435 \u043e\u0442\u043c\u0435\u043d\u0435\u043d\u043e.');
}

async function setMine(linkId) {
    resetMessages();
    const link = links.value.find((entry) => Number(entry?.id) === Number(linkId));
    const targetOwnerId = Number(link?.other_user?.id ?? 0);
    if (targetOwnerId > 0) {
        selectedOwnerId.value = targetOwnerId;
        persistLocalDefaultOwner(targetOwnerId);
        lastPersistedOwnerId = targetOwnerId;
    }

    persistCurrentSyncStateCache();
    queueSetMine(linkId);
    syncOfflineQueue().catch(() => {});
    showStatus('\u0421\u043f\u0438\u0441\u043e\u043a \u0443\u0441\u0442\u0430\u043d\u043e\u0432\u043b\u0435\u043d \u043f\u043e \u0443\u043c\u043e\u043b\u0447\u0430\u043d\u0438\u044e.');
}

async function breakLink(linkId) {
    resetMessages();
    const numericLinkId = Number(linkId);
    links.value = links.value.filter((entry) => Number(entry?.id) !== numericLinkId);
    listOptions.value = listOptions.value.filter((option) => Number(option?.link_id) !== numericLinkId);

    const selectedOptionExists = listOptions.value.some((option) => Number(option?.owner_id) === Number(selectedOwnerId.value));
    if (!selectedOptionExists) {
        selectedOwnerId.value = Number(localUser.id);
        persistLocalDefaultOwner(localUser.id);
        lastPersistedOwnerId = Number(localUser.id);
    }

    persistCurrentSyncStateCache();
    queueBreakLink(linkId);
    syncOfflineQueue().catch(() => {});
    showStatus('\u0421\u0432\u044f\u0437\u044c \u0441\u043f\u0438\u0441\u043a\u043e\u0432 \u0440\u0430\u0437\u043e\u0440\u0432\u0430\u043d\u0430.');
}

async function saveProfile() {
    resetMessages();
    profileForm.loading = true;

    const optimisticProfile = {
        name: String(profileForm.name ?? '').trim(),
        tag: normalizeProfileTagInput(profileForm.tag),
        email: String(profileForm.email ?? '').trim(),
    };

    if (!optimisticProfile.name || !optimisticProfile.tag || !optimisticProfile.email) {
        profileForm.loading = false;
        return;
    }

    localUser.name = optimisticProfile.name;
    localUser.tag = optimisticProfile.tag;
    localUser.email = optimisticProfile.email;
    profileForm.name = optimisticProfile.name;
    profileForm.tag = optimisticProfile.tag;
    profileForm.email = optimisticProfile.email;

    queueProfileUpdate(optimisticProfile);
    syncOfflineQueue().catch(() => {});
    showStatus('\u041f\u0440\u043e\u0444\u0438\u043b\u044c \u043e\u0431\u043d\u043e\u0432\u043b\u0435\u043d.');
    profileForm.loading = false;
}

async function savePassword() {
    resetMessages();
    passwordForm.loading = true;

    const nextPasswordPayload = {
        current_password: passwordForm.current_password,
        password: passwordForm.password,
        password_confirmation: passwordForm.password_confirmation,
    };

    if (
        !nextPasswordPayload.current_password
        || !nextPasswordPayload.password
        || nextPasswordPayload.password !== nextPasswordPayload.password_confirmation
    ) {
        passwordForm.loading = false;
        return;
    }

    queuePasswordUpdate(nextPasswordPayload);
    passwordForm.current_password = '';
    passwordForm.password = '';
    passwordForm.password_confirmation = '';

    syncOfflineQueue().catch(() => {});
    showStatus('\u041f\u0430\u0440\u043e\u043b\u044c \u043e\u0431\u043d\u043e\u0432\u043b\u0435\u043d.');
    passwordForm.loading = false;
}

function subscribeUserChannel() {
    if (!window.Echo) {
        return;
    }

    userChannelName = `users.${localUser.id}`;
    window.Echo.private(userChannelName).listen('.user.sync.changed', () => {
        refreshState();
    });
}

function buildListChannelName(ownerId) {
    const linkId = resolveLinkIdForOwner(ownerId);
    if (linkId) {
        return `lists.shared.${linkId}`;
    }

    return `lists.personal.${Number(ownerId)}`;
}

function subscribeListChannel(ownerId) {
    if (!window.Echo) {
        return;
    }

    const nextChannelName = buildListChannelName(ownerId);

    if (listChannelName) {
        window.Echo.leave(listChannelName);
    }

    listChannelName = nextChannelName;
    window.Echo.private(listChannelName).listen('.list.items.changed', () => {
        loadActiveTabItems();
        loadActiveTabSuggestions();
    });
}

watch(selectedOwnerId, async (ownerId) => {
    listDropdownOpen.value = false;
    await flushSwipeUndoState();
    persistLocalDefaultOwner(ownerId);
    hydrateSelectedListsFromCache();

    persistDefaultOwner(ownerId);

    subscribeListChannel(ownerId);
    await Promise.all([loadAllItems(), loadAllSuggestions()]);

    if (activeTab.value === 'profile') {
        await loadProductSuggestionStats();
    }
});

watch(activeTab, async (tab) => {
    listDropdownOpen.value = false;
    await Promise.all([loadActiveTabItems(), loadActiveTabSuggestions()]);

    if (tab === 'profile') {
        await loadProductSuggestionStats();
    }
});

watch(isSkippableAnimationPlaying, (isActive) => {
    applyScrollLockState(isActive);
}, { immediate: true });

onMounted(async () => {
    loadOfflineStateFromStorage();
    applyCachedSyncState(true);
    hydrateSelectedListsFromCache();

    if (typeof window !== 'undefined') {
        isBrowserOnline.value = window.navigator.onLine;
    }

    await syncOfflineQueue();
    await refreshState(false, true);
    await Promise.all([loadAllItems(), loadAllSuggestions()]);
    gamificationSyncEnabled = true;

    if (activeTab.value === 'profile') {
        await loadProductSuggestionStats();
    }

    subscribeUserChannel();
    subscribeListChannel(selectedOwnerId.value);

    itemsPollTimer = window.setInterval(() => {
        if (document.hidden) {
            return;
        }

        loadActiveTabItems();
    }, 2500);

    statePollTimer = window.setInterval(() => {
        if (document.hidden) {
            return;
        }

        refreshState();
    }, 3000);

    suggestionsPollTimer = window.setInterval(() => {
        if (document.hidden) {
            return;
        }

        loadActiveTabSuggestions();
    }, 20000);

    queueSyncTimer = window.setInterval(() => {
        syncOfflineQueue();
    }, 1000);

    handleOnlineEvent = async () => {
        isBrowserOnline.value = true;
        serverReachable.value = true;

        await syncOfflineQueue();
        await Promise.all([refreshState(false, true), loadAllItems(), loadAllSuggestions()]);
    };

    handleOfflineEvent = () => {
        isBrowserOnline.value = false;
    };

    window.addEventListener('online', handleOnlineEvent);
    window.addEventListener('offline', handleOfflineEvent);
});

onBeforeUnmount(() => {
    requestAnimationSkip();
    if (swipeUndoState.value) {
        stageSwipeAction(swipeUndoState.value);
        swipeUndoState.value = null;
    }
    clearSwipeUndoTimer();
    batchRemovingItemKeys.value = [];
    batchCollapseHiddenItemKeys.value = [];
    batchCollapseScene.value = null;
    resetMegaCardImpactAnimation();
    batchRemovalAnimating.value = false;
    deleteFeedbackBursts.value = [];
    xpStars.value = [];
    xpLevelUpBackdropVisible.value = false;
    xpLevelUpRaised.value = false;
    xpLevelUpImpact.value = false;
    xpRewardVisible.value = false;
    xpProgressInstant.value = false;
    pendingXpGain = 0;
    xpGainProcessing = false;
    lastXpGainSoundAt = 0;
    clearEffectTimeouts();
    clearXpGainSources();
    clearSoundPools();
    applyScrollLockState(false);
    listSyncVersions.clear();
    deletedItemTombstones.clear();
    itemCardElements.clear();
    disposeToasts();

    if (itemsPollTimer) {
        clearInterval(itemsPollTimer);
    }

    if (statePollTimer) {
        clearInterval(statePollTimer);
    }

    if (suggestionsPollTimer) {
        clearInterval(suggestionsPollTimer);
    }

    if (queueSyncTimer) {
        clearInterval(queueSyncTimer);
    }

    if (gamificationSyncTimer) {
        clearTimeout(gamificationSyncTimer);
        gamificationSyncTimer = null;
    }

    gamificationSyncEnabled = false;

    if (queuedUpdateSyncTimer) {
        clearTimeout(queuedUpdateSyncTimer);
        queuedUpdateSyncTimer = null;
    }

    if (typeof window !== 'undefined' && handleOnlineEvent) {
        window.removeEventListener('online', handleOnlineEvent);
        handleOnlineEvent = null;
    }

    if (typeof window !== 'undefined' && handleOfflineEvent) {
        window.removeEventListener('offline', handleOfflineEvent);
        handleOfflineEvent = null;
    }

    if (window.Echo && userChannelName) {
        window.Echo.leave(userChannelName);
    }

    if (window.Echo && listChannelName) {
        window.Echo.leave(listChannelName);
    }
});
</script>

<template>
    <Head title="Dandash" />

    <div class="min-h-screen bg-[#19181a] text-[#fcfcfa]">
        <div class="mx-auto flex min-h-screen w-full max-w-md flex-col px-4 pb-36 pt-4">

            <div
                v-if="offlineMode"
                class="mb-3 flex items-center justify-between gap-2 rounded-2xl border border-[#403e41] bg-[#221f22] px-3 py-2 text-xs text-[#bcb7ba]"
            >
                <span class="inline-flex items-center gap-2">
                    <WifiOff class="h-4 w-4 text-[#fcfcfa]" />
                    <span>
                        offline: {{ offlineStatusText }}, изменения сохраняются локально
                    </span>
                </span>
                <span v-if="queuedChangesCount > 0" class="shrink-0 rounded-lg border border-[#403e41] px-2 py-0.5 text-[11px]">
                    {{ queuedChangesCount }}
                </span>
            </div>


            <section v-if="activeTab !== 'profile'" class="mb-4 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <h1 class="text-base font-semibold">
                        {{ activeTab === 'products' ? 'Список продуктов' : 'Список дел' }}
                    </h1>
                    <span
                        v-if="activeTab === 'products' || activeTab === 'todos'"
                        class="rounded-lg border border-[#403e41] bg-[#221f22] px-2 py-0.5 text-xs text-[#9f9a9d]"
                    >
                        {{ activeListStats.completed }}/{{ activeListStats.total }}
                    </span>
                </div>

                <div class="relative">
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-xl border border-[#403e41] bg-[#221f22] px-3 py-2 text-xs text-[#fcfcfa]"
                        @click="listDropdownOpen = !listDropdownOpen"
                    >
                        <span class="max-w-36 truncate">
                            {{ selectedListLabel }}
                        </span>
                        <ChevronDown class="h-4 w-4" />
                    </button>

                    <div
                        v-if="listDropdownOpen"
                        class="absolute right-0 z-20 mt-2 w-56 rounded-2xl border border-[#403e41] bg-[#221f22] p-2 shadow-2xl"
                    >
                        <button
                            v-for="option in listOptions"
                            :key="`option-${option.owner_id}-${option.link_id ?? 'personal'}`"
                            type="button"
                            class="mb-1 w-full rounded-xl px-3 py-2 text-left text-sm transition last:mb-0"
                            :class="
                                Number(option.owner_id) === Number(selectedOwnerId)
                                    ? 'bg-[#2d2a2c] text-[#fcfcfa]'
                                    : 'text-[#bcb7ba] hover:bg-[#2d2a2c]'
                            "
                            @click="selectedOwnerId = option.owner_id"
                        >
                            <span class="flex items-center justify-between gap-2">
                                <span class="truncate">{{ option.label }}</span>
                                <Check
                                    v-if="Number(option.owner_id) === Number(selectedOwnerId)"
                                    class="h-4 w-4 shrink-0"
                                />
                            </span>
                        </button>
                    </div>
                </div>
            </section>

            <section v-if="activeTab === 'products'" class="flex-1">
                <div class="mb-4 space-y-2">
                    <div class="flex gap-2">
                        <input
                            v-model="newProductText"
                            type="text"
                            placeholder="Добавить продукт (например: молоко 2 л)..."
                            class="w-full rounded-2xl border border-[#403e41] bg-[#221f22] px-4 py-3 text-sm text-[#fcfcfa] placeholder:text-[#7f7b7e] focus:border-[#fcfcfa]/45 focus:outline-none"
                            @keyup.enter="addProduct"
                        >
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-2xl border border-[#403e41] bg-[#221f22] px-4 text-sm font-semibold text-[#fcfcfa] transition hover:border-[#fcfcfa]/45"
                            @click="addProduct"
                        >
                            <Plus class="h-4 w-4" />
                        </button>
                    </div>
                </div>

                <div class="mb-3">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 py-1"
                        @click="productSuggestionsOpen = !productSuggestionsOpen"
                    >
                        <span class="h-px flex-1 rounded-full bg-[#403e41]" />
                        <ChevronDown
                            class="h-4 w-4 shrink-0 text-[#9f9a9d] transition-transform duration-200"
                            :class="productSuggestionsOpen ? 'rotate-0' : '-rotate-90'"
                        />
                    </button>

                    <Transition name="smart-suggestions">
                        <div v-if="productSuggestionsOpen && visibleProductSuggestions.length > 0" class="mt-2 space-y-2 overflow-hidden">
                            <div
                                v-for="(suggestion, index) in visibleProductSuggestions"
                                :key="`product-suggestion-${getSuggestionKey(suggestion)}-${index}`"
                                class="flex items-center justify-between gap-3 rounded-2xl border border-[#5b7fff] bg-[#2d2a2c] px-3 py-2"
                            >
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-semibold text-[#fcfcfa]">
                                        {{ suggestion.suggested_text }}
                                    </div>
                                    <div class="mt-1 text-[11px] text-[#9f9a9d]">
                                        {{ suggestionStatusText(suggestion, 'product') }}
                                    </div>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <button
                                        type="button"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-xl border border-[#a5d774]/40 bg-[#a5d774]/15 text-[#a5d774]"
                                        @click="applySuggestionToList('product', suggestion)"
                                    >
                                        <Check class="h-4 w-4" />
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-xl border border-[#ee5c81]/40 bg-[#ee5c81]/15 text-[#ee5c81]"
                                        @click="dismissSuggestion('product', suggestion)"
                                    >
                                        <X class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </Transition>
                </div>

                <draggable
                    v-model="productItems"
                    item-key="local_id"
                    handle=".drag-handle"
                    ghost-class="drag-ghost"
                    chosen-class="drag-chosen"
                    drag-class="drag-dragging"
                    :animation="220"
                    class="space-y-3"
                    @end="onItemsReorder('product', $event)"
                >
                    <template #item="{ element: item }">
                        <div
                            :ref="(element) => setItemCardRef('product', item, element)"
                            :class="{
                                'batch-remove-fly': isBatchRemovingItem(item),
                                'batch-remove-hidden': isBatchCollapseHiddenItem(item),
                            }"
                        >
                            <SwipeListItem
                                :is-completed="item.is_completed"
                                @complete="toggleCompleted(item)"
                                @remove="removeItem(item)"
                                @tap="beginEdit(item)"
                            >
                                <div class="relative space-y-2 pr-7">
                                    <button
                                        type="button"
                                        class="drag-handle absolute right-[-4px] top-0 inline-flex h-5 w-5 items-center justify-center rounded-md text-[11px] font-semibold leading-none tracking-[-0.08em] text-[#7f7b7e] transition hover:text-[#fcfcfa]"
                                        aria-label="Перетащить карточку"
                                    >
                                        :::
                                    </button>
                                    <input
                                        v-if="editingItemId === item.id"
                                        :id="`edit-item-${item.id}`"
                                        v-model="editingText"
                                        type="text"
                                        class="w-full rounded-xl border border-[#403e41] bg-[#2d2a2c] px-3 py-2 text-sm text-[#fcfcfa] focus:border-[#fcfcfa]/45 focus:outline-none"
                                        @keyup.enter="saveEdit(item)"
                                        @keyup.esc="cancelEdit"
                                        @blur="saveProductEditOnBlur(item)"
                                        @click.stop
                                    >
                                    <p
                                        v-else
                                        class="m-0 text-sm font-medium"
                                        :class="item.is_completed ? 'text-[#6e6a6d] line-through decoration-[#6e6a6d]' : 'text-[#fcfcfa]'"
                                    >
                                        {{ getProductDisplayText(item) }}
                                    </p>
                                    <p
                                        v-if="formatProductMeasure(item)"
                                        class="m-0 text-xs"
                                        :class="item.is_completed ? 'text-[#6e6a6d]' : 'text-[#9f9a9d]'"
                                    >
                                        {{ formatProductMeasure(item) }}
                                    </p>
                                </div>
                            </SwipeListItem>
                        </div>
                    </template>
                </draggable>
            </section>

            <section v-if="activeTab === 'todos'" class="flex-1">
                <div class="mb-4 space-y-2">
                    <div class="flex gap-2">
                        <input
                            v-model="newTodoText"
                            type="text"
                            placeholder="Добавить дело..."
                            class="w-full rounded-2xl border border-[#403e41] bg-[#221f22] px-4 py-3 text-sm text-[#fcfcfa] placeholder:text-[#7f7b7e] focus:border-[#fcfcfa]/45 focus:outline-none"
                            @keyup.enter="addTodo"
                        >
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-2xl border border-[#403e41] bg-[#221f22] px-4 text-sm font-semibold text-[#fcfcfa] transition hover:border-[#fcfcfa]/45"
                            @click="addTodo"
                        >
                            <Plus class="h-4 w-4" />
                        </button>
                        <label
                            class="relative inline-flex cursor-pointer items-center rounded-2xl border border-[#403e41] bg-[#221f22] px-3 text-[#fcfcfa] transition hover:border-[#fcfcfa]"
                            :class="{ 'border-[#fcfcfa]': !!newTodoDueAt }"
                            aria-label="Выбрать дедлайн"
                        >
                            <CalendarDays class="h-4 w-4 text-[#fcfcfa]" />
                            <input
                                v-model="newTodoDueAt"
                                type="datetime-local"
                                class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                            >
                        </label>
                    </div>
                </div>

                <div class="mb-3">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 py-1"
                        @click="todoSuggestionsOpen = !todoSuggestionsOpen"
                    >
                        <span class="h-px flex-1 rounded-full bg-[#403e41]" />
                        <ChevronDown
                            class="h-4 w-4 shrink-0 text-[#9f9a9d] transition-transform duration-200"
                            :class="todoSuggestionsOpen ? 'rotate-0' : '-rotate-90'"
                        />
                    </button>

                    <Transition name="smart-suggestions">
                        <div v-if="todoSuggestionsOpen && visibleTodoSuggestions.length > 0" class="mt-2 space-y-2 overflow-hidden">
                            <div
                                v-for="(suggestion, index) in visibleTodoSuggestions"
                                :key="`todo-suggestion-${getSuggestionKey(suggestion)}-${index}`"
                                class="flex items-center justify-between gap-3 rounded-2xl border border-[#5b7fff] bg-[#2d2a2c] px-3 py-2"
                            >
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-semibold text-[#fcfcfa]">
                                        {{ suggestion.suggested_text }}
                                    </div>
                                    <div class="mt-1 text-[11px] text-[#9f9a9d]">
                                        {{ suggestionStatusText(suggestion, 'todo') }}
                                    </div>
                                </div>
                                <div class="flex shrink-0 items-center gap-2">
                                    <button
                                        type="button"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-xl border border-[#a5d774]/40 bg-[#a5d774]/15 text-[#a5d774]"
                                        @click="applySuggestionToList('todo', suggestion)"
                                    >
                                        <Check class="h-4 w-4" />
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-xl border border-[#ee5c81]/40 bg-[#ee5c81]/15 text-[#ee5c81]"
                                        @click="dismissSuggestion('todo', suggestion)"
                                    >
                                        <X class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        </div>
                    </Transition>
                </div>

                <draggable
                    v-model="todoItems"
                    item-key="local_id"
                    handle=".drag-handle"
                    ghost-class="drag-ghost"
                    chosen-class="drag-chosen"
                    drag-class="drag-dragging"
                    :animation="220"
                    class="space-y-3"
                    @end="onItemsReorder('todo', $event)"
                >
                    <template #item="{ element: item }">
                        <div
                            :ref="(element) => setItemCardRef('todo', item, element)"
                            :class="{
                                'batch-remove-fly': isBatchRemovingItem(item),
                                'batch-remove-hidden': isBatchCollapseHiddenItem(item),
                            }"
                        >
                            <SwipeListItem
                                :is-completed="item.is_completed"
                                @complete="toggleCompleted(item)"
                                @remove="removeItem(item)"
                                @tap="beginEdit(item)"
                            >
                                <div class="relative space-y-2 pr-7">
                                    <button
                                        type="button"
                                        class="drag-handle absolute right-[-4px] top-0 inline-flex h-5 w-5 items-center justify-center rounded-md text-[11px] font-semibold leading-none tracking-[-0.08em] text-[#7f7b7e] transition hover:text-[#fcfcfa]"
                                        aria-label="Перетащить карточку"
                                    >
                                        :::
                                    </button>
                                    <div>
                                        <input
                                            v-if="editingItemId === item.id"
                                            :id="`edit-item-${item.id}`"
                                            v-model="editingText"
                                            type="text"
                                            class="w-full rounded-xl border border-[#403e41] bg-[#2d2a2c] px-3 py-2 text-sm text-[#fcfcfa] focus:border-[#fcfcfa]/45 focus:outline-none"
                                            @keyup.enter="saveEdit(item)"
                                            @keyup.esc="cancelEdit"
                                            @blur="saveTodoEditOnBlur(item)"
                                            @click.stop
                                        >
                                        <p
                                            v-else
                                            class="m-0 text-sm font-medium"
                                            :class="item.is_completed ? 'text-[#6e6a6d] line-through decoration-[#6e6a6d]' : 'text-[#fcfcfa]'"
                                        >
                                            {{ item.text }}
                                        </p>
                                        <div class="mt-1 flex items-center justify-between gap-2">
                                            <p class="m-0 text-xs" :class="item.is_completed ? 'text-[#6e6a6d]' : 'text-[#9f9a9d]'">
                                                {{ formatDueAt(item.due_at) }}
                                            </p>
                                            <div class="flex items-center gap-2">
                                            <button
                                                type="button"
                                                data-no-swipe
                                                class="inline-flex h-6 w-6 items-center justify-center rounded-lg border border-[#fcfcfa]/45 text-[#fcfcfa] transition hover:border-[#fcfcfa]"
                                                aria-label="Изменить дедлайн"
                                                @pointerdown.stop="markTodoControlInteraction"
                                                @click.stop="openTodoItemDuePicker(item)"
                                            >
                                                <CalendarDays class="h-3.5 w-3.5 text-[#fcfcfa]" />
                                            </button>
                                            <button
                                                v-if="!item.is_completed"
                                                type="button"
                                                data-no-swipe
                                                class="rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.04em]"
                                                :class="todoPriorityClass(item)"
                                                @pointerdown.stop="markTodoControlInteraction"
                                                @click.stop="cycleTodoPriority(item)"
                                            >
                                                {{ todoPriorityLabel(item) }}
                                            </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </SwipeListItem>
                        </div>
                    </template>
                </draggable>
            </section>

            <section v-if="activeTab === 'profile'" class="flex-1 space-y-4">
                <div class="rounded-3xl border border-[#403e41] bg-[#2d2a2c] p-4">
                    <div class="text-[11px] uppercase tracking-[0.18em] text-[#7f7b7e]">
                        Dandash
                    </div>
                    <div class="mt-1 text-lg font-semibold text-[#fcfcfa]">
                        {{ localUser.name }}
                    </div>
                    <div class="mt-1 flex items-center gap-2 text-xs text-[#9f9a9d]">
                        <span>@{{ localUser.tag || 'tag' }}</span>
                        <span class="rounded-full border border-[#5b7fff]/40 bg-[#5b7fff]/12 px-2 py-0.5 text-[11px] font-semibold text-[#d8e7ff]">
                            {{ '\u041f\u0440\u043e\u0434\u0443\u043a\u0442\u0438\u0432\u043d\u043e\u0441\u0442\u044c: ' }}{{ productivityScore }}
                        </span>
                    </div>
                </div>

                <button
                    type="button"
                    class="flex w-full items-center justify-center gap-2 rounded-2xl border border-[#403e41] bg-[#221f22] px-4 py-3 text-sm font-semibold text-[#fcfcfa]"
                    @click="shareModalOpen = true"
                >
                    <Share2 class="h-4 w-4" />
                    Поделиться списками
                </button>

                <button
                    type="button"
                    class="w-full rounded-2xl border border-[#403e41] bg-[#221f22] px-4 py-3 text-sm font-semibold text-[#fcfcfa]"
                    @click="
                        inviteModalOpen = true;
                        inviteModalTab = 'invitations';
                    "
                >
                    Мои приглашения ({{ pendingInvitationsCount }})
                </button>

                <button
                    type="button"
                    class="flex w-full items-center justify-between gap-3 rounded-2xl border border-[#403e41] bg-[#221f22] px-4 py-3 text-left text-sm font-semibold text-[#fcfcfa]"
                    @click="openProductStatsModal"
                >
                    <span>{{ '\u0421\u0442\u0430\u0442\u0438\u0441\u0442\u0438\u043a\u0430 \u043f\u043e\u043a\u0443\u043f\u043a\u0430\u043c' }}</span>
                    <span class="text-xs text-[#9f9a9d]">
                        {{ productSuggestionStats.length }} {{ '\u043f\u043e\u0437\u0438\u0446\u0438\u0439' }}
                    </span>
                </button>

                <div class="rounded-3xl border border-[#403e41] bg-[#2d2a2c] p-4">
                    <h3 class="mb-3 text-sm font-semibold text-[#fcfcfa]">
                        Настройки аккаунта
                    </h3>
                    <div class="space-y-2">
                        <input
                            v-model="profileForm.name"
                            type="text"
                            class="w-full rounded-xl border border-[#403e41] bg-[#2d2a2c] px-3 py-2 text-sm text-[#fcfcfa] focus:border-[#fcfcfa]/45 focus:outline-none"
                            :placeholder="'\u041d\u0438\u043a'"
                        >
                        <input
                            v-model="profileForm.tag"
                            type="text"
                            class="w-full rounded-xl border border-[#403e41] bg-[#2d2a2c] px-3 py-2 text-sm text-[#fcfcfa] focus:border-[#fcfcfa]/45 focus:outline-none"
                            placeholder="@tag"
                        >
                        <input
                            v-model="profileForm.email"
                            type="email"
                            class="w-full rounded-xl border border-[#403e41] bg-[#2d2a2c] px-3 py-2 text-sm text-[#fcfcfa] focus:border-[#fcfcfa]/45 focus:outline-none"
                            placeholder="Email"
                        >
                        <button
                            type="button"
                            class="w-full rounded-xl bg-[#fcfcfa] px-3 py-2 text-sm font-semibold text-[#19181a]"
                            :disabled="profileForm.loading"
                            @click="saveProfile"
                        >
                            Сохранить профиль
                        </button>
                    </div>
                </div>

                <div class="rounded-3xl border border-[#403e41] bg-[#2d2a2c] p-4">
                    <h3 class="mb-3 text-sm font-semibold text-[#fcfcfa]">
                        Смена пароля
                    </h3>
                    <div class="space-y-2">
                        <input
                            v-model="passwordForm.current_password"
                            type="password"
                            class="w-full rounded-xl border border-[#403e41] bg-[#2d2a2c] px-3 py-2 text-sm text-[#fcfcfa] focus:border-[#fcfcfa]/45 focus:outline-none"
                            placeholder="Текущий пароль"
                        >
                        <input
                            v-model="passwordForm.password"
                            type="password"
                            class="w-full rounded-xl border border-[#403e41] bg-[#2d2a2c] px-3 py-2 text-sm text-[#fcfcfa] focus:border-[#fcfcfa]/45 focus:outline-none"
                            placeholder="Новый пароль"
                        >
                        <input
                            v-model="passwordForm.password_confirmation"
                            type="password"
                            class="w-full rounded-xl border border-[#403e41] bg-[#2d2a2c] px-3 py-2 text-sm text-[#fcfcfa] focus:border-[#fcfcfa]/45 focus:outline-none"
                            placeholder="Повторите пароль"
                        >
                        <button
                            type="button"
                            class="w-full rounded-xl bg-[#fcfcfa] px-3 py-2 text-sm font-semibold text-[#19181a]"
                            :disabled="passwordForm.loading"
                            @click="savePassword"
                        >
                            Сохранить пароль
                        </button>
                    </div>
                </div>
            </section>
        </div>


        <Transition name="todo-due-modal">
            <div
                v-if="todoDueModalOpen"
                class="fixed inset-0 z-[120] flex items-end bg-[#19181a]/78 p-2.5 backdrop-blur-[2px]"
                @click.self="closeTodoDueModal"
            >
                <div class="mx-auto w-full max-w-md rounded-[28px] border border-[#403e41] bg-[#221f22] p-4 shadow-[0_24px_60px_rgba(0,0,0,0.45)]">
                    <div class="mx-auto mb-3 h-1.5 w-11 rounded-full bg-[#403e41]" />

                    <div class="mb-3 flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-[10px] uppercase tracking-[0.18em] text-[#7f7b7e]">DEADLINE</p>
                            <h2 class="mt-1 text-sm font-semibold text-[#fcfcfa]">{{ '\u0414\u0435\u0434\u043b\u0430\u0439\u043d' }}</h2>
                        </div>
                        <button
                            type="button"
                            class="inline-flex h-8 w-8 items-center justify-center rounded-xl border border-[#403e41] text-[#bcb7ba] transition hover:border-[#fcfcfa]/40 hover:text-[#fcfcfa]"
                            @click="closeTodoDueModal"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>

                    <label class="mb-1 block text-[11px] text-[#9f9a9d]">
                        {{ '\u0414\u0430\u0442\u0430 \u0438 \u0432\u0440\u0435\u043c\u044f' }}
                    </label>
                    <input
                        v-model="todoDueModalValue"
                        type="datetime-local"
                        class="h-11 w-full rounded-xl border border-[#403e41] bg-[#2d2a2c] px-3 text-sm text-[#fcfcfa] outline-none transition focus:border-[#fcfcfa]/45"
                    >

                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button
                            type="button"
                            class="rounded-xl border border-[#403e41] bg-[#2d2a2c] px-3 py-2 text-sm font-semibold text-[#fcfcfa] transition hover:border-[#fcfcfa]/35"
                            @click="clearTodoDueModal"
                        >
                            {{ '\u041e\u0447\u0438\u0441\u0442\u0438\u0442\u044c' }}
                        </button>
                        <button
                            type="button"
                            class="rounded-xl bg-[#fcfcfa] px-3 py-2 text-sm font-semibold text-[#19181a] transition hover:bg-[#f1f1ef]"
                            @click="saveTodoDueModal"
                        >
                            {{ '\u0421\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c' }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>

        <input
            ref="todoItemDuePickerRef"
            v-model="todoItemDuePickerValue"
            type="datetime-local"
            class="fixed left-[-9999px] top-[-9999px] h-px w-px opacity-0"
            tabindex="-1"
            aria-hidden="true"
            @change="applyTodoItemDuePickerValue"
        >

        <div class="delete-burst-layer" aria-hidden="true">
            <div v-if="batchCollapseScene" class="batch-collapse-scene">
                <div
                    v-for="incoming in batchCollapseScene.incoming"
                    :key="`batch-collapse-incoming-${incoming.id}`"
                    class="batch-collapse-incoming"
                    :class="{ 'batch-collapse-incoming--active': batchCollapseScene.incomingActive }"
                    :style="{
                        left: `${incoming.x}px`,
                        top: `${incoming.y}px`,
                        width: `${incoming.width}px`,
                        height: `${incoming.height}px`,
                        '--collapse-dx': `${incoming.dx}px`,
                        '--collapse-dy': `${incoming.dy}px`,
                        '--collapse-delay': `${incoming.delay}ms`,
                        '--collapse-duration': `${incoming.duration}ms`,
                        '--collapse-target-scale': incoming.targetScale,
                    }"
                />

                <div
                    ref="megaCardRef"
                    class="batch-collapse-mega"
                    :class="{
                        'batch-collapse-mega--whiten': batchCollapseScene.whitening,
                        'batch-collapse-mega--glow': batchCollapseScene.glowing,
                        'batch-collapse-mega--burst': batchCollapseScene.bursting,
                    }"
                    :style="{
                        left: `${batchCollapseScene.x}px`,
                        top: `${batchCollapseScene.y}px`,
                        width: `${batchCollapseScene.width}px`,
                        height: `${batchCollapseScene.height}px`,
                        boxShadow: megaCardShadowStyle(batchCollapseScene),
                        filter: megaCardFilterStyle(batchCollapseScene),
                    }"
                />
            </div>

            <div
                v-for="burst in deleteFeedbackBursts"
                :key="`delete-burst-${burst.id}`"
                class="delete-burst"
                :style="{ left: `${burst.x}px`, top: `${burst.y}px` }"
            >
                <span
                    v-for="particle in burst.particles"
                    :key="`delete-burst-${burst.id}-particle-${particle.id}`"
                    class="delete-burst-particle"
                    :style="{
                        '--particle-x': `${particle.dx}px`,
                        '--particle-y': `${particle.dy}px`,
                        '--particle-delay': `${particle.delay}ms`,
                    }"
                />
            </div>

            <span
                v-for="star in xpStars"
                :key="`xp-star-${star.id}`"
                class="xp-flow-star"
                :class="{ 'xp-flow-star--active': star.active }"
                :style="{
                    left: `${star.x}px`,
                    top: `${star.y}px`,
                    '--star-dx': `${star.dx}px`,
                    '--star-dy': `${star.dy}px`,
                    '--star-delay': `${star.delay}ms`,
                    '--star-duration': `${star.duration}ms`,
                    '--star-size': `${star.size}px`,
                    '--star-rotate': `${star.rotate}deg`,
                }"
            />
        </div>

        <div
            v-if="isSkippableAnimationPlaying"
            class="animation-skip-layer"
            aria-hidden="true"
            @pointerdown.stop.prevent="handleAnimationSkipTap"
            @touchmove.stop.prevent
            @wheel.stop.prevent
        />

        <Transition name="item">
            <div
                v-if="swipeUndoState"
                class="fixed bottom-32 left-1/2 z-40 flex w-[calc(100%-20px)] max-w-md -translate-x-1/2 items-center justify-between gap-3 rounded-2xl border border-[#403e41] bg-[#221f22]/95 px-3 py-2 text-xs text-[#fcfcfa] shadow-xl backdrop-blur"
            >
                <span class="truncate text-[#bcb7ba]">
                    {{ swipeUndoMessage }}
                </span>
                <div class="flex shrink-0 items-center gap-2">
                    <button
                        v-if="canShowRemoveCompletedButton"
                        type="button"
                        class="rounded-xl border border-[#ee5c81]/55 bg-[#ee5c81]/14 p-2 text-[#ee5c81] hover:border-[#ee5c81]"
                        aria-label="Удалить выполненные"
                        @click="removeCompletedAfterSwipe($event)"
                    >
                        <Trash2 class="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        class="rounded-xl border border-[#403e41] p-2 text-[#fcfcfa] hover:border-[#fcfcfa]/45"
                        aria-label="Отменить"
                        @click="undoSwipeAction"
                    >
                        <RotateCcw class="h-4 w-4" />
                    </button>
                </div>
            </div>
        </Transition>

        <TransitionGroup
            name="toast"
            tag="div"
            class="pointer-events-none fixed top-3 left-1/2 z-[70] flex w-[calc(100%-20px)] max-w-md -translate-x-1/2 flex-col gap-2"
        >
            <div
                v-for="toast in toasts"
                :key="`toast-${toast.id}`"
                class="toast-card pointer-events-auto select-none rounded-2xl border px-3 py-2 text-sm shadow-xl backdrop-blur"
                :class="toast.type === 'error'
                    ? 'border-[#ee5c81]/60 bg-[#221f22]/96 text-[#ee5c81]'
                    : 'border-[#a5d774]/55 bg-[#221f22]/96 text-[#a5d774]'"
                :style="{ transform: `translateX(${toast.deltaX || 0}px)` }"
                @pointerdown="onToastPointerDown(toast.id, $event)"
                @pointermove="onToastPointerMove(toast.id, $event)"
                @pointerup="onToastPointerUp(toast.id)"
                @pointercancel="onToastPointerCancel(toast.id)"
                @pointerleave="onToastPointerUp(toast.id)"
            >
                <p class="truncate">{{ toast.message }}</p>
            </div>
        </TransitionGroup>

        <Transition name="xp-backdrop">
            <div
                v-if="xpLevelUpBackdropVisible"
                class="xp-levelup-backdrop"
                aria-hidden="true"
            />
        </Transition>

        <div
            class="fixed bottom-[92px] left-1/2 z-50 w-[calc(100%-20px)] max-w-md -translate-x-1/2 px-1.5 transition-transform duration-500"
            :class="xpLevelUpRaised ? 'translate-y-[-52px]' : 'translate-y-0'"
        >
            <Transition name="xp-reward">
                <div v-if="xpRewardVisible" class="xp-reward-text">
                    {{ `+${xpRewardAmount} \u043f\u0440\u043e\u0434\u0443\u043a\u0442\u0438\u0432\u043d\u043e\u0441\u0442\u0438` }}
                </div>
            </Transition>

            <div class="xp-progress-wrap" :class="{ 'xp-progress-wrap--impact': xpLevelUpImpact }">
                <div ref="progressBarRef" class="xp-progress-track">
                    <div
                        class="xp-progress-fill"
                        :class="{ 'xp-progress-fill--instant': xpProgressInstant }"
                        :style="{
                            width: `${xpProgressPercent}%`,
                            '--xp-fill-duration': `${xpProgressFillDurationMs}ms`,
                            '--xp-fill-start': xpProgressFillPalette.start,
                            '--xp-fill-ring': xpProgressFillPalette.ring,
                            '--xp-fill-glow': xpProgressFillPalette.glow,
                        }"
                    />
                </div>
            </div>
        </div>

        <nav
            class="fixed bottom-3 left-1/2 z-[90] flex w-[calc(100%-20px)] max-w-md -translate-x-1/2 rounded-3xl border border-[#403e41] bg-[#221f22]/95 p-2 backdrop-blur"
            :class="isSkippableAnimationPlaying ? 'pointer-events-none' : ''"
        >
            <button
                type="button"
                class="flex flex-1 flex-col items-center rounded-2xl px-3 py-2 text-xs"
                :class="activeTab === 'products' ? 'bg-[#fcfcfa] text-[#19181a]' : 'text-[#bcb7ba]'"
                @click="activeTab = 'products'"
            >
                <ShoppingCart class="bottom-menu-icon mb-1 h-4 w-4" />
                Продукты
            </button>
            <button
                type="button"
                class="flex flex-1 flex-col items-center rounded-2xl px-3 py-2 text-xs"
                :class="activeTab === 'todos' ? 'bg-[#fcfcfa] text-[#19181a]' : 'text-[#bcb7ba]'"
                @click="activeTab = 'todos'"
            >
                <Check class="bottom-menu-icon mb-1 h-4 w-4" />
                Дела
            </button>
            <button
                type="button"
                class="flex flex-1 flex-col items-center rounded-2xl px-3 py-2 text-xs"
                :class="activeTab === 'profile' ? 'bg-[#fcfcfa] text-[#19181a]' : 'text-[#bcb7ba]'"
                @click="activeTab = 'profile'"
            >
                <UserRound class="bottom-menu-icon mb-1 h-4 w-4" />
                Профиль
            </button>
        </nav>

        <Transition name="app-modal">
            <div v-if="shareModalOpen" class="fixed inset-0 z-[120] bg-[#19181a]/90 p-2.5" @click.self="shareModalOpen = false">
                <div class="flex h-full flex-col rounded-3xl border border-[#403e41] bg-[#2d2a2c] p-4">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-base font-semibold">
                        Поделиться списками
                    </h2>
                    <button
                        type="button"
                        class="rounded-xl border border-[#403e41] p-2 text-[#bcb7ba]"
                        @click="shareModalOpen = false"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>

                <div class="mb-3 flex gap-2">
                    <input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Введите тег..."
                        class="w-full rounded-xl border border-[#403e41] bg-[#221f22] px-3 py-2 text-sm text-[#fcfcfa] focus:border-[#fcfcfa]/45 focus:outline-none"
                        @keyup.enter="findUsers"
                    >
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-xl border border-[#403e41] px-3 py-2 text-sm text-[#fcfcfa]"
                        :disabled="searchBusy"
                        @click="findUsers"
                    >
                        <Search class="h-4 w-4" />
                        Найти
                    </button>
                </div>

                <div class="flex-1 space-y-2 overflow-y-auto">
                    <div
                        v-for="result in searchResults"
                        :key="`search-${result.id}`"
                        class="flex items-center justify-between rounded-2xl border border-[#403e41] bg-[#221f22] px-3 py-3"
                    >
                        <div>
                            <div class="text-sm font-medium text-[#fcfcfa]">
                                {{ result.name }}
                            </div>
                            <div class="text-xs text-[#9f9a9d]">
                                @{{ result.tag }}
                            </div>
                        </div>
                        <button
                            type="button"
                            class="rounded-xl border border-[#403e41] px-3 py-1.5 text-xs font-semibold text-[#fcfcfa] hover:border-[#fcfcfa]/45"
                            @click="sendInvite(result.id)"
                        >
                            Пригласить
                        </button>
                    </div>
                </div>
                </div>
            </div>
        </Transition>

        <Transition name="app-modal">
            <div v-if="inviteModalOpen" class="fixed inset-0 z-[120] bg-[#19181a]/90 p-2.5" @click.self="inviteModalOpen = false">
            <div class="flex h-full flex-col rounded-3xl border border-[#403e41] bg-[#2d2a2c] p-4">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-base font-semibold">
                        Мои приглашения
                    </h2>
                    <button
                        type="button"
                        class="rounded-xl border border-[#403e41] p-2 text-[#bcb7ba]"
                        @click="inviteModalOpen = false"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>

                <div class="mb-3 grid grid-cols-2 gap-2 rounded-2xl bg-[#221f22] p-1">
                    <button
                        type="button"
                        class="rounded-xl py-2 text-sm font-semibold"
                        :class="
                            inviteModalTab === 'invitations'
                                ? 'bg-[#fcfcfa] text-[#19181a]'
                                : 'text-[#9f9a9d]'
                        "
                        @click="inviteModalTab = 'invitations'"
                    >
                        Приглашения
                    </button>
                    <button
                        type="button"
                        class="rounded-xl py-2 text-sm font-semibold"
                        :class="
                            inviteModalTab === 'lists'
                                ? 'bg-[#fcfcfa] text-[#19181a]'
                                : 'text-[#9f9a9d]'
                        "
                        @click="inviteModalTab = 'lists'"
                    >
                        {{ '\u0421\u043f\u0438\u0441\u043a\u0438' }}
                    </button>
                </div>

                <div v-if="inviteModalTab === 'invitations'" class="flex-1 space-y-2 overflow-y-auto">
                    <div
                        v-for="invitation in invitations"
                        :key="`inv-${invitation.id}`"
                        class="rounded-2xl border border-[#403e41] bg-[#221f22] px-3 py-3"
                    >
                        <div class="mb-2 text-sm font-medium text-[#fcfcfa]">
                            {{ invitation.inviter.name }}
                        </div>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                class="rounded-xl bg-[#a5d774]/20 px-3 py-1.5 text-xs font-semibold text-[#a5d774]"
                                @click="acceptInvitation(invitation.id)"
                            >
                                Принять
                            </button>
                            <button
                                type="button"
                                class="rounded-xl bg-[#ee5c81]/20 px-3 py-1.5 text-xs font-semibold text-[#ee5c81]"
                                @click="declineInvitation(invitation.id)"
                            >
                                Отменить
                            </button>
                        </div>
                    </div>
                </div>

                <div v-if="inviteModalTab === 'lists'" class="flex-1 space-y-2 overflow-y-auto">
                    <div
                        v-for="link in links"
                        :key="`link-${link.id}`"
                        class="rounded-2xl border border-[#403e41] bg-[#221f22] px-3 py-3"
                    >
                        <div class="mb-1 text-sm font-medium text-[#fcfcfa]">
                            {{ link.other_user.name }}
                        </div>
                        <div class="mb-2 text-xs text-[#9f9a9d]">
                            Нажмите "Установить моим", чтобы открыть этот список по умолчанию.
                        </div>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                class="rounded-xl bg-[#fcfcfa] px-3 py-1.5 text-xs font-semibold text-[#19181a] disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="!link.can_set_default"
                                @click="setMine(link.id)"
                            >
                                Установить моим
                            </button>
                            <button
                                type="button"
                                class="rounded-xl bg-[#ee5c81]/20 px-3 py-1.5 text-xs font-semibold text-[#ee5c81]"
                                @click="breakLink(link.id)"
                            >
                                Разорвать
                            </button>
                        </div>
                    </div>
                </div>
                </div>
            </div>
        </Transition>

        <Transition name="app-modal">
            <div v-if="productStatsModalOpen" class="fixed inset-0 z-[120] bg-[#19181a]/90 p-2.5" @click.self="productStatsModalOpen = false">
                <div class="flex h-full flex-col rounded-3xl border border-[#403e41] bg-[#2d2a2c] p-4">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-base font-semibold">{{ '\u0421\u0442\u0430\u0442\u0438\u0441\u0442\u0438\u043a\u0430 \u043f\u043e\u043a\u0443\u043f\u043e\u043a' }}</h2>
                        <button type="button" class="rounded-xl border border-[#403e41] p-2 text-[#bcb7ba]" @click="productStatsModalOpen = false">
                            <X class="h-4 w-4" />
                        </button>
                    </div>

                    <p v-if="productSuggestionStatsLoading" class="text-xs text-[#9f9a9d]">{{ '\u041e\u0431\u043d\u043e\u0432\u043b\u0435\u043d\u0438\u0435\u2026' }}</p>
                    <div v-else class="mb-3 grid grid-cols-2 gap-2">
                        <div class="rounded-2xl border border-[#403e41] bg-[#221f22] px-3 py-2">
                            <p class="text-[10px] uppercase tracking-[0.14em] text-[#7f7b7e]">{{ '\u0414\u043e\u0431\u0430\u0432\u043b\u0435\u043d\u043e' }}</p>
                            <p class="mt-1 text-sm font-semibold text-[#fcfcfa]">{{ formatProductStatsSummaryNumber(productStatsSummary.total_added) }}</p>
                        </div>
                        <div class="rounded-2xl border border-[#403e41] bg-[#221f22] px-3 py-2">
                            <p class="text-[10px] uppercase tracking-[0.14em] text-[#7f7b7e]">{{ '\u041a\u0443\u043f\u043b\u0435\u043d\u043e' }}</p>
                            <p class="mt-1 text-sm font-semibold text-[#fcfcfa]">{{ formatProductStatsSummaryNumber(productStatsSummary.total_completed) }}</p>
                        </div>
                        <div class="rounded-2xl border border-[#403e41] bg-[#221f22] px-3 py-2">
                            <p class="text-[10px] uppercase tracking-[0.14em] text-[#7f7b7e]">{{ '\u0423\u043d\u0438\u043a\u0430\u043b\u044c\u043d\u044b\u0445' }}</p>
                            <p class="mt-1 text-sm font-semibold text-[#fcfcfa]">{{ formatProductStatsSummaryNumber(productStatsSummary.unique_products) }}</p>
                        </div>
                        <div class="rounded-2xl border border-[#403e41] bg-[#221f22] px-3 py-2">
                            <p class="text-[10px] uppercase tracking-[0.14em] text-[#7f7b7e]">{{ '\u041a \u043f\u043e\u043a\u0443\u043f\u043a\u0435' }}</p>
                            <p class="mt-1 text-sm font-semibold text-[#fcfcfa]">
                                {{ formatProductStatsSummaryNumber(productStatsSummary.due_suggestions) }}
                                <span class="text-[11px] font-medium text-[#9f9a9d]">/ {{ formatProductStatsSummaryNumber(productStatsSummary.upcoming_suggestions) }}</span>
                            </p>
                        </div>
                        <p class="col-span-2 text-[11px] text-[#9f9a9d]">
                            {{ '\u041f\u043e\u0441\u043b\u0435\u0434\u043d\u044f\u044f \u0430\u043a\u0442\u0438\u0432\u043d\u043e\u0441\u0442\u044c:' }} {{ formatProductStatsDate(productStatsSummary.last_activity_at) }}
                        </p>
                    </div>
                    <p v-if="!productSuggestionStatsLoading && productSuggestionStats.length === 0" class="text-xs text-[#9f9a9d]">{{ '\u041f\u043e\u043a\u0430 \u043d\u0435\u0442 \u0434\u0430\u043d\u043d\u044b\u0445 \u043f\u043e \u043f\u043e\u043a\u0443\u043f\u043a\u0430\u043c.' }}</p>

                    <div v-if="!productSuggestionStatsLoading && productSuggestionStats.length > 0" class="flex-1 space-y-2 overflow-y-auto">
                        <div
                            v-for="entry in productSuggestionStats"
                            :key="`stats-modal-${entry.suggestion_key}`"
                            class="rounded-2xl border border-[#403e41] bg-[#221f22] px-3 py-3"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-[#fcfcfa]">{{ entry.text }}</p>
                                    <p class="mt-1 text-[11px] text-[#9f9a9d]">{{ entry.occurrences }} {{ '\u0440\u0430\u0437' }} / {{ '\u0441\u0440.' }} {{ formatProductStatsInterval(entry.average_interval_seconds) }}</p>
                                    <p class="text-[11px] text-[#9f9a9d]">{{ '\u041f\u043e\u0441\u043b\u0435\u0434\u043d\u044f\u044f \u043f\u043e\u043a\u0443\u043f\u043a\u0430:' }} {{ formatProductStatsDate(entry.last_completed_at) }}</p>
                                </div>

                                <button
                                    type="button"
                                    class="shrink-0 rounded-xl border border-[#403e41] bg-[#2d2a2c] px-2.5 py-1.5 text-[11px] font-semibold text-[#fcfcfa] transition hover:border-[#fcfcfa]/45 disabled:cursor-not-allowed disabled:opacity-55"
                                    :disabled="isResettingSuggestionKey(entry.suggestion_key)"
                                    @click="resetProductSuggestionStatsRow(entry)"
                                >
                                    {{ isResettingSuggestionKey(entry.suggestion_key) ? '\u2026' : '\u0421\u0431\u0440\u043e\u0441\u0438\u0442\u044c' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </div>
</template>

<style scoped>
.item-enter-active,
.item-leave-active {
    transition: all 0.2s ease;
}

.item-enter-from,
.item-leave-to {
    opacity: 0;
    transform: translateY(8px) scale(0.98);
}

.smart-suggestions-enter-active,
.smart-suggestions-leave-active {
    overflow: hidden;
    transition: max-height 0.22s ease, opacity 0.22s ease, transform 0.22s ease;
}

.smart-suggestions-enter-from,
.smart-suggestions-leave-to {
    max-height: 0;
    opacity: 0;
    transform: translateY(-6px);
}

.smart-suggestions-enter-to,
.smart-suggestions-leave-from {
    max-height: 340px;
    opacity: 1;
    transform: translateY(0);
}

.todo-due-modal-enter-active,
.todo-due-modal-leave-active {
    transition: opacity 0.24s cubic-bezier(0.22, 1, 0.36, 1);
}

.todo-due-modal-enter-from,
.todo-due-modal-leave-to {
    opacity: 0;
}

.todo-due-modal-enter-active > div,
.todo-due-modal-leave-active > div {
    transition: transform 0.28s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.28s ease;
}

.todo-due-modal-enter-from > div,
.todo-due-modal-leave-to > div {
    transform: translateY(16px) scale(0.97);
    opacity: 0;
}

.app-modal-enter-active,
.app-modal-leave-active {
    transition: opacity 0.24s cubic-bezier(0.22, 1, 0.36, 1);
}

.app-modal-enter-from,
.app-modal-leave-to {
    opacity: 0;
}

.app-modal-enter-active > div,
.app-modal-leave-active > div {
    transition: transform 0.28s cubic-bezier(0.22, 1, 0.36, 1), opacity 0.28s ease;
}

.app-modal-enter-from > div,
.app-modal-leave-to > div {
    transform: translateY(12px) scale(0.985);
    opacity: 0;
}

.toast-enter-active,
.toast-leave-active {
    transition: opacity 0.2s ease, transform 0.2s ease;
}

.toast-enter-from,
.toast-leave-to {
    opacity: 0;
    transform: translateY(-8px) scale(0.98);
}

.toast-move {
    transition: transform 0.2s ease;
}

.toast-card {
    touch-action: pan-y;
}

.bottom-menu-icon {
    stroke-width: 2.65;
}

.drag-handle {
    cursor: grab;
    user-select: none;
    -webkit-user-select: none;
    touch-action: none;
}

.drag-handle:active {
    cursor: grabbing;
}

.drag-ghost {
    opacity: 0.35;
}

.drag-chosen {
    transform: scale(1.01);
}

.drag-dragging {
    opacity: 0.92;
}

.batch-remove-fly {
    animation: batch-remove-fly 0.19s cubic-bezier(0.2, 0.85, 0.3, 1) forwards;
}

.batch-remove-hidden {
    opacity: 0;
}

@keyframes batch-remove-fly {
    0% {
        opacity: 1;
        transform: translateX(0) scale(1);
    }

    100% {
        opacity: 0;
        transform: translateX(108px) scale(0.96);
    }
}

.batch-collapse-scene {
    position: absolute;
    inset: 0;
}

.batch-collapse-incoming,
.batch-collapse-mega {
    position: absolute;
    border-radius: 16px;
    border: 1px solid rgb(64 62 65 / 88%);
    background: rgb(34 31 34 / 96%);
}

.batch-collapse-incoming {
    transform: translate3d(0, 0, 0) scale(1);
    opacity: 0.94;
    transition-property: transform, opacity, filter;
    transition-duration: var(--collapse-duration, 640ms);
    transition-timing-function: cubic-bezier(0.2, 0.88, 0.34, 1);
    transition-delay: var(--collapse-delay);
}

.batch-collapse-incoming--active {
    opacity: 0;
    filter: brightness(1.2);
    transform: translate3d(var(--collapse-dx), var(--collapse-dy), 0) scale(var(--collapse-target-scale, 0.42));
}

.batch-collapse-mega {
    box-shadow: 0 12px 32px rgb(0 0 0 / 26%);
    transition:
        background-color 1s cubic-bezier(0.2, 0.78, 0.32, 1),
        border-color 1s cubic-bezier(0.2, 0.78, 0.32, 1),
        box-shadow 0.36s cubic-bezier(0.2, 0.78, 0.32, 1),
        transform 0.17s cubic-bezier(0.2, 0.78, 0.32, 1),
        opacity 0.17s linear;
}

.batch-collapse-mega--whiten {
    background: rgb(252 252 250 / 97%);
    border-color: rgb(252 252 250 / 98%);
}

.batch-collapse-mega--glow {
    box-shadow:
        0 0 0 1px rgb(252 252 250 / 48%),
        0 0 34px rgb(252 252 250 / 44%),
        0 16px 40px rgb(0 0 0 / 34%);
}

.batch-collapse-mega--burst {
    opacity: 0;
    transform: scale(1.22);
}

.delete-burst-layer {
    position: fixed;
    inset: 0;
    z-index: 60;
    pointer-events: none;
    overflow: hidden;
}

.animation-skip-layer {
    position: fixed;
    inset: 0;
    z-index: 80;
    touch-action: none;
}

.delete-burst {
    position: absolute;
    left: 0;
    top: 0;
}

.delete-burst-particle {
    position: absolute;
    left: 0;
    top: 0;
    width: 4px;
    height: 4px;
    border-radius: 9999px;
    background: rgb(252 252 250 / 94%);
    opacity: 0;
    transform: translate3d(0, 0, 0) scale(0.95);
    animation: delete-burst-particle 0.34s ease-out forwards;
    animation-delay: var(--particle-delay);
}

.xp-flow-star {
    position: absolute;
    left: 0;
    top: 0;
    width: var(--star-size);
    height: var(--star-size);
    opacity: 0;
    filter: drop-shadow(0 0 6px rgb(255 255 255 / 72%));
    transform: translate3d(0, 0, 0) scale(0.95) rotate(var(--star-rotate));
    transition-property: transform, opacity;
    transition-duration: var(--star-duration), 220ms;
    transition-timing-function: cubic-bezier(0.38, 0.04, 0.96, 1), linear;
    transition-delay: var(--star-delay), var(--star-delay);
    clip-path: polygon(
        50% 0%,
        61% 35%,
        98% 35%,
        68% 57%,
        79% 91%,
        50% 70%,
        21% 91%,
        32% 57%,
        2% 35%,
        39% 35%
    );
    background: radial-gradient(circle at 36% 30%, rgb(255 255 255 / 100%), rgb(255 255 255 / 86%));
}

.xp-flow-star--active {
    opacity: 1;
    transform: translate3d(var(--star-dx), var(--star-dy), 0) scale(0.45) rotate(calc(var(--star-rotate) + 72deg));
}

.xp-levelup-backdrop {
    position: fixed;
    inset: 0;
    z-index: 45;
    pointer-events: none;
    -webkit-backdrop-filter: blur(8px);
    backdrop-filter: blur(8px);
    background: rgb(12 15 24 / 18%);
}

.xp-progress-wrap {
    border-radius: 9999px;
    border: 1px solid rgb(64 62 65 / 88%);
    background: rgb(34 31 34 / 92%);
    padding: 5px;
    box-shadow: 0 6px 20px rgb(0 0 0 / 24%);
}

.xp-progress-wrap--impact {
    animation: xp-progress-impact 0.62s cubic-bezier(0.18, 1.25, 0.32, 1);
}

.xp-progress-track {
    height: 8px;
    width: 100%;
    overflow: hidden;
    border-radius: 9999px;
    background: linear-gradient(180deg, rgb(44 41 44 / 92%), rgb(27 25 27 / 92%));
}

.xp-progress-fill {
    height: 100%;
    width: 0;
    border-radius: 9999px;
    background: linear-gradient(90deg, var(--xp-fill-start, rgb(80 145 255 / 98%)), rgb(252 252 250 / 98%));
    box-shadow:
        0 0 0 1px var(--xp-fill-ring, rgb(183 216 255 / 42%)),
        0 0 14px var(--xp-fill-glow, rgb(130 183 255 / 62%));
    transition: width var(--xp-fill-duration, 240ms) cubic-bezier(0.18, 0.86, 0.28, 1);
}

.xp-progress-fill--instant {
    transition: none;
}

.xp-reward-text {
    margin-bottom: 8px;
    text-align: center;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: rgb(231 242 255 / 98%);
    text-shadow:
        0 0 16px rgb(94 159 255 / 66%),
        0 2px 8px rgb(0 0 0 / 45%);
}

.xp-backdrop-enter-active,
.xp-backdrop-leave-active {
    transition: opacity 0.28s cubic-bezier(0.22, 1, 0.36, 1);
}

.xp-backdrop-enter-from,
.xp-backdrop-leave-to {
    opacity: 0;
}

.xp-reward-enter-active,
.xp-reward-leave-active {
    transition: opacity 0.22s ease, transform 0.32s cubic-bezier(0.2, 0.86, 0.32, 1);
}

.xp-reward-enter-from,
.xp-reward-leave-to {
    opacity: 0;
    transform: translateY(12px) scale(0.96);
}

@keyframes xp-progress-impact {
    0% {
        transform: scale(1);
    }

    28% {
        transform: scale(1.14);
    }

    56% {
        transform: scale(0.95);
    }

    100% {
        transform: scale(1);
    }
}

@keyframes delete-burst-particle {
    0% {
        opacity: 1;
        transform: translate3d(0, 0, 0) scale(1);
    }

    100% {
        opacity: 0;
        transform: translate3d(var(--particle-x), var(--particle-y), 0) scale(0.35);
    }
}
</style>





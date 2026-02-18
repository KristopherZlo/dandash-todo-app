<script setup>
import SwipeListItem from '@/Components/SwipeListItem.vue';
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

const toasts = ref([]);

const productStatsModalOpen = ref(false);
const smartSuggestionsNoticeVisible = ref(true);
const productSuggestionsOpen = ref(true);
const todoSuggestionsOpen = ref(true);
const batchRemovingItemKeys = ref([]);
const batchRemovalAnimating = ref(false);
const deleteFeedbackBursts = ref([]);
const productSuggestionStats = ref([]);
const productSuggestionStatsLoading = ref(false);
const resettingSuggestionKeys = ref([]);

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

const CACHE_VERSION = 'v1';
const OFFLINE_QUEUE_STORAGE_KEY = `dandash:offline-queue:${CACHE_VERSION}:user-${localUser.id}`;
const ITEMS_CACHE_STORAGE_KEY = `dandash:items-cache:${CACHE_VERSION}:user-${localUser.id}`;
const LOCAL_DEFAULT_OWNER_KEY = `dandash:default-owner:${CACHE_VERSION}:user-${localUser.id}`;

let itemsPollTimer = null;
let statePollTimer = null;
let suggestionsPollTimer = null;
let queueSyncTimer = null;
let listChannelName = null;
let userChannelName = null;
let smartSuggestionsNoticeTimer = null;
let swipeUndoTimer = null;
let lastPersistedOwnerId = Number(props.initialState.default_owner_id ?? localUser.id);
let queueSyncInProgress = false;
let nextTempId = -1;
let handleOnlineEvent = null;
let handleOfflineEvent = null;
let nextDeleteFeedbackBurstId = 1;
let skipTodoBlurSaveUntil = 0;
let queuedUpdateTouchedAt = 0;
let queuedUpdateSyncTimer = null;
let nextToastId = 1;

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

    return productSuggestions.value.filter((suggestion) => {
        const comparableSuggestion = normalizeProductComparableText(suggestion?.suggested_text);

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

    return todoSuggestions.value.filter((suggestion) => {
        const comparableSuggestion = normalizeTodoComparableText(suggestion?.suggested_text);

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

const SWIPE_UNDO_WINDOW_MS = 4500;
const BATCH_REMOVE_CARD_ANIMATION_MS = 190;
const UPDATE_SYNC_COALESCE_MS = 1000;
const TOAST_AUTO_CLOSE_MS = 3200;
const TOAST_SWIPE_DISMISS_THRESHOLD = 64;
const PRODUCT_UNIT_ALIASES = {
    // Pieces
    '\u0448\u0442': '\u0448\u0442',
    '\u0448\u0442\u0443\u043a\u0430': '\u0448\u0442',
    '\u0448\u0442\u0443\u043a\u0438': '\u0448\u0442',
    '\u0448\u0442\u0443\u043a': '\u0448\u0442',
    '\u0448\u0442\u0443\u0447\u043a\u0430': '\u0448\u0442',
    '\u0448\u0442\u0443\u0447\u043a\u0438': '\u0448\u0442',
    '\u0448\u0442\u0443\u0447\u0435\u043a': '\u0448\u0442',
    '\u0435\u0434': '\u0448\u0442',
    '\u0435\u0434\u0438\u043d\u0438\u0446\u0430': '\u0448\u0442',
    '\u0435\u0434\u0438\u043d\u0438\u0446\u044b': '\u0448\u0442',
    '\u0435\u0434\u0438\u043d\u0438\u0446': '\u0448\u0442',
    'pc': '\u0448\u0442',
    'pcs': '\u0448\u0442',
    'piece': '\u0448\u0442',
    'pieces': '\u0448\u0442',

    // Weight
    '\u043a\u0433': '\u043a\u0433',
    '\u043a\u0438\u043b\u043e': '\u043a\u0433',
    '\u043a\u0438\u043b\u043e\u0433\u0440\u0430\u043c\u043c': '\u043a\u0433',
    '\u043a\u0438\u043b\u043e\u0433\u0440\u0430\u043c\u043c\u0430': '\u043a\u0433',
    '\u043a\u0438\u043b\u043e\u0433\u0440\u0430\u043c\u043c\u043e\u0432': '\u043a\u0433',
    'kg': '\u043a\u0433',
    'kilo': '\u043a\u0433',
    'kilos': '\u043a\u0433',

    '\u0433': '\u0433',
    '\u0433\u0440': '\u0433',
    '\u0433\u0440\u0430\u043c\u043c': '\u0433',
    '\u0433\u0440\u0430\u043c\u043c\u0430': '\u0433',
    '\u0433\u0440\u0430\u043c\u043c\u043e\u0432': '\u0433',
    'gram': '\u0433',
    'grams': '\u0433',

    // Volume
    '\u043b': '\u043b',
    '\u043b\u0438\u0442\u0440': '\u043b',
    '\u043b\u0438\u0442\u0440\u0430': '\u043b',
    '\u043b\u0438\u0442\u0440\u043e\u0432': '\u043b',
    'l': '\u043b',
    'liter': '\u043b',
    'liters': '\u043b',

    '\u043c\u043b': '\u043c\u043b',
    '\u043c\u0438\u043b\u043b\u0438\u043b\u0438\u0442\u0440': '\u043c\u043b',
    '\u043c\u0438\u043b\u043b\u0438\u043b\u0438\u0442\u0440\u0430': '\u043c\u043b',
    '\u043c\u0438\u043b\u043b\u0438\u043b\u0438\u0442\u0440\u043e\u0432': '\u043c\u043b',
    'ml': '\u043c\u043b',
    'milliliter': '\u043c\u043b',
    'milliliters': '\u043c\u043b',

    // Packs / packages
    '\u0443\u043f': '\u0443\u043f',
    '\u0443\u043f\u0430\u043a': '\u0443\u043f',
    '\u0443\u043f\u0430\u043a\u043e\u0432\u043a\u0430': '\u0443\u043f',
    '\u0443\u043f\u0430\u043a\u043e\u0432\u043a\u0438': '\u0443\u043f',
    '\u0443\u043f\u0430\u043a\u043e\u0432\u043e\u043a': '\u0443\u043f',
    'pack': '\u0443\u043f',
    'packs': '\u0443\u043f',
    'package': '\u0443\u043f',
    'packages': '\u0443\u043f',
    'pkg': '\u0443\u043f',

    '\u043f\u0430\u0447': '\u043f\u0430\u0447',
    '\u043f\u0430\u0447\u043a\u0430': '\u043f\u0430\u0447',
    '\u043f\u0430\u0447\u043a\u0438': '\u043f\u0430\u0447',
    '\u043f\u0430\u0447\u0435\u043a': '\u043f\u0430\u0447',

    '\u043f\u0430\u043a': '\u043f\u0430\u043a',
    '\u043f\u0430\u043a\u0435\u0442': '\u043f\u0430\u043a',
    '\u043f\u0430\u043a\u0435\u0442\u0430': '\u043f\u0430\u043a',
    '\u043f\u0430\u043a\u0435\u0442\u043e\u0432': '\u043f\u0430\u043a',
    'packet': '\u043f\u0430\u043a',
    'packets': '\u043f\u0430\u043a',

    // Containers
    '\u0431\u0443\u0442': '\u0431\u0443\u0442',
    '\u0431\u0443\u0442\u044b\u043b\u043a\u0430': '\u0431\u0443\u0442',
    '\u0431\u0443\u0442\u044b\u043b\u043a\u0438': '\u0431\u0443\u0442',
    '\u0431\u0443\u0442\u044b\u043b\u043e\u043a': '\u0431\u0443\u0442',
    'bottle': '\u0431\u0443\u0442',
    'bottles': '\u0431\u0443\u0442',

    '\u0431\u0430\u043d': '\u0431\u0430\u043d',
    '\u0431\u0430\u043d\u043a\u0430': '\u0431\u0430\u043d',
    '\u0431\u0430\u043d\u043a\u0438': '\u0431\u0430\u043d',
    '\u0431\u0430\u043d\u043e\u043a': '\u0431\u0430\u043d',
    'jar': '\u0431\u0430\u043d',
    'jars': '\u0431\u0430\u043d',

    '\u043a\u043e\u0440': '\u043a\u043e\u0440',
    '\u043a\u043e\u0440\u043e\u0431\u043a\u0430': '\u043a\u043e\u0440',
    '\u043a\u043e\u0440\u043e\u0431\u043a\u0438': '\u043a\u043e\u0440',
    '\u043a\u043e\u0440\u043e\u0431\u043e\u043a': '\u043a\u043e\u0440',
    'box': '\u043a\u043e\u0440',
    'boxes': '\u043a\u043e\u0440',

    '\u0440\u0443\u043b': '\u0440\u0443\u043b',
    '\u0440\u0443\u043b\u043e\u043d': '\u0440\u0443\u043b',
    '\u0440\u0443\u043b\u043e\u043d\u0430': '\u0440\u0443\u043b',
    '\u0440\u0443\u043b\u043e\u043d\u043e\u0432': '\u0440\u0443\u043b',
    'roll': '\u0440\u0443\u043b',
    'rolls': '\u0440\u0443\u043b',

    // Dozen / portion
    '\u0434\u044e\u0436': '\u0434\u044e\u0436',
    '\u0434\u044e\u0436\u0438\u043d\u0430': '\u0434\u044e\u0436',
    '\u0434\u044e\u0436\u0438\u043d\u044b': '\u0434\u044e\u0436',
    '\u0434\u044e\u0436\u0438\u043d': '\u0434\u044e\u0436',
    'dozen': '\u0434\u044e\u0436',
    'dozens': '\u0434\u044e\u0436',
    'dz': '\u0434\u044e\u0436',

    '\u043f\u043e\u0440\u0446': '\u043f\u043e\u0440\u0446',
    '\u043f\u043e\u0440\u0446\u0438\u044f': '\u043f\u043e\u0440\u0446',
    '\u043f\u043e\u0440\u0446\u0438\u0438': '\u043f\u043e\u0440\u0446',
    '\u043f\u043e\u0440\u0446\u0438\u0439': '\u043f\u043e\u0440\u0446',
    'portion': '\u043f\u043e\u0440\u0446',
    'portions': '\u043f\u043e\u0440\u0446',
};
const PRODUCT_UNIT_PATTERN = Object.keys(PRODUCT_UNIT_ALIASES)
    .map((token) => token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
    .sort((left, right) => right.length - left.length)
    .join('|');

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

function isCurrentListContext(ownerId, linkId = null) {
    return Number(ownerId) === Number(selectedOwnerId.value)
        && normalizeLinkId(linkId) === normalizeLinkId(selectedListLinkId.value);
}

function itemViewKey(item) {
    return String(item?.local_id ?? item?.id ?? '');
}

function isBatchRemovingItem(item) {
    return batchRemovingItemKeys.value.includes(itemViewKey(item));
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
    const previousLocalIdById = new Map(
        (Array.isArray(previousItems) ? previousItems : [])
            .map((item) => [Number(item?.id), String(item?.local_id ?? '').trim()])
            .filter(([id, localId]) => Number.isFinite(id) && localId !== ''),
    );

    return sortItems((items ?? []).map((item) => {
        const normalized = normalizeItem(item, null, context);
        const preservedLocalId = previousLocalIdById.get(Number(normalized.id));

        if (preservedLocalId) {
            normalized.local_id = preservedLocalId;
        }

        return normalized;
    }));
}

function readListFromCache(ownerId, type, linkId = undefined) {
    const key = listCacheKey(ownerId, type, resolveLinkIdForOwner(ownerId, linkId));
    return cloneItems(cachedItemsByList.value[key]);
}

function writeListToCache(ownerId, type, items, linkId = undefined) {
    const resolvedLinkId = resolveLinkIdForOwner(ownerId, linkId);
    const key = listCacheKey(ownerId, type, resolvedLinkId);
    const normalized = sortItems(items);
    const previous = Array.isArray(cachedItemsByList.value[key]) ? cachedItemsByList.value[key] : [];
    const cacheChanged = !areItemsEquivalent(previous, normalized);

    if (cacheChanged) {
        cachedItemsByList.value[key] = normalized;
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

function hydrateSelectedListsFromCache() {
    setVisibleItems('product', readListFromCache(selectedOwnerId.value, 'product', selectedListLinkId.value));
    setVisibleItems('todo', readListFromCache(selectedOwnerId.value, 'todo', selectedListLinkId.value));
}

function persistQueue() {
    if (typeof window === 'undefined') {
        return;
    }

    window.localStorage.setItem(OFFLINE_QUEUE_STORAGE_KEY, JSON.stringify(offlineQueue.value));
}

function loadOfflineStateFromStorage() {
    if (typeof window === 'undefined') {
        return;
    }

    const parsedQueue = parseJson(window.localStorage.getItem(OFFLINE_QUEUE_STORAGE_KEY), []);
    const parsedCache = parseJson(window.localStorage.getItem(ITEMS_CACHE_STORAGE_KEY), {});

    offlineQueue.value = Array.isArray(parsedQueue) ? parsedQueue : [];
    cachedItemsByList.value = parsedCache && typeof parsedCache === 'object' && !Array.isArray(parsedCache)
        ? parsedCache
        : {};

    const cachedTempIds = Object.values(cachedItemsByList.value)
        .flatMap((items) => (Array.isArray(items) ? items : []))
        .map((item) => Number(item?.id))
        .filter((id) => Number.isFinite(id) && id < 0);
    const queuedTempIds = offlineQueue.value
        .map((operation) => Number(operation?.item_id))
        .filter((id) => Number.isFinite(id) && id < 0);
    const minTempId = Math.min(-1, ...cachedTempIds, ...queuedTempIds);
    nextTempId = Number.isFinite(minTempId) ? minTempId : -1;

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

function removeLocalItem(ownerId, type, itemId, linkId = undefined) {
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
        syncOfflineQueue().catch((error) => {
            showError(error);
        });
    }, safeDelay);
}

function markQueuedUpdateTouched() {
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

    if (createIndex !== -1) {
        offlineQueue.value[createIndex] = {
            ...offlineQueue.value[createIndex],
            payload: {
                ...offlineQueue.value[createIndex].payload,
                ...payload,
            },
        };
        persistQueue();
        markQueuedUpdateTouched();
        return;
    }

    const updateIndex = findQueueIndexFromEnd(
        (operation) =>
            operation.action === 'update'
            && Number(operation.item_id) === numericItemId
            && normalizeLinkId(operation.link_id) === resolvedLinkId,
    );

    if (updateIndex !== -1) {
        offlineQueue.value[updateIndex] = {
            ...offlineQueue.value[updateIndex],
            payload: {
                ...offlineQueue.value[updateIndex].payload,
                ...payload,
            },
        };
        persistQueue();
        markQueuedUpdateTouched();
        return;
    }

    enqueueOperation({
        action: 'update',
        owner_id: Number(ownerId),
        link_id: resolvedLinkId,
        type,
        item_id: numericItemId,
        payload: { ...payload },
    });
    markQueuedUpdateTouched();
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

    if (createIndex !== -1) {
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

async function finalizeSwipeAction(state) {
    if (!state) {
        return;
    }

    stageSwipeAction(state);
    await syncOfflineQueue();

    if (!hasPendingOperations(state.ownerId, state.type, state.linkId)) {
        await loadSuggestions(state.type);
    }
}

async function flushSwipeUndoState() {
    if (!swipeUndoState.value) {
        return;
    }

    const pendingState = swipeUndoState.value;
    swipeUndoState.value = null;
    clearSwipeUndoTimer();
    await finalizeSwipeAction(pendingState);
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

        finalizeSwipeAction(pendingState).catch(showError);
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

async function removeCompletedAfterSwipe(event = null) {
    if (!swipeUndoState.value || swipeUndoState.value.action !== 'remove') {
        return;
    }

    const activeState = swipeUndoState.value;
    if (!activeState.canRemoveCompletedBatch || batchRemovalAnimating.value) {
        return;
    }

    triggerDeleteBurst(event);

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
    batchRemovingItemKeys.value = completedEntries.map((entry) => itemViewKey(entry.item));

    await waitForMs(BATCH_REMOVE_CARD_ANIMATION_MS);

    if (swipeUndoState.value !== activeState || swipeUndoState.value?.action !== 'remove') {
        batchRemovingItemKeys.value = [];
        batchRemovalAnimating.value = false;
        return;
    }

    applyLocalUpdate(ownerId, type, (items) => items.filter((entry) => !entry.is_completed), linkId);
    batchRemovingItemKeys.value = [];
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
    });
}

async function syncOfflineQueue() {
    if (queueSyncInProgress || offlineQueue.value.length === 0 || browserOffline.value) {
        return;
    }

    queueSyncInProgress = true;

    try {
        while (offlineQueue.value.length > 0) {
            const operation = offlineQueue.value[0];

            try {
                if (operation.action === 'create') {
                    const response = await requestApi(() => window.axios.post('api/items', {
                        owner_id: operation.owner_id,
                        link_id: normalizeLinkId(operation.link_id),
                        type: operation.type,
                        text: operation.payload.text,
                        quantity: operation.type === 'product' ? (operation.payload.quantity ?? null) : null,
                        unit: operation.type === 'product' ? (operation.payload.unit ?? null) : null,
                        due_at: operation.type === 'todo' ? (operation.payload.due_at ?? null) : null,
                        priority: operation.type === 'todo' ? normalizeTodoPriority(operation.payload.priority) : null,
                    }));

                    let syncedItem = normalizeItem(response.data.item, `srv-${response.data.item.id}`, {
                        ownerIdOverride: operation.owner_id,
                        linkIdOverride: operation.link_id,
                    });
                    if (operation.payload.is_completed) {
                        const completedResponse = await requestApi(() => window.axios.patch(`api/items/${syncedItem.id}`, {
                            is_completed: true,
                        }));
                        syncedItem = normalizeItem(completedResponse.data.item, `srv-${completedResponse.data.item.id}`, {
                            ownerIdOverride: operation.owner_id,
                            linkIdOverride: operation.link_id,
                        });
                    }
                    const previousTempId = Number(operation.item_id);

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

                    rewriteQueuedItemId(previousTempId, syncedItem.id);
                    dropQueueOperation(operation.op_id);
                    continue;
                }

                if (operation.action === 'update') {
                    if (Number(operation.item_id) <= 0) {
                        dropQueueOperation(operation.op_id);
                        continue;
                    }

                    const quietRemainingMs = getQueuedUpdateQuietRemainingMs();
                    if (quietRemainingMs > 0) {
                        scheduleQueuedUpdateSync(quietRemainingMs);
                        break;
                    }

                    const response = await requestApi(() => window.axios.patch(`api/items/${operation.item_id}`, {
                        ...operation.payload,
                    }));

                    const updatedItem = normalizeItem(response.data.item, `srv-${response.data.item.id}`, {
                        ownerIdOverride: operation.owner_id,
                        linkIdOverride: operation.link_id,
                    });
                    upsertLocalItem(operation.owner_id, operation.type, updatedItem, {
                        linkId: operation.link_id,
                    });
                    dropQueueOperation(operation.op_id);
                    continue;
                }

                if (operation.action === 'delete') {
                    if (Number(operation.item_id) <= 0) {
                        dropQueueOperation(operation.op_id);
                        continue;
                    }

                    await requestApi(() => window.axios.delete(`api/items/${operation.item_id}`));
                    dropQueueOperation(operation.op_id);
                    continue;
                }

                if (operation.action === 'reorder') {
                    const order = Array.isArray(operation.payload?.order)
                        ? operation.payload.order
                            .map((entry) => Number(entry))
                            .filter((entry) => Number.isFinite(entry) && entry > 0)
                        : [];

                    if (order.length === 0) {
                        dropQueueOperation(operation.op_id);
                        continue;
                    }

                    await requestApi(() => window.axios.post('api/items/reorder', {
                        owner_id: operation.owner_id,
                        link_id: normalizeLinkId(operation.link_id),
                        type: operation.type,
                        order,
                    }));
                    dropQueueOperation(operation.op_id);
                    continue;
                }

                dropQueueOperation(operation.op_id);
            } catch (error) {
                if (isConnectivityError(error)) {
                    break;
                }

                const statusCode = Number(error?.response?.status ?? 0);

                if (operation.action === 'delete' && statusCode === 404) {
                    dropQueueOperation(operation.op_id);
                    continue;
                }

                if (operation.action === 'update' && statusCode === 404) {
                    removeLocalItem(operation.owner_id, operation.type, operation.item_id, operation.link_id);
                    dropQueueOperation(operation.op_id);
                    continue;
                }

                if (operation.action === 'create') {
                    removeLocalItem(operation.owner_id, operation.type, operation.item_id, operation.link_id);
                }

                dropQueueOperation(operation.op_id);

                if (operation.action === 'update' || operation.action === 'delete' || operation.action === 'reorder') {
                    await loadItems(operation.type, false, operation.owner_id, operation.link_id);
                }

                showError(error);
            }
        }
    } finally {
        queueSyncInProgress = false;
    }
}

function resetMessages() {
    // Toast-based notifications do not need hard resets.
}

function clearToastTimer(toast) {
    if (!toast || typeof window === 'undefined') {
        return;
    }

    if (toast.timerId) {
        clearTimeout(toast.timerId);
        toast.timerId = null;
    }
}

function removeToast(toastId) {
    const numericId = Number(toastId);
    const toast = toasts.value.find((entry) => Number(entry.id) === numericId);
    if (toast) {
        clearToastTimer(toast);
    }

    toasts.value = toasts.value.filter((entry) => Number(entry.id) !== numericId);
}

function scheduleToastAutoclose(toast, duration = TOAST_AUTO_CLOSE_MS) {
    if (!toast || typeof window === 'undefined') {
        return;
    }

    clearToastTimer(toast);
    const safeDuration = Math.max(900, Number(duration) || TOAST_AUTO_CLOSE_MS);

    toast.timerId = window.setTimeout(() => {
        removeToast(toast.id);
    }, safeDuration);
}

function pushToast(message, type = 'info', duration = TOAST_AUTO_CLOSE_MS) {
    const normalizedMessage = String(message ?? '').trim();
    if (!normalizedMessage) {
        return;
    }

    const toast = {
        id: nextToastId,
        type,
        message: normalizedMessage,
        deltaX: 0,
        startX: 0,
        dragging: false,
        timerId: null,
        duration: Math.max(900, Number(duration) || TOAST_AUTO_CLOSE_MS),
    };
    nextToastId += 1;

    toasts.value = [...toasts.value, toast];
    scheduleToastAutoclose(toast, toast.duration);
}

function resolvePointerClientX(event) {
    if (!event) {
        return null;
    }

    if (typeof event.clientX === 'number') {
        return Number(event.clientX);
    }

    if ('touches' in event && event.touches?.length > 0) {
        return Number(event.touches[0].clientX);
    }

    if ('changedTouches' in event && event.changedTouches?.length > 0) {
        return Number(event.changedTouches[0].clientX);
    }

    return null;
}

function findToastById(toastId) {
    const numericId = Number(toastId);
    return toasts.value.find((entry) => Number(entry.id) === numericId) ?? null;
}

function onToastPointerDown(toastId, event) {
    const toast = findToastById(toastId);
    if (!toast) {
        return;
    }

    const clientX = resolvePointerClientX(event);
    if (clientX === null) {
        return;
    }

    toast.dragging = true;
    toast.startX = clientX;
    toast.deltaX = 0;
    clearToastTimer(toast);
}

function onToastPointerMove(toastId, event) {
    const toast = findToastById(toastId);
    if (!toast || !toast.dragging) {
        return;
    }

    const clientX = resolvePointerClientX(event);
    if (clientX === null) {
        return;
    }

    const delta = clientX - toast.startX;
    toast.deltaX = Math.max(-220, Math.min(220, delta));
}

function onToastPointerUp(toastId) {
    const toast = findToastById(toastId);
    if (!toast) {
        return;
    }

    const dismiss = Math.abs(toast.deltaX) >= TOAST_SWIPE_DISMISS_THRESHOLD;

    if (dismiss) {
        removeToast(toast.id);
        return;
    }

    toast.dragging = false;
    toast.deltaX = 0;
    scheduleToastAutoclose(toast, toast.duration);
}

function onToastPointerCancel(toastId) {
    onToastPointerUp(toastId);
}

function showStatus(message) {
    pushToast(message, 'success', TOAST_AUTO_CLOSE_MS);
}

function showError(error) {
    const fallback = '\u041f\u0440\u043e\u0438\u0437\u043e\u0448\u043b\u0430 \u043e\u0448\u0438\u0431\u043a\u0430. \u041f\u043e\u043f\u0440\u043e\u0431\u0443\u0439\u0442\u0435 \u0435\u0449\u0451 \u0440\u0430\u0437.';
    const responseErrors = error?.response?.data?.errors;
    const firstError = responseErrors ? Object.values(responseErrors)[0]?.[0] : null;
    pushToast(firstError || error?.response?.data?.message || fallback, 'error', 4400);
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

function normalizeQuantityInput(value) {
    const raw = String(value ?? '').trim().replace(',', '.');
    if (raw === '') {
        return null;
    }

    const parsed = Number(raw);
    if (!Number.isFinite(parsed) || parsed <= 0) {
        return null;
    }

    return Math.round(parsed * 100) / 100;
}

function normalizeUnitInput(value) {
    const rawUnit = String(value ?? '')
        .trim()
        .toLowerCase()
        .replace(/\u0451/g, '\u0435')
        .replace(/[.,;:]+$/g, '');

    if (rawUnit === '') {
        return null;
    }

    const normalized = PRODUCT_UNIT_ALIASES[rawUnit];
    if (!normalized) {
        return null;
    }

    return normalized;
}

function parseProductTextPayload(rawText) {
    const source = String(rawText ?? '').trim();
    if (source === '') {
        return {
            text: '',
            quantity: null,
            unit: null,
        };
    }

    const numberPattern = '(\\d+(?:[\\.,]\\d{1,2})?)';
    const unitPattern = `(${PRODUCT_UNIT_PATTERN})`;
    const separatorPattern = '[\\.,;:]?';
    const prefixMatcher = new RegExp(`^${numberPattern}\\s*${unitPattern}${separatorPattern}\\s+(.+)$`, 'iu');
    const suffixMatcher = new RegExp(`^(.+?)\\s+${numberPattern}\\s*${unitPattern}${separatorPattern}$`, 'iu');

    let text = source;
    let quantity = null;
    let unit = null;

    const prefixMatch = source.match(prefixMatcher);
    if (prefixMatch) {
        quantity = normalizeQuantityInput(prefixMatch[1]);
        unit = normalizeUnitInput(prefixMatch[2]);
        text = String(prefixMatch[3] ?? '').trim();
    } else {
        const suffixMatch = source.match(suffixMatcher);
        if (suffixMatch) {
            text = String(suffixMatch[1] ?? '').trim();
            quantity = normalizeQuantityInput(suffixMatch[2]);
            unit = normalizeUnitInput(suffixMatch[3]);
        }
    }

    if (quantity === null || unit === null || text === '') {
        return {
            text: source,
            quantity: null,
            unit: null,
        };
    }

    return {
        text,
        quantity,
        unit,
    };
}

function normalizeProductComparableText(value) {
    const source = String(value ?? '').trim();
    if (source === '') {
        return '';
    }

    const parsed = parseProductTextPayload(source);
    const baseText = String(parsed.text ?? source).trim();

    return baseText
        .toLowerCase()
        .replace(/\u0451/g, '\u0435')
        .replace(/[^\p{L}\p{N}\s]+/gu, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function normalizeTodoComparableText(value) {
    const source = String(value ?? '').trim();
    if (source === '') {
        return '';
    }

    return source
        .toLowerCase()
        .replace(/\u0451/g, '\u0435')
        .replace(/[^\p{L}\p{N}\s]+/gu, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function formatQuantityValue(value) {
    const quantity = Number(value);
    if (!Number.isFinite(quantity)) {
        return '';
    }

    if (Number.isInteger(quantity)) {
        return String(quantity);
    }

    return String(Math.round(quantity * 100) / 100).replace(/\.?0+$/, '');
}

function getProductDisplayParts(item) {
    const sourceText = String(item?.text ?? '').trim();
    const explicitQuantity = normalizeQuantityInput(item?.quantity);
    const explicitUnitRaw = String(item?.unit ?? '').trim();
    const explicitUnitNormalized = explicitQuantity !== null ? normalizeUnitInput(item?.unit) : null;
    const explicitUnit = explicitQuantity !== null
        ? (explicitUnitNormalized ?? (explicitUnitRaw === '' ? null : explicitUnitRaw.slice(0, 24)))
        : null;

    if (explicitQuantity !== null) {
        return {
            text: sourceText,
            quantity: explicitQuantity,
            unit: explicitUnit,
        };
    }

    const parsed = parseProductTextPayload(sourceText);
    if (parsed.quantity !== null && parsed.text !== '') {
        return parsed;
    }

    return {
        text: sourceText,
        quantity: null,
        unit: null,
    };
}

function getProductDisplayText(item) {
    const parts = getProductDisplayParts(item);
    return parts.text;
}

function formatProductMeasure(item) {
    if (item?.type !== 'product') {
        return '';
    }

    const parts = getProductDisplayParts(item);
    const quantity = parts.quantity !== null ? formatQuantityValue(parts.quantity) : '';
    const unit = parts.unit;

    if (quantity && unit) {
        return `${quantity} ${unit}`;
    }

    if (quantity) {
        return quantity;
    }

    if (unit) {
        return unit;
    }

    return '';
}

function buildProductEditableText(item) {
    const parts = getProductDisplayParts(item);
    const text = parts.text;
    const quantity = parts.quantity !== null ? formatQuantityValue(parts.quantity) : '';
    const unit = parts.unit;

    if (!quantity) {
        return text;
    }

    if (unit) {
        return `${text} ${quantity} ${unit}`.trim();
    }

    return `${text} ${quantity}`.trim();
}

function normalizeTodoPriority(value, fallback = null) {
    const normalized = String(value ?? '').trim().toLowerCase();

    if (normalized === 'urgent' || normalized === 'today' || normalized === 'later') {
        return normalized;
    }

    return fallback;
}

function inferTodoPriorityFromDueAt(isoValue) {
    const dueAt = isoValue ? new Date(isoValue) : null;
    if (!dueAt || Number.isNaN(dueAt.getTime())) {
        return 'later';
    }

    const now = new Date();
    const diffMs = dueAt.getTime() - now.getTime();
    const isToday = dueAt.toDateString() === now.toDateString();

    if (diffMs <= 2 * 60 * 60 * 1000) {
        return 'urgent';
    }

    if (isToday || diffMs <= 24 * 60 * 60 * 1000) {
        return 'today';
    }

    return 'later';
}

function getTodoPriority(item) {
    return normalizeTodoPriority(item?.priority, inferTodoPriorityFromDueAt(item?.due_at));
}

function todoPriorityLabel(item) {
    const priority = getTodoPriority(item);

    if (priority === 'urgent') {
        return '\u0421\u0440\u043e\u0447\u043d\u043e';
    }

    if (priority === 'today') {
        return '\u0421\u0435\u0433\u043e\u0434\u043d\u044f';
    }

    return '\u041f\u043e\u0442\u043e\u043c';
}

function todoPriorityClass(item) {
    const priority = getTodoPriority(item);

    if (priority === 'urgent') {
        return 'border-[#ee5c81]/55 bg-[#ee5c81]/14 text-[#ee5c81]';
    }

    if (priority === 'today') {
        return 'border-[#d4b06e]/55 bg-[#d4b06e]/14 text-[#d4b06e]';
    }

    return 'border-[#a5d774]/50 bg-[#a5d774]/12 text-[#a5d774]';
}

function nextTodoPriority(priority) {
    if (priority === 'urgent') {
        return 'today';
    }

    if (priority === 'today') {
        return 'later';
    }

    return 'urgent';
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

function formatIntervalSeconds(rawSeconds) {
    const seconds = Math.max(0, Number(rawSeconds) || 0);

    if (seconds >= 86400) {
        return `${Math.round((seconds / 86400) * 10) / 10} \u0434\u043d.`;
    }

    if (seconds >= 3600) {
        return `${Math.round((seconds / 3600) * 10) / 10} \u0447`;
    }

    return `${Math.max(1, Math.round(seconds / 60))} \u043c\u0438\u043d`;
}

function suggestionStatusText(suggestion, type) {
    if (suggestion.is_due || Number(suggestion.seconds_until_expected) <= 0) {
        return type === 'product' ? 'Пора купить снова' : 'Пора запланировать снова';
    }

    return `Через ${formatIntervalSeconds(suggestion.seconds_until_expected)}`;
}

async function applySuggestionToList(type, suggestion) {
    const text = String(suggestion?.suggested_text ?? '').trim();
    if (!text) {
        return;
    }

    resetMessages();

    try {
        await createItemOptimistically(type, text);
        removeSuggestionFromView(type, suggestion);

        if (!hasPendingOperations(selectedOwnerId.value, type, selectedListLinkId.value)) {
            await loadSuggestions(type);
        }
    } catch (error) {
        showError(error);
    }
}

async function dismissSuggestion(type, suggestion) {
    const suggestionKey = getSuggestionKey(suggestion);
    if (!suggestionKey) {
        return;
    }

    resetMessages();
    removeSuggestionFromView(type, suggestion);

    try {
        if (browserOffline.value) {
            return;
        }

        await requestApi(() => window.axios.post('api/items/suggestions/dismiss', {
            owner_id: selectedOwnerId.value,
            link_id: selectedListLinkId.value,
            type,
            suggestion_key: suggestionKey,
            average_interval_seconds: Number(suggestion?.average_interval_seconds ?? 0),
        }));

        await loadSuggestions(type);
    } catch (error) {
        if (isConnectivityError(error)) {
            return;
        }

        showError(error);
    }
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

    if (type === 'product') {
        productSuggestions.value = productSuggestions.value.filter(
            (entry) => getSuggestionKey(entry) !== suggestionKey,
        );
        return;
    }

    todoSuggestions.value = todoSuggestions.value.filter(
        (entry) => getSuggestionKey(entry) !== suggestionKey,
    );
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

    pendingInvitationsCount.value = state.pending_invitations_count ?? 0;
    invitations.value = state.invitations ?? [];
    links.value = state.links ?? [];
    listOptions.value = state.list_options ?? [];

    const defaultOwnerId = Number(state.default_owner_id ?? localUser.id);
    const defaultExists = listOptions.value.some((option) => Number(option.owner_id) === defaultOwnerId);
    const selectedExists = listOptions.value.some((option) => Number(option.owner_id) === Number(selectedOwnerId.value));

    if (defaultExists) {
        lastPersistedOwnerId = defaultOwnerId;
        persistLocalDefaultOwner(defaultOwnerId);
    }

    if (syncSelection && defaultExists) {
        selectedOwnerId.value = defaultOwnerId;
        return;
    }

    if (!selectedExists && defaultExists) {
        selectedOwnerId.value = defaultOwnerId;
        return;
    }

    if (!selectedExists) {
        selectedOwnerId.value = localUser.id;
    }
}

async function refreshState(showErrors = false, syncSelection = false) {
    if (browserOffline.value) {
        return;
    }

    try {
        const response = await requestApi(() => window.axios.get('api/sync/state'));
        applyState(response.data, { syncSelection });
    } catch (error) {
        if (isConnectivityError(error)) {
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
    const assignCached = () => {
        if (!isCurrentListContext(ownerId, linkId)) {
            return;
        }

        const cached = readListFromCache(ownerId, type, linkId);
        setVisibleItems(type, cached);
    };

    try {
        if (
            browserOffline.value
            || hasPendingOperations(ownerId, type, linkId)
            || hasPendingSwipeAction(ownerId, type, linkId)
        ) {
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

        const normalizedItems = normalizeItems(response.data.items ?? [], cachedBeforeRequest, {
            ownerIdOverride: ownerId,
            linkIdOverride: linkId,
        });
        writeListToCache(ownerId, type, normalizedItems, linkId);
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

    if (browserOffline.value) {
        return;
    }

    suggestionsLoading[type] = true;

    try {
        const response = await requestApi(() => window.axios.get('api/items/suggestions', {
            params: {
                owner_id: selectedOwnerId.value,
                link_id: selectedListLinkId.value,
                type,
                limit: 6,
            },
        }));

        if (type === 'product') {
            productSuggestions.value = response.data.suggestions ?? [];
            return;
        }

        todoSuggestions.value = response.data.suggestions ?? [];
    } catch (error) {
        if (isConnectivityError(error)) {
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

    if (browserOffline.value) {
        return;
    }

    productSuggestionStatsLoading.value = true;

    try {
        const response = await requestApi(() => window.axios.get('api/items/suggestions/stats', {
            params: {
                owner_id: selectedOwnerId.value,
                link_id: selectedListLinkId.value,
                limit: 50,
            },
        }));

        productSuggestionStats.value = Array.isArray(response.data?.stats)
            ? response.data.stats
            : [];
    } catch (error) {
        if (isConnectivityError(error)) {
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
        await requestApi(() => window.axios.post('api/items/suggestions/reset', {
            owner_id: selectedOwnerId.value,
            link_id: selectedListLinkId.value,
            type: 'product',
            suggestion_key: suggestionKey,
        }));

        await Promise.all([
            loadProductSuggestionStats(false),
            loadSuggestions('product'),
        ]);

        showStatus('\u0414\u0430\u043d\u043d\u044b\u0435 \u043f\u043e\u0434\u0441\u043a\u0430\u0437\u043e\u043a \u0441\u0431\u0440\u043e\u0448\u0435\u043d\u044b.');
    } catch (error) {
        if (isConnectivityError(error)) {
            return;
        }

        showError(error);
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

    try {
        const response = await requestApi(() => window.axios.post('api/sync/default-owner', {
            owner_id: normalizedOwnerId,
        }));

        applyState(response.data);
        lastPersistedOwnerId = Number(response.data.default_owner_id ?? normalizedOwnerId);
        persistLocalDefaultOwner(lastPersistedOwnerId);
    } catch (error) {
        if (isConnectivityError(error)) {
            return;
        }

        showError(error);
    }
}

async function toggleCompleted(item) {
    resetMessages();
    await flushSwipeUndoState();

    const { ownerId, linkId } = resolveItemContext(item);
    const nextCompleted = !item.is_completed;
    const nextSortOrder = nextSortOrderForLocalList(ownerId, item.type, nextCompleted, linkId);

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

    startSwipeUndoState({
        action: 'toggle',
        ownerId,
        linkId,
        type: item.type,
        item: { ...item },
        nextCompleted,
        nextSortOrder,
    });
}

async function removeItem(item) {
    resetMessages();
    await flushSwipeUndoState();

    const { ownerId, linkId } = resolveItemContext(item);
    const currentItems = readListFromCache(ownerId, item.type, linkId);
    const previousIndex = currentItems.findIndex((entry) => Number(entry.id) === Number(item.id));
    const completedItemsCount = currentItems.filter((entry) => entry.is_completed).length;
    const canRemoveCompletedBatch = Boolean(item.is_completed && completedItemsCount > 2);

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

    if (query.length < 2) {
        searchResults.value = [];
        return;
    }

    if (browserOffline.value) {
        searchResults.value = [];
        return;
    }

    searchBusy.value = true;

    try {
        const response = await requestApi(() => window.axios.get('api/users/search', {
            params: { query },
        }));

        searchResults.value = response.data.users ?? [];
    } catch (error) {
        if (isConnectivityError(error)) {
            searchResults.value = [];
            return;
        }

        showError(error);
    } finally {
        searchBusy.value = false;
    }
}

async function sendInvite(userId) {
    resetMessages();

    try {
        await requestApi(() => window.axios.post('api/invitations', {
            user_id: userId,
        }));

        showStatus('Приглашение отправлено.');
        await refreshState();
    } catch (error) {
        if (isConnectivityError(error)) {
            return;
        }

        showError(error);
    }
}

async function acceptInvitation(invitationId) {
    resetMessages();

    try {
        const response = await requestApi(() => window.axios.post(`api/invitations/${invitationId}/accept`));
        applyState(response.data, { syncSelection: true });
        showStatus('Приглашение принято.');
    } catch (error) {
        if (isConnectivityError(error)) {
            return;
        }

        showError(error);
    }
}

async function declineInvitation(invitationId) {
    resetMessages();

    try {
        const response = await requestApi(() => window.axios.post(`api/invitations/${invitationId}/decline`));
        applyState(response.data, { syncSelection: true });
        showStatus('Приглашение отменено.');
    } catch (error) {
        if (isConnectivityError(error)) {
            return;
        }

        showError(error);
    }
}

async function setMine(linkId) {
    resetMessages();

    try {
        const response = await requestApi(() => window.axios.post(`api/links/${linkId}/set-mine`));
        applyState(response.data, { syncSelection: true });
        showStatus('Список установлен по умолчанию.');
    } catch (error) {
        if (isConnectivityError(error)) {
            return;
        }

        showError(error);
    }
}

async function breakLink(linkId) {
    resetMessages();

    try {
        const response = await requestApi(() => window.axios.delete(`api/links/${linkId}`));
        applyState(response.data, { syncSelection: true });
        showStatus('Связь списков разорвана.');
    } catch (error) {
        if (isConnectivityError(error)) {
            return;
        }

        showError(error);
    }
}

async function saveProfile() {
    resetMessages();
    profileForm.loading = true;

    try {
        const response = await requestApi(() => window.axios.patch('api/profile', {
            name: profileForm.name,
            tag: profileForm.tag,
            email: profileForm.email,
        }));

        localUser.name = response.data.user.name;
        localUser.tag = response.data.user.tag;
        localUser.email = response.data.user.email;
        showStatus('Профиль обновлен.');
    } catch (error) {
        if (isConnectivityError(error)) {
            return;
        }

        showError(error);
    } finally {
        profileForm.loading = false;
    }
}

async function savePassword() {
    resetMessages();
    passwordForm.loading = true;

    try {
        await requestApi(() => window.axios.put('api/profile/password', {
            current_password: passwordForm.current_password,
            password: passwordForm.password,
            password_confirmation: passwordForm.password_confirmation,
        }));

        passwordForm.current_password = '';
        passwordForm.password = '';
        passwordForm.password_confirmation = '';
        showStatus('Пароль обновлен.');
    } catch (error) {
        if (isConnectivityError(error)) {
            return;
        }

        showError(error);
    } finally {
        passwordForm.loading = false;
    }
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

    await persistDefaultOwner(ownerId);
    await syncOfflineQueue();

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

onMounted(async () => {
    loadOfflineStateFromStorage();
    hydrateSelectedListsFromCache();

    if (typeof window !== 'undefined') {
        isBrowserOnline.value = window.navigator.onLine;
    }

    await syncOfflineQueue();
    await refreshState(false, true);
    await Promise.all([loadAllItems(), loadAllSuggestions()]);

    if (activeTab.value === 'profile') {
        await loadProductSuggestionStats();
    }

    smartSuggestionsNoticeTimer = window.setTimeout(() => {
        smartSuggestionsNoticeVisible.value = false;
    }, 9000);

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
    if (swipeUndoState.value) {
        stageSwipeAction(swipeUndoState.value);
        swipeUndoState.value = null;
    }
    clearSwipeUndoTimer();
    batchRemovingItemKeys.value = [];
    batchRemovalAnimating.value = false;
    deleteFeedbackBursts.value = [];

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

    if (smartSuggestionsNoticeTimer) {
        clearTimeout(smartSuggestionsNoticeTimer);
    }

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
        <div class="mx-auto flex min-h-screen w-full max-w-md flex-col px-4 pb-28 pt-4">

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

            <div
                v-if="activeTab !== 'profile' && smartSuggestionsNoticeVisible"
                class="mb-3 rounded-2xl border border-[#403e41] bg-[#221f22] px-3 py-2 text-xs text-[#bcb7ba]"
            >
                Умные подсказки сейчас тестовые и различаются для аккаунтов.
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
                        <div :class="{ 'batch-remove-fly': isBatchRemovingItem(item) }">
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
                                        class="text-sm font-medium"
                                        :class="item.is_completed ? 'text-[#6e6a6d] line-through decoration-[#6e6a6d]' : 'text-[#fcfcfa]'"
                                    >
                                        {{ getProductDisplayText(item) }}
                                    </p>
                                    <p
                                        v-if="formatProductMeasure(item)"
                                        class="text-xs"
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
                        <div :class="{ 'batch-remove-fly': isBatchRemovingItem(item) }">
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
                                            class="text-sm font-medium"
                                            :class="item.is_completed ? 'text-[#6e6a6d] line-through decoration-[#6e6a6d]' : 'text-[#fcfcfa]'"
                                        >
                                            {{ item.text }}
                                        </p>
                                        <div class="mt-1 flex items-center justify-between gap-2">
                                            <p class="text-xs" :class="item.is_completed ? 'text-[#6e6a6d]' : 'text-[#9f9a9d]'">
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
                    <div class="mt-1 text-xs text-[#9f9a9d]">
                        @{{ localUser.tag || 'tag' }}
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
                class="fixed inset-0 z-50 flex items-end bg-[#19181a]/78 p-2.5 backdrop-blur-[2px]"
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
        </div>

        <Transition name="item">
            <div
                v-if="swipeUndoState"
                class="fixed bottom-24 left-1/2 z-40 flex w-[calc(100%-20px)] max-w-md -translate-x-1/2 items-center justify-between gap-3 rounded-2xl border border-[#403e41] bg-[#221f22]/95 px-3 py-2 text-xs text-[#fcfcfa] shadow-xl backdrop-blur"
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

        <nav
            class="fixed bottom-3 left-1/2 z-40 flex w-[calc(100%-20px)] max-w-md -translate-x-1/2 rounded-3xl border border-[#403e41] bg-[#221f22]/95 p-2 backdrop-blur"
        >
            <button
                type="button"
                class="flex flex-1 flex-col items-center rounded-2xl px-3 py-2 text-xs"
                :class="activeTab === 'products' ? 'bg-[#fcfcfa] text-[#19181a]' : 'text-[#bcb7ba]'"
                @click="activeTab = 'products'"
            >
                <ShoppingCart class="mb-1 h-4 w-4" />
                Продукты
            </button>
            <button
                type="button"
                class="flex flex-1 flex-col items-center rounded-2xl px-3 py-2 text-xs"
                :class="activeTab === 'todos' ? 'bg-[#fcfcfa] text-[#19181a]' : 'text-[#bcb7ba]'"
                @click="activeTab = 'todos'"
            >
                <Check class="mb-1 h-4 w-4" />
                Дела
            </button>
            <button
                type="button"
                class="flex flex-1 flex-col items-center rounded-2xl px-3 py-2 text-xs"
                :class="activeTab === 'profile' ? 'bg-[#fcfcfa] text-[#19181a]' : 'text-[#bcb7ba]'"
                @click="activeTab = 'profile'"
            >
                <UserRound class="mb-1 h-4 w-4" />
                Профиль
            </button>
        </nav>

        <Transition name="app-modal">
            <div v-if="shareModalOpen" class="fixed inset-0 z-50 bg-[#19181a]/90 p-2.5" @click.self="shareModalOpen = false">
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
            <div v-if="inviteModalOpen" class="fixed inset-0 z-50 bg-[#19181a]/90 p-2.5" @click.self="inviteModalOpen = false">
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
            <div v-if="productStatsModalOpen" class="fixed inset-0 z-50 bg-[#19181a]/90 p-2.5" @click.self="productStatsModalOpen = false">
                <div class="flex h-full flex-col rounded-3xl border border-[#403e41] bg-[#2d2a2c] p-4">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-base font-semibold">{{ '\u0421\u0442\u0430\u0442\u0438\u0441\u0442\u0438\u043a\u0430 \u043f\u043e\u043a\u0443\u043f\u043e\u043a' }}</h2>
                        <button type="button" class="rounded-xl border border-[#403e41] p-2 text-[#bcb7ba]" @click="productStatsModalOpen = false">
                            <X class="h-4 w-4" />
                        </button>
                    </div>

                    <p v-if="productSuggestionStatsLoading" class="text-xs text-[#9f9a9d]">{{ '\u041e\u0431\u043d\u043e\u0432\u043b\u0435\u043d\u0438\u0435\u2026' }}</p>
                    <p v-else-if="productSuggestionStats.length === 0" class="text-xs text-[#9f9a9d]">{{ '\u041f\u043e\u043a\u0430 \u043d\u0435\u0442 \u0434\u0430\u043d\u043d\u044b\u0445 \u043f\u043e \u043f\u043e\u043a\u0443\u043f\u043a\u0430\u043c.' }}</p>

                    <div v-else class="flex-1 space-y-2 overflow-y-auto">
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

.delete-burst-layer {
    position: fixed;
    inset: 0;
    z-index: 60;
    pointer-events: none;
    overflow: hidden;
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



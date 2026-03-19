# Frontend deep dive: как реально работает list-item UI

Дата среза: `2026-03-19`.

## Что это за документ

Это подробный разбор frontend-слоя по текущему порядку кода.

Порядок файлов:

1. `resources/js/Pages/Dashboard.vue`
2. `resources/js/Components/SwipeListItem.vue`
3. `resources/js/modules/dashboard/realtimeListMerge.js`
4. `resources/js/modules/dashboard/productText.js`
5. `resources/js/modules/dashboard/todoPriority.js`
6. `resources/js/modules/dashboard/textNormalize.js`
7. затем только смежные helpers, которые реально влияют на list-item flow

Фокус документа:

- add / edit / remove / reorder;
- optimistic cache;
- offline queue;
- realtime merge;
- swipe и undo;
- animation path;
- точки хрупкости и откровенные недочёты.

## Как читать этот файл

Формат deliberately смешанный:

- все блоки идут в порядке файла;
- для каждого блока объясняется каждая сущность;
- для ключевых функций используется почти построчный разбор.

Для `Dashboard.vue` literal разбор каждой строки файла был бы слишком шумным. Здесь используется практический эквивалент:

- блоки идут в исходном порядке;
- каждая константа, ref, helper и runtime-функция объяснены;
- ключевые функции add/remove/sync/realtime разобраны построчно по смыслу.

## `Dashboard.vue`

## 1. Imports: что подключается и зачем

### UI-компоненты

- `SwipeListItem` даёт swipe-жесты и semantic events `complete/remove/tap`.
- `ToastStack` показывает ошибки и статусы.

### Composables

- `useDashboardChrome` управляет `activeTab`, theme и UI-shell side effects.
- `useToasts` даёт методы `showError/showStatus/resetMessages`.

### Product helpers

- `buildProductEditableText`
- `formatProductMeasure`
- `getProductDisplayText`
- `normalizeProductComparableText`
- `normalizeQuantityInput`
- `normalizeUnitInput`
- `parseProductTextPayload`

Эти функции критичны для add/edit/display продукта.

### Realtime helpers

- `deduplicateItemsById`
- `findRealtimeMatchForPendingCreate`

Это glue между local optimistic state и full realtime snapshot.

### Todo helpers

- `getTodoPriority`
- `inferTodoPriorityFromDueAt`
- `nextTodoPriority`
- `normalizeTodoPriority`
- `todoPriorityClass`
- `todoPriorityLabel`
- `normalizeTodoComparableText`

### Framework / drag / icons

- `usePage` тащит Inertia props.
- `draggable` управляет reorder.
- иконки purely visual.

### Что already видно на уровне imports

Один файл одновременно отвечает за:

- list CRUD;
- queue;
- cache;
- realtime;
- suggestions;
- XP;
- sound;
- batch animations;
- sharing/profile/mood.

Это первый structural smell giant-file architecture.

## 2. Props и bootstrap состояния

`defineProps({ initialState })`:

- получает server-hydrated snapshot.

`const page = usePage()`:

- даёт доступ к Inertia props.

`const localUser = reactive({...})`:

- `id` — участвует в storage keys, каналах, ignore self-events;
- `name/tag/email` — локальная копия профиля, которая потом мутируется profile/save/sync logic.

`appVersion`, `buildVersion`:

- к list-item flow почти не относятся.

## 3. Gesture и drag constants

- `TOUCH_DRAG_HOLD_DELAY_MS = 500` — hold before drag на touch.
- `SAFARI_DRAG_FALLBACK_TOLERANCE_PX = 8` — Safari workaround.
- `SAFARI_DRAG_ANIMATION_MS = 0` — Safari workaround.
- `DEFAULT_DRAG_ANIMATION_MS = 220` — обычная drag animation.

`detectSafariBrowser()`:

- читает `navigator.userAgent` и `navigator.vendor`;
- убеждается, что это Safari Apple, а не Chromium/WebKit wrapper;
- возвращает boolean.

Дальше вычисляются:

- `isSafariBrowser`
- `dragAnimationMs`
- `dragFallbackTolerancePx`

Это прямые runtime-настройки reorder UX.

## 4. `useDashboardChrome(...)`

Composables layer отдаёт:

- `activeTab`
- `themeMode`
- `adsEnabled`
- `resolvedTheme`
- `persistActiveTabToStorage`
- `applyThemeMode`
- `setThemeMode`
- `mountDashboardChrome`
- `unmountDashboardChrome`

Для list-item flow это важно потому, что:

- `activeTab` решает, что грузить;
- lifecycle и watchers вокруг вкладок завязаны на него;
- mount/unmount идут в общем boot sequence Dashboard.

## 5. Главные refs и reactive state

### Context

- `selectedOwnerId` — текущий owner для выбранного списка.
- `listOptions` — dropdown options.
- `links` — shared links, из которых потом вытаскивается `link_id`.

### Visible list state

- `productItems`
- `todoItems`

Это именно видимые массивы, а не master cache.

### Suggestions

- `productSuggestions`
- `todoSuggestions`

### Form state

- `newProductText`
- `newTodoText`
- `newTodoDueAt`
- `editingItemId`
- `editingText`
- `editingDueAt`

### Animation state для item path

- `batchRemovingItemKeys`
- `batchRemovalAnimating`
- `batchCollapseHiddenItemKeys`
- `batchCollapseScene`
- `recentlyAddedItemKeys`
- `deleteFeedbackBursts`
- `xpStars`
- `progressBarRef`
- `megaCardRef`
- `animationSkipEpoch`
- `soundEnabled`

### Offline/sync state

- `isBrowserOnline`
- `serverReachable`
- `offlineQueue`
- `cachedItemsByList`
- `cachedSuggestionsByList`
- `cachedProductStatsByList`
- `cachedSyncState`

Компонент держит и UI state, и transport state, и cache state, и animation state одновременно.

## 6. Storage keys и глобальные mutable объекты

Storage keys:

- `OFFLINE_QUEUE_STORAGE_KEY`
- `ITEMS_CACHE_STORAGE_KEY`
- `SUGGESTIONS_CACHE_STORAGE_KEY`
- `PRODUCT_STATS_CACHE_STORAGE_KEY`
- `SYNC_STATE_CACHE_STORAGE_KEY`
- `LOCAL_DEFAULT_OWNER_KEY`

Ключевые mutable vars:

- `queueSyncRetryTimer`
- `listChannelName`
- `swipeUndoTimer`
- `queueSyncInProgress`
- `queueSyncPromise`
- `nextTempId`
- `queuedUpdateTouchedAt`
- `queuedUpdateSyncTimer`
- `queueRetryAt`
- `syncInFlightOperationIds`
- `effectTimeouts`
- `itemCardElements`
- `listSyncVersions`
- `listServerVersions`
- `deletedItemTombstones`
- `recentLocalMutationsByList`
- `latestRealtimeEventTokenByList`

Это важный smell: state размазан между `ref`, `Map`, `Set`, `let` и таймерами.

## 7. Выбор текущего списка

`normalizeLinkId(value)`:

- привести к числу;
- если это finite `> 0`, вернуть integer;
- иначе `null`.

`findListOptionByOwner(ownerId)`:

- найти dropdown option по `owner_id`.

`resolveLinkIdForOwner(ownerId, explicitLinkId)`:

- если explicit `link_id` уже есть, использовать его;
- иначе смотреть `link_id` внутри выбранного list option.

`selectedListOption`, `selectedListLinkId`, `selectedListLabel`:

- превращают `selectedOwnerId` в runtime context.

Скрытый дефект: функция предполагает, что `owner_id` стабильно представляет logical list. Для shared list это уже не так.

## 8. Computed вокруг offline и swipe state

- `offlineMode`
- `browserOffline`
- `queuedChangesCount`
- `offlineStatusText`

`swipeUndoState` — pending swipe action container.

`swipeUndoMessage`:

- без state возвращает пустую строку;
- для `remove_completed_batch` говорит о batch remove;
- для `remove` говорит об удалении item;
- иначе возвращает toggle-message.

`canShowRemoveCompletedButton`:

- true только когда текущий swipe state — это обычный remove completed item и batch animation не идёт.

Это уже намекает на dead toggle branch.

## 9. Константы animation/undo/XP

К list-item flow напрямую относятся:

- `SWIPE_UNDO_WINDOW_MS = 4500`
- `BATCH_REMOVE_CARD_ANIMATION_MS = 190`
- `BATCH_CINEMATIC_REMOVE_THRESHOLD = 4`
- `BATCH_CINEMATIC_WHITEN_MS = 1000`
- `BATCH_CINEMATIC_GLOW_MS = 420`
- `BATCH_CINEMATIC_BURST_MS = 170`
- `XP_PROGRESS_PER_TOGGLE`

Эти строки задают не только визуальный ритм. Они определяют длительность undo, animation lock и batch-delete UX.

## 10. Базовые утилиты cache и сравнения

`parseJson(value, fallback)`:

- безопасно парсит JSON;
- при ошибке возвращает fallback.

`cloneItems(items)`:

- возвращает поверхностные копии item-объектов.

`listCacheKey(ownerId, type, linkId)`:

- строит ключ вида `owner:personal:type` или `owner:link-id:type`.

`normalizeSortOrderValue(value, fallback)`:

- приводит значение к целому sort order или fallback.

`sortItems(items)`:

- сначала сортирует по `is_completed`;
- затем по `sort_order`;
- затем по `created_at` в обратном порядке.

`normalizeComparableValue(value)` и `parseComparableTimestampMs(value)`:

- служат low-level сравнениям и merge-guards.

`areItemsEquivalent(leftItems, rightItems)`:

- сравнивает массивы по всем важным полям, включая `pending_sync`.

## 11. Нормализация item и кеш-слой

`normalizeItem(item, localIdOverride, context)`:

- форсирует `owner_id` и `list_link_id`;
- нормализует `sort_order`;
- если `local_id` нет, создаёт `srv-${id}`;
- нормализует todo priority;
- сбрасывает `pending_sync` в `false`.

`normalizeItems(items, previousItems, context)`:

- строит карту старых `local_id`;
- строит карту старых items;
- нормализует incoming items;
- сохраняет старый `local_id`;
- при более свежем `previous.updated_at` предпочитает previous item;
- dedup + sort итоговый массив.

`readListFromCache()`:

- читает массив по cache key.

`writeListToCache()`:

- dedup + sort;
- сравнивает новый и старый cache;
- чистит tombstones существующих items;
- bump’ает локальную cache-version;
- обновляет `productItems` или `todoItems`, если scope сейчас видим;
- пишет `cachedItemsByList` в `localStorage`.

`applyLocalUpdate()`:

- читает cache, прогоняет updater, записывает обратно.

`upsertLocalItem()`:

- вставляет новый item или shallow-merge’ит старый.

## 12. Загрузка кеша из `localStorage`

`loadOfflineStateFromStorage()`:

- читает queue, items cache, suggestions cache, stats cache и sync state;
- восстанавливает refs;
- восстанавливает gamification/mood timestamps;
- вычисляет следующий `nextTempId` по cached temp ids и queued temp ids.

Это критично для offline continuity: temp ids не должны пересекаться после reload.

## 13. Transport helpers

`isConnectivityError(error)`:

- классифицирует сетевые и transport-level сбои.

`isRetriableRequestError(error)`:

- считает retriable connectivity error, `5xx` и `429`.

`requestApi(executor)`:

- выполняет API call;
- при успехе помечает server reachable;
- при `401/419` редиректит на login;
- при connectivity error помечает server unreachable;
- иначе пробрасывает ошибку дальше.

Это общий transport wrapper для GET и sync chunk.

## 14. Temp ids и optimistic create

`generateTempId()`:

- декрементирует глобальный `nextTempId`;
- возвращает новый отрицательный id.

`nextSortOrderForLocalList(...)`:

- читает текущий cache bucket;
- находит минимальный `sort_order`;
- возвращает `min - 1000` или `1000`.

`createOptimisticItem({...})`:

- берёт `now`;
- создаёт temp id;
- возвращает item с:
  - `id < 0`
  - `local_id = tmp-*`
  - локальными timestamp
  - `pending_sync = true`
  - типоспецифичными полями

## 15. Очередь: низкоуровневые примитивы

`findQueueIndexFromEnd(predicate)`:

- ищет последнюю подходящую operation.

`hasPendingOperations(ownerId, type, linkId)`:

- проверяет, есть ли операции по текущему logical list scope.

`enqueueOperation(operation)`:

- создаёт `op_id`;
- пушит operation в `offlineQueue`;
- вызывает `persistQueue()`.

`rewriteQueuedItemId(previousId, nextId)`:

- переписывает `item_id` и `reorder.payload.order[]` после temp->server rewrite.

`dropQueueOperation(opId)`:

- удаляет operation из очереди.

## 16. Очередь: create / update / delete / reorder

`queueCreate()`:

- помечает list как недавно мутировавший;
- кладёт `create` в очередь;
- сериализует `text/quantity/unit/due_at/priority/is_completed/sort_order`.

Важно: `sort_order` для server create сейчас не имеет смысла.

`queueReorder()`:

- нормализует `order[]`;
- coalesce’ит reorder по scope, заменяя последний queued reorder.

`queueUpdate()`:

- если update касается temp item с queued create, payload вливается прямо в create;
- если update уже есть, payload вливается в existing update;
- иначе создаётся новый update.

`queueDelete()`:

- если delete касается temp item с queued create, весь temp-хвост удаляется без server delete;
- queued updates удаляются;
- queued reorder очищается от item id;
- delete не дублируется.

`collectSyncChunkOperations()`:

- выбрасывает stale temp mutations без create;
- coalesce’ит часть update payload по quiet-period;
- ограничивает chunk размером;
- даёт delete priority, если delete уже есть в очереди.

## 17. Sync loop

`buildSyncChunkPayload()`:

- превращает внутреннюю очередь в wire-format API.

`syncOfflineQueue()` делает следующее:

- не пускает параллельные sync loop;
- не работает при offline;
- уважает retry cooldown;
- собирает chunk;
- POST’ит `/api/sync/chunk`;
- по каждому result:
  - при `ok` вызывает `applySuccessfulSyncedOperation()` и удаляет op;
  - при допустимом client error дропает op;
  - иначе включает retry delay и останавливается.

Ключевая слабость: `shouldDropOperationOnClientError()` не включает list-item create/update на `422`.

## 18. Применение ack-результатов

`applySuccessfulSyncedOperation(operation, resultData)` — центральный reducer подтверждённых операций.

### Ветка `create`

- обновляет server version;
- нормализует server item;
- проверяет queued delete intent и swipe delete intent;
- решает, надо ли сохранить локальный pending state поверх server item;
- заменяет temp item на server item;
- сохраняет `local_id`;
- удаляет дубли;
- переписывает queued ids и swipe ids.

### Ветка `update`

- обновляет server version;
- если есть более новый локальный intent, ничего не делает;
- иначе только снимает `pending_sync` и обновляет `updated_at`.

### Ветка `delete`

- обновляет server version;
- ставит tombstone;
- удаляет item из cache.

### Ветка `reorder`

- только обновляет server version.

## 19. Tombstones и version guards

`listSyncVersionKey()`, `getListSyncVersion()`, `bumpListSyncVersion()`:

- ведут локальную версию cache-мутаций.

`getKnownServerListVersion()`, `setKnownServerListVersion()`:

- ведут подтверждённую server version.

`markListMutated()`, `hasRecentListMutation()`:

- короткоживущий guard против late GET/realtime overwrite.

`deletedItemTombstoneKey()`, `markItemsAsDeleted()`, `getDeletedItemTombstone()`:

- реализуют anti-resurrection слой.

`readFilteredServerItems()`:

- отбрасывает items с pending delete intent;
- не даёт серверу вернуть item, если его `updated_at` не новее локального момента удаления.

## 20. CRUD actions верхнего уровня

`createItemOptimistically(type, text, dueAt, options)`:

- главный entrypoint add logic.

`toggleCompleted(item)`:

- flush’ит старый swipe state;
- считает `nextCompleted` и новый `sort_order`;
- локально меняет item и XP;
- кладёт `update` в очередь;
- сразу запускает sync.

`removeItem(item)`:

- flush’ит старый swipe state;
- вычисляет `previousIndex`;
- определяет, доступен ли batch remove;
- удаляет item локально;
- открывает undo snackbar.

`onItemsReorder(type, event)`:

- переставляет `sort_order` по текущему индексу;
- пишет список в cache;
- ставит `reorder` в очередь;
- синкает.

## 21. Swipe / undo / animation subsystem

`setItemCardRef(type, item, element)`:

- записывает DOM ref карточки в `itemCardElements` по stable key на базе `itemViewKey()`.

`markRecentlyAddedItem(item)`:

- добавляет item key в `recentlyAddedItemKeys`;
- снимает флаг таймером.

`clearSwipeUndoTimer()`:

- чистит snackbar timer.

`hasPendingSwipeAction(ownerId, type, linkId)`:

- проверяет, есть ли активный swipe state для list scope.

`isSwipeStateForItem(state, ownerId, type, itemId, linkId)`:

- определяет, относится ли swipe state к конкретному item.

`rewriteSwipeUndoItemId(previousId, nextId, ...)`:

- после create ack заменяет temp id на server id внутри swipe state.

`stageSwipeAction(state)`:

- для `remove` ставит `queueDelete()`;
- для `remove_completed_batch` ставит delete для primary item и всего removed набора;
- для `toggle` содержит legacy branch.

`finalizeSwipeAction(state, { background })`:

- staging queue action;
- затем sync, либо в фоне, либо с ожиданием.

`flushSwipeUndoState()`:

- снимает текущий swipe state;
- чистит таймер;
- финализирует pending action.

`startSwipeUndoState(state)`:

- сохраняет state;
- ставит таймер `SWIPE_UNDO_WINDOW_MS`;
- по таймеру запускает background finalize.

`undoSwipeAction()`:

- для `remove` возвращает item по `previousIndex`;
- для `remove_completed_batch` возвращает весь набор и откатывает XP;
- для `toggle` откатывает item и XP, но эта ветка сейчас не используется runtime.

## 22. Burst / XP / white-card animation

`triggerDeleteBurst(event)`:

- вычисляет точку события;
- создаёт набор particle objects;
- пишет burst в `deleteFeedbackBursts`;
- через таймер убирает burst.

`spawnXpStars(originX, originY, removedCount, options)`:

- рассчитывает количество звёзд и общий XP gain;
- создаёт звездочки с траекторией в progress bar;
- ставит таймеры на начисление XP и cleanup.

`buildBatchCollapseScene(type, completedEntries)`:

- читает DOM rect карточек;
- выбирает top card как основу mega-card;
- для остальных строит incoming cards с `dx/dy/delay/duration`.

`playBatchCollapseAnimation(...)`:

- запускает whiten phase;
- включает incoming travel;
- триггерит impact sound и glow;
- запускает burst;
- спавнит XP stars;
- очищает scene.

`applyScrollLockState(locked)`:

- блокирует или возвращает scroll/touch-action.

`requestAnimationSkip()` и `handleAnimationSkipTap()`:

- повышают `animationSkipEpoch`;
- временно глушат звук;
- позволяют досрочно завершить skippable animation.

## 23. GET loading layer

`loadItems(type, showErrors, ownerIdOverride, linkIdOverride)`:

- защищается от параллельного load;
- берёт owner/link/context cache;
- если offline / server unreachable / sync conflict / pending swipe / animation:
  - показывает cache и выходит;
- иначе делает GET `/api/items`;
- после ответа перепроверяет conflicts и freshness;
- затем:
  - `normalizeItems()`
  - `readFilteredServerItems()`
  - `writeListToCache()`
  - `setKnownServerListVersion()`

`loadAllItems()`:

- грузит product и todo параллельно.

## 24. Realtime layer

`resolveRealtimeListType(eventPayload)`:

- использует `payload.type`, иначе fallback на active tab.

`mergeRealtimeItemsWithLocalPending(ownerId, type, incomingItems, linkId)`:

- берёт `previousItems`;
- нормализует incoming snapshot;
- сохраняет локальные pending create/update/delete;
- пытается сматчить temp create с server item;
- не даёт server snapshot затереть локальный pending intent.

`shouldApplyRealtimeListSnapshot(...)`:

- если есть `list_version`, принимает только более новую version;
- иначе использует `changed_at` token ordering.

`subscribeListChannel(ownerId)`:

- пересобирает channel name;
- оставляет старый канал;
- подписывается на новый;
- игнорирует self events;
- прогоняет snapshot через merge + filter + cache write.

## 25. Watchers и lifecycle

`watch(selectedOwnerId, ...)`:

- flush’ит swipe state;
- persist’ит owner;
- гидратирует visible lists из cache;
- переподписывается на realtime канал;
- грузит items и suggestions.

Это очень сильный watcher: он одновременно меняет cache, subscription и network behavior.

`watch(activeTab, ...)`:

- persist’ит вкладку;
- грузит items/suggestions для активного таба.

`watch(() => isWhiteCardAnimationActive(), ...)`:

- после animation снимает suggestion block state, но не запускает явный reload.

`watch(isSkippableAnimationPlaying, ...)`:

- включает/выключает scroll lock.

`onMounted(...)`:

- поднимает chrome;
- загружает offline state;
- применяет cached sync state;
- гидратирует visible lists;
- пытается синкануть очередь;
- тянет fresh sync state;
- грузит items и suggestions;
- подписывается на user/list каналы.

`onBeforeUnmount(...)`:

- skip active animations;
- если swipe state есть, staging’ит его в очередь;
- чистит timers/maps/sets/scene state.

## 26. Template: products tab

- add input связан с `newProductText`;
- enter и кнопка ведут в `addProduct()`;
- suggestions panel рендерит accept/dismiss actions;
- `draggable` использует `v-model="productItems"` и `item-key="local_id"`;
- каждый item-shell вешает runtime classes:
  - `batch-remove-fly`
  - `batch-remove-hidden`
  - `item-added-pop`
- `SwipeListItem` эмитит:
  - `@complete="toggleCompleted(item)"`
  - `@remove="removeItem(item)"`
  - `@tap="beginEdit(item)"`

`item-key="local_id"` здесь критичен: temp id может поменяться на server id, а DOM identity карточки должна остаться прежней.

## 27. Template: todos tab

Почти тот же каркас, но дополнительно есть:

- `datetime-local` при создании;
- кнопка deadline picker;
- кнопка cycle priority;
- `data-no-swipe` у интерактивных controls.

Почему это важно:

- todo card содержит interactive elements внутри swipe-shell;
- поэтому `SwipeListItem` должен уметь игнорировать gesture start на таких targets.

## 28. Overlay/template слои анимаций

- `batchCollapseScene` рисует поверх списка отдельную white-card cinematic layer;
- `deleteFeedbackBursts` рисует частицы удаления;
- `xpStars` рисует полёт XP к progress bar;
- `animation-skip-layer` перехватывает pointer/touch/wheel;
- snackbar undo живёт отдельно и зависит от `swipeUndoState`.

## 29. CSS/animation block

Ключевые classes и их runtime-источники:

- `item-added-pop` <- `markRecentlyAddedItem()`
- `batch-remove-fly` <- `batchRemovingItemKeys`
- `batch-remove-hidden` <- `batchCollapseHiddenItemKeys`
- `batch-collapse-incoming` <- `batchCollapseScene.incoming`
- `batch-collapse-mega--whiten` <- `scene.whitening`
- `batch-collapse-mega--glow` <- `scene.glowing`
- `batch-collapse-mega--burst` <- `scene.bursting`
- `delete-burst-particle` <- `deleteFeedbackBursts`
- `xp-flow-star` <- `xpStars`
- `animation-skip-layer` <- `isSkippableAnimationPlaying`

## `SwipeListItem.vue`

Компонент держит gesture state в refs:

- `startX`, `startY`
- `dragX`
- `dragging`
- `touchId`
- `pressStartedAt`
- `touchSwipeLocked`
- `suppressMouseUntil`

Константы:

- `gestureThreshold = 72`
- `swipeActivationDistance = 8`
- `maxSwipeAngleDeg = 35`
- `TOUCH_MOUSE_SUPPRESSION_MS = 520`

Ключевые функции:

- `isDragHandleTarget()` не даёт свайпу конфликтовать с reorder handle;
- `isInteractiveTarget()` не даёт свайпу стартовать с кнопок, input и `data-no-swipe`;
- `beginDrag()` записывает старт gesture;
- `updateDrag()` двигает карточку, ограничивая диапазон;
- `endDrag()` решает, эмитить `complete`, `remove` или `tap`;
- `cancelDrag()` просто сбрасывает state;
- `suppressSyntheticMouseEvents()` и `shouldIgnoreMouseEvent()` защищают от двойных событий после touch;
- `onMouseDown/onMouseMove/onMouseUp` обслуживают mouse path;
- `onTouchStart/onTouchMove/onTouchEnd` обслуживают touch path, включая фильтр по углу.

Template:

- backdrop всегда присутствует под карточкой;
- левая половина = complete/restore semantics;
- правая половина = remove semantics;
- сама карточка двигается через inline `transform`.

Styles:

- контейнер клипует swipe;
- backdrop красится отдельно;
- карточка получает `transition: transform`.

## Helper-модули

### `realtimeListMerge.js`

`deduplicateItemsById(items)`:

- удаляет дубли по numeric `id` и по `local_id`;
- сохраняет первое вхождение.

`canMatchPendingCreateToRealtimeServerItem(localPendingItem, incomingItem, options)`:

- проверяет temp/server id;
- сравнивает type/owner/link/text/quantity/unit/due_at/priority;
- сравнивает `created_at` в 10-минутном окне;
- намеренно допускает completion mismatch.

`findRealtimeMatchForPendingCreate(...)`:

- ищет лучший match по минимальной разнице `created_at`;
- не использует incoming ids, которые уже были заняты другим temp item.

### `productText.js`

Модуль парсит продуктовую строку:

- `normalizeQuantityInput()`
- `normalizeUnitInput()`
- `parseProductTextPayload()`
- `getProductDisplayText()`
- `formatProductMeasure()`
- `buildProductEditableText()`

Он участвует в:

- add product;
- edit product;
- display продукта;
- сопоставлении продукта в UI.

### `todoPriority.js`

- `normalizeTodoPriority()` нормализует приоритет;
- `inferTodoPriorityFromDueAt()` выводит fallback из deadline;
- `getTodoPriority()` выдаёт текущий runtime priority;
- `todoPriorityLabel()` и `todoPriorityClass()` рисуют UI;
- `nextTodoPriority()` переключает по кругу.

### `textNormalize.js`

`normalizeTodoComparableText(value)`:

- trim;
- lowercase;
- `ё -> е`;
- удаление пунктуации;
- схлопывание пробелов.

Это простой pure helper, без сложного состояния.

## Каталог изъянов и откровенных недочётов frontend-логики

Архитектурные:

- giant-file `Dashboard.vue` держит cache, queue, realtime, animation, XP, suggestions и unrelated domains одновременно;
- state размазан между Vue refs, `Map`, `Set`, таймерами и обычными `let`;
- item flow не отделён от animation flow и transport flow.

Логические:

- shared list owner/cache identity split;
- `422` create/update poison queue;
- dead path `swipeUndoState.action === 'toggle'`;
- completed create зависит от server-side двухшагового path и special-case realtime matching;
- `sort_order` в create payload — ложный контракт.

Производительные:

- full-snapshot realtime после каждой мутации;
- reorder через N отдельных `save()`;
- частые clone/sort/dedup циклы даже при частичных изменениях.

UX и race conditions:

- слишком консервативные guards держат UI на stale cache дольше, чем хотелось бы;
- suggestion refresh после white-card animation не доведён до конца;
- XP и часть визуальных эффектов применяются до server ack;
- temp create matching эвристический, а не deterministic.

## Итог

Frontend list-item flow работает потому, что вокруг optimistic UI построено много защитных слоёв:

- temp ids;
- `local_id`;
- queue rewrite;
- tombstones;
- local version guards;
- known server version guards;
- realtime merge;
- blocked loads;
- animation locks.

Но цена высокая:

- это тяжело объяснять;
- это тяжело тестировать;
- это легко сломать небольшим изменением;
- часть сложности обслуживает не продуктовую задачу, а последствия прежних решений.

Если рефакторить дальше, первым приоритетом должен быть не cosmetic split компонента, а уменьшение числа скрытых identity, merge-правил и queue special-cases.

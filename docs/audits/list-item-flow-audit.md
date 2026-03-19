# Аудит текущей логики list items: add / remove / animation / sync

Дата среза: `2026-03-19`.

Документ основан на текущем worktree, включая незакоммиченные изменения в:

- `resources/js/Pages/Dashboard.vue`
- `resources/js/Components/SwipeListItem.vue`
- `resources/js/modules/dashboard/realtimeListMerge.js`
- `resources/js/modules/dashboard/realtimeListMerge.test.js`

## Что это за документ

Это не план нового дизайна и не proposal на рефакторинг. Это описание того, как логика работает сейчас:

- как item добавляется;
- как он удаляется;
- как UI делает optimistic update;
- как строится оффлайн-очередь;
- как chunk sync проходит через backend;
- как realtime возвращает state обратно на клиент;
- где в этой цепочке есть баги, хрупкие места и лишняя сложность.

Документ построен по принципу `findings first`: сначала конфликтующие решения и поломанные места, потом happy path.

## Главные файлы и контракты

Backend:

- `routes/web.php`
- `app/Http/Controllers/Api/ListItemController.php`
- `app/Http/Controllers/Api/SyncChunkController.php`
- `app/Services/ListItems/ListItemApiService.php`
- `app/Services/ListItems/ListAccessService.php`
- `app/Services/ListItems/ListItemOrderingService.php`
- `app/Services/ListItems/ListItemRealtimeNotifier.php`
- `app/Services/ListItems/ListItemSerializer.php`
- `app/Services/ListItems/ListSyncVersionService.php`
- `app/Services/SyncChunk/Handlers/ListItemSyncChunkActionHandler.php`
- `app/Services/SyncChunk/SyncChunkProcessor.php`
- `app/Services/SyncChunk/SyncChunkOperationStore.php`
- `app/Services/SyncChunk/SyncChunkActionRequestFactory.php`
- `app/Events/ListItemsChanged.php`

Frontend:

- `resources/js/Pages/Dashboard.vue`
- `resources/js/Components/SwipeListItem.vue`
- `resources/js/modules/dashboard/realtimeListMerge.js`
- `resources/js/modules/dashboard/productText.js`
- `resources/js/modules/dashboard/todoPriority.js`
- `resources/js/modules/dashboard/textNormalize.js`

Публичные runtime-контракты:

- `GET /api/items`
- `POST /api/items`
- `PATCH /api/items/{item}`
- `DELETE /api/items/{item}`
- `POST /api/items/reorder`
- `POST /api/sync/chunk`

Realtime payload `list.items.changed`:

- `owner_id`
- `list_link_id`
- `type`
- `actor_user_id`
- `list_version`
- `items`
- `changed_at`

Клиентский shape item:

- `id`
- `local_id`
- `owner_id`
- `list_link_id`
- `type`
- `text`
- `sort_order`
- `quantity`
- `unit`
- `due_at`
- `priority`
- `is_completed`
- `completed_at`
- `created_at`
- `updated_at`
- `pending_sync`

Chunk operation shape:

- `op_id`
- `action`
- `owner_id`
- `link_id`
- `type`
- `item_id`
- `payload`

## Findings First

## 1. Критично: shared-list identity на клиенте и backend не совпадает

Frontend строит selected context вокруг `selectedOwnerId`, `resolveLinkIdForOwner()` и `listCacheKey(ownerId, type, linkId)`.  
`ListSyncService::getListOptions()` отдаёт shared option с `owner_id = other_user.id`.

Backend при этом в `ListAccessService::resolveReadContext()` и `resolveCreateContext()` для shared list возвращает `ListAccessContext((int) $link->user_one_id, (int) $link->id)`.

Итог:

- один и тот же shared list может жить под разными `owner_id`;
- initial GET и optimistic cache идут под одним ключом;
- realtime event, version и tombstones могут уйти под другим;
- merge и anti-resurrection guards становятся непредсказуемыми.

Это не эстетическая проблема. Это source-of-truth conflict.

## 2. Критично: `422` на list-item create/update отравляет очередь

`createItemOptimistically()` и `saveEdit()` кладут optimistic state в cache и очередь до server ack.  
`ListItemApiService::store()` и `update()` валидируют `text` как `max:255`.

Если backend отвечает `422`:

- операция остаётся в `offlineQueue`;
- `pending_sync` остаётся висеть;
- `hasPendingOperations()` продолжает блокировать часть загрузок;
- очередь ретраится бесконечно;
- item остаётся в полулокальном сломанном состоянии.

Причина: `shouldDropOperationOnClientError()` не дропает list-item `create/update` на `422`.

## 3. Критично: completed create через chunk сделан как `store` + второй `update`

`ListItemSyncChunkActionHandler::handleCreateOperation()` сначала вызывает `store()`, потом, если `payload.is_completed`, отдельно вызывает `update(['is_completed' => true])`.

Это создаёт:

- два server action на одну логическую операцию;
- два `list_version` bump;
- два broadcast;
- промежуточный snapshot со свежесозданным, но ещё не completed item;
- special-case в realtime matching, который должен терпеть completion mismatch.

Именно отсюда вырос relaxed match в `realtimeListMerge.js`.

## 4. Средняя тяжесть: reorder и realtime дорогие

`ListItemOrderingService::persistOrders()` делает `save()` по одному item.  
`ListItemRealtimeNotifier::dispatchListItemsChangedSafely()` после каждой мутации снова читает весь scope и шлёт полный snapshot.

Последствия:

- reorder = `O(n)` writes;
- create/delete/toggle/reorder = полный reread списка;
- broadcast payload растёт вместе с длиной списка.

## 5. Средняя тяжесть: create payload содержит `sort_order`, который server не использует

`queueCreate()` сериализует `sort_order` в payload.  
`handleCreateOperation()` и `ListItemApiService::store()` этот `sort_order` не принимают и не используют.

Это ложный контракт между frontend и backend.

## 6. Средняя тяжесть: toggle undo path фактически мёртвый

В `Dashboard.vue` есть:

- `swipeUndoState.action === 'toggle'`
- логика `stageSwipeAction()` для toggle
- undo branch для toggle

Но `toggleCompleted()` не создаёт такой state. Он сразу делает local update, `queueUpdate()` и `syncOfflineQueue()`.

Значит toggle-undo сейчас не реальный UX path, а legacy/dead code.

## 7. Средняя тяжесть: refresh suggestions после white-card animation недореализован

Во время cinematic batch remove используется `markSuggestionRefreshBlocked(type)`.  
После animation watcher вызывает `flushBlockedSuggestionRefreshes()`.

Но текущая реализация только чистит blocked set. Явного `loadSuggestions()` после animation нет.

Это выглядит как незавершённая интеграция.

## 8. Хрупкость: matching temp create к server item эвристический

`findRealtimeMatchForPendingCreate()` и `canMatchPendingCreateToRealtimeServerItem()` сравнивают:

- `type`
- `owner_id`
- `list_link_id`
- `text`
- `quantity`
- `unit`
- `due_at`
- `priority`
- окно по `created_at` в `10 минут`

Completion mismatch отдельно разрешён из-за промежуточного create snapshot.

## Карта данных

Backend хранит:

- `list_items` как фактический item state;
- `list_sync_versions` как version по scope;
- `sync_operations` как idempotency storage;
- `list_item_events` как analytics history для suggestions.

Frontend хранит:

- `offlineQueue`
- `cachedItemsByList`
- `cachedSuggestionsByList`
- `cachedSyncState`
- `listSyncVersions`
- `listServerVersions`
- `deletedItemTombstones`
- `recentLocalMutationsByList`
- `latestRealtimeEventTokenByList`
- `swipeUndoState`

## Happy path: добавление item

Entry points:

- `addProduct()`
- `addTodo()`

Оба ведут в `createItemOptimistically()`.

`createItemOptimistically()`:

- берёт `selectedOwnerId` и `selectedListLinkId`;
- для product разбирает строку через `parseProductTextPayload()`;
- нормализует поля;
- считает верхний локальный `sort_order` через `nextSortOrderForLocalList()`;
- создаёт temp item через `createOptimisticItem()`;
- пишет item в cache через `upsertLocalItem(..., { atTop: true })`;
- помечает карточку как недавно добавленную через `markRecentlyAddedItem()`;
- кладёт `create` в очередь через `queueCreate()`;
- запускает `syncOfflineQueue()`.

Temp item получает:

- отрицательный `id`;
- стабильный `local_id = tmp-*`;
- локальные `created_at/updated_at`;
- `pending_sync = true`.

Backend-path:

- `SyncChunkController::sync()`
- `SyncChunkProcessor::process()`
- `ListItemSyncChunkActionHandler::handleCreateOperation()`
- `ListItemApiService::store()`

`store()`:

- валидирует payload;
- через `ListAccessService` определяет scope;
- нормализует quantity/unit/priority;
- сам вычисляет `sort_order` через `ListItemOrderingService::nextSortOrder()`;
- создаёт item;
- пишет analytics add-event;
- bump’ает `list_version`;
- broadcast’ит полный snapshot списка.

Client ack:

- `applySuccessfulSyncedOperation('create')`;
- заменяет temp id на реальный;
- сохраняет `local_id`;
- переписывает queued ids и swipe ids;
- удаляет дубли.

Broken / fragile behavior в add path:

- `422` create не дропается;
- shared scope может попасть в другой cache key;
- `sort_order` в create payload ложный;
- completed create даёт промежуточный server snapshot;
- temp-match эвристический.

## Happy path: удаление item

Свайп в `SwipeListItem.vue` по `delta <= -gestureThreshold` эмитит `remove`.

`removeItem(item)`:

- `resetMessages()`
- `await flushSwipeUndoState()`
- определяет `ownerId/linkId`;
- читает current cache;
- запоминает `previousIndex`;
- считает число completed items;
- определяет `canRemoveCompletedBatch`;
- вызывает `removeLocalItem()`;
- открывает `startSwipeUndoState({ action: 'remove', ... })`.

`removeLocalItem()`:

- ставит tombstone через `markItemsAsDeleted()`;
- удаляет item из cache через `applyLocalUpdate()`.

Если undo не нажали:

- таймер в `startSwipeUndoState()` вызывает `finalizeSwipeAction()`;
- `stageSwipeAction()` кладёт `delete` через `queueDelete()`;
- `syncOfflineQueue()` отправляет chunk delete.

`queueDelete()` умеет важный short-circuit:

- если item был temp item и unsynced create ещё в очереди, весь temp-хвост вычищается без server delete.

Backend-path delete:

- `ListItemSyncChunkActionHandler::handleDeleteOperation()`
- `ListItemApiService::destroy()`

`destroy()`:

- проверяет доступ;
- делает hard delete;
- bump’ает `list_version`;
- broadcast’ит полный snapshot;
- возвращает `status + list_version`.

Client ack delete:

- снова ставит tombstone;
- гарантированно вырезает item из cache.

Broken / fragile behavior в delete path:

- shared identity split может увести ack в другой logical cache;
- resurrection guard держится на server `updated_at`, а не на отдельном delete marker;
- delete хорошо обработан на `404`, но соседние create/update в той же очереди могут уже быть poisoned.

## Batch remove completed items

После удаления completed item snackbar может показать кнопку batch remove, если:

- удаляемый item уже completed;
- completed items в списке не меньше `BATCH_CINEMATIC_REMOVE_THRESHOLD = 4`.

`removeCompletedAfterSwipe()`:

- останавливает swipe timer;
- собирает все completed entries из cache;
- включает `batchRemovalAnimating`;
- в cinematic mode строит scene через `buildBatchCollapseScene()` и запускает `playBatchCollapseAnimation()`;
- в fallback mode даёт `triggerDeleteBurst()` и `batch-remove-fly`.

После animation:

- completed ids собираются в `removedItemIds`;
- на них ставятся tombstones;
- они удаляются из cache;
- создаётся новый `swipeUndoState({ action: 'remove_completed_batch', ... })`.

Undo batch path:

- возвращает items по `previousIndex`;
- откатывает XP через `rollbackXpGainSource()`.

## Toggle completed

`toggleCompleted(item)`:

- flush’ит старый swipe state;
- считает `nextCompleted`;
- считает новый `sort_order` для active/completed bucket;
- сразу локально обновляет item;
- сразу локально двигает XP;
- кладёт `update` в очередь;
- сразу запускает sync.

Backend `ListItemApiService::update()`:

- применяет `is_completed`;
- ставит или очищает `completed_at`;
- пересчитывает `sort_order`, если completion state изменился и `sort_order` не пришёл явно;
- пишет analytics complete-event только при переходе в completed;
- bump’ает `list_version`;
- broadcast’ит snapshot.

Broken / fragile behavior:

- toggle undo path в runtime не используется;
- XP меняется до server ack;
- client update ack не вмерживает весь server item, а только снимает `pending_sync` и обновляет `updated_at`.

## Reorder

Frontend:

- `draggable` работает по `item-key="local_id"`;
- `onItemsReorder(type, event)` переписывает `sort_order` по текущему индексу;
- пишет reordered массив в cache;
- ставит `reorder` в очередь;
- сразу синкает.

Backend:

- `ListItemApiService::reorder()` валидирует запрос и строит query по scope;
- `ListItemOrderingService::reorderItemsForScope()` делит ids на active/completed и дописывает недостающие элементы хвостом;
- `persistOrders()` записывает order по одному item с шагом `1000`.

Broken / fragile behavior:

- reorder дорогой по числу `save()`;
- realtime всё равно шлёт полный snapshot;
- shared identity split опять может разнести reorder-ack и realtime по разным cache key.

## GET path и stale-response guards

`loadItems()` не ходит в сеть, если:

- browser offline;
- server unreachable;
- `hasListSyncConflict()`;
- есть pending swipe action;
- активна white-card animation;
- активна batch removal animation.

Если GET выполнен, после ответа ещё раз проверяется:

- не появился ли sync conflict;
- не старее ли response относительно уже известного server version.

Только потом:

- `normalizeItems()`
- `readFilteredServerItems()`
- `writeListToCache()`
- `setKnownServerListVersion()`

Broken / fragile behavior:

- guards очень консервативны, UI может дольше жить на stale cache;
- существует два слоя versioning: local cache-version и known server version;
- shared identity split дополнительно ухудшает предсказуемость.

## Realtime path

Backend event `ListItemsChanged`:

- выбирает `lists.shared.{linkId}` или `lists.personal.{ownerId}`;
- шлёт `owner_id`, `list_link_id`, `type`, `actor_user_id`, `list_version`, `items`, `changed_at`.

Frontend `subscribeListChannel(ownerId)`:

- пересобирает channel name;
- оставляет старый канал;
- подписывается на новый;
- игнорирует self events по `actor_user_id`;
- прогоняет snapshot через:
  - `shouldApplyRealtimeListSnapshot()`
  - `mergeRealtimeItemsWithLocalPending()`
  - `readFilteredServerItems()`
  - `writeListToCache()`
  - `setKnownServerListVersion()`

Назначение `mergeRealtimeItemsWithLocalPending()`:

- не дать server snapshot затереть pending create/update/delete;
- сохранить temp create до его authorative ack;
- сопоставить temp create с server item.

Broken / fragile behavior:

- full-snapshot broadcast дорогой;
- merge-логика сложна именно потому, что вокруг optimistic queue наращено много special-cases;
- temp matching эвристический;
- shared owner mismatch может сделать realtime merge silent no-op.

## Что уже покрыто тестами

Существующие backend tests фиксируют:

- store + index;
- update todo fields;
- reorder active/completed;
- delete item;
- forbid read чужого unlinked list;
- ordered chunk processing;
- stop-on-first-error;
- idempotent create по `op_id`;
- create completed через chunk;
- repeated completion timestamp behavior;
- uncomplete clears `completed_at`;
- shared add after invitation;
- broadcast payload shape.

## Что не закрыто

Сценарии, которые особенно нуждаются в явной проверке:

- shared pair, где текущий пользователь является `user_two`, а realtime owner приходит как `user_one`;
- `422` на create/update и зависание очереди;
- create -> edit до ack;
- create -> delete до ack;
- late GET после delete ack;
- duplicate realtime snapshots;
- batch remove completed + undo + XP rollback;
- suggestion refresh после white-card animation;
- reconnect после накопившейся offline queue.

## Итог

Текущая система держится на большом числе защитных client-side механизмов:

- temp ids;
- `local_id`;
- queue rewrite;
- tombstones;
- local version guards;
- known server version guards;
- realtime merge;
- blocked loads;
- animation locks.

Проблема не в самой сложности интерфейса. Проблема в том, что заметная часть этой сложности обслуживает уже неудачные решения:

- shared identity split;
- completed create в два шага;
- poisoned queue на `422`;
- full-snapshot realtime после каждой мутации.

Любой дальнейший рефакторинг должен начинаться с этих узлов, а не с косметического деления `Dashboard.vue`.

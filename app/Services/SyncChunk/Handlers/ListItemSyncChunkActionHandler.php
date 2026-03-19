<?php

namespace App\Services\SyncChunk\Handlers;

use App\Models\ListItem;
use App\Services\ListItems\ListItemApiService;
use App\Services\SyncChunk\Contracts\SyncChunkActionHandler;
use App\Services\SyncChunk\SyncChunkActionRequestFactory;
use Illuminate\Http\Request;

class ListItemSyncChunkActionHandler implements SyncChunkActionHandler
{
    public function __construct(
        private readonly ListItemApiService $listItemApiService,
        private readonly SyncChunkActionRequestFactory $requestFactory
    ) {
    }

    public function supports(string $action): bool
    {
        return in_array($action, [
            'create',
            'update',
            'delete',
            'reorder',
            'dismiss_suggestion',
            'reset_suggestion',
            'update_suggestion_settings',
        ], true);
    }

    public function handle(Request $request, array $operation): array
    {
        $action = (string) ($operation['action'] ?? '');

        return match ($action) {
            'create' => $this->handleCreateOperation($request, $operation),
            'update' => $this->handleUpdateOperation($request, $operation),
            'delete' => $this->handleDeleteOperation($request, $operation),
            'reorder' => $this->handleReorderOperation($request, $operation),
            'dismiss_suggestion' => $this->handleDismissSuggestionOperation($request, $operation),
            'reset_suggestion' => $this->handleResetSuggestionOperation($request, $operation),
            'update_suggestion_settings' => $this->handleUpdateSuggestionSettingsOperation($request, $operation),
            default => [],
        };
    }

    private function handleCreateOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $type = (string) ($operation['type'] ?? '');

        $storeRequest = $this->requestFactory->make($request, [
            'owner_id' => (int) ($operation['owner_id'] ?? 0),
            'link_id' => $this->normalizeNullablePositiveInteger($operation['link_id'] ?? null),
            'type' => $type,
            'text' => (string) ($payload['text'] ?? ''),
            'is_completed' => (bool) ($payload['is_completed'] ?? false),
            'quantity' => $type === ListItem::TYPE_PRODUCT ? ($payload['quantity'] ?? null) : null,
            'unit' => $type === ListItem::TYPE_PRODUCT ? ($payload['unit'] ?? null) : null,
            'due_at' => $type === ListItem::TYPE_TODO ? ($payload['due_at'] ?? null) : null,
            'priority' => $type === ListItem::TYPE_TODO ? ($payload['priority'] ?? null) : null,
        ]);

        return $this->listItemApiService->store($storeRequest);
    }

    private function handleUpdateOperation(Request $request, array $operation): array
    {
        $itemId = (int) ($operation['item_id'] ?? 0);
        if ($itemId <= 0) {
            return [];
        }

        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $updateRequest = $this->requestFactory->make($request, $payload);

        return $this->listItemApiService->update($updateRequest, ListItem::query()->findOrFail($itemId));
    }

    private function handleDeleteOperation(Request $request, array $operation): array
    {
        $itemId = (int) ($operation['item_id'] ?? 0);
        if ($itemId <= 0) {
            return [];
        }

        return $this->listItemApiService->destroy(
            $this->requestFactory->make($request, []),
            ListItem::query()->findOrFail($itemId),
        );
    }

    private function handleReorderOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $order = is_array($payload['order'] ?? null) ? $payload['order'] : [];

        $reorderRequest = $this->requestFactory->make($request, [
            'owner_id' => (int) ($operation['owner_id'] ?? 0),
            'link_id' => $this->normalizeNullablePositiveInteger($operation['link_id'] ?? null),
            'type' => (string) ($operation['type'] ?? ''),
            'order' => array_values($order),
        ]);

        return $this->listItemApiService->reorder($reorderRequest);
    }

    private function handleDismissSuggestionOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];

        $dismissRequest = $this->requestFactory->make($request, [
            'owner_id' => (int) ($operation['owner_id'] ?? 0),
            'link_id' => $this->normalizeNullablePositiveInteger($operation['link_id'] ?? null),
            'type' => (string) ($operation['type'] ?? ''),
            'suggestion_key' => (string) ($payload['suggestion_key'] ?? ''),
            'average_interval_seconds' => (int) ($payload['average_interval_seconds'] ?? 0),
        ]);

        return $this->listItemApiService->dismissSuggestion($dismissRequest);
    }

    private function handleResetSuggestionOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];

        $resetRequest = $this->requestFactory->make($request, [
            'owner_id' => (int) ($operation['owner_id'] ?? 0),
            'link_id' => $this->normalizeNullablePositiveInteger($operation['link_id'] ?? null),
            'type' => (string) ($operation['type'] ?? ''),
            'suggestion_key' => (string) ($payload['suggestion_key'] ?? ''),
        ]);

        return $this->listItemApiService->resetSuggestionData($resetRequest);
    }

    private function handleUpdateSuggestionSettingsOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];

        $settingsRequest = $this->requestFactory->make($request, [
            'owner_id' => (int) ($operation['owner_id'] ?? 0),
            'link_id' => $this->normalizeNullablePositiveInteger($operation['link_id'] ?? null),
            'type' => (string) ($operation['type'] ?? ''),
            'suggestion_key' => (string) ($payload['suggestion_key'] ?? ''),
            'custom_interval_seconds' => array_key_exists('custom_interval_seconds', $payload)
                ? $payload['custom_interval_seconds']
                : null,
            'ignored' => array_key_exists('ignored', $payload)
                ? (bool) $payload['ignored']
                : null,
        ]);

        return $this->listItemApiService->updateSuggestionSettings($settingsRequest);
    }

    private function normalizeNullablePositiveInteger(mixed $value): ?int
    {
        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : null;
    }
}

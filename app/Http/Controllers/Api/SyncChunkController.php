<?php

namespace App\Http\Controllers\Api;

use App\Events\UserSyncStateChanged;
use App\Http\Controllers\Controller;
use App\Models\ListInvitation;
use App\Models\ListItem;
use App\Models\ListLink;
use App\Models\SyncOperation;
use App\Models\User;
use App\Services\ListSyncService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SyncChunkController extends Controller
{
    private const OPERATION_RESERVATION_TTL_SECONDS = 60;
    private const MOOD_COLOR_VALUES = ['red', 'yellow', 'green'];
    private const MOOD_FIRE_EMOJIS = ['🥰', '😝', '😈'];
    private const MOOD_BATTERY_EMOJIS = ['😴', '😡', '😄', '😊'];
    private const MOOD_UNKNOWN_EMOJI = '❔';

    public function __construct(
        private readonly ListItemController $listItemController,
        private readonly SharingController $sharingController,
        private readonly ProfileSettingsController $profileSettingsController,
        private readonly ListSyncService $listSyncService,
    ) {
    }

    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operations' => ['required', 'array', 'min:1', 'max:80'],
            'operations.*.op_id' => ['required', 'string', 'max:120'],
            'operations.*.action' => ['required', 'string', 'max:64'],
        ]);

        $results = [];
        $userId = (int) $request->user()->id;

        foreach ($validated['operations'] as $index => $summaryOperation) {
            $operation = $request->input("operations.{$index}", []);
            if (! is_array($operation)) {
                $operation = [];
            }

            $opId = (string) ($summaryOperation['op_id'] ?? '');
            $action = (string) ($summaryOperation['action'] ?? '');
            $reservedOperation = null;

            try {
                $storedResult = $this->resolveStoredOperationResult($userId, $opId, $action);
                if ($storedResult !== null) {
                    $results[] = $storedResult;
                    continue;
                }

                $reservedOperation = $this->reserveOperationResult($userId, $opId, $action);
                $operationResult = [
                    'op_id' => $opId,
                    'action' => $action,
                    'status' => 'ok',
                    'data' => $this->handleOperation($request, $operation),
                ];
                $this->completeReservedOperation($reservedOperation, $operationResult);
                $results[] = $operationResult;
            } catch (ValidationException $exception) {
                $this->releaseReservedOperation($reservedOperation);
                $results[] = [
                    'op_id' => $opId,
                    'action' => $action,
                    'status' => 'error',
                    'http_status' => 422,
                    'message' => $exception->getMessage(),
                    'errors' => $exception->errors(),
                ];
                break;
            } catch (ModelNotFoundException $exception) {
                $this->releaseReservedOperation($reservedOperation);
                $results[] = [
                    'op_id' => $opId,
                    'action' => $action,
                    'status' => 'error',
                    'http_status' => 404,
                    'message' => 'Not found.',
                    'errors' => [],
                ];
                break;
            } catch (HttpExceptionInterface $exception) {
                $this->releaseReservedOperation($reservedOperation);
                $results[] = [
                    'op_id' => $opId,
                    'action' => $action,
                    'status' => 'error',
                    'http_status' => $exception->getStatusCode(),
                    'message' => $exception->getMessage(),
                    'errors' => [],
                ];
                break;
            } catch (\Throwable $exception) {
                $this->releaseReservedOperation($reservedOperation);
                Log::error('Chunk sync operation failed.', [
                    'op_id' => $opId,
                    'action' => $action,
                    'error' => $exception->getMessage(),
                ]);

                $results[] = [
                    'op_id' => $opId,
                    'action' => $action,
                    'status' => 'error',
                    'http_status' => 500,
                    'message' => 'Chunk operation failed.',
                    'errors' => [],
                ];
                break;
            }
        }

        return response()->json([
            'results' => $results,
        ]);
    }

    private function handleOperation(Request $request, array $operation): array
    {
        $action = (string) ($operation['action'] ?? '');

        return match ($action) {
            'create' => $this->handleCreateOperation($request, $operation),
            'update' => $this->handleUpdateOperation($request, $operation),
            'delete' => $this->handleDeleteOperation($request, $operation),
            'reorder' => $this->handleReorderOperation($request, $operation),
            'dismiss_suggestion' => $this->handleDismissSuggestionOperation($request, $operation),
            'reset_suggestion' => $this->handleResetSuggestionOperation($request, $operation),
            'set_default_owner' => $this->handleSetDefaultOwnerOperation($request, $operation),
            'send_invitation' => $this->handleSendInvitationOperation($request, $operation),
            'accept_invitation' => $this->handleAcceptInvitationOperation($request, $operation),
            'decline_invitation' => $this->handleDeclineInvitationOperation($request, $operation),
            'set_mine' => $this->handleSetMineOperation($request, $operation),
            'break_link' => $this->handleBreakLinkOperation($request, $operation),
            'update_profile' => $this->handleUpdateProfileOperation($request, $operation),
            'update_password' => $this->handleUpdatePasswordOperation($request, $operation),
            'sync_gamification' => $this->handleSyncGamificationOperation($request, $operation),
            'update_mood' => $this->handleUpdateMoodOperation($request, $operation),
            default => [],
        };
    }

    private function handleCreateOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $type = (string) ($operation['type'] ?? '');

        $storeRequest = $this->makeActionRequest($request, [
            'owner_id' => (int) ($operation['owner_id'] ?? 0),
            'link_id' => $this->normalizeNullablePositiveInteger($operation['link_id'] ?? null),
            'type' => $type,
            'text' => (string) ($payload['text'] ?? ''),
            'quantity' => $type === ListItem::TYPE_PRODUCT ? ($payload['quantity'] ?? null) : null,
            'unit' => $type === ListItem::TYPE_PRODUCT ? ($payload['unit'] ?? null) : null,
            'due_at' => $type === ListItem::TYPE_TODO ? ($payload['due_at'] ?? null) : null,
            'priority' => $type === ListItem::TYPE_TODO ? ($payload['priority'] ?? null) : null,
        ]);

        $storeResponse = $this->listItemController->store($storeRequest);
        $storeData = $this->decodeResponseData($storeResponse);
        $createdItem = is_array($storeData['item'] ?? null) ? $storeData['item'] : null;
        $listVersion = (int) ($storeData['list_version'] ?? 0);

        if (! $createdItem) {
            return [];
        }

        if ((bool) ($payload['is_completed'] ?? false)) {
            $createdItemId = (int) ($createdItem['id'] ?? 0);
            if ($createdItemId > 0) {
                $updatedResponse = $this->listItemController->update(
                    $this->makeActionRequest($request, ['is_completed' => true]),
                    ListItem::query()->findOrFail($createdItemId),
                );

                $updatedData = $this->decodeResponseData($updatedResponse);
                $updatedItem = is_array($updatedData['item'] ?? null) ? $updatedData['item'] : null;
                $updatedListVersion = (int) ($updatedData['list_version'] ?? 0);
                if ($updatedListVersion > 0) {
                    $listVersion = $updatedListVersion;
                }
                if ($updatedItem) {
                    $createdItem = $updatedItem;
                }
            }
        }

        return [
            'item' => $createdItem,
            'list_version' => $listVersion > 0 ? $listVersion : null,
        ];
    }

    private function handleUpdateOperation(Request $request, array $operation): array
    {
        $itemId = (int) ($operation['item_id'] ?? 0);
        if ($itemId <= 0) {
            return [];
        }

        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $updateRequest = $this->makeActionRequest($request, $payload);
        $response = $this->listItemController->update($updateRequest, ListItem::query()->findOrFail($itemId));

        return $this->decodeResponseData($response);
    }

    private function handleDeleteOperation(Request $request, array $operation): array
    {
        $itemId = (int) ($operation['item_id'] ?? 0);
        if ($itemId <= 0) {
            return [];
        }

        $response = $this->listItemController->destroy(
            $this->makeActionRequest($request, []),
            ListItem::query()->findOrFail($itemId),
        );

        return $this->decodeResponseData($response);
    }

    private function handleReorderOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $order = is_array($payload['order'] ?? null) ? $payload['order'] : [];

        $reorderRequest = $this->makeActionRequest($request, [
            'owner_id' => (int) ($operation['owner_id'] ?? 0),
            'link_id' => $this->normalizeNullablePositiveInteger($operation['link_id'] ?? null),
            'type' => (string) ($operation['type'] ?? ''),
            'order' => array_values($order),
        ]);

        return $this->decodeResponseData($this->listItemController->reorder($reorderRequest));
    }

    private function handleDismissSuggestionOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];

        $dismissRequest = $this->makeActionRequest($request, [
            'owner_id' => (int) ($operation['owner_id'] ?? 0),
            'link_id' => $this->normalizeNullablePositiveInteger($operation['link_id'] ?? null),
            'type' => (string) ($operation['type'] ?? ''),
            'suggestion_key' => (string) ($payload['suggestion_key'] ?? ''),
            'average_interval_seconds' => (int) ($payload['average_interval_seconds'] ?? 0),
        ]);

        return $this->decodeResponseData($this->listItemController->dismissSuggestion($dismissRequest));
    }

    private function handleResetSuggestionOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];

        $resetRequest = $this->makeActionRequest($request, [
            'owner_id' => (int) ($operation['owner_id'] ?? 0),
            'link_id' => $this->normalizeNullablePositiveInteger($operation['link_id'] ?? null),
            'type' => (string) ($operation['type'] ?? ''),
            'suggestion_key' => (string) ($payload['suggestion_key'] ?? ''),
        ]);

        return $this->decodeResponseData($this->listItemController->resetSuggestionData($resetRequest));
    }

    private function handleSetDefaultOwnerOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];

        return $this->decodeResponseData($this->sharingController->setDefaultOwner(
            $this->makeActionRequest($request, [
                'owner_id' => (int) ($payload['owner_id'] ?? 0),
            ]),
        ));
    }

    private function handleSendInvitationOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];

        return $this->decodeResponseData($this->sharingController->sendInvitation(
            $this->makeActionRequest($request, [
                'user_id' => (int) ($payload['user_id'] ?? 0),
            ]),
        ));
    }

    private function handleAcceptInvitationOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $invitationId = (int) ($payload['invitation_id'] ?? 0);

        return $this->decodeResponseData($this->sharingController->acceptInvitation(
            $this->makeActionRequest($request, []),
            ListInvitation::query()->findOrFail($invitationId),
        ));
    }

    private function handleDeclineInvitationOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $invitationId = (int) ($payload['invitation_id'] ?? 0);

        return $this->decodeResponseData($this->sharingController->declineInvitation(
            $this->makeActionRequest($request, []),
            ListInvitation::query()->findOrFail($invitationId),
        ));
    }

    private function handleSetMineOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $linkId = (int) ($payload['link_id'] ?? 0);

        return $this->decodeResponseData($this->sharingController->setListAsMine(
            $this->makeActionRequest($request, []),
            ListLink::query()->findOrFail($linkId),
        ));
    }

    private function handleBreakLinkOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $linkId = (int) ($payload['link_id'] ?? 0);

        return $this->decodeResponseData($this->sharingController->destroyLink(
            $this->makeActionRequest($request, []),
            ListLink::query()->findOrFail($linkId),
        ));
    }

    private function handleUpdateProfileOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];

        return $this->decodeResponseData($this->profileSettingsController->update(
            $this->makeActionRequest($request, [
                'name' => (string) ($payload['name'] ?? ''),
                'tag' => (string) ($payload['tag'] ?? ''),
                'email' => (string) ($payload['email'] ?? ''),
            ]),
        ));
    }

    private function handleUpdatePasswordOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];

        return $this->decodeResponseData($this->profileSettingsController->updatePassword(
            $this->makeActionRequest($request, [
                'current_password' => (string) ($payload['current_password'] ?? ''),
                'password' => (string) ($payload['password'] ?? ''),
                'password_confirmation' => (string) ($payload['password_confirmation'] ?? ''),
            ]),
        ));
    }

    private function handleSyncGamificationOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];

        $xpProgress = $this->normalizeXpProgress($payload['xp_progress'] ?? null);
        $productivityScore = max(0, (int) ($payload['productivity_score'] ?? 0));
        $rewardHistory = $this->normalizeRewardHistory($payload['productivity_reward_history'] ?? []);
        $xpColorSeed = max(1, (int) ($payload['xp_color_seed'] ?? 1));
        $incomingUpdatedAtMs = $this->normalizePositiveInteger($payload['updated_at_ms'] ?? null);

        $user = $request->user();
        $currentUpdatedAtMs = $user->gamification_updated_at?->valueOf();

        if ($incomingUpdatedAtMs === null || ($currentUpdatedAtMs !== null && $incomingUpdatedAtMs < $currentUpdatedAtMs)) {
            return [
                'gamification' => $this->listSyncService->getGamificationState($user->fresh()),
                'applied' => false,
            ];
        }

        $user->xp_progress = $xpProgress;
        $user->productivity_score = $productivityScore;
        $user->productivity_reward_history = $rewardHistory;
        $user->xp_color_seed = $xpColorSeed;
        $user->gamification_updated_at = now();
        $user->save();
        $this->dispatchUserSyncStateChangedSafely(
            (int) $user->id,
            'gamification_changed',
            (int) $user->id
        );

        return [
            'gamification' => $this->listSyncService->getGamificationState($user->fresh()),
            'applied' => true,
        ];
    }

    private function handleUpdateMoodOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $user = $request->user();
        $incomingUpdatedAtMs = $this->normalizeMoodUpdatedAtMs($payload);
        $currentUpdatedAtMs = $user->mood_updated_at?->valueOf();

        if ($incomingUpdatedAtMs === null || ($currentUpdatedAtMs !== null && $incomingUpdatedAtMs < $currentUpdatedAtMs)) {
            $freshUser = $user->fresh();

            return [
                'mood' => $this->listSyncService->getMoodState($freshUser),
                'mood_cards' => $this->listSyncService->getMoodCards($freshUser)->values()->all(),
                'applied' => false,
            ];
        }

        $user->mood_color = $this->normalizeMoodColor($payload['color'] ?? null);
        $user->mood_fire_level = $this->normalizeMoodLevel($payload['fire_level'] ?? null);
        $user->mood_fire_emoji = $this->normalizeMoodEmoji(
            $payload['fire_emoji'] ?? null,
            self::MOOD_FIRE_EMOJIS,
            self::MOOD_FIRE_EMOJIS[0]
        );
        $user->mood_battery_level = $this->normalizeMoodLevel($payload['battery_level'] ?? null);
        $user->mood_battery_emoji = $this->normalizeMoodEmoji(
            $payload['battery_emoji'] ?? null,
            self::MOOD_BATTERY_EMOJIS,
            self::MOOD_BATTERY_EMOJIS[3]
        );
        $user->mood_updated_at = now();
        $user->save();

        $this->dispatchUserSyncStateChangedForLinkedUsers(
            (int) $user->id,
            'mood_changed',
            (int) $user->id
        );

        $freshUser = $user->fresh();

        return [
            'mood' => $this->listSyncService->getMoodState($freshUser),
            'mood_cards' => $this->listSyncService->getMoodCards($freshUser)->values()->all(),
            'applied' => true,
        ];
    }

    private function makeActionRequest(Request $baseRequest, array $payload): Request
    {
        $actionRequest = Request::create('/', 'POST', $payload);
        $actionRequest->setUserResolver(static fn () => $baseRequest->user());

        return $actionRequest;
    }

    private function decodeResponseData(JsonResponse $response): array
    {
        $decoded = json_decode($response->getContent(), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeNullablePositiveInteger(mixed $value): ?int
    {
        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : null;
    }

    private function normalizePositiveInteger(mixed $value): ?int
    {
        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : null;
    }

    private function normalizeMoodUpdatedAtMs(array $payload): ?int
    {
        $updatedAtMs = $this->normalizePositiveInteger($payload['updated_at_ms'] ?? null);
        if ($updatedAtMs !== null) {
            return $updatedAtMs;
        }

        $updatedAt = trim((string) ($payload['updated_at'] ?? ''));
        if ($updatedAt === '') {
            return null;
        }

        $timestamp = strtotime($updatedAt);
        if ($timestamp === false || $timestamp <= 0) {
            return null;
        }

        return $timestamp * 1000;
    }

    private function normalizeXpProgress(mixed $value): float
    {
        $parsed = (float) $value;
        if (! is_finite($parsed)) {
            return 0.0;
        }

        return max(0.0, min(0.999999, $parsed));
    }

    private function normalizeRewardHistory(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = array_values(array_filter(
            array_map(static fn (mixed $entry): int => max(0, (int) $entry), $value),
            static fn (int $entry): bool => $entry > 0
        ));

        if (count($normalized) > 10000) {
            return array_slice($normalized, -10000);
        }

        return $normalized;
    }

    private function normalizeMoodColor(mixed $value): string
    {
        $candidate = strtolower(trim((string) $value));

        return in_array($candidate, self::MOOD_COLOR_VALUES, true)
            ? $candidate
            : self::MOOD_COLOR_VALUES[1];
    }

    private function normalizeMoodLevel(mixed $value): int
    {
        return max(0, min(100, (int) $value));
    }

    private function normalizeMoodEmoji(mixed $value, array $allowed, string $fallback): string
    {
        $candidate = trim((string) ($value ?? ''));
        if ($candidate === self::MOOD_UNKNOWN_EMOJI) {
            return $candidate;
        }

        return in_array($candidate, $allowed, true)
            ? $candidate
            : $fallback;
    }

    private function resolveStoredOperationResult(int $userId, string $opId, string $fallbackAction): ?array
    {
        $normalizedOpId = trim($opId);
        if ($normalizedOpId === '') {
            return null;
        }

        $storedOperation = SyncOperation::query()
            ->where('user_id', $userId)
            ->where('op_id', $normalizedOpId)
            ->first();

        if (! $storedOperation) {
            return null;
        }

        if ((string) $storedOperation->status !== SyncOperation::STATUS_COMPLETED) {
            return null;
        }

        $storedResult = is_array($storedOperation->result) ? $storedOperation->result : [];
        if ($storedResult === []) {
            return null;
        }

        return $this->normalizeStoredOperationResult($storedResult, $normalizedOpId, $fallbackAction);
    }

    private function reserveOperationResult(int $userId, string $opId, string $action): ?SyncOperation
    {
        $normalizedOpId = trim($opId);
        if ($normalizedOpId === '') {
            return null;
        }

        try {
            return SyncOperation::query()->create([
                'user_id' => $userId,
                'op_id' => $normalizedOpId,
                'action' => $action,
                'status' => SyncOperation::STATUS_PROCESSING,
                'result' => null,
            ]);
        } catch (QueryException $exception) {
            if (! $this->isDuplicateKeyException($exception)) {
                throw $exception;
            }
        }

        $existingOperation = SyncOperation::query()
            ->where('user_id', $userId)
            ->where('op_id', $normalizedOpId)
            ->first();

        if (! $existingOperation) {
            throw new HttpException(409, 'Operation is already being processed.');
        }

        if ((string) $existingOperation->status === SyncOperation::STATUS_COMPLETED) {
            throw new HttpException(409, 'Operation has already been processed.');
        }

        $updatedAt = $existingOperation->updated_at ?? $existingOperation->created_at;
        if ($updatedAt && $updatedAt->lt(now()->subSeconds(self::OPERATION_RESERVATION_TTL_SECONDS))) {
            $existingOperation->delete();

            return $this->reserveOperationResult($userId, $normalizedOpId, $action);
        }

        throw new HttpException(409, 'Operation is already being processed.');
    }

    private function completeReservedOperation(?SyncOperation $reservedOperation, array $result): void
    {
        if (! $reservedOperation) {
            return;
        }

        $reservedOperation->status = SyncOperation::STATUS_COMPLETED;
        $reservedOperation->result = $result;
        $reservedOperation->save();
    }

    private function releaseReservedOperation(?SyncOperation $reservedOperation): void
    {
        if (! $reservedOperation) {
            return;
        }

        $reservedOperation->delete();
    }

    private function normalizeStoredOperationResult(array $storedResult, string $opId, string $fallbackAction): array
    {
        return [
            'op_id' => (string) ($storedResult['op_id'] ?? $opId),
            'action' => (string) ($storedResult['action'] ?? $fallbackAction),
            'status' => (string) ($storedResult['status'] ?? 'ok'),
            'data' => is_array($storedResult['data'] ?? null) ? $storedResult['data'] : [],
        ];
    }

    private function isDuplicateKeyException(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        if ($sqlState !== '23000') {
            return false;
        }

        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        if ($driverCode === 1062) {
            return true;
        }

        return str_contains(strtolower($exception->getMessage()), 'duplicate');
    }

    private function dispatchUserSyncStateChangedSafely(
        int $userId,
        string $reason,
        ?int $actorUserId = null
    ): void
    {
        try {
            $targetUser = User::query()->find($userId);
            $statePayload = $targetUser
                ? $this->listSyncService->getState($targetUser)
                : null;

            broadcast(new UserSyncStateChanged($userId, $reason, $actorUserId, $statePayload))->toOthers();
        } catch (\Throwable $exception) {
            Log::warning('Realtime user sync dispatch failed.', [
                'user_id' => $userId,
                'reason' => $reason,
                'actor_user_id' => $actorUserId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function dispatchUserSyncStateChangedForLinkedUsers(
        int $userId,
        string $reason,
        ?int $actorUserId = null
    ): void
    {
        $targets = ListLink::query()
            ->where('is_active', true)
            ->where(function ($query) use ($userId): void {
                $query->where('user_one_id', $userId)
                    ->orWhere('user_two_id', $userId);
            })
            ->get(['user_one_id', 'user_two_id'])
            ->flatMap(static fn (ListLink $link): array => [
                (int) $link->user_one_id,
                (int) $link->user_two_id,
            ])
            ->unique()
            ->values();

        foreach ($targets as $targetUserId) {
            $this->dispatchUserSyncStateChangedSafely((int) $targetUserId, $reason, $actorUserId);
        }
    }
}

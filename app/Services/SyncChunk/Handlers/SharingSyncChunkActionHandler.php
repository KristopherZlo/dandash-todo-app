<?php

namespace App\Services\SyncChunk\Handlers;

use App\Models\ListInvitation;
use App\Models\UserList;
use App\Services\ListSyncService;
use App\Services\Lists\ListCatalogService;
use App\Services\Realtime\UserSyncStateBroadcaster;
use App\Services\Sharing\ListSharingService;
use App\Services\SyncChunk\Contracts\SyncChunkActionHandler;
use Illuminate\Http\Request;

class SharingSyncChunkActionHandler implements SyncChunkActionHandler
{
    public function __construct(
        private readonly ListSharingService $listSharingService,
        private readonly ListCatalogService $listCatalogService,
        private readonly ListSyncService $listSyncService,
        private readonly UserSyncStateBroadcaster $syncStateBroadcaster,
    ) {
    }

    public function supports(string $action): bool
    {
        return in_array($action, [
            'create_list',
            'rename_list',
            'delete_list',
            'set_default_list',
            'save_template',
            'create_from_template',
            'delete_template',
            'send_invitation',
            'accept_invitation',
            'decline_invitation',
            'remove_member',
        ], true);
    }

    public function handle(Request $request, array $operation): array
    {
        $action = (string) ($operation['action'] ?? '');

        return match ($action) {
            'create_list' => $this->handleCreateListOperation($request, $operation),
            'rename_list' => $this->handleRenameListOperation($request, $operation),
            'delete_list' => $this->handleDeleteListOperation($request, $operation),
            'set_default_list' => $this->handleSetDefaultListOperation($request, $operation),
            'save_template' => $this->handleSaveTemplateOperation($request, $operation),
            'create_from_template' => $this->handleCreateFromTemplateOperation($request, $operation),
            'delete_template' => $this->handleDeleteTemplateOperation($request, $operation),
            'send_invitation' => $this->handleSendInvitationOperation($request, $operation),
            'accept_invitation' => $this->handleAcceptInvitationOperation($request, $operation),
            'decline_invitation' => $this->handleDeclineInvitationOperation($request, $operation),
            'remove_member' => $this->handleRemoveMemberOperation($request, $operation),
            default => [],
        };
    }

    private function handleCreateListOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $list = $this->listCatalogService->createList(
            $request->user(),
            (string) ($payload['name'] ?? ''),
        );

        $this->syncStateBroadcaster->broadcastToUsers(
            [(int) $request->user()->id],
            'list_created',
            (int) $request->user()->id,
        );

        return [
            'status' => 'ok',
            'list_id' => (int) $list->id,
            'state' => $this->listSyncService->getState($request->user()),
        ];
    }

    private function handleRenameListOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $list = $this->resolveList($payload['list_id'] ?? $operation['list_id'] ?? null);

        $this->listCatalogService->renameList(
            $request->user(),
            $list,
            (string) ($payload['name'] ?? ''),
        );

        $this->syncStateBroadcaster->broadcastToUsers(
            $this->memberUserIds($list),
            'list_renamed',
            (int) $request->user()->id,
        );

        return [
            'status' => 'ok',
            'state' => $this->listSyncService->getState($request->user()),
        ];
    }

    private function handleDeleteListOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $list = $this->resolveList($payload['list_id'] ?? $operation['list_id'] ?? null);
        $memberUserIds = $this->memberUserIds($list);

        $this->listCatalogService->deleteList($request->user(), $list);
        $this->syncStateBroadcaster->broadcastToUsers(
            $memberUserIds,
            'list_deleted',
            (int) $request->user()->id,
        );

        return [
            'status' => 'ok',
            'state' => $this->listSyncService->getState($request->user()),
        ];
    }

    private function handleSetDefaultListOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];

        return $this->listSharingService->setDefaultList(
            $request->user(),
            $this->resolveList($payload['list_id'] ?? $operation['list_id'] ?? null),
        );
    }

    private function handleSaveTemplateOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $template = $this->listCatalogService->saveTemplate(
            $request->user(),
            $this->resolveList($payload['source_list_id'] ?? null),
            (string) ($payload['name'] ?? ''),
        );

        $this->syncStateBroadcaster->broadcastToUsers(
            [(int) $request->user()->id],
            'template_created',
            (int) $request->user()->id,
        );

        return [
            'status' => 'ok',
            'template_id' => (int) $template->id,
            'state' => $this->listSyncService->getState($request->user()),
        ];
    }

    private function handleCreateFromTemplateOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $list = $this->listCatalogService->createFromTemplate(
            $request->user(),
            $this->resolveList($payload['template_id'] ?? null),
            array_key_exists('name', $payload) ? (string) $payload['name'] : null,
        );

        $this->syncStateBroadcaster->broadcastToUsers(
            [(int) $request->user()->id],
            'list_created_from_template',
            (int) $request->user()->id,
        );

        return [
            'status' => 'ok',
            'list_id' => (int) $list->id,
            'state' => $this->listSyncService->getState($request->user()),
        ];
    }

    private function handleDeleteTemplateOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $template = $this->resolveList($payload['template_id'] ?? $payload['list_id'] ?? $operation['list_id'] ?? null);

        $this->listCatalogService->deleteList($request->user(), $template);
        $this->syncStateBroadcaster->broadcastToUsers(
            [(int) $request->user()->id],
            'template_deleted',
            (int) $request->user()->id,
        );

        return [
            'status' => 'ok',
            'state' => $this->listSyncService->getState($request->user()),
        ];
    }

    private function handleSendInvitationOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];

        return $this->listSharingService->sendInvitation(
            $request->user(),
            $this->resolveList($payload['list_id'] ?? $operation['list_id'] ?? null),
            (int) ($payload['user_id'] ?? 0)
        );
    }

    private function handleAcceptInvitationOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $invitationId = (int) ($payload['invitation_id'] ?? 0);

        return $this->listSharingService->acceptInvitation(
            $request->user(),
            ListInvitation::query()->findOrFail($invitationId)
        );
    }

    private function handleDeclineInvitationOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $invitationId = (int) ($payload['invitation_id'] ?? 0);

        return $this->listSharingService->declineInvitation(
            $request->user(),
            ListInvitation::query()->findOrFail($invitationId)
        );
    }

    private function handleRemoveMemberOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];

        return $this->listSharingService->removeMember(
            $request->user(),
            $this->resolveList($payload['list_id'] ?? $operation['list_id'] ?? null),
            (int) ($payload['user_id'] ?? 0),
        );
    }

    private function resolveList(mixed $value): UserList
    {
        return UserList::query()->findOrFail((int) $value);
    }

    /**
     * @return array<int, int>
     */
    private function memberUserIds(UserList $list): array
    {
        return $list->members()
            ->pluck('user_id')
            ->map(static fn ($value): int => (int) $value)
            ->values()
            ->all();
    }
}

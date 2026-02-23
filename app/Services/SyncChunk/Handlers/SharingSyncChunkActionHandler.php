<?php

namespace App\Services\SyncChunk\Handlers;

use App\Models\ListInvitation;
use App\Models\ListLink;
use App\Services\Sharing\ListSharingService;
use App\Services\SyncChunk\Contracts\SyncChunkActionHandler;
use Illuminate\Http\Request;

class SharingSyncChunkActionHandler implements SyncChunkActionHandler
{
    public function __construct(
        private readonly ListSharingService $listSharingService
    ) {
    }

    public function supports(string $action): bool
    {
        return in_array($action, [
            'set_default_owner',
            'send_invitation',
            'accept_invitation',
            'decline_invitation',
            'set_mine',
            'break_link',
        ], true);
    }

    public function handle(Request $request, array $operation): array
    {
        $action = (string) ($operation['action'] ?? '');

        return match ($action) {
            'set_default_owner' => $this->handleSetDefaultOwnerOperation($request, $operation),
            'send_invitation' => $this->handleSendInvitationOperation($request, $operation),
            'accept_invitation' => $this->handleAcceptInvitationOperation($request, $operation),
            'decline_invitation' => $this->handleDeclineInvitationOperation($request, $operation),
            'set_mine' => $this->handleSetMineOperation($request, $operation),
            'break_link' => $this->handleBreakLinkOperation($request, $operation),
            default => [],
        };
    }

    private function handleSetDefaultOwnerOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];

        return $this->listSharingService->setDefaultOwner(
            $request->user(),
            (int) ($payload['owner_id'] ?? 0)
        );
    }

    private function handleSendInvitationOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];

        return $this->listSharingService->sendInvitation(
            $request->user(),
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

    private function handleSetMineOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $linkId = (int) ($payload['link_id'] ?? 0);

        return $this->listSharingService->setListAsMine(
            $request->user(),
            ListLink::query()->findOrFail($linkId)
        );
    }

    private function handleBreakLinkOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $linkId = (int) ($payload['link_id'] ?? 0);

        return $this->listSharingService->destroyLink(
            $request->user(),
            ListLink::query()->findOrFail($linkId)
        );
    }
}

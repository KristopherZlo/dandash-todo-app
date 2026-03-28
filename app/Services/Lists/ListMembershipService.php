<?php

namespace App\Services\Lists;

use App\Models\ListInvitation;
use App\Models\ListMember;
use App\Models\User;
use App\Models\UserList;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ListMembershipService
{
    public function __construct(
        private readonly ListCatalogService $listCatalogService
    ) {
    }

    public function searchUsers(User $currentUser, string $query, ?UserList $list = null): array
    {
        $normalizedQuery = Str::lower(ltrim(trim($query), '@'));

        if ($normalizedQuery === '') {
            return ['users' => []];
        }

        $memberIds = [];
        if ($list) {
            $this->listCatalogService->ensureOwner($currentUser, $list);
            $memberIds = $list->members()->pluck('user_id')->map(static fn ($value): int => (int) $value)->all();
        }

        $users = User::query()
            ->where('id', '!=', (int) $currentUser->id)
            ->when($memberIds !== [], static fn ($query) => $query->whereNotIn('id', $memberIds))
            ->where('tag', 'like', '%'.$normalizedQuery.'%')
            ->orderBy('tag')
            ->limit(10)
            ->get(['id', 'name', 'tag', 'email'])
            ->map(static fn (User $user): array => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'tag' => (string) $user->tag,
                'email' => (string) $user->email,
            ])
            ->values()
            ->all();

        return ['users' => $users];
    }

    public function sendInvitation(User $currentUser, UserList $list, int $targetUserId): ListInvitation
    {
        $this->listCatalogService->ensureOwner($currentUser, $list);
        abort_if($list->is_template, Response::HTTP_UNPROCESSABLE_ENTITY, 'Templates cannot be shared.');

        if ((int) $currentUser->id === $targetUserId) {
            throw ValidationException::withMessages([
                'user_id' => 'Нельзя приглашать самого себя.',
            ]);
        }

        $alreadyMember = ListMember::query()
            ->where('list_id', (int) $list->id)
            ->where('user_id', $targetUserId)
            ->exists();

        if ($alreadyMember) {
            throw ValidationException::withMessages([
                'user_id' => 'Этот пользователь уже в списке.',
            ]);
        }

        $pendingExists = ListInvitation::query()
            ->where('list_id', (int) $list->id)
            ->where('invitee_id', $targetUserId)
            ->where('status', ListInvitation::STATUS_PENDING)
            ->exists();

        if ($pendingExists) {
            throw ValidationException::withMessages([
                'user_id' => 'Приглашение в этот список уже отправлено.',
            ]);
        }

        return ListInvitation::query()->create([
            'list_id' => (int) $list->id,
            'inviter_id' => (int) $currentUser->id,
            'invitee_id' => $targetUserId,
            'status' => ListInvitation::STATUS_PENDING,
        ]);
    }

    public function acceptInvitation(User $currentUser, ListInvitation $invitation): UserList
    {
        abort_unless((int) $invitation->invitee_id === (int) $currentUser->id, Response::HTTP_FORBIDDEN);
        abort_unless($invitation->isPending(), Response::HTTP_UNPROCESSABLE_ENTITY, 'Invitation is not active.');

        /** @var UserList $list */
        $list = $invitation->list()->firstOrFail();
        abort_if($list->is_template, Response::HTTP_UNPROCESSABLE_ENTITY, 'Template invites are not supported.');

        DB::transaction(function () use ($currentUser, $invitation, $list): void {
            ListMember::query()->firstOrCreate([
                'list_id' => (int) $list->id,
                'user_id' => (int) $currentUser->id,
            ], [
                'role' => ListMember::ROLE_EDITOR,
            ]);

            $invitation->status = ListInvitation::STATUS_ACCEPTED;
            $invitation->responded_at = now();
            $invitation->save();

            if (! $currentUser->preferred_list_id) {
                $currentUser->preferred_list_id = (int) $list->id;
                $currentUser->save();
            }
        });

        return $list->fresh(['members']);
    }

    public function declineInvitation(User $currentUser, ListInvitation $invitation): UserList
    {
        $isInvitee = (int) $invitation->invitee_id === (int) $currentUser->id;
        $isInviter = (int) $invitation->inviter_id === (int) $currentUser->id;

        abort_unless($isInvitee || $isInviter, Response::HTTP_FORBIDDEN);
        abort_unless($invitation->isPending(), Response::HTTP_UNPROCESSABLE_ENTITY, 'Invitation is not active.');

        $invitation->status = $isInvitee
            ? ListInvitation::STATUS_DECLINED
            : ListInvitation::STATUS_CANCELLED;
        $invitation->responded_at = now();
        $invitation->save();

        return $invitation->list()->firstOrFail();
    }

    public function removeMember(User $currentUser, UserList $list, int $memberUserId): void
    {
        $this->listCatalogService->ensureOwner($currentUser, $list);
        abort_if($list->is_template, Response::HTTP_UNPROCESSABLE_ENTITY, 'Templates do not have members.');
        abort_if((int) $currentUser->id === $memberUserId, Response::HTTP_UNPROCESSABLE_ENTITY, 'Owner cannot remove themselves.');

        $member = ListMember::query()
            ->where('list_id', (int) $list->id)
            ->where('user_id', $memberUserId)
            ->first();

        abort_unless($member, Response::HTTP_NOT_FOUND, 'Member not found.');
        abort_if($member->isOwner(), Response::HTTP_UNPROCESSABLE_ENTITY, 'Owner cannot be removed.');

        DB::transaction(function () use ($list, $memberUserId, $member): void {
            $member->delete();

            ListInvitation::query()
                ->where('list_id', (int) $list->id)
                ->where('invitee_id', $memberUserId)
                ->where('status', ListInvitation::STATUS_PENDING)
                ->delete();
        });
    }
}

<?php

namespace App\Services;

use App\Models\ListInvitation;
use App\Models\ListLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListSyncService
{
    public function canonicalUserPair(int $firstUserId, int $secondUserId): array
    {
        return $firstUserId < $secondUserId
            ? [$firstUserId, $secondUserId]
            : [$secondUserId, $firstUserId];
    }

    public function canAccessOwner(User $user, int $ownerId): bool
    {
        if ($user->id === $ownerId) {
            return true;
        }

        return ListLink::query()
            ->where('is_active', true)
            ->where(function (Builder $query) use ($user, $ownerId): void {
                $query->where(function (Builder $pairQuery) use ($user, $ownerId): void {
                    $pairQuery->where('user_one_id', $user->id)
                        ->where('user_two_id', $ownerId);
                })->orWhere(function (Builder $pairQuery) use ($user, $ownerId): void {
                    $pairQuery->where('user_one_id', $ownerId)
                        ->where('user_two_id', $user->id);
                });
            })
            ->exists();
    }

    public function getState(User $user): array
    {
        $invitations = $this->getPendingInvitations($user);
        $listOptions = $this->getListOptions($user)->values();

        return [
            'pending_invitations_count' => $invitations->count(),
            'invitations' => $invitations->values()->all(),
            'links' => $this->getLinks($user)->values()->all(),
            'list_options' => $listOptions->all(),
            'default_owner_id' => $this->resolveDefaultOwnerId($user, $listOptions),
            'gamification' => $this->getGamificationState($user),
        ];
    }

    public function getGamificationState(User $user): array
    {
        $xpProgress = (float) ($user->xp_progress ?? 0.0);
        $rewardHistory = array_values(array_filter(
            array_map(
                static fn (mixed $entry): int => max(0, (int) $entry),
                is_array($user->productivity_reward_history) ? $user->productivity_reward_history : []
            ),
            static fn (int $entry): bool => $entry > 0
        ));

        return [
            'xp_progress' => max(0.0, min(0.999999, $xpProgress)),
            'productivity_score' => max(0, (int) ($user->productivity_score ?? 0)),
            'productivity_reward_history' => $rewardHistory,
            'xp_color_seed' => max(1, (int) ($user->xp_color_seed ?? 1)),
            'updated_at_ms' => $user->gamification_updated_at?->valueOf(),
        ];
    }

    public function getPendingInvitations(User $user): Collection
    {
        return ListInvitation::query()
            ->with('inviter:id,name,email')
            ->where('invitee_id', $user->id)
            ->where('status', ListInvitation::STATUS_PENDING)
            ->latest('id')
            ->get()
            ->map(function (ListInvitation $invitation): array {
                return [
                    'id' => $invitation->id,
                    'status' => $invitation->status,
                    'created_at' => optional($invitation->created_at)->toISOString(),
                    'inviter' => [
                        'id' => $invitation->inviter_id,
                        'name' => $invitation->inviter?->name,
                        'email' => $invitation->inviter?->email,
                    ],
                ];
            });
    }

    public function getLinks(User $user): Collection
    {
        return $this->baseLinksForUser($user)
            ->with(['userOne:id,name,email', 'userTwo:id,name,email', 'syncOwner:id,name,email'])
            ->latest('updated_at')
            ->get()
            ->map(function (ListLink $link) use ($user): array {
                $otherUser = $link->user_one_id === $user->id ? $link->userTwo : $link->userOne;

                return [
                    'id' => $link->id,
                    'is_active' => (bool) $link->is_active,
                    'sync_owner_id' => $link->sync_owner_id,
                    'sync_owner_name' => $link->syncOwner?->name,
                    'can_set_default' => $otherUser?->id
                        ? $this->canAccessOwner($user, (int) $otherUser->id)
                        : false,
                    'accepted_at' => optional($link->accepted_at)->toISOString(),
                    'other_user' => [
                        'id' => $otherUser?->id,
                        'name' => $otherUser?->name,
                        'email' => $otherUser?->email,
                    ],
                ];
            });
    }

    public function getListOptions(User $user): Collection
    {
        $options = collect([
            [
                'owner_id' => $user->id,
                'link_id' => null,
                'label' => 'Личный',
                'is_personal' => true,
            ],
        ]);

        $links = $this->baseLinksForUser($user)
            ->with(['userOne:id,name', 'userTwo:id,name'])
            ->get();

        foreach ($links as $link) {
            $otherUser = $link->user_one_id === $user->id ? $link->userTwo : $link->userOne;
            if (! $otherUser) {
                continue;
            }

            $options->push([
                'owner_id' => (int) $otherUser->id,
                'link_id' => (int) $link->id,
                'label' => sprintf('Вы и %s', $otherUser->name),
                'is_personal' => false,
            ]);
        }

        return $options;
    }

    private function baseLinksForUser(User $user): Builder
    {
        return ListLink::query()
            ->where('is_active', true)
            ->where(function (Builder $query) use ($user): void {
                $query->where('user_one_id', $user->id)
                    ->orWhere('user_two_id', $user->id);
            });
    }

    private function resolveDefaultOwnerId(User $user, Collection $listOptions): int
    {
        $availableOwnerIds = $listOptions
            ->pluck('owner_id')
            ->map(fn (mixed $ownerId): int => (int) $ownerId)
            ->values();

        if ($availableOwnerIds->isEmpty()) {
            return $user->id;
        }

        $preferredOwnerId = (int) ($user->preferred_owner_id ?? 0);

        if ($preferredOwnerId > 0 && $availableOwnerIds->contains($preferredOwnerId)) {
            return $preferredOwnerId;
        }

        if ($availableOwnerIds->contains($user->id)) {
            return $user->id;
        }

        return (int) $availableOwnerIds->first();
    }
}

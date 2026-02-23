<?php

namespace App\Services;

use App\Models\ListInvitation;
use App\Models\ListLink;
use App\Models\User;
use App\Services\UserState\UserGamificationStateService;
use App\Services\UserState\UserMoodStateService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListSyncService
{
    public function __construct(
        private readonly UserGamificationStateService $gamificationStateService,
        private readonly UserMoodStateService $moodStateService
    ) {
    }

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
        $outgoingInvitations = $this->getOutgoingPendingInvitations($user);
        $listOptions = $this->getListOptions($user)->values();

        return [
            'pending_invitations_count' => $invitations->count(),
            'invitations' => $invitations->values()->all(),
            'outgoing_pending_invitations' => $outgoingInvitations->values()->all(),
            'links' => $this->getLinks($user)->values()->all(),
            'list_options' => $listOptions->all(),
            'default_owner_id' => $this->resolveDefaultOwnerId($user, $listOptions),
            'gamification' => $this->getGamificationState($user),
            'mood_cards' => $this->getMoodCards($user)->values()->all(),
        ];
    }

    public function getGamificationState(User $user): array
    {
        return $this->gamificationStateService->buildPayload($user);
    }

    public function getMoodState(User $user): array
    {
        return $this->moodStateService->buildPayload($user);
    }

    public function getMoodCards(User $user): Collection
    {
        $cards = collect([
            $this->buildMoodCardPayload($user, true),
        ]);

        $links = $this->baseLinksForUser($user)
            ->with([
                'userOne:id,name,mood_color,mood_fire_level,mood_fire_emoji,mood_battery_level,mood_battery_emoji,mood_updated_at',
                'userTwo:id,name,mood_color,mood_fire_level,mood_fire_emoji,mood_battery_level,mood_battery_emoji,mood_updated_at',
            ])
            ->get();

        foreach ($links as $link) {
            $otherUser = $link->user_one_id === $user->id ? $link->userTwo : $link->userOne;
            if (! $otherUser) {
                continue;
            }

            $cards->push($this->buildMoodCardPayload($otherUser, false));
        }

        $uniqueCards = $cards
            ->unique(fn (array $card): int => (int) ($card['id'] ?? 0))
            ->values();
        $selfCard = $uniqueCards->firstWhere('is_self', true) ?? $this->buildMoodCardPayload($user, true);
        $otherCards = $uniqueCards
            ->filter(fn (array $card): bool => ! ((bool) ($card['is_self'] ?? false)))
            ->sortBy(fn (array $card): string => strtolower((string) ($card['name'] ?? '')))
            ->values();

        return collect([$selfCard])
            ->merge($otherCards)
            ->values();
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

    public function getOutgoingPendingInvitations(User $user): Collection
    {
        return ListInvitation::query()
            ->with('invitee:id,name,email,tag')
            ->where('inviter_id', $user->id)
            ->where('status', ListInvitation::STATUS_PENDING)
            ->latest('id')
            ->get()
            ->map(function (ListInvitation $invitation): array {
                return [
                    'id' => $invitation->id,
                    'status' => $invitation->status,
                    'created_at' => optional($invitation->created_at)->toISOString(),
                    'invitee' => [
                        'id' => $invitation->invitee_id,
                        'name' => $invitation->invitee?->name,
                        'email' => $invitation->invitee?->email,
                        'tag' => $invitation->invitee?->tag,
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

    private function buildMoodCardPayload(User $user, bool $isSelf): array
    {
        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'is_self' => $isSelf,
            'mood' => $this->getMoodState($user),
        ];
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

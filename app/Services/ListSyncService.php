<?php

namespace App\Services;

use App\Models\ListInvitation;
use App\Models\ListMember;
use App\Models\User;
use App\Services\Lists\ListCatalogService;
use App\Services\Lists\ListSummaryService;
use App\Services\UserState\UserGamificationStateService;
use App\Services\UserState\UserMoodStateService;
use Illuminate\Support\Collection;

class ListSyncService
{
    public function __construct(
        private readonly UserGamificationStateService $gamificationStateService,
        private readonly UserMoodStateService $moodStateService,
        private readonly ListCatalogService $listCatalogService,
        private readonly ListSummaryService $listSummaryService,
    ) {
    }

    public function canAccessList(User $user, int $listId): bool
    {
        if ($listId <= 0) {
            return false;
        }

        return ListMember::query()
            ->where('list_id', $listId)
            ->where('user_id', (int) $user->id)
            ->exists();
    }

    public function getState(User $user): array
    {
        $this->listCatalogService->ensurePersonalListExists($user);
        $freshUser = $user->fresh() ?? $user;
        $lists = $this->listSummaryService->summariesForUser($freshUser)->values();

        return [
            'pending_invitations_count' => $this->getPendingInvitations($freshUser)->count(),
            'invitations' => $this->getPendingInvitations($freshUser)->values()->all(),
            'outgoing_pending_invitations' => $this->getOutgoingPendingInvitations($freshUser)->values()->all(),
            'lists' => $lists->all(),
            'templates' => $this->listSummaryService->templatesForUser($freshUser)->all(),
            'default_list_id' => $this->resolveDefaultListId($freshUser, $lists),
            'gamification' => $this->getGamificationState($freshUser),
            'mood_cards' => $this->getMoodCards($freshUser)->values()->all(),
            'self_mood_preferences' => $this->moodStateService->buildPreferencesPayload($freshUser),
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

        $visibleListIds = ListMember::query()
            ->join('lists', 'lists.id', '=', 'list_members.list_id')
            ->where('list_members.user_id', (int) $user->id)
            ->where('lists.is_template', false)
            ->pluck('list_members.list_id')
            ->map(static fn ($value): int => (int) $value)
            ->values()
            ->all();

        if ($visibleListIds === []) {
            return $cards;
        }

        $otherUserIds = ListMember::query()
            ->join('lists', 'lists.id', '=', 'list_members.list_id')
            ->whereIn('list_members.list_id', $visibleListIds)
            ->where('lists.is_template', false)
            ->where('list_members.user_id', '!=', (int) $user->id)
            ->pluck('list_members.user_id')
            ->map(static fn ($value): int => (int) $value)
            ->unique()
            ->values()
            ->all();

        if ($otherUserIds === []) {
            return $cards;
        }

        $otherUsers = User::query()
            ->whereIn('id', $otherUserIds)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'mood_color',
                'mood_fire_level',
                'mood_fire_emoji',
                'mood_fire_recent_emojis',
                'mood_battery_level',
                'mood_battery_emoji',
                'mood_battery_recent_emojis',
                'mood_updated_at',
            ]);

        foreach ($otherUsers as $otherUser) {
            $cards->push($this->buildMoodCardPayload($otherUser, false));
        }

        return $cards->values();
    }

    public function getPendingInvitations(User $user): Collection
    {
        return ListInvitation::query()
            ->with(['inviter:id,name,email', 'list:id,name'])
            ->where('invitee_id', (int) $user->id)
            ->where('status', ListInvitation::STATUS_PENDING)
            ->latest('id')
            ->get()
            ->map(static function (ListInvitation $invitation): array {
                return [
                    'id' => (int) $invitation->id,
                    'list_id' => (int) ($invitation->list_id ?? 0),
                    'list_name' => (string) ($invitation->list?->name ?? ''),
                    'status' => (string) $invitation->status,
                    'created_at' => optional($invitation->created_at)->toISOString(),
                    'inviter' => [
                        'id' => (int) $invitation->inviter_id,
                        'name' => (string) ($invitation->inviter?->name ?? ''),
                        'email' => (string) ($invitation->inviter?->email ?? ''),
                    ],
                ];
            });
    }

    public function getOutgoingPendingInvitations(User $user): Collection
    {
        return ListInvitation::query()
            ->with(['invitee:id,name,email,tag', 'list:id,name'])
            ->where('inviter_id', (int) $user->id)
            ->where('status', ListInvitation::STATUS_PENDING)
            ->latest('id')
            ->get()
            ->map(static function (ListInvitation $invitation): array {
                return [
                    'id' => (int) $invitation->id,
                    'list_id' => (int) ($invitation->list_id ?? 0),
                    'list_name' => (string) ($invitation->list?->name ?? ''),
                    'status' => (string) $invitation->status,
                    'created_at' => optional($invitation->created_at)->toISOString(),
                    'invitee' => [
                        'id' => (int) $invitation->invitee_id,
                        'name' => (string) ($invitation->invitee?->name ?? ''),
                        'email' => (string) ($invitation->invitee?->email ?? ''),
                        'tag' => (string) ($invitation->invitee?->tag ?? ''),
                    ],
                ];
            });
    }

    private function buildMoodCardPayload(User $user, bool $isSelf): array
    {
        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'is_self' => $isSelf,
            'mood' => $this->getMoodState($user),
        ];
    }

    private function resolveDefaultListId(User $user, Collection $lists): int
    {
        $availableListIds = $lists
            ->pluck('id')
            ->map(static fn ($value): int => (int) $value)
            ->values();

        if ($availableListIds->isEmpty()) {
            $fallbackList = $this->listCatalogService->ensurePersonalListExists($user);

            return (int) $fallbackList->id;
        }

        $preferredListId = (int) ($user->preferred_list_id ?? 0);

        if ($preferredListId > 0 && $availableListIds->contains($preferredListId)) {
            return $preferredListId;
        }

        return (int) $availableListIds->first();
    }
}

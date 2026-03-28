<?php

namespace App\Services\Lists;

use App\Models\ListItem;
use App\Models\ListMember;
use App\Models\User;
use App\Models\UserList;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ListSummaryService
{
    public function summariesForUser(User $user): Collection
    {
        $lists = UserList::query()
            ->regular()
            ->visibleTo((int) $user->id)
            ->with(['members.user:id,name,email,tag'])
            ->join('list_members as current_membership', function ($join) use ($user): void {
                $join->on('lists.id', '=', 'current_membership.list_id')
                    ->where('current_membership.user_id', '=', (int) $user->id);
            })
            ->select('lists.*', 'current_membership.role as membership_role')
            ->orderByDesc('last_activity_at')
            ->orderBy('name')
            ->get();

        return $this->mapSummaries($lists);
    }

    public function templatesForUser(User $user): Collection
    {
        $templates = UserList::query()
            ->templates()
            ->where('owner_user_id', (int) $user->id)
            ->withCount([
                'items as product_count' => static fn ($query) => $query->where('type', ListItem::TYPE_PRODUCT),
                'items as todo_count' => static fn ($query) => $query->where('type', ListItem::TYPE_TODO),
            ])
            ->orderBy('name')
            ->get();

        return $templates->map(static fn (UserList $list): array => [
            'id' => (int) $list->id,
            'name' => (string) $list->name,
            'owner_user_id' => (int) $list->owner_user_id,
            'is_template' => true,
            'product_count' => (int) ($list->product_count ?? 0),
            'todo_count' => (int) ($list->todo_count ?? 0),
            'updated_at' => optional($list->updated_at)->toISOString(),
        ])->values();
    }

    public function summaryForUser(User $user, int $listId): ?array
    {
        return $this->summariesForUser($user)
            ->first(static fn (array $summary): bool => (int) ($summary['id'] ?? 0) === $listId);
    }

    public function touchList(int|UserList $list, CarbonInterface|string|null $timestamp = null): void
    {
        $listId = $list instanceof UserList ? (int) $list->id : (int) $list;
        if ($listId <= 0) {
            return;
        }

        $resolvedTimestamp = $timestamp ?: now();

        UserList::query()
            ->whereKey($listId)
            ->update([
                'last_activity_at' => $resolvedTimestamp,
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  Collection<int, UserList>  $lists
     * @return Collection<int, array<string, mixed>>
     */
    private function mapSummaries(Collection $lists): Collection
    {
        $itemCounts = ListItem::query()
            ->selectRaw(
                'list_id,
                SUM(CASE WHEN type = ? AND is_completed = 0 THEN 1 ELSE 0 END) as open_products_count,
                SUM(CASE WHEN type = ? AND is_completed = 0 THEN 1 ELSE 0 END) as open_todos_count',
                [ListItem::TYPE_PRODUCT, ListItem::TYPE_TODO]
            )
            ->whereIn('list_id', $lists->pluck('id')->all())
            ->groupBy('list_id')
            ->get()
            ->keyBy('list_id');

        return $lists->map(static function (UserList $list) use ($itemCounts): array {
            $countRow = $itemCounts->get((int) $list->id);
            $openProductsCount = (int) ($countRow?->open_products_count ?? 0);
            $openTodosCount = (int) ($countRow?->open_todos_count ?? 0);
            $members = $list->members
                ->map(static fn (ListMember $member): array => [
                    'id' => (int) $member->user_id,
                    'name' => (string) ($member->user?->name ?? ''),
                    'email' => (string) ($member->user?->email ?? ''),
                    'tag' => (string) ($member->user?->tag ?? ''),
                    'role' => (string) $member->role,
                ])
                ->sortBy(static fn (array $member): array => [
                    $member['role'] === ListMember::ROLE_OWNER ? 0 : 1,
                    mb_strtolower($member['name'], 'UTF-8'),
                ])
                ->values()
                ->all();

            return [
                'id' => (int) $list->id,
                'name' => (string) $list->name,
                'owner_user_id' => (int) $list->owner_user_id,
                'is_template' => false,
                'role' => (string) ($list->membership_role ?? ListMember::ROLE_EDITOR),
                'member_count' => count($members),
                'members' => $members,
                'open_products_count' => $openProductsCount,
                'open_todos_count' => $openTodosCount,
                'total_pending_count' => $openProductsCount + $openTodosCount,
                'last_activity_at' => optional($list->last_activity_at)->toISOString(),
                'updated_at' => optional($list->updated_at)->toISOString(),
            ];
        })->values();
    }
}

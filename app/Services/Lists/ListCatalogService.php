<?php

namespace App\Services\Lists;

use App\Models\ListItem;
use App\Models\ListMember;
use App\Models\User;
use App\Models\UserList;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ListCatalogService
{
    public function __construct(
        private readonly ListSummaryService $listSummaryService
    ) {
    }

    public function ensurePersonalListExists(User $user): UserList
    {
        $list = UserList::query()
            ->regular()
            ->where('owner_user_id', (int) $user->id)
            ->whereHas('members', static function ($query) use ($user): void {
                $query->where('user_id', (int) $user->id)
                    ->where('role', ListMember::ROLE_OWNER);
            })
            ->whereDoesntHave('members', static function ($query) use ($user): void {
                $query->where('user_id', '!=', (int) $user->id);
            })
            ->orderBy('id')
            ->first();

        if ($list) {
            return $list;
        }

        return DB::transaction(function () use ($user): UserList {
            $list = UserList::query()->create([
                'owner_user_id' => (int) $user->id,
                'name' => 'Личный',
                'is_template' => false,
            ]);

            ListMember::query()->create([
                'list_id' => (int) $list->id,
                'user_id' => (int) $user->id,
                'role' => ListMember::ROLE_OWNER,
            ]);

            $user->preferred_list_id = (int) $list->id;
            $user->save();

            return $list;
        });
    }

    public function createList(User $user, string $name): UserList
    {
        return DB::transaction(function () use ($user, $name): UserList {
            $list = UserList::query()->create([
                'owner_user_id' => (int) $user->id,
                'name' => $this->normalizeListName($name),
                'is_template' => false,
            ]);

            ListMember::query()->create([
                'list_id' => (int) $list->id,
                'user_id' => (int) $user->id,
                'role' => ListMember::ROLE_OWNER,
            ]);

            return $list;
        });
    }

    public function renameList(User $user, UserList $list, string $name): UserList
    {
        $this->ensureOwner($user, $list);
        abort_if($list->is_template, Response::HTTP_UNPROCESSABLE_ENTITY, 'Template rename uses template flow.');

        $list->name = $this->normalizeListName($name, (string) $list->name);
        $list->save();

        return $list->fresh();
    }

    public function deleteList(User $user, UserList $list): void
    {
        $this->ensureOwner($user, $list);

        DB::transaction(function () use ($user, $list): void {
            $ownedRegularListCount = UserList::query()
                ->regular()
                ->where('owner_user_id', (int) $user->id)
                ->count();

            $isOwnedRegularList = ! $list->is_template && (int) $list->owner_user_id === (int) $user->id;
            if ($isOwnedRegularList && $ownedRegularListCount <= 1) {
                $this->createList($user, 'Личный');
            }

            $list->delete();

            if ((int) ($user->preferred_list_id ?? 0) === (int) $list->id) {
                $freshUser = $user->fresh();
                if ($freshUser) {
                    $fallbackList = $this->ensurePersonalListExists($freshUser);
                    $freshUser->preferred_list_id = (int) $fallbackList->id;
                    $freshUser->save();
                }
            }
        });
    }

    public function setDefaultList(User $user, UserList $list): void
    {
        $this->ensureMember($user, $list);
        abort_if($list->is_template, Response::HTTP_UNPROCESSABLE_ENTITY, 'Templates cannot be selected.');

        $user->preferred_list_id = (int) $list->id;
        $user->save();
    }

    public function saveTemplate(User $user, UserList $sourceList, string $name): UserList
    {
        $this->ensureMember($user, $sourceList);
        abort_if($sourceList->is_template, Response::HTTP_UNPROCESSABLE_ENTITY, 'Nested templates are not supported.');

        return DB::transaction(function () use ($user, $sourceList, $name): UserList {
            $template = UserList::query()->create([
                'owner_user_id' => (int) $user->id,
                'name' => $this->normalizeListName($name, (string) $sourceList->name),
                'is_template' => true,
            ]);

            ListMember::query()->create([
                'list_id' => (int) $template->id,
                'user_id' => (int) $user->id,
                'role' => ListMember::ROLE_OWNER,
            ]);

            $sourceItems = ListItem::query()
                ->forList((int) $sourceList->id)
                ->orderBy('type')
                ->orderBy('is_completed')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            foreach ($sourceItems as $item) {
                ListItem::query()->create([
                    'owner_id' => (int) $user->id,
                    'list_id' => (int) $template->id,
                    'list_link_id' => null,
                    'type' => (string) $item->type,
                    'text' => (string) $item->text,
                    'sort_order' => (int) ($item->sort_order ?? 0),
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'due_at' => $item->due_at,
                    'priority' => $item->priority,
                    'is_completed' => (bool) $item->is_completed,
                    'completed_at' => $item->completed_at,
                    'created_by_id' => (int) $user->id,
                    'updated_by_id' => (int) $user->id,
                ]);
            }

            return $template->fresh();
        });
    }

    public function createFromTemplate(User $user, UserList $template, ?string $name = null): UserList
    {
        $this->ensureOwner($user, $template);
        abort_unless($template->is_template, Response::HTTP_UNPROCESSABLE_ENTITY, 'Template is required.');

        return DB::transaction(function () use ($user, $template, $name): UserList {
            $list = $this->createList($user, $name ?: (string) $template->name);

            $templateItems = ListItem::query()
                ->forList((int) $template->id)
                ->orderBy('type')
                ->orderBy('is_completed')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

            foreach ($templateItems as $item) {
                ListItem::query()->create([
                    'owner_id' => (int) $user->id,
                    'list_id' => (int) $list->id,
                    'list_link_id' => null,
                    'type' => (string) $item->type,
                    'text' => (string) $item->text,
                    'sort_order' => (int) ($item->sort_order ?? 0),
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'due_at' => $item->due_at,
                    'priority' => $item->priority,
                    'is_completed' => false,
                    'completed_at' => null,
                    'created_by_id' => (int) $user->id,
                    'updated_by_id' => (int) $user->id,
                ]);
            }

            $this->listSummaryService->touchList((int) $list->id);

            return $list->fresh();
        });
    }

    public function ensureMember(User $user, UserList $list): ListMember
    {
        $member = ListMember::query()
            ->where('list_id', (int) $list->id)
            ->where('user_id', (int) $user->id)
            ->first();

        abort_unless($member, Response::HTTP_FORBIDDEN, 'You do not have access to this list.');

        return $member;
    }

    public function ensureOwner(User $user, UserList $list): ListMember
    {
        $member = $this->ensureMember($user, $list);
        abort_unless($member->isOwner(), Response::HTTP_FORBIDDEN, 'Only the list owner can manage this list.');

        return $member;
    }

    private function normalizeListName(string $value, string $fallback = 'Новый список'): string
    {
        $normalized = trim($value);

        return $normalized !== '' ? mb_substr($normalized, 0, 120, 'UTF-8') : $fallback;
    }
}

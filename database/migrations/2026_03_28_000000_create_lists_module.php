<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('is_template')->default(false)->index();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamps();

            $table->index(['owner_user_id', 'is_template']);
        });

        Schema::create('list_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('list_id')->constrained('lists')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 24);
            $table->timestamps();

            $table->unique(['list_id', 'user_id']);
            $table->index(['user_id', 'role']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('preferred_list_id')
                ->nullable()
                ->after('preferred_owner_id')
                ->constrained('lists')
                ->nullOnDelete();
            $table->json('mood_fire_recent_emojis')
                ->nullable()
                ->after('mood_fire_emoji');
            $table->json('mood_battery_recent_emojis')
                ->nullable()
                ->after('mood_battery_emoji');
            $table->string('mood_fire_emoji', 64)->nullable()->change();
            $table->string('mood_battery_emoji', 64)->nullable()->change();
        });

        Schema::table('list_items', function (Blueprint $table): void {
            $table->foreignId('list_id')
                ->nullable()
                ->after('list_link_id')
                ->constrained('lists')
                ->cascadeOnDelete();
            $table->index(['list_id', 'type', 'is_completed', 'sort_order'], 'list_items_list_type_completed_sort_idx');
        });

        Schema::table('list_item_events', function (Blueprint $table): void {
            $table->foreignId('list_id')
                ->nullable()
                ->after('list_link_id')
                ->constrained('lists')
                ->cascadeOnDelete();
            $table->index(['list_id', 'type', 'event_type'], 'list_item_events_list_type_event_idx');
        });

        Schema::table('list_item_suggestion_states', function (Blueprint $table): void {
            $table->foreignId('list_id')
                ->nullable()
                ->after('owner_id')
                ->constrained('lists')
                ->cascadeOnDelete();
        });

        Schema::table('list_sync_versions', function (Blueprint $table): void {
            $table->foreignId('list_id')
                ->nullable()
                ->after('list_link_id')
                ->constrained('lists')
                ->cascadeOnDelete();
            $table->index(['list_id', 'type'], 'list_sync_versions_list_type_idx');
        });

        Schema::table('list_invitations', function (Blueprint $table): void {
            $table->foreignId('list_id')
                ->nullable()
                ->after('invitee_id')
                ->constrained('lists')
                ->cascadeOnDelete();
            $table->index(['list_id', 'status'], 'list_invitations_list_status_idx');
        });

        $this->backfillLists();

        Schema::table('list_item_suggestion_states', function (Blueprint $table): void {
            $table->unique(['list_id', 'type', 'suggestion_key'], 'list_item_suggestion_states_list_type_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('list_item_suggestion_states', function (Blueprint $table): void {
            $table->dropUnique('list_item_suggestion_states_list_type_key_unique');
        });

        Schema::table('list_invitations', function (Blueprint $table): void {
            $table->dropIndex('list_invitations_list_status_idx');
            $table->dropConstrainedForeignId('list_id');
        });

        Schema::table('list_sync_versions', function (Blueprint $table): void {
            $table->dropIndex('list_sync_versions_list_type_idx');
            $table->dropConstrainedForeignId('list_id');
        });

        Schema::table('list_item_suggestion_states', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('list_id');
        });

        Schema::table('list_item_events', function (Blueprint $table): void {
            $table->dropIndex('list_item_events_list_type_event_idx');
            $table->dropConstrainedForeignId('list_id');
        });

        Schema::table('list_items', function (Blueprint $table): void {
            $table->dropIndex('list_items_list_type_completed_sort_idx');
            $table->dropConstrainedForeignId('list_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('preferred_list_id');
            $table->dropColumn(['mood_fire_recent_emojis', 'mood_battery_recent_emojis']);
            $table->string('mood_fire_emoji', 16)->nullable()->change();
            $table->string('mood_battery_emoji', 16)->nullable()->change();
        });

        Schema::dropIfExists('list_members');
        Schema::dropIfExists('lists');
    }

    private function backfillLists(): void
    {
        $now = now();
        $personalListIds = [];
        $sharedListIds = [];

        $users = DB::table('users')
            ->select(['id', 'created_at', 'updated_at', 'preferred_owner_id', 'mood_fire_emoji', 'mood_battery_emoji'])
            ->orderBy('id')
            ->get();

        foreach ($users as $user) {
            $createdAt = $user->created_at ?? $now;
            $updatedAt = $user->updated_at ?? $now;

            $listId = DB::table('lists')->insertGetId([
                'owner_user_id' => (int) $user->id,
                'name' => 'Личный',
                'is_template' => false,
                'last_activity_at' => null,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ]);

            DB::table('list_members')->insert([
                'list_id' => $listId,
                'user_id' => (int) $user->id,
                'role' => 'owner',
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ]);

            $personalListIds[(int) $user->id] = $listId;
        }

        $links = DB::table('list_links')
            ->select(['id', 'user_one_id', 'user_two_id', 'sync_owner_id', 'is_active', 'accepted_at', 'created_at', 'updated_at'])
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($links as $link) {
            $createdAt = $link->created_at ?? $now;
            $updatedAt = $link->updated_at ?? $now;
            $ownerUserId = (int) ($link->sync_owner_id ?: $link->user_one_id);

            $listId = DB::table('lists')->insertGetId([
                'owner_user_id' => $ownerUserId,
                'name' => 'Общий список',
                'is_template' => false,
                'last_activity_at' => $link->accepted_at ?? null,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ]);

            DB::table('list_members')->insert([
                [
                    'list_id' => $listId,
                    'user_id' => (int) $link->user_one_id,
                    'role' => (int) $link->user_one_id === $ownerUserId ? 'owner' : 'editor',
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ],
                [
                    'list_id' => $listId,
                    'user_id' => (int) $link->user_two_id,
                    'role' => (int) $link->user_two_id === $ownerUserId ? 'owner' : 'editor',
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ],
            ]);

            $sharedListIds[(int) $link->id] = [
                'list_id' => $listId,
                'owner_user_id' => $ownerUserId,
            ];
        }

        $this->backfillListItems($personalListIds, $sharedListIds);
        $this->backfillListItemEvents($personalListIds, $sharedListIds);
        $this->backfillSuggestionStates($personalListIds);
        $this->backfillSyncVersions($personalListIds, $sharedListIds);
        $this->backfillInvitations($personalListIds, $sharedListIds);
        $this->backfillUsers($users, $personalListIds, $sharedListIds);
        $this->refreshListActivity();
    }

    private function backfillListItems(array $personalListIds, array $sharedListIds): void
    {
        DB::table('list_items')
            ->select(['id', 'owner_id', 'list_link_id'])
            ->orderBy('id')
            ->chunkById(500, function ($items) use ($personalListIds, $sharedListIds): void {
                foreach ($items as $item) {
                    $listId = $this->resolveLegacyListId(
                        (int) ($item->owner_id ?? 0),
                        $item->list_link_id !== null ? (int) $item->list_link_id : null,
                        $personalListIds,
                        $sharedListIds,
                    );

                    if (! $listId) {
                        continue;
                    }

                    DB::table('list_items')
                        ->where('id', (int) $item->id)
                        ->update([
                            'list_id' => $listId,
                        ]);
                }
            });
    }

    private function backfillListItemEvents(array $personalListIds, array $sharedListIds): void
    {
        DB::table('list_item_events')
            ->select(['id', 'owner_id', 'list_link_id'])
            ->orderBy('id')
            ->chunkById(500, function ($events) use ($personalListIds, $sharedListIds): void {
                foreach ($events as $event) {
                    $listId = $this->resolveLegacyListId(
                        (int) ($event->owner_id ?? 0),
                        $event->list_link_id !== null ? (int) $event->list_link_id : null,
                        $personalListIds,
                        $sharedListIds,
                    );

                    if (! $listId) {
                        continue;
                    }

                    DB::table('list_item_events')
                        ->where('id', (int) $event->id)
                        ->update([
                            'list_id' => $listId,
                        ]);
                }
            });
    }

    private function backfillSuggestionStates(array $personalListIds): void
    {
        DB::table('list_item_suggestion_states')
            ->select(['id', 'owner_id'])
            ->orderBy('id')
            ->chunkById(500, function ($states) use ($personalListIds): void {
                foreach ($states as $state) {
                    $listId = $personalListIds[(int) ($state->owner_id ?? 0)] ?? null;
                    if (! $listId) {
                        continue;
                    }

                    DB::table('list_item_suggestion_states')
                        ->where('id', (int) $state->id)
                        ->update([
                            'list_id' => $listId,
                        ]);
                }
            });
    }

    private function backfillSyncVersions(array $personalListIds, array $sharedListIds): void
    {
        DB::table('list_sync_versions')
            ->select(['id', 'owner_id', 'list_link_id', 'type'])
            ->orderBy('id')
            ->chunkById(500, function ($versions) use ($personalListIds, $sharedListIds): void {
                foreach ($versions as $version) {
                    $listId = $this->resolveLegacyListId(
                        (int) ($version->owner_id ?? 0),
                        $version->list_link_id !== null ? (int) $version->list_link_id : null,
                        $personalListIds,
                        $sharedListIds,
                    );

                    if (! $listId) {
                        continue;
                    }

                    DB::table('list_sync_versions')
                        ->where('id', (int) $version->id)
                        ->update([
                            'list_id' => $listId,
                            'scope_key' => sprintf('list:%d|type:%s', $listId, (string) ($version->type ?? '')),
                        ]);
                }
            });
    }

    private function backfillInvitations(array $personalListIds, array $sharedListIds): void
    {
        $linkPairs = DB::table('list_links')
            ->select(['id', 'user_one_id', 'user_two_id'])
            ->where('is_active', true)
            ->get()
            ->mapWithKeys(static function ($link): array {
                $left = min((int) $link->user_one_id, (int) $link->user_two_id);
                $right = max((int) $link->user_one_id, (int) $link->user_two_id);

                return [sprintf('%d:%d', $left, $right) => (int) $link->id];
            })
            ->all();

        DB::table('list_invitations')
            ->select(['id', 'inviter_id', 'invitee_id', 'status'])
            ->orderBy('id')
            ->chunkById(500, function ($invitations) use ($personalListIds, $sharedListIds, $linkPairs): void {
                foreach ($invitations as $invitation) {
                    $pairKey = sprintf(
                        '%d:%d',
                        min((int) $invitation->inviter_id, (int) $invitation->invitee_id),
                        max((int) $invitation->inviter_id, (int) $invitation->invitee_id),
                    );

                    $linkId = $linkPairs[$pairKey] ?? null;
                    $listId = $linkId && isset($sharedListIds[$linkId])
                        ? (int) $sharedListIds[$linkId]['list_id']
                        : ($personalListIds[(int) $invitation->inviter_id] ?? null);

                    if (! $listId) {
                        continue;
                    }

                    DB::table('list_invitations')
                        ->where('id', (int) $invitation->id)
                        ->update([
                            'list_id' => $listId,
                        ]);
                }
            });
    }

    private function backfillUsers($users, array $personalListIds, array $sharedListIds): void
    {
        $activeLinkPairs = DB::table('list_links')
            ->select(['id', 'user_one_id', 'user_two_id'])
            ->where('is_active', true)
            ->get()
            ->mapWithKeys(static function ($link): array {
                $left = min((int) $link->user_one_id, (int) $link->user_two_id);
                $right = max((int) $link->user_one_id, (int) $link->user_two_id);

                return [sprintf('%d:%d', $left, $right) => (int) $link->id];
            })
            ->all();

        foreach ($users as $user) {
            $preferredOwnerId = (int) ($user->preferred_owner_id ?? 0);
            $preferredListId = $personalListIds[(int) $user->id] ?? null;

            if ($preferredOwnerId > 0 && $preferredOwnerId !== (int) $user->id) {
                $pairKey = sprintf(
                    '%d:%d',
                    min((int) $user->id, $preferredOwnerId),
                    max((int) $user->id, $preferredOwnerId),
                );
                $linkId = $activeLinkPairs[$pairKey] ?? null;
                $preferredListId = $linkId && isset($sharedListIds[$linkId])
                    ? (int) $sharedListIds[$linkId]['list_id']
                    : $preferredListId;
            }

            DB::table('users')
                ->where('id', (int) $user->id)
                ->update([
                    'preferred_list_id' => $preferredListId,
                    'mood_fire_recent_emojis' => $this->buildRecentEmojiPayload($user->mood_fire_emoji ?? null),
                    'mood_battery_recent_emojis' => $this->buildRecentEmojiPayload($user->mood_battery_emoji ?? null),
                ]);
        }
    }

    private function refreshListActivity(): void
    {
        $activityByList = DB::table('list_items')
            ->selectRaw('list_id, MAX(COALESCE(updated_at, created_at)) as last_activity_at')
            ->whereNotNull('list_id')
            ->groupBy('list_id')
            ->pluck('last_activity_at', 'list_id')
            ->all();

        foreach ($activityByList as $listId => $lastActivityAt) {
            DB::table('lists')
                ->where('id', (int) $listId)
                ->update([
                    'last_activity_at' => $lastActivityAt,
                ]);
        }
    }

    private function resolveLegacyListId(
        int $ownerId,
        ?int $listLinkId,
        array $personalListIds,
        array $sharedListIds,
    ): ?int {
        if ($listLinkId !== null && isset($sharedListIds[$listLinkId])) {
            return (int) $sharedListIds[$listLinkId]['list_id'];
        }

        return $personalListIds[$ownerId] ?? null;
    }

    private function buildRecentEmojiPayload(?string $emoji): ?string
    {
        $normalized = trim((string) ($emoji ?? ''));
        if ($normalized === '' || $normalized === '❔') {
            return null;
        }

        return json_encode([$normalized], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
};

<?php

namespace App\Services\ListItems;

use App\Models\ListSyncVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ListSyncVersionService
{
    public function getVersion(int $ownerId, string $type, ?int $listLinkId = null): int
    {
        $scopeKey = $this->scopeKey($ownerId, $type, $listLinkId);

        return (int) (ListSyncVersion::query()
            ->where('scope_key', $scopeKey)
            ->value('version') ?? 0);
    }

    public function bumpVersion(int $ownerId, string $type, ?int $listLinkId = null): int
    {
        $scopeKey = $this->scopeKey($ownerId, $type, $listLinkId);

        return DB::transaction(function () use ($scopeKey, $ownerId, $type, $listLinkId): int {
            $record = ListSyncVersion::query()
                ->where('scope_key', $scopeKey)
                ->lockForUpdate()
                ->first();

            if (! $record) {
                try {
                    $created = ListSyncVersion::query()->create([
                        'scope_key' => $scopeKey,
                        'owner_id' => $ownerId,
                        'list_link_id' => $listLinkId,
                        'type' => $type,
                        'version' => 1,
                    ]);

                    return (int) $created->version;
                } catch (QueryException $exception) {
                    if (! $this->isDuplicateKeyException($exception)) {
                        throw $exception;
                    }

                    $record = ListSyncVersion::query()
                        ->where('scope_key', $scopeKey)
                        ->lockForUpdate()
                        ->firstOrFail();
                }
            }

            $record->version = (int) $record->version + 1;
            $record->save();

            return (int) $record->version;
        }, 3);
    }

    private function scopeKey(int $ownerId, string $type, ?int $listLinkId): string
    {
        return sprintf(
            'owner:%d|type:%s|link:%d',
            $ownerId,
            $type,
            $listLinkId ? (int) $listLinkId : 0
        );
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
}


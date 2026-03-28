<?php

namespace App\Services\ListItems;

use App\Models\ListSyncVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ListSyncVersionService
{
    public function getVersion(int $listId, string $type): int
    {
        $scopeKey = $this->scopeKey($listId, $type);

        try {
            return (int) (ListSyncVersion::query()
                ->where('scope_key', $scopeKey)
                ->value('version') ?? 0);
        } catch (QueryException $exception) {
            if ($this->isMissingTableException($exception)) {
                return 0;
            }

            throw $exception;
        }
    }

    public function bumpVersion(int $listId, string $type, ?int $ownerId = null): int
    {
        $scopeKey = $this->scopeKey($listId, $type);

        try {
            return DB::transaction(function () use ($scopeKey, $listId, $ownerId, $type): int {
                $record = ListSyncVersion::query()
                    ->where('scope_key', $scopeKey)
                    ->lockForUpdate()
                    ->first();

                if (! $record) {
                    try {
                        $created = ListSyncVersion::query()->create([
                            'scope_key' => $scopeKey,
                            'owner_id' => (int) ($ownerId ?? 0),
                            'list_link_id' => null,
                            'list_id' => $listId,
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
        } catch (QueryException $exception) {
            if ($this->isMissingTableException($exception)) {
                return 0;
            }

            throw $exception;
        }
    }

    private function scopeKey(int $listId, string $type): string
    {
        return sprintf('list:%d|type:%s', $listId, $type);
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

    private function isMissingTableException(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        if ($sqlState === '42S02') {
            return true;
        }

        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        if ($driverCode === 1146) {
            return true;
        }

        return str_contains(strtolower($exception->getMessage()), 'doesn\'t exist');
    }
}

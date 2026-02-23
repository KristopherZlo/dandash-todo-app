<?php

namespace App\Services\SyncChunk;

use App\Models\SyncOperation;
use Illuminate\Database\QueryException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SyncChunkOperationStore
{
    private const OPERATION_RESERVATION_TTL_SECONDS = 60;

    public function resolveStoredResult(int $userId, string $opId, string $fallbackAction): ?array
    {
        $normalizedOpId = trim($opId);
        if ($normalizedOpId === '') {
            return null;
        }

        $storedOperation = SyncOperation::query()
            ->where('user_id', $userId)
            ->where('op_id', $normalizedOpId)
            ->first();

        if (! $storedOperation) {
            return null;
        }

        if ((string) $storedOperation->status !== SyncOperation::STATUS_COMPLETED) {
            return null;
        }

        $storedResult = is_array($storedOperation->result) ? $storedOperation->result : [];
        if ($storedResult === []) {
            return null;
        }

        return $this->normalizeStoredOperationResult($storedResult, $normalizedOpId, $fallbackAction);
    }

    public function reserve(int $userId, string $opId, string $action): ?SyncOperation
    {
        $normalizedOpId = trim($opId);
        if ($normalizedOpId === '') {
            return null;
        }

        try {
            return SyncOperation::query()->create([
                'user_id' => $userId,
                'op_id' => $normalizedOpId,
                'action' => $action,
                'status' => SyncOperation::STATUS_PROCESSING,
                'result' => null,
            ]);
        } catch (QueryException $exception) {
            if (! $this->isDuplicateKeyException($exception)) {
                throw $exception;
            }
        }

        $existingOperation = SyncOperation::query()
            ->where('user_id', $userId)
            ->where('op_id', $normalizedOpId)
            ->first();

        if (! $existingOperation) {
            throw new HttpException(409, 'Operation is already being processed.');
        }

        if ((string) $existingOperation->status === SyncOperation::STATUS_COMPLETED) {
            throw new HttpException(409, 'Operation has already been processed.');
        }

        $updatedAt = $existingOperation->updated_at ?? $existingOperation->created_at;
        if ($updatedAt && $updatedAt->lt(now()->subSeconds(self::OPERATION_RESERVATION_TTL_SECONDS))) {
            $existingOperation->delete();

            return $this->reserve($userId, $normalizedOpId, $action);
        }

        throw new HttpException(409, 'Operation is already being processed.');
    }

    public function complete(?SyncOperation $reservedOperation, array $result): void
    {
        if (! $reservedOperation) {
            return;
        }

        $reservedOperation->status = SyncOperation::STATUS_COMPLETED;
        $reservedOperation->result = $result;
        $reservedOperation->save();
    }

    public function release(?SyncOperation $reservedOperation): void
    {
        if (! $reservedOperation) {
            return;
        }

        $reservedOperation->delete();
    }

    private function normalizeStoredOperationResult(array $storedResult, string $opId, string $fallbackAction): array
    {
        return [
            'op_id' => (string) ($storedResult['op_id'] ?? $opId),
            'action' => (string) ($storedResult['action'] ?? $fallbackAction),
            'status' => (string) ($storedResult['status'] ?? 'ok'),
            'data' => is_array($storedResult['data'] ?? null) ? $storedResult['data'] : [],
        ];
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

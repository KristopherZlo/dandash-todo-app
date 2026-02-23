<?php

namespace App\Services\SyncChunk;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SyncChunkProcessor
{
    public function __construct(
        private readonly SyncChunkOperationDispatcher $dispatcher,
        private readonly SyncChunkOperationStore $operationStore
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $validatedOperations
     * @return array<int, array<string, mixed>>
     */
    public function process(Request $request, array $validatedOperations): array
    {
        $results = [];
        $userId = (int) $request->user()->id;

        foreach ($validatedOperations as $index => $summaryOperation) {
            $operation = $request->input("operations.{$index}", []);
            if (! is_array($operation)) {
                $operation = [];
            }

            $opId = (string) ($summaryOperation['op_id'] ?? '');
            $action = (string) ($summaryOperation['action'] ?? '');
            $reservedOperation = null;

            try {
                $storedResult = $this->operationStore->resolveStoredResult($userId, $opId, $action);
                if ($storedResult !== null) {
                    $results[] = $storedResult;

                    continue;
                }

                $reservedOperation = $this->operationStore->reserve($userId, $opId, $action);
                $operationResult = [
                    'op_id' => $opId,
                    'action' => $action,
                    'status' => 'ok',
                    'data' => $this->dispatcher->dispatch($request, $operation),
                ];
                $this->operationStore->complete($reservedOperation, $operationResult);
                $results[] = $operationResult;
            } catch (ValidationException $exception) {
                $this->operationStore->release($reservedOperation);
                $results[] = [
                    'op_id' => $opId,
                    'action' => $action,
                    'status' => 'error',
                    'http_status' => 422,
                    'message' => $exception->getMessage(),
                    'errors' => $exception->errors(),
                ];

                break;
            } catch (ModelNotFoundException) {
                $this->operationStore->release($reservedOperation);
                $results[] = [
                    'op_id' => $opId,
                    'action' => $action,
                    'status' => 'error',
                    'http_status' => 404,
                    'message' => 'Not found.',
                    'errors' => [],
                ];

                break;
            } catch (HttpExceptionInterface $exception) {
                $this->operationStore->release($reservedOperation);
                $results[] = [
                    'op_id' => $opId,
                    'action' => $action,
                    'status' => 'error',
                    'http_status' => $exception->getStatusCode(),
                    'message' => $exception->getMessage(),
                    'errors' => [],
                ];

                break;
            } catch (\Throwable $exception) {
                $this->operationStore->release($reservedOperation);
                Log::error('Chunk sync operation failed.', [
                    'op_id' => $opId,
                    'action' => $action,
                    'error' => $exception->getMessage(),
                ]);

                $results[] = [
                    'op_id' => $opId,
                    'action' => $action,
                    'status' => 'error',
                    'http_status' => 500,
                    'message' => 'Chunk operation failed.',
                    'errors' => [],
                ];

                break;
            }
        }

        return $results;
    }
}

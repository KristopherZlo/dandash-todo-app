<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SyncChunk\SyncChunkProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncChunkController extends Controller
{
    public function __construct(
        private readonly SyncChunkProcessor $syncChunkProcessor
    ) {
    }

    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operations' => ['required', 'array', 'min:1', 'max:80'],
            'operations.*.op_id' => ['required', 'string', 'max:120'],
            'operations.*.action' => ['required', 'string', 'max:64'],
        ]);

        return response()->json([
            'results' => $this->syncChunkProcessor->process($request, (array) $validated['operations']),
        ]);
    }
}

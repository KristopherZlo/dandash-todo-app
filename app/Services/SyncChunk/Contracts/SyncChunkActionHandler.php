<?php

namespace App\Services\SyncChunk\Contracts;

use Illuminate\Http\Request;

interface SyncChunkActionHandler
{
    public function supports(string $action): bool;

    public function handle(Request $request, array $operation): array;
}

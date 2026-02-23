<?php

namespace App\Services\SyncChunk;

use Illuminate\Http\Request;

class SyncChunkActionRequestFactory
{
    public function make(Request $baseRequest, array $payload): Request
    {
        $actionRequest = Request::create('/', 'POST', $payload);
        $actionRequest->setUserResolver(static fn () => $baseRequest->user());

        return $actionRequest;
    }
}

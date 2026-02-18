<?php

namespace App\Http\Controllers;

use App\Services\ListSyncService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AppController extends Controller
{
    public function __construct(
        private readonly ListSyncService $listSyncService
    ) {
    }

    public function __invoke(Request $request): Response
    {
        return Inertia::render('Dashboard', [
            'initialState' => $this->listSyncService->getState($request->user()),
        ]);
    }
}

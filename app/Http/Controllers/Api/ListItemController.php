<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ListItem;
use App\Services\ListItems\ListItemApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListItemController extends Controller
{
    public function __construct(
        private readonly ListItemApiService $listItemApiService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->listItemApiService->index($request));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->listItemApiService->store($request), 201);
    }

    public function suggestions(Request $request): JsonResponse
    {
        return response()->json($this->listItemApiService->suggestions($request));
    }

    public function productStats(Request $request): JsonResponse
    {
        return response()->json($this->listItemApiService->productStats($request));
    }

    public function resetSuggestionData(Request $request): JsonResponse
    {
        return response()->json($this->listItemApiService->resetSuggestionData($request));
    }

    public function dismissSuggestion(Request $request): JsonResponse
    {
        return response()->json($this->listItemApiService->dismissSuggestion($request));
    }

    public function update(Request $request, ListItem $item): JsonResponse
    {
        return response()->json($this->listItemApiService->update($request, $item));
    }

    public function reorder(Request $request): JsonResponse
    {
        return response()->json($this->listItemApiService->reorder($request));
    }

    public function destroy(Request $request, ListItem $item): JsonResponse
    {
        return response()->json($this->listItemApiService->destroy($request, $item));
    }
}

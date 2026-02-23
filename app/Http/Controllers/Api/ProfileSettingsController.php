<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Profile\ProfileSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileSettingsController extends Controller
{
    public function __construct(
        private readonly ProfileSettingsService $profileSettingsService
    ) {
    }

    public function update(Request $request): JsonResponse
    {
        return response()->json(
            $this->profileSettingsService->updateProfile($request->user(), (array) $request->all())
        );
    }

    public function updatePassword(Request $request): JsonResponse
    {
        return response()->json(
            $this->profileSettingsService->updatePassword($request->user(), (array) $request->all())
        );
    }
}

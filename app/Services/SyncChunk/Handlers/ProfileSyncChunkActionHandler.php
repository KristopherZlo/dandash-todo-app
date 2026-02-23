<?php

namespace App\Services\SyncChunk\Handlers;

use App\Services\Profile\ProfileSettingsService;
use App\Services\SyncChunk\Contracts\SyncChunkActionHandler;
use Illuminate\Http\Request;

class ProfileSyncChunkActionHandler implements SyncChunkActionHandler
{
    public function __construct(
        private readonly ProfileSettingsService $profileSettingsService
    ) {
    }

    public function supports(string $action): bool
    {
        return in_array($action, ['update_profile', 'update_password'], true);
    }

    public function handle(Request $request, array $operation): array
    {
        $action = (string) ($operation['action'] ?? '');

        return match ($action) {
            'update_profile' => $this->handleUpdateProfileOperation($request, $operation),
            'update_password' => $this->handleUpdatePasswordOperation($request, $operation),
            default => [],
        };
    }

    private function handleUpdateProfileOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];

        return $this->profileSettingsService->updateProfile($request->user(), [
            'name' => (string) ($payload['name'] ?? ''),
            'tag' => (string) ($payload['tag'] ?? ''),
            'email' => (string) ($payload['email'] ?? ''),
        ]);
    }

    private function handleUpdatePasswordOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];

        return $this->profileSettingsService->updatePassword($request->user(), [
            'current_password' => (string) ($payload['current_password'] ?? ''),
            'password' => (string) ($payload['password'] ?? ''),
            'password_confirmation' => (string) ($payload['password_confirmation'] ?? ''),
        ]);
    }
}

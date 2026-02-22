<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'meta' => [
                'app_version' => (string) config('app.version', 'dev'),
                'build_version' => $this->resolveBuildVersion(),
            ],
        ];
    }

    private function resolveBuildVersion(): string
    {
        $manifestPath = public_path('build/manifest.json');

        if (! is_file($manifestPath)) {
            return 'dev';
        }

        $modifiedAt = @filemtime($manifestPath);
        if ($modifiedAt === false || $modifiedAt <= 0) {
            return 'unknown';
        }

        return gmdate('Ymd-His', (int) $modifiedAt);
    }
}

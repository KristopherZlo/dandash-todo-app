<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no">
        <meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
        <meta name="googlebot" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
        <meta name="bingbot" content="noindex, nofollow, noarchive, nosnippet, noimageindex">
        <meta name="app-base-url" content="{{ rtrim(request()->getSchemeAndHttpHost() . request()->getBaseUrl(), '/') }}">
        <script>
            (function () {
                var storageKey = 'dandash:theme-mode:v1';
                var mode = 'system';
                var allowed = { system: true, light: true, dark: true };

                try {
                    var saved = window.localStorage.getItem(storageKey);
                    if (saved && allowed[saved]) {
                        mode = saved;
                    }
                } catch (error) {
                    // Ignore storage read errors.
                }

                var resolved = mode;
                if (mode === 'system') {
                    resolved = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
                        ? 'dark'
                        : 'light';
                }

                document.documentElement.dataset.theme = resolved;
                document.documentElement.style.colorScheme = resolved;
            })();
        </script>

        <title inertia>{{ config('app.name', 'Laravel') }}</title>
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
        <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=manrope:400,500,600,700,800|space+grotesk:500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>

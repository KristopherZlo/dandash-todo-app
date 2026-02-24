<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="app-page auth-flat-page">
        <div class="app-shell flex min-h-screen flex-col justify-center py-8">
            <Link
                href="/"
                class="app-card auth-flat-card mb-4 inline-flex items-center justify-between px-5 py-3"
            >
                <span class="app-brand text-sm">Dandash</span>
                <span class="auth-flat-card-meta text-xs">Shared Lists</span>
            </Link>

            <div class="app-card auth-flat-card p-5 sm:p-6">
                <slot />
            </div>
        </div>
    </div>
</template>

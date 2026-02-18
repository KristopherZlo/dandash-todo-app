<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="app-page">
        <div
            class="pointer-events-none fixed left-[-120px] top-[8%] h-72 w-72 rounded-full bg-[#403e41]/45 blur-3xl"
        />
        <div
            class="pointer-events-none fixed bottom-[4%] right-[-100px] h-72 w-72 rounded-full bg-[#2d2a2c]/70 blur-3xl"
        />

        <div class="app-shell flex min-h-screen flex-col justify-center py-8">
            <Link
                href="/"
                class="app-card mb-4 inline-flex items-center justify-between px-5 py-3 text-[#fcfcfa]"
            >
                <span class="app-brand text-sm text-[#fcfcfa]">Dandash</span>
                <span class="text-xs text-[#9f9a9d]">Shared Lists</span>
            </Link>

            <div class="app-card p-5 sm:p-6">
                <slot />
            </div>
        </div>
    </div>
</template>

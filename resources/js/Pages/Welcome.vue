<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
        type: Boolean,
    },
    laravelVersion: {
        type: String,
        required: true,
    },
    phpVersion: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <Head title="Dandash" />

    <div class="app-page">
        <div class="mx-auto flex min-h-screen w-full max-w-5xl flex-col justify-center px-5 py-10">
            <div class="grid gap-6 lg:grid-cols-[1.1fr,0.9fr] lg:items-center">
                <section class="app-card animate-rise p-7 sm:p-8">
                    <div class="app-kicker mb-3">Shared Productivity</div>
                    <h1 class="app-title text-balance text-4xl">
                        Dandash для совместных списков и дел
                    </h1>
                    <p class="app-subtitle mt-4 text-base">
                        Приложение для покупок, задач и семейных списков в реальном времени.
                    </p>

                    <div v-if="canLogin" class="mt-6 flex flex-wrap gap-3">
                        <Link
                            v-if="$page.props.auth.user"
                            :href="route('dashboard')"
                            class="app-button-primary"
                        >
                            Открыть дашборд
                        </Link>

                        <template v-else>
                            <Link :href="route('login')" class="app-button-primary">
                                Войти
                            </Link>
                            <Link
                                v-if="canRegister"
                                :href="route('register')"
                                class="app-button-secondary"
                            >
                                Регистрация
                            </Link>
                        </template>
                    </div>
                </section>

                <section class="app-card animate-pop stagger-1 p-7 sm:p-8">
                    <h2 class="text-2xl font-semibold text-slate-800">
                        Техническая информация
                    </h2>
                    <ul class="mt-4 space-y-3 text-sm text-slate-600">
                        <li class="app-panel px-4 py-3">
                            Laravel v{{ laravelVersion }}
                        </li>
                        <li class="app-panel px-4 py-3">
                            PHP v{{ phpVersion }}
                        </li>
                        <li class="app-panel px-4 py-3">
                            Vue + Inertia + Tailwind
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </div>
</template>

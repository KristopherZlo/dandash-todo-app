<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    status: {
        type: String,
        default: '',
    },
});

const form = useForm({
    email: '',
    password: '',
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Вход" />

        <div class="auth-quiet">
            <h1 class="app-title">
                Вход в Dandash
            </h1>
            <p class="app-subtitle mb-5">
                Введите e-mail и пароль, чтобы открыть списки.
            </p>

            <div
                v-if="status"
                class="app-status mb-4"
            >
                {{ status }}
            </div>

            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <label for="email" class="app-label">E-mail</label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        required
                        autofocus
                        autocomplete="username"
                        placeholder="name@example.com"
                        class="app-input"
                    >
                    <p v-if="form.errors.email" class="app-error">
                        {{ form.errors.email }}
                    </p>
                </div>

                <div>
                    <label for="password" class="app-label">Пароль</label>
                    <input
                        id="password"
                        v-model="form.password"
                        type="password"
                        required
                        autocomplete="current-password"
                        placeholder="Введите пароль"
                        class="app-input"
                    >
                    <p v-if="form.errors.password" class="app-error">
                        {{ form.errors.password }}
                    </p>
                </div>

                <button
                    type="submit"
                    class="app-button-primary w-full"
                    :disabled="form.processing"
                >
                    Войти
                </button>

                <div class="space-y-2 text-center text-sm">
                    <Link :href="route('password.request')" class="app-link">
                        Забыли пароль?
                    </Link>
                    <div>
                        <Link :href="route('register')" class="app-link">
                            Создать новый аккаунт
                        </Link>
                    </div>
                </div>
            </form>
        </div>
    </GuestLayout>
</template>
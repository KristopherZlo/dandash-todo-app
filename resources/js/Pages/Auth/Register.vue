<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    registration_code: '',
    name: '',
    tag: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Регистрация" />

        <div class="auth-quiet">
            <h1 class="app-title">
                Создание аккаунта
            </h1>
            <p class="app-subtitle mb-5">
                Укажите одноразовый код регистрации и заполните профиль.
            </p>

            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <label for="registration_code" class="app-label">Код приглашения</label>
                    <input
                        id="registration_code"
                        v-model="form.registration_code"
                        type="text"
                        required
                        autofocus
                        placeholder="Введите код"
                        class="app-input"
                    >
                    <p v-if="form.errors.registration_code" class="app-error">
                        {{ form.errors.registration_code }}
                    </p>
                </div>

                <div>
                    <label for="name" class="app-label">Ник</label>
                    <input
                        id="name"
                        v-model="form.name"
                        type="text"
                        required
                        autocomplete="nickname"
                        placeholder="Как вас подписать"
                        class="app-input"
                    >
                    <p v-if="form.errors.name" class="app-error">
                        {{ form.errors.name }}
                    </p>
                </div>

                <div>
                    <label for="tag" class="app-label">Тег</label>
                    <input
                        id="tag"
                        v-model="form.tag"
                        type="text"
                        required
                        autocomplete="off"
                        placeholder="my_tag"
                        class="app-input"
                    >
                    <p v-if="form.errors.tag" class="app-error">
                        {{ form.errors.tag }}
                    </p>
                </div>

                <div>
                    <label for="email" class="app-label">E-mail</label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        required
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
                        autocomplete="new-password"
                        placeholder="Минимум 8 символов"
                        class="app-input"
                    >
                    <p v-if="form.errors.password" class="app-error">
                        {{ form.errors.password }}
                    </p>
                </div>

                <div>
                    <label for="password_confirmation" class="app-label">Подтверждение пароля</label>
                    <input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        required
                        autocomplete="new-password"
                        placeholder="Повторите пароль"
                        class="app-input"
                    >
                </div>

                <button
                    type="submit"
                    class="app-button-primary w-full"
                    :disabled="form.processing"
                >
                    Создать аккаунт
                </button>

                <div class="text-center text-sm">
                    <Link :href="route('login')" class="app-link">
                        Уже есть аккаунт
                    </Link>
                </div>
            </form>
        </div>
    </GuestLayout>
</template>
<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
});

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Подтверждение пароля" />

        <h1 class="app-title">
            Подтвердите пароль
        </h1>
        <p class="app-subtitle mb-5">
            Это защищённая зона приложения. Подтвердите пароль для продолжения.
        </p>

        <form class="space-y-4" @submit.prevent="submit">
            <div>
                <InputLabel for="password" value="Пароль" />
                <TextInput
                    id="password"
                    type="password"
                    class="w-full"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                    autofocus
                    placeholder="Введите текущий пароль"
                />
                <InputError :message="form.errors.password" />
            </div>

            <PrimaryButton
                class="w-full"
                :class="{ 'opacity-80': form.processing }"
                :disabled="form.processing"
            >
                Подтвердить
            </PrimaryButton>
        </form>
    </GuestLayout>
</template>

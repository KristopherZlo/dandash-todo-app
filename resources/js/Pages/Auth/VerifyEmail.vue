<script setup>
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    status: {
        type: String,
        default: '',
    },
});

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(
    () => props.status === 'verification-link-sent',
);
</script>

<template>
    <GuestLayout>
        <Head title="Подтверждение e-mail" />

        <h1 class="app-title">
            Подтверждение e-mail
        </h1>
        <p class="app-subtitle mb-5">
            Откройте письмо и перейдите по ссылке подтверждения. Если письма нет, отправьте его повторно.
        </p>

        <div
            v-if="verificationLinkSent"
            class="app-status mb-4"
        >
            Новая ссылка подтверждения отправлена на ваш e-mail.
        </div>

        <form class="space-y-4" @submit.prevent="submit">
            <PrimaryButton
                class="w-full"
                :class="{ 'opacity-80': form.processing }"
                :disabled="form.processing"
            >
                Отправить письмо снова
            </PrimaryButton>

            <Link
                :href="route('logout')"
                method="post"
                as="button"
                class="app-button-secondary w-full"
            >
                Выйти
            </Link>
        </form>
    </GuestLayout>
</template>

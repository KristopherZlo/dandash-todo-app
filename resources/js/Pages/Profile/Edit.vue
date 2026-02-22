<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ToastStack from '@/Components/ToastStack.vue';
import { useToasts } from '@/composables/useToasts';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';
import { onBeforeUnmount, watch } from 'vue';

const props = defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const {
    toasts,
    showStatus,
    showToast,
    onToastPointerDown,
    onToastPointerMove,
    onToastPointerUp,
    onToastPointerCancel,
    disposeToasts,
} = useToasts();

const handleProfileNotification = (payload = {}) => {
    const message = String(payload.message ?? '').trim();
    if (!message) {
        return;
    }

    const type = String(payload.type ?? 'success').trim().toLowerCase();
    if (type === 'success') {
        showStatus(message);
        return;
    }

    showToast(message, type);
};

watch(
    () => props.status,
    (nextStatus) => {
        if (nextStatus === 'verification-link-sent') {
            showStatus('Новая ссылка подтверждения отправлена.');
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    disposeToasts();
});
</script>

<template>
    <Head title="Профиль" />

    <AuthenticatedLayout>
        <ToastStack
            :toasts="toasts"
            :handle-pointer-down="onToastPointerDown"
            :handle-pointer-move="onToastPointerMove"
            :handle-pointer-up="onToastPointerUp"
            :handle-pointer-cancel="onToastPointerCancel"
        />

        <template #header>
            <div>
                <h2 class="text-2xl font-semibold text-slate-800">
                    Профиль
                </h2>
                <p class="mt-1 text-sm text-slate-500">
                    Управление данными аккаунта и безопасностью.
                </p>
            </div>
        </template>

        <div class="mx-auto max-w-4xl space-y-5">
            <div class="app-card p-5 sm:p-6">
                <UpdateProfileInformationForm
                    :must-verify-email="mustVerifyEmail"
                    :status="status"
                    @notify="handleProfileNotification"
                />
            </div>

            <div class="app-card p-5 sm:p-6">
                <UpdatePasswordForm @notify="handleProfileNotification" />
            </div>

            <div class="app-card p-5 sm:p-6">
                <DeleteUserForm @notify="handleProfileNotification" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});
</script>

<template>
    <Head title="Профиль" />

    <AuthenticatedLayout>
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
                />
            </div>

            <div class="app-card p-5 sm:p-6">
                <UpdatePasswordForm />
            </div>

            <div class="app-card p-5 sm:p-6">
                <DeleteUserForm />
            </div>
        </div>
    </AuthenticatedLayout>
</template>

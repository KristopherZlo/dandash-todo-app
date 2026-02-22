<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';

const emit = defineEmits(['notify']);

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const user = usePage().props.auth.user;

const form = useForm({
    name: user.name,
    email: user.email,
});

const submitProfileUpdate = () => {
    form.patch(route('profile.update'), {
        onSuccess: () => {
            emit('notify', {
                type: 'success',
                message: 'Изменения профиля сохранены.',
            });
        },
    });
};
</script>

<template>
    <section>
        <header>
            <h3 class="text-xl font-semibold text-slate-800">
                Данные профиля
            </h3>
            <p class="mt-1 text-sm text-slate-500">
                Обновите имя и e-mail, чтобы аккаунт оставался актуальным.
            </p>
        </header>

        <form
            @submit.prevent="submitProfileUpdate"
            class="mt-5 space-y-4"
        >
            <div>
                <InputLabel for="name" value="Имя" />
                <TextInput
                    id="name"
                    type="text"
                    class="w-full"
                    v-model="form.name"
                    required
                    autofocus
                    autocomplete="name"
                    placeholder="Ваше имя"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div>
                <InputLabel for="email" value="E-mail" />
                <TextInput
                    id="email"
                    type="email"
                    class="w-full"
                    v-model="form.email"
                    required
                    autocomplete="username"
                    placeholder="name@example.com"
                />
                <InputError :message="form.errors.email" />
            </div>

            <div v-if="mustVerifyEmail && user.email_verified_at === null" class="rounded-2xl border border-amber-200 bg-amber-50/80 px-4 py-3">
                <p class="text-sm text-amber-800">
                    E-mail не подтверждён.
                    <Link
                        :href="route('verification.send')"
                        method="post"
                        as="button"
                        class="app-link"
                    >
                        Отправить письмо повторно
                    </Link>
                </p>

                <div
                    v-show="false && status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-[#a5d774]"
                >
                    Новая ссылка подтверждения отправлена.
                </div>
            </div>

            <div class="flex items-center gap-3">
                <PrimaryButton :disabled="form.processing">Сохранить</PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out duration-200"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out duration-200"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-if="false && form.recentlySuccessful"
                        class="text-sm font-medium text-[#a5d774]"
                    >
                        Изменения сохранены.
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>


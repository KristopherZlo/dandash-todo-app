<script setup>
import DangerButton from '@/Components/DangerButton.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';

const emit = defineEmits(['notify']);
const confirmingUserDeletion = ref(false);
const passwordInput = ref(null);

const form = useForm({
    password: '',
});

const confirmUserDeletion = () => {
    confirmingUserDeletion.value = true;
    emit('notify', {
        type: 'info',
        message: 'Подтвердите удаление аккаунта в открывшемся окне.',
    });

    nextTick(() => passwordInput.value.focus());
};

const deleteUser = () => {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => passwordInput.value.focus(),
        onFinish: () => form.reset(),
    });
};

const closeModal = () => {
    confirmingUserDeletion.value = false;

    form.clearErrors();
    form.reset();
};
</script>

<template>
    <section class="space-y-5">
        <header>
            <h3 class="text-xl font-semibold text-[#ee5c81]">
                Удаление аккаунта
            </h3>

            <p class="mt-1 text-sm text-slate-500">
                Это действие необратимо. Все связанные данные и списки будут удалены.
            </p>
        </header>

        <DangerButton @click="confirmUserDeletion">Удалить аккаунт</DangerButton>

        <Modal :show="confirmingUserDeletion" @close="closeModal">
            <div class="p-6 sm:p-7">
                <h4 class="text-xl font-semibold text-slate-800">
                    Подтвердите удаление аккаунта
                </h4>

                <p class="mt-2 text-sm text-slate-600">
                    Введите текущий пароль, чтобы окончательно удалить аккаунт.
                </p>

                <div class="mt-5">
                    <InputLabel for="password" value="Пароль" class="sr-only" />

                    <TextInput
                        id="password"
                        ref="passwordInput"
                        v-model="form.password"
                        type="password"
                        class="w-full"
                        placeholder="Введите пароль"
                        @keyup.enter="deleteUser"
                    />

                    <InputError :message="form.errors.password" />
                </div>

                <div class="mt-6 flex flex-wrap justify-end gap-2">
                    <SecondaryButton @click="closeModal">
                        Отмена
                    </SecondaryButton>

                    <DangerButton
                        :class="{ 'opacity-80': form.processing }"
                        :disabled="form.processing"
                        @click="deleteUser"
                    >
                        Удалить
                    </DangerButton>
                </div>
            </div>
        </Modal>
    </section>
</template>

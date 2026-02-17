<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref, nextTick } from 'vue';

const useRecovery = ref(false);
const codeInput = ref(null);
const recoveryInput = ref(null);

const form = useForm({
    two_factor_code: '',
    recovery_code: '',
});

const toggleRecovery = async () => {
    useRecovery.value = !useRecovery.value;
    form.two_factor_code = '';
    form.recovery_code = '';
    form.clearErrors();

    await nextTick();
    if (useRecovery.value) {
        recoveryInput.value?.focus();
    } else {
        codeInput.value?.focus();
    }
};

const submit = () => {
    form.post(route('two-factor.verify'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Verificacion de dos pasos" />

        <div class="text-center mb-6">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-pink-100 mb-4">
                <svg class="h-6 w-6 text-pink-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
            </div>
            <h2 class="text-lg font-semibold text-gray-900">Verificacion de dos pasos</h2>
            <p class="mt-1 text-sm text-gray-600" v-if="!useRecovery">
                Ingresa el codigo de 6 digitos de tu aplicacion de autenticacion.
            </p>
            <p class="mt-1 text-sm text-gray-600" v-else>
                Ingresa uno de tus codigos de recuperacion.
            </p>
        </div>

        <form @submit.prevent="submit">
            <div v-if="!useRecovery">
                <InputLabel for="two_factor_code" value="Codigo de verificacion" />
                <TextInput
                    id="two_factor_code"
                    ref="codeInput"
                    v-model="form.two_factor_code"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    maxlength="6"
                    class="mt-1 block w-full text-center text-2xl tracking-widest"
                    autofocus
                />
                <InputError class="mt-2" :message="form.errors.two_factor_code" />
            </div>

            <div v-else>
                <InputLabel for="recovery_code" value="Codigo de recuperacion" />
                <TextInput
                    id="recovery_code"
                    ref="recoveryInput"
                    v-model="form.recovery_code"
                    type="text"
                    autocomplete="one-time-code"
                    class="mt-1 block w-full text-center tracking-wider"
                />
                <InputError class="mt-2" :message="form.errors.recovery_code" />
            </div>

            <div class="mt-6">
                <PrimaryButton class="w-full justify-center" :disabled="form.processing">
                    {{ form.processing ? 'Verificando...' : 'Verificar' }}
                </PrimaryButton>
            </div>

            <div class="mt-4 text-center">
                <button
                    type="button"
                    @click="toggleRecovery"
                    class="text-sm text-pink-600 hover:text-pink-800 underline"
                >
                    {{ useRecovery ? 'Usar codigo de autenticacion' : 'Usar codigo de recuperacion' }}
                </button>
            </div>
        </form>
    </GuestLayout>
</template>

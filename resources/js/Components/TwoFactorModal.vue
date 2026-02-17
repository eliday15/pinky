<script setup>
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    show: Boolean,
    action: String,
    method: {
        type: String,
        default: 'post',
    },
    title: {
        type: String,
        default: 'Verificacion requerida',
    },
    message: {
        type: String,
        default: 'Ingresa el codigo de 6 digitos de tu aplicacion de autenticacion para confirmar esta accion.',
    },
    extraData: {
        type: Object,
        default: () => ({}),
    },
});

const emit = defineEmits(['close', 'success']);

const form = useForm({
    two_factor_code: '',
    ...props.extraData,
});

// Keep extraData in sync
watch(() => props.extraData, (newData) => {
    Object.keys(newData).forEach(key => {
        form[key] = newData[key];
    });
}, { deep: true });

// Reset form when modal opens
watch(() => props.show, (isShowing) => {
    if (isShowing) {
        form.two_factor_code = '';
        form.clearErrors();
    }
});

const submit = () => {
    form[props.method](props.action, {
        preserveScroll: true,
        onSuccess: () => {
            emit('success');
            emit('close');
        },
    });
};
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" @click="$emit('close')"></div>

            <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full">
                <!-- Header -->
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex items-center">
                        <div class="flex-shrink-0 flex items-center justify-center h-10 w-10 rounded-full bg-pink-100">
                            <svg class="h-5 w-5 text-pink-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                        </div>
                        <h3 class="ml-3 text-lg font-semibold text-gray-900">{{ title }}</h3>
                    </div>
                </div>

                <!-- Body -->
                <form @submit.prevent="submit">
                    <div class="px-6 py-4">
                        <p class="text-sm text-gray-600 mb-4">{{ message }}</p>

                        <label for="two_factor_code_modal" class="block text-sm font-medium text-gray-700 mb-1">
                            Codigo de verificacion
                        </label>
                        <input
                            id="two_factor_code_modal"
                            v-model="form.two_factor_code"
                            type="text"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            maxlength="6"
                            class="w-full text-center text-2xl tracking-widest rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            placeholder="000000"
                            autofocus
                        />
                        <p v-if="form.errors.two_factor_code" class="mt-2 text-sm text-red-600">
                            {{ form.errors.two_factor_code }}
                        </p>
                    </div>

                    <!-- Footer -->
                    <div class="px-6 py-4 border-t border-gray-200 flex justify-end space-x-3">
                        <button
                            type="button"
                            @click="$emit('close')"
                            class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="form.processing || form.two_factor_code.length !== 6"
                            class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 disabled:opacity-50"
                        >
                            {{ form.processing ? 'Verificando...' : 'Confirmar' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

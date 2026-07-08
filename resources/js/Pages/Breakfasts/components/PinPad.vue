<script setup>
import { computed } from 'vue';

// Teclado numérico táctil para el NIP del kiosco. No muestra los dígitos:
// solo puntos, como un cajero.
const props = defineProps({
    modelValue: { type: String, default: '' },
    maxLength: { type: Number, default: 8 },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'submit']);

const dots = computed(() => props.modelValue.length);

const press = (digit) => {
    if (props.disabled || props.modelValue.length >= props.maxLength) return;
    emit('update:modelValue', props.modelValue + digit);
};

const erase = () => {
    if (props.disabled) return;
    emit('update:modelValue', props.modelValue.slice(0, -1));
};

const submit = () => {
    if (props.disabled || props.modelValue.length < 4) return;
    emit('submit');
};
</script>

<template>
    <div class="w-full max-w-xs mx-auto">
        <div class="flex justify-center gap-3 mb-6 h-4">
            <span
                v-for="i in Math.max(dots, 4)"
                :key="i"
                class="w-4 h-4 rounded-full border-2 border-pink-400 transition-colors"
                :class="i <= dots ? 'bg-pink-500' : 'bg-transparent'"
            />
        </div>
        <div class="grid grid-cols-3 gap-3">
            <button
                v-for="digit in ['1', '2', '3', '4', '5', '6', '7', '8', '9']"
                :key="digit"
                type="button"
                :disabled="disabled"
                class="py-5 rounded-2xl bg-white text-3xl font-semibold text-gray-800 shadow active:bg-pink-100 disabled:opacity-50"
                @click="press(digit)"
            >
                {{ digit }}
            </button>
            <button
                type="button"
                :disabled="disabled"
                class="py-5 rounded-2xl bg-gray-200 text-xl font-semibold text-gray-700 shadow active:bg-gray-300 disabled:opacity-50"
                @click="erase"
            >
                ⌫
            </button>
            <button
                type="button"
                :disabled="disabled"
                class="py-5 rounded-2xl bg-white text-3xl font-semibold text-gray-800 shadow active:bg-pink-100 disabled:opacity-50"
                @click="press('0')"
            >
                0
            </button>
            <button
                type="button"
                :disabled="disabled || modelValue.length < 4"
                class="py-5 rounded-2xl bg-pink-600 text-xl font-semibold text-white shadow active:bg-pink-700 disabled:opacity-50"
                @click="submit"
            >
                OK
            </button>
        </div>
    </div>
</template>

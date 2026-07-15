<script setup>
import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

// Toast global de mensajes flash (success/error/warning): el backend ya los
// manda en cada redirect (HandleInertiaRequests) pero nadie los pintaba, así
// que acciones como "Calcular Nómina" terminaban sin ninguna señal visible.
const page = usePage();

const visible = ref(false);
const message = ref('');
const kind = ref('success');
let timer = null;

const styles = {
    success: 'bg-green-600 text-white',
    error: 'bg-red-600 text-white',
    warning: 'bg-amber-500 text-white',
};

watch(
    () => page.props.flash,
    (flash) => {
        const next = flash?.success
            ? { kind: 'success', message: flash.success }
            : flash?.error
                ? { kind: 'error', message: flash.error }
                : flash?.warning
                    ? { kind: 'warning', message: flash.warning }
                    : null;

        if (!next) return;

        message.value = next.message;
        kind.value = next.kind;
        visible.value = true;
        clearTimeout(timer);
        // Los errores se quedan más tiempo: hay que alcanzar a leerlos.
        timer = setTimeout(() => (visible.value = false), next.kind === 'error' ? 10000 : 6000);
    },
    { deep: true, immediate: true },
);
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="translate-y-2 opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-150 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <div
            v-if="visible"
            class="fixed bottom-6 right-6 z-50 flex max-w-md items-start gap-3 rounded-lg px-4 py-3 shadow-lg print:hidden"
            :class="styles[kind]"
            role="status"
        >
            <svg v-if="kind === 'success'" class="mt-0.5 h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            <svg v-else class="mt-0.5 h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
            </svg>
            <p class="text-sm font-medium">{{ message }}</p>
            <button class="ml-2 shrink-0 opacity-80 hover:opacity-100" aria-label="Cerrar" @click="visible = false">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </Transition>
</template>

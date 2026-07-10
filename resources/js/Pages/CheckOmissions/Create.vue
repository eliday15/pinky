<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    employees: Array,
    reasonOptions: Object,
    prefill: Object,
});

const form = useForm({
    employee_id: props.prefill?.employee_id ?? '',
    work_date: props.prefill?.work_date ?? '',
    reason: '',
    comments: '',
});

const commentsRequired = computed(() => form.reason === 'otro');

const submit = () => {
    form.post(route('check-omissions.store'));
};
</script>

<template>
    <Head title="Nueva omisión de checada" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Nueva omisión de checada
            </h2>
        </template>

        <div class="max-w-3xl">
            <div class="mb-6">
                <Link
                    :href="route('check-omissions.index')"
                    class="text-pink-600 hover:text-pink-800"
                >
                    &larr; Volver a omisiones
                </Link>
            </div>

            <!-- Info Banner -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                <div class="flex">
                    <svg class="h-5 w-5 text-blue-400 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                    </svg>
                    <div class="ml-3 text-sm text-blue-700">
                        <p>
                            <strong>Entrega de mercancía:</strong> el día se paga completo, sin descuento de falta.
                        </p>
                        <p class="mt-1">
                            <strong>Otro:</strong> el día se convierte en retardo (puede acumular falta por retardos según política).
                        </p>
                    </div>
                </div>
            </div>

            <form @submit.prevent="submit" class="bg-white rounded-lg shadow p-6 space-y-6">

                <!-- Employee -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Empleado <span class="text-red-500">*</span>
                    </label>
                    <select
                        v-model="form.employee_id"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        :class="{ 'border-red-500': form.errors.employee_id }"
                    >
                        <option value="">Seleccionar empleado...</option>
                        <option v-for="emp in employees" :key="emp.id" :value="emp.id">
                            {{ emp.full_name }}
                        </option>
                    </select>
                    <p v-if="form.errors.employee_id" class="mt-1 text-sm text-red-600">
                        {{ form.errors.employee_id }}
                    </p>
                </div>

                <!-- Work Date -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Fecha de la omisión <span class="text-red-500">*</span>
                    </label>
                    <input
                        v-model="form.work_date"
                        type="date"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        :class="{ 'border-red-500': form.errors.work_date }"
                    />
                    <p v-if="form.errors.work_date" class="mt-1 text-sm text-red-600">
                        {{ form.errors.work_date }}
                    </p>
                </div>

                <!-- Reason -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Motivo <span class="text-red-500">*</span>
                    </label>
                    <select
                        v-model="form.reason"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        :class="{ 'border-red-500': form.errors.reason }"
                    >
                        <option value="">Seleccionar motivo...</option>
                        <option v-for="[key, label] in Object.entries(reasonOptions)" :key="key" :value="key">
                            {{ label }}
                        </option>
                    </select>
                    <p v-if="form.errors.reason" class="mt-1 text-sm text-red-600">
                        {{ form.errors.reason }}
                    </p>
                </div>

                <!-- Reason hint -->
                <div v-if="form.reason" class="bg-gray-50 rounded-lg p-3 -mt-2">
                    <p v-if="form.reason === 'entrega_mercancia'" class="text-sm text-green-700">
                        Este motivo <strong>no genera falta</strong>: el día se paga completo.
                    </p>
                    <p v-else class="text-sm text-amber-700">
                        Este motivo <strong>convierte el día en retardo</strong>. Acumular suficientes retardos puede generar una falta.
                    </p>
                </div>

                <!-- Comments -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        <span v-if="commentsRequired">Especificar motivo <span class="text-red-500">*</span></span>
                        <span v-else>Comentarios (opcional)</span>
                    </label>
                    <textarea
                        v-model="form.comments"
                        rows="3"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        :class="{ 'border-red-500': form.errors.comments }"
                        :placeholder="commentsRequired ? 'Describir el motivo específico...' : 'Observaciones adicionales...'"
                    ></textarea>
                    <p v-if="form.errors.comments" class="mt-1 text-sm text-red-600">
                        {{ form.errors.comments }}
                    </p>
                </div>

                <!-- Actions -->
                <div class="flex justify-end space-x-4 pt-4 border-t">
                    <button
                        type="button"
                        @click="router.visit(route('check-omissions.index'))"
                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-6 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 disabled:opacity-50"
                    >
                        {{ form.processing ? 'Guardando...' : 'Registrar omisión' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

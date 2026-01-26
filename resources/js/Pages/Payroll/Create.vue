<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    suggestedDates: Object,
});

const form = useForm({
    name: '',
    type: 'biweekly',
    start_date: props.suggestedDates?.start_date || '',
    end_date: props.suggestedDates?.end_date || '',
    payment_date: props.suggestedDates?.payment_date || '',
});

const periodDays = computed(() => {
    if (!form.start_date || !form.end_date) return 0;
    const start = new Date(form.start_date);
    const end = new Date(form.end_date);
    return Math.ceil((end - start) / (1000 * 60 * 60 * 24)) + 1;
});

const formatDateForName = (date) => {
    return new Date(date).toLocaleDateString('es-MX', {
        day: 'numeric',
        month: 'short',
    });
};

const generateName = () => {
    if (form.start_date && form.end_date) {
        const typeLabel = form.type === 'biweekly' ? 'Quincena' : form.type === 'weekly' ? 'Semana' : 'Mes';
        form.name = `${typeLabel} ${formatDateForName(form.start_date)} - ${formatDateForName(form.end_date)}`;
    }
};

const submit = () => {
    if (!form.name) {
        generateName();
    }
    form.post(route('payroll.store'));
};
</script>

<template>
    <Head title="Nuevo Periodo de Nomina" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Nuevo Periodo de Nomina
            </h2>
        </template>

        <div class="max-w-2xl">
            <div class="mb-6">
                <Link
                    :href="route('payroll.index')"
                    class="text-pink-600 hover:text-pink-800"
                >
                    &larr; Volver a nominas
                </Link>
            </div>

            <form @submit.prevent="submit" class="bg-white rounded-lg shadow p-6 space-y-6">
                <!-- Period Type -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Tipo de Periodo <span class="text-red-500">*</span>
                    </label>
                    <select
                        v-model="form.type"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    >
                        <option value="weekly">Semanal (7 dias)</option>
                        <option value="biweekly">Quincenal (14 dias)</option>
                        <option value="monthly">Mensual</option>
                    </select>
                    <p v-if="form.errors.type" class="mt-1 text-sm text-red-600">{{ form.errors.type }}</p>
                </div>

                <!-- Date Range -->
                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Fecha Inicio <span class="text-red-500">*</span>
                        </label>
                        <input
                            v-model="form.start_date"
                            type="date"
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            :class="{ 'border-red-500': form.errors.start_date }"
                        />
                        <p v-if="form.errors.start_date" class="mt-1 text-sm text-red-600">{{ form.errors.start_date }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Fecha Fin <span class="text-red-500">*</span>
                        </label>
                        <input
                            v-model="form.end_date"
                            type="date"
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            :class="{ 'border-red-500': form.errors.end_date }"
                        />
                        <p v-if="form.errors.end_date" class="mt-1 text-sm text-red-600">{{ form.errors.end_date }}</p>
                    </div>
                </div>

                <!-- Period Days Info -->
                <div v-if="periodDays > 0" class="flex items-center bg-gray-50 rounded-lg p-4">
                    <svg class="w-5 h-5 text-gray-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    <span class="text-sm text-gray-600">
                        Este periodo abarca <span class="font-medium text-gray-900">{{ periodDays }} dias</span>
                    </span>
                </div>

                <!-- Payment Date -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Fecha de Pago <span class="text-red-500">*</span>
                    </label>
                    <input
                        v-model="form.payment_date"
                        type="date"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        :class="{ 'border-red-500': form.errors.payment_date }"
                    />
                    <p class="mt-1 text-xs text-gray-500">Fecha en que se pagara la nomina</p>
                    <p v-if="form.errors.payment_date" class="mt-1 text-sm text-red-600">{{ form.errors.payment_date }}</p>
                </div>

                <!-- Name -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Nombre del Periodo
                    </label>
                    <div class="flex space-x-2">
                        <input
                            v-model="form.name"
                            type="text"
                            placeholder="Ej: Quincena 1-15 Dic"
                            class="flex-1 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            :class="{ 'border-red-500': form.errors.name }"
                        />
                        <button
                            type="button"
                            @click="generateName"
                            class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200"
                        >
                            Generar
                        </button>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">Dejalo vacio para generar automaticamente</p>
                    <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                </div>

                <!-- Info Box -->
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <div class="flex">
                        <svg class="h-5 w-5 text-blue-400" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                        </svg>
                        <div class="ml-3 text-sm text-blue-700">
                            <p class="font-medium">Siguiente paso</p>
                            <p>Despues de crear el periodo, podras calcular la nomina para todos los empleados activos.</p>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex justify-end space-x-4 pt-4 border-t">
                    <Link
                        :href="route('payroll.index')"
                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50"
                    >
                        Cancelar
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-6 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 disabled:opacity-50"
                    >
                        {{ form.processing ? 'Creando...' : 'Crear Periodo' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

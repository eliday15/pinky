<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    employees: Array,
    selectedEmployee: [Number, String],
    types: Array,
});

const form = useForm({
    employee_id: props.selectedEmployee || '',
    type: '',
    date: new Date().toISOString().split('T')[0],
    start_time: '',
    end_time: '',
    hours: '',
    reason: '',
    evidence: null,
});

const evidencePreview = ref(null);

const handleFileChange = (event) => {
    const file = event.target.files[0];
    if (file) {
        form.evidence = file;
        if (file.type.startsWith('image/')) {
            evidencePreview.value = URL.createObjectURL(file);
        } else {
            evidencePreview.value = null;
        }
    }
};

const removeEvidence = () => {
    form.evidence = null;
    evidencePreview.value = null;
};

const submit = () => {
    form.post(route('authorizations.store'));
};

const typeDescriptions = {
    overtime: 'Horas adicionales trabajadas fuera del horario normal',
    night_shift: 'Turno nocturno o velada completa',
    exit_permission: 'Permiso para salir antes del horario establecido',
    entry_permission: 'Permiso para entrar despues del horario establecido',
    schedule_change: 'Cambio temporal en el horario de trabajo',
    special: 'Autorizacion especial que no encaja en otras categorias',
};
</script>

<template>
    <Head title="Nueva Autorizacion" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Nueva Autorizacion
            </h2>
        </template>

        <div class="max-w-3xl">
            <!-- Breadcrumb -->
            <div class="mb-6">
                <Link :href="route('authorizations.index')" class="text-pink-600 hover:text-pink-800">
                    &larr; Volver a autorizaciones
                </Link>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <!-- Employee & Type -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Informacion General</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Empleado *
                            </label>
                            <select
                                v-model="form.employee_id"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.employee_id }"
                            >
                                <option value="">Seleccionar...</option>
                                <option v-for="emp in employees" :key="emp.id" :value="emp.id">
                                    {{ emp.full_name }} ({{ emp.employee_number }})
                                </option>
                            </select>
                            <p v-if="form.errors.employee_id" class="mt-1 text-sm text-red-600">
                                {{ form.errors.employee_id }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Tipo de Autorizacion *
                            </label>
                            <select
                                v-model="form.type"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.type }"
                            >
                                <option value="">Seleccionar...</option>
                                <option v-for="type in types" :key="type.value" :value="type.value">
                                    {{ type.label }}
                                </option>
                            </select>
                            <p v-if="form.type && typeDescriptions[form.type]" class="mt-1 text-sm text-gray-500">
                                {{ typeDescriptions[form.type] }}
                            </p>
                            <p v-if="form.errors.type" class="mt-1 text-sm text-red-600">
                                {{ form.errors.type }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Date & Time -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Fecha y Horario</h3>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Fecha *
                            </label>
                            <input
                                v-model="form.date"
                                type="date"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.date }"
                            />
                            <p v-if="form.errors.date" class="mt-1 text-sm text-red-600">
                                {{ form.errors.date }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Hora Inicio
                            </label>
                            <input
                                v-model="form.start_time"
                                type="time"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Hora Fin
                            </label>
                            <input
                                v-model="form.end_time"
                                type="time"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Horas Totales
                            </label>
                            <input
                                v-model="form.hours"
                                type="number"
                                step="0.5"
                                min="0"
                                max="24"
                                placeholder="Auto o manual"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            />
                            <p class="mt-1 text-xs text-gray-500">
                                Se calcula automaticamente si pone inicio/fin
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Reason -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Justificacion</h3>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Razon / Motivo *
                        </label>
                        <textarea
                            v-model="form.reason"
                            rows="4"
                            placeholder="Describa el motivo de esta autorizacion..."
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            :class="{ 'border-red-500': form.errors.reason }"
                        ></textarea>
                        <p v-if="form.errors.reason" class="mt-1 text-sm text-red-600">
                            {{ form.errors.reason }}
                        </p>
                    </div>
                </div>

                <!-- Evidence Upload -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Evidencia / Documento</h3>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Subir documento de soporte
                        </label>
                        <div v-if="!form.evidence" class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-lg hover:border-pink-400 transition-colors">
                            <div class="space-y-1 text-center">
                                <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                    <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <div class="flex text-sm text-gray-600">
                                    <label class="relative cursor-pointer bg-white rounded-md font-medium text-pink-600 hover:text-pink-500 focus-within:outline-none">
                                        <span>Subir archivo</span>
                                        <input
                                            type="file"
                                            class="sr-only"
                                            accept=".pdf,.jpg,.jpeg,.png"
                                            @change="handleFileChange"
                                        />
                                    </label>
                                    <p class="pl-1">o arrastrar y soltar</p>
                                </div>
                                <p class="text-xs text-gray-500">PDF, JPG, PNG hasta 5MB</p>
                            </div>
                        </div>
                        <div v-else class="mt-2 p-4 bg-gray-50 rounded-lg flex items-center justify-between">
                            <div class="flex items-center">
                                <svg class="w-8 h-8 text-pink-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <div>
                                    <p class="text-sm font-medium text-gray-900">{{ form.evidence.name }}</p>
                                    <p class="text-xs text-gray-500">{{ (form.evidence.size / 1024).toFixed(1) }} KB</p>
                                </div>
                            </div>
                            <button
                                type="button"
                                @click="removeEvidence"
                                class="text-red-600 hover:text-red-800"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                        <img v-if="evidencePreview" :src="evidencePreview" class="mt-3 max-h-40 rounded-lg" alt="Vista previa" />
                        <p v-if="form.errors.evidence" class="mt-1 text-sm text-red-600">
                            {{ form.errors.evidence }}
                        </p>
                        <p class="mt-2 text-xs text-gray-500">
                            Para horas extra y veladas es recomendable adjuntar evidencia del trabajo realizado.
                        </p>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex justify-end space-x-4">
                    <Link
                        :href="route('authorizations.index')"
                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors"
                    >
                        Cancelar
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-6 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors disabled:opacity-50"
                    >
                        {{ form.processing ? 'Guardando...' : 'Crear Autorizacion' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

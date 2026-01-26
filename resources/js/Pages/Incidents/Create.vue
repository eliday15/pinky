<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const props = defineProps({
    incidentTypes: Array,
    employees: Array,
    selectedEmployee: [Number, String],
});

const form = useForm({
    employee_id: props.selectedEmployee || '',
    incident_type_id: '',
    start_date: '',
    end_date: '',
    reason: '',
    document: null,
});

const documentPreview = ref(null);

const handleDocumentChange = (event) => {
    const file = event.target.files[0];
    if (file) {
        form.document = file;
        if (file.type.startsWith('image/')) {
            documentPreview.value = URL.createObjectURL(file);
        } else {
            documentPreview.value = null;
        }
    }
};

const removeDocument = () => {
    form.document = null;
    documentPreview.value = null;
};

const requiresDocument = computed(() => {
    // Para incapacidades siempre se requiere documento (code: INC)
    return selectedIncidentType.value?.code === 'INC' ||
           selectedIncidentType.value?.requires_document === true;
});

const selectedEmployeeData = computed(() => {
    if (!form.employee_id) return null;
    return props.employees.find(e => e.id == form.employee_id);
});

const selectedIncidentType = computed(() => {
    if (!form.incident_type_id) return null;
    return props.incidentTypes.find(t => t.id == form.incident_type_id);
});

/**
 * Calculate working days between two dates (excludes weekends).
 * Note: Backend also excludes holidays, but we can't access that data here.
 * This provides a close approximation for the user.
 */
const calculateWorkingDays = (startDate, endDate) => {
    let count = 0;
    const current = new Date(startDate);
    const end = new Date(endDate);

    while (current <= end) {
        const dayOfWeek = current.getDay();
        // 0 = Sunday, 6 = Saturday
        if (dayOfWeek !== 0 && dayOfWeek !== 6) {
            count++;
        }
        current.setDate(current.getDate() + 1);
    }

    return Math.max(1, count); // At least 1 day
};

const daysCount = computed(() => {
    if (!form.start_date || !form.end_date) return 0;
    return calculateWorkingDays(form.start_date, form.end_date);
});

// Calendar days for informational purposes
const calendarDaysCount = computed(() => {
    if (!form.start_date || !form.end_date) return 0;
    const start = new Date(form.start_date);
    const end = new Date(form.end_date);
    const diff = Math.ceil((end - start) / (1000 * 60 * 60 * 24)) + 1;
    return diff > 0 ? diff : 0;
});

const vacationBalance = computed(() => {
    if (!selectedEmployeeData.value) return { entitled: 0, used: 0, available: 0 };
    const entitled = selectedEmployeeData.value.vacation_days_entitled || 0;
    const used = selectedEmployeeData.value.vacation_days_used || 0;
    return {
        entitled,
        used,
        available: entitled - used,
    };
});

const isVacationType = computed(() => {
    return selectedIncidentType.value?.deducts_vacation === true;
});

const vacationWarning = computed(() => {
    if (!isVacationType.value) return null;
    if (daysCount.value > vacationBalance.value.available) {
        return `El empleado solo tiene ${vacationBalance.value.available} dias de vacaciones disponibles.`;
    }
    return null;
});

const submit = () => {
    form.post(route('incidents.store'));
};
</script>

<template>
    <Head title="Nueva Incidencia" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Nueva Incidencia
            </h2>
        </template>

        <div class="max-w-3xl">
            <div class="mb-6">
                <Link
                    :href="route('incidents.index')"
                    class="text-pink-600 hover:text-pink-800"
                >
                    &larr; Volver a incidencias
                </Link>
            </div>

            <form @submit.prevent="submit" class="bg-white rounded-lg shadow p-6 space-y-6">
                <!-- Employee Selection -->
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
                            {{ emp.employee_number }} - {{ emp.full_name }}
                        </option>
                    </select>
                    <p v-if="form.errors.employee_id" class="mt-1 text-sm text-red-600">
                        {{ form.errors.employee_id }}
                    </p>
                </div>

                <!-- Employee Vacation Info -->
                <div v-if="selectedEmployeeData" class="bg-gray-50 rounded-lg p-4">
                    <h4 class="text-sm font-medium text-gray-700 mb-2">Saldo de Vacaciones</h4>
                    <div class="grid grid-cols-3 gap-4 text-sm">
                        <div>
                            <span class="text-gray-500">Derecho:</span>
                            <span class="ml-2 font-medium">{{ vacationBalance.entitled }} dias</span>
                        </div>
                        <div>
                            <span class="text-gray-500">Usados:</span>
                            <span class="ml-2 font-medium">{{ vacationBalance.used }} dias</span>
                        </div>
                        <div>
                            <span class="text-gray-500">Disponibles:</span>
                            <span class="ml-2 font-medium text-green-600">{{ vacationBalance.available }} dias</span>
                        </div>
                    </div>
                </div>

                <!-- Incident Type -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Tipo de Incidencia <span class="text-red-500">*</span>
                    </label>
                    <select
                        v-model="form.incident_type_id"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        :class="{ 'border-red-500': form.errors.incident_type_id }"
                    >
                        <option value="">Seleccionar tipo...</option>
                        <option v-for="type in incidentTypes" :key="type.id" :value="type.id">
                            {{ type.name }}
                        </option>
                    </select>
                    <p v-if="form.errors.incident_type_id" class="mt-1 text-sm text-red-600">
                        {{ form.errors.incident_type_id }}
                    </p>
                </div>

                <!-- Incident Type Info -->
                <div v-if="selectedIncidentType" class="flex flex-wrap gap-2">
                    <span
                        class="px-2 py-1 text-xs font-medium rounded-full"
                        :class="selectedIncidentType.is_paid ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                    >
                        {{ selectedIncidentType.is_paid ? 'Con goce de sueldo' : 'Sin goce de sueldo' }}
                    </span>
                    <span
                        v-if="selectedIncidentType.deducts_vacation"
                        class="px-2 py-1 text-xs font-medium rounded-full bg-yellow-100 text-yellow-800"
                    >
                        Descuenta vacaciones
                    </span>
                    <span
                        v-if="selectedIncidentType.requires_approval"
                        class="px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800"
                    >
                        Requiere aprobacion
                    </span>
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
                        <p v-if="form.errors.start_date" class="mt-1 text-sm text-red-600">
                            {{ form.errors.start_date }}
                        </p>
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
                        <p v-if="form.errors.end_date" class="mt-1 text-sm text-red-600">
                            {{ form.errors.end_date }}
                        </p>
                    </div>
                </div>

                <!-- Days Count -->
                <div v-if="daysCount > 0" class="bg-blue-50 rounded-lg p-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <span class="text-sm text-gray-600">Dias habiles:</span>
                            <span class="ml-2 px-3 py-1 bg-pink-100 text-pink-800 font-medium rounded-full">
                                {{ daysCount }} {{ daysCount === 1 ? 'dia' : 'dias' }}
                            </span>
                        </div>
                        <div v-if="calendarDaysCount !== daysCount" class="text-xs text-gray-500">
                            ({{ calendarDaysCount }} dias calendario - {{ calendarDaysCount - daysCount }} fines de semana)
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-gray-500">
                        * El calculo final del backend puede variar si hay dias festivos en el rango.
                    </p>
                </div>

                <!-- Vacation Warning -->
                <div v-if="vacationWarning" class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                    <div class="flex">
                        <svg class="h-5 w-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                        <p class="ml-3 text-sm text-yellow-700">{{ vacationWarning }}</p>
                    </div>
                </div>

                <!-- Reason -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Motivo / Observaciones
                    </label>
                    <textarea
                        v-model="form.reason"
                        rows="3"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        :class="{ 'border-red-500': form.errors.reason }"
                        placeholder="Descripcion o motivo de la incidencia..."
                    ></textarea>
                    <p v-if="form.errors.reason" class="mt-1 text-sm text-red-600">
                        {{ form.errors.reason }}
                    </p>
                </div>

                <!-- Document Upload -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Documento de Soporte
                        <span v-if="requiresDocument" class="text-red-500">*</span>
                    </label>
                    <div v-if="!form.document" class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-lg hover:border-pink-400 transition-colors">
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
                                        @change="handleDocumentChange"
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
                                <p class="text-sm font-medium text-gray-900">{{ form.document.name }}</p>
                                <p class="text-xs text-gray-500">{{ (form.document.size / 1024).toFixed(1) }} KB</p>
                            </div>
                        </div>
                        <button
                            type="button"
                            @click="removeDocument"
                            class="text-red-600 hover:text-red-800"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <img v-if="documentPreview" :src="documentPreview" class="mt-3 max-h-40 rounded-lg" alt="Vista previa" />
                    <p v-if="form.errors.document" class="mt-1 text-sm text-red-600">
                        {{ form.errors.document }}
                    </p>
                    <p v-if="requiresDocument" class="mt-2 text-xs text-orange-600">
                        Este tipo de incidencia requiere documento de soporte (ej. comprobante medico).
                    </p>
                </div>

                <!-- Actions -->
                <div class="flex justify-end space-x-4 pt-4 border-t">
                    <Link
                        :href="route('incidents.index')"
                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50"
                    >
                        Cancelar
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-6 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 disabled:opacity-50"
                    >
                        {{ form.processing ? 'Guardando...' : 'Crear Incidencia' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref, computed, reactive, watch } from 'vue';
import debounce from 'lodash/debounce';

const props = defineProps({
    employees: Array,
    filters: Object,
    hoursPerDay: Number,
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});
const errors = computed(() => page.props.errors ?? {});
const hasErrors = computed(() => Object.keys(errors.value).length > 0);

const search = ref(props.filters.search || '');
const enrolledOnly = ref(!!props.filters.enrolled_only);

const applyFilters = debounce(() => {
    router.get(route('vacation-hours.index'), {
        search: search.value || undefined,
        enrolled_only: enrolledOnly.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
}, 300);

watch([search, enrolledOnly], applyFilters);

// Per-row input state keyed by employee id
const convertDays = reactive({});
const revertHours = reactive({});

const convert = (empId) => {
    const days = convertDays[empId];
    if (!days || days < 1) return;
    router.post(route('vacation-hours.convert'), { employee_id: empId, days }, { preserveScroll: true });
};

const revert = (empId) => {
    const hours = revertHours[empId];
    if (!hours || hours < 0.5) return;
    router.post(route('vacation-hours.revert'), { employee_id: empId, hours }, { preserveScroll: true });
};
</script>

<template>
    <Head title="Bolsa de horas a cuenta de vacaciones" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Bolsa de horas a cuenta de vacaciones
            </h2>
        </template>

        <!-- Page heading + helper text -->
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-800">Bolsa de horas a cuenta de vacaciones</h1>
            <p class="mt-1 text-gray-600">
                Convierte días de vacaciones en horas (1 día = {{ hoursPerDay }} h).
                El colaborador las gasta en permisos de entrada tarde / salida temprano hasta agotarlas.
                El descuento del saldo es proporcional a lo gastado.
            </p>
        </div>

        <!-- Flash success -->
        <div v-if="flash.success" class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg text-green-800">
            {{ flash.success }}
        </div>

        <!-- Flash error -->
        <div v-if="flash.error" class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-800">
            {{ flash.error }}
        </div>

        <!-- Validation errors from convert / revert -->
        <div v-if="hasErrors" class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg">
            <ul class="list-disc list-inside space-y-1 text-sm text-red-800">
                <li v-for="(msg, field) in errors" :key="field">{{ msg }}</li>
            </ul>
        </div>

        <!-- Filters -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <div class="flex flex-wrap items-end gap-4">
                <div class="flex-1 min-w-48">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Buscar</label>
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Nombre empleado..."
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    />
                </div>
                <div class="flex items-center gap-2 pb-1">
                    <input
                        id="enrolled-only"
                        v-model="enrolledOnly"
                        type="checkbox"
                        class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                    />
                    <label for="enrolled-only" class="text-sm font-medium text-gray-700 cursor-pointer">
                        Solo inscritos
                    </label>
                </div>
            </div>
        </div>

        <!-- Table -->
        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Colaborador
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Departamento
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Días disponibles
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Bolsa convertida
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Usadas
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Disponibles en bolsa
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Acciones
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="emp in employees" :key="emp.id" class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-full bg-pink-100 flex items-center justify-center flex-shrink-0">
                                    <span class="text-pink-600 text-sm font-medium">
                                        {{ emp.full_name?.charAt(0) || '?' }}
                                    </span>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm font-medium text-gray-900">{{ emp.full_name }}</p>
                                    <p class="text-xs text-gray-500">{{ emp.employee_number }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            {{ emp.department || '—' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            {{ emp.vacation_days_available }} días
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            {{ emp.hours_credited }} h
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            {{ emp.hours_used }} h
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span
                                class="text-sm font-bold"
                                :class="emp.hours_remaining > 0 ? 'text-green-700' : 'text-gray-400'"
                            >
                                {{ emp.hours_remaining }} h
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex flex-wrap gap-2 items-center">
                                <!-- Convertir -->
                                <div class="flex items-center gap-1">
                                    <input
                                        v-model.number="convertDays[emp.id]"
                                        type="number"
                                        min="1"
                                        placeholder="días"
                                        class="w-16 text-sm rounded border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                    />
                                    <button
                                        type="button"
                                        @click="convert(emp.id)"
                                        :disabled="!convertDays[emp.id] || convertDays[emp.id] < 1"
                                        class="px-2 py-1 text-xs bg-pink-600 text-white rounded hover:bg-pink-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        Convertir
                                    </button>
                                </div>
                                <!-- Revertir -->
                                <div class="flex items-center gap-1">
                                    <input
                                        v-model.number="revertHours[emp.id]"
                                        type="number"
                                        min="0.5"
                                        step="0.5"
                                        placeholder="horas"
                                        class="w-16 text-sm rounded border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                    />
                                    <button
                                        type="button"
                                        @click="revert(emp.id)"
                                        :disabled="!revertHours[emp.id] || revertHours[emp.id] < 0.5"
                                        class="px-2 py-1 text-xs bg-gray-600 text-white rounded hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                    >
                                        Revertir
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="employees.length === 0">
                        <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                            No se encontraron empleados
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

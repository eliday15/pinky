<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import debounce from 'lodash/debounce';

const props = defineProps({
    employees: Object,
    dates: Array,
    startDate: String,
    endDate: String,
    summary: Object,
    lastSync: String,
    departments: Array,
    filters: Object,
    can: Object,
});

const selectedStartDate = ref(props.startDate);
const selectedEndDate = ref(props.endDate);
const department = ref(props.filters.department || '');
const status = ref(props.filters.status || '');
const search = ref(props.filters.search || '');
const autoRefreshEnabled = ref(true);
let refreshInterval = null;

const isRangeView = computed(() => props.dates.length > 1);

const applyFilters = debounce(() => {
    router.get(route('attendance.index'), {
        start_date: selectedStartDate.value,
        end_date: selectedEndDate.value,
        department: department.value || undefined,
        status: status.value || undefined,
        search: search.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
}, 300);

watch([selectedStartDate, selectedEndDate, department, status, search], applyFilters);

// Auto-refresh every 2 minutes
const startAutoRefresh = () => {
    refreshInterval = setInterval(() => {
        if (autoRefreshEnabled.value) {
            router.reload({ only: ['employees', 'summary', 'lastSync'] });
        }
    }, 120000);
};

onMounted(() => startAutoRefresh());
onUnmounted(() => { if (refreshInterval) clearInterval(refreshInterval); });

const statusColors = {
    present: 'bg-green-100 text-green-800',
    late: 'bg-yellow-100 text-yellow-800',
    absent: 'bg-red-100 text-red-800',
    partial: 'bg-orange-100 text-orange-800',
    holiday: 'bg-blue-100 text-blue-800',
    vacation: 'bg-purple-100 text-purple-800',
    sick_leave: 'bg-pink-100 text-pink-800',
    permission: 'bg-indigo-100 text-indigo-800',
};

const statusLabels = {
    present: 'Presente',
    late: 'Retardo',
    absent: 'Ausente',
    partial: 'Parcial',
    holiday: 'Festivo',
    vacation: 'Vacaciones',
    sick_leave: 'Incapacidad',
    permission: 'Permiso',
};

const syncNow = () => {
    if (confirm('¿Iniciar sincronizacion con ZKTeco?')) {
        router.post(route('attendance.sync'));
    }
};

const downloadExcel = () => {
    const params = new URLSearchParams({
        start_date: selectedStartDate.value,
        end_date: selectedEndDate.value,
    });
    if (department.value) params.set('department', department.value);
    window.open(route('attendance.export') + '?' + params.toString(), '_blank');
};

const formatDate = (dateStr) => {
    const [year, month, day] = dateStr.split('-').map(Number);
    const date = new Date(year, month - 1, day);
    return date.toLocaleDateString('es-MX', {
        weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
    });
};

const formatShortDate = (dateStr) => {
    const [year, month, day] = dateStr.split('-').map(Number);
    const date = new Date(year, month - 1, day);
    return date.toLocaleDateString('es-MX', {
        weekday: 'short', day: 'numeric', month: 'short'
    });
};

const dateRangeLabel = computed(() => {
    if (selectedStartDate.value === selectedEndDate.value) {
        return formatDate(selectedStartDate.value);
    }
    return `Del ${formatDate(selectedStartDate.value)} al ${formatDate(selectedEndDate.value)}`;
});

const getScheduleLabel = (employee) => {
    const schedule = employee.schedule;
    if (!schedule) return '-';
    const entry = (schedule.entry_time || '').substring(0, 5) || '?';
    const exit = (schedule.exit_time || '').substring(0, 5) || '?';
    return `${entry} - ${exit}`;
};

const getAttendance = (employee, date) => {
    return employee.attendance_by_date?.[date] || null;
};
</script>

<template>
    <Head title="Asistencia" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Asistencia
            </h2>
        </template>

        <!-- Summary Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center">
                    <div class="p-2 rounded-full bg-green-100">
                        <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-2xl font-bold text-gray-800">{{ summary.present }}</p>
                        <p class="text-sm text-gray-500">Presentes</p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center">
                    <div class="p-2 rounded-full bg-yellow-100">
                        <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-2xl font-bold text-gray-800">{{ summary.late }}</p>
                        <p class="text-sm text-gray-500">Retardos</p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center">
                    <div class="p-2 rounded-full bg-red-100">
                        <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-2xl font-bold text-gray-800">{{ summary.absent }}</p>
                        <p class="text-sm text-gray-500">Ausentes</p>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">Ultima sync</p>
                        <p class="text-sm font-medium text-gray-800">{{ lastSync }}</p>
                        <div class="flex items-center mt-1">
                            <span
                                :class="autoRefreshEnabled ? 'bg-green-500' : 'bg-gray-300'"
                                class="w-2 h-2 rounded-full animate-pulse"
                            ></span>
                            <button
                                @click="autoRefreshEnabled = !autoRefreshEnabled"
                                class="ml-1 text-xs text-gray-500 hover:text-gray-700"
                            >
                                {{ autoRefreshEnabled ? 'Auto-sync ON' : 'Auto-sync OFF' }}
                            </button>
                        </div>
                    </div>
                    <button
                        v-if="can?.sync"
                        @click="syncNow"
                        class="p-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700"
                        title="Sincronizar ahora"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <div class="flex flex-wrap items-center gap-4">
                <div class="flex items-center gap-2">
                    <label class="text-sm text-gray-500 whitespace-nowrap">Desde</label>
                    <input
                        v-model="selectedStartDate"
                        type="date"
                        class="rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    />
                </div>
                <div class="flex items-center gap-2">
                    <label class="text-sm text-gray-500 whitespace-nowrap">Hasta</label>
                    <input
                        v-model="selectedEndDate"
                        type="date"
                        :min="selectedStartDate"
                        class="rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    />
                </div>

                <input
                    v-model="search"
                    type="text"
                    placeholder="Buscar empleado..."
                    class="rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                />

                <select
                    v-model="department"
                    class="rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                >
                    <option value="">Todos los departamentos</option>
                    <option v-for="dept in departments" :key="dept.id" :value="dept.id">
                        {{ dept.name }}
                    </option>
                </select>

                <div class="ml-auto flex items-center gap-2">
                    <button
                        v-if="can?.export"
                        @click="downloadExcel"
                        class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 flex items-center gap-2"
                        title="Descargar Excel"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        Excel
                    </button>
                    <Link
                        :href="route('attendance.calendar')"
                        class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200"
                    >
                        Ver Calendario
                    </Link>
                </div>
            </div>
            <p class="mt-2 text-sm text-gray-500 capitalize">{{ dateRangeLabel }}</p>
        </div>

        <!-- Table -->
        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase sticky left-0 bg-gray-50 z-10 min-w-[200px]">Empleado</th>
                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase min-w-[120px]">Departamento</th>
                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase min-w-[100px]">Horario</th>
                        <th
                            v-for="date in dates"
                            :key="date"
                            class="px-3 py-3 text-center text-xs font-medium text-gray-500 uppercase min-w-[140px] border-l border-gray-200"
                            :colspan="1"
                        >
                            {{ formatShortDate(date) }}
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="employee in employees.data" :key="employee.id" class="hover:bg-gray-50">
                        <!-- Employee name (sticky) -->
                        <td class="px-4 py-3 whitespace-nowrap sticky left-0 bg-white z-10">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-full bg-pink-100 flex items-center justify-center flex-shrink-0">
                                    <span class="text-pink-600 text-sm font-medium">
                                        {{ employee.full_name?.charAt(0) || '?' }}
                                    </span>
                                </div>
                                <div class="ml-2">
                                    <p class="text-sm font-medium text-gray-900 truncate max-w-[150px]">{{ employee.full_name }}</p>
                                    <p class="text-xs text-gray-500">{{ employee.employee_number }}</p>
                                </div>
                            </div>
                        </td>
                        <!-- Department -->
                        <td class="px-3 py-3 whitespace-nowrap text-sm text-gray-500">
                            {{ employee.department?.name || '-' }}
                        </td>
                        <!-- Schedule -->
                        <td class="px-3 py-3 whitespace-nowrap text-sm text-gray-500">
                            {{ getScheduleLabel(employee) }}
                        </td>
                        <!-- Per-date cells -->
                        <td
                            v-for="date in dates"
                            :key="date"
                            class="px-3 py-3 text-center border-l border-gray-100"
                        >
                            <template v-if="getAttendance(employee, date)">
                                <div class="text-sm">
                                    <span class="text-gray-700">{{ getAttendance(employee, date).check_in || '-' }}</span>
                                    <span class="text-gray-400 mx-1">-</span>
                                    <span class="text-gray-700">{{ getAttendance(employee, date).check_out || '-' }}</span>
                                </div>
                                <div class="flex items-center justify-center gap-1 mt-1">
                                    <span class="text-xs text-gray-500">{{ getAttendance(employee, date).worked_hours }}h</span>
                                    <span
                                        :class="[statusColors[getAttendance(employee, date).status], 'px-1.5 py-0.5 text-[10px] font-medium rounded-full']"
                                    >
                                        {{ statusLabels[getAttendance(employee, date).status] }}
                                    </span>
                                </div>
                                <div v-if="getAttendance(employee, date).late_minutes > 0" class="text-[10px] text-yellow-600 mt-0.5">
                                    +{{ getAttendance(employee, date).late_minutes }}min
                                </div>
                            </template>
                            <template v-else>
                                <span class="text-gray-300 text-sm">-</span>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="employees.data.length === 0">
                        <td :colspan="3 + dates.length" class="px-6 py-12 text-center text-gray-500">
                            No hay registros de asistencia para este periodo
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Pagination -->
            <div v-if="employees.last_page > 1" class="px-6 py-4 border-t border-gray-200">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-gray-700">
                        Mostrando {{ employees.from }} a {{ employees.to }} de {{ employees.total }} empleados
                    </p>
                    <div class="flex space-x-2">
                        <Link
                            v-for="link in employees.links"
                            :key="link.label"
                            :href="link.url || '#'"
                            :class="[
                                'px-3 py-1 rounded text-sm',
                                link.active ? 'bg-pink-600 text-white' : link.url ? 'bg-gray-100 text-gray-700 hover:bg-gray-200' : 'bg-gray-50 text-gray-400'
                            ]"
                            v-html="link.label"
                        />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

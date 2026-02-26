<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import debounce from 'lodash/debounce';

const props = defineProps({
    records: Object,
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

const isRangeView = computed(() => selectedStartDate.value !== selectedEndDate.value);

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

// Auto-refresh every 2 minutes to sync with ZKTeco changes
const startAutoRefresh = () => {
    refreshInterval = setInterval(() => {
        if (autoRefreshEnabled.value) {
            router.reload({ only: ['records', 'summary', 'lastSync'] });
        }
    }, 120000); // 2 minutes
};

onMounted(() => {
    startAutoRefresh();
});

onUnmounted(() => {
    if (refreshInterval) {
        clearInterval(refreshInterval);
    }
});

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
    if (department.value) {
        params.set('department', department.value);
    }
    window.open(route('attendance.export') + '?' + params.toString(), '_blank');
};

const formatDate = (dateStr) => {
    const [year, month, day] = dateStr.split('-').map(Number);
    const date = new Date(year, month - 1, day);
    return date.toLocaleDateString('es-MX', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
};

const formatShortDate = (dateStr) => {
    if (!dateStr) return '-';
    // Handle both "2026-02-18" and "2026-02-18T00:00:00.000000Z" formats
    const clean = String(dateStr).substring(0, 10);
    const [year, month, day] = clean.split('-').map(Number);
    const date = new Date(year, month - 1, day);
    return date.toLocaleDateString('es-MX', {
        weekday: 'short',
        day: 'numeric',
        month: 'short'
    });
};

const dateRangeLabel = computed(() => {
    if (selectedStartDate.value === selectedEndDate.value) {
        return formatDate(selectedStartDate.value);
    }
    return `Del ${formatDate(selectedStartDate.value)} al ${formatDate(selectedEndDate.value)}`;
});

// Convert time string "HH:MM:SS" to minutes since midnight
const timeToMinutes = (timeStr) => {
    if (!timeStr) return 0;
    const parts = timeStr.split(':');
    return parseInt(parts[0]) * 60 + parseInt(parts[1]);
};

// Filter duplicate punches (within 5 minutes of each other)
const filterDuplicatePunches = (punches, windowMinutes = 5) => {
    if (!punches || punches.length === 0) return [];

    // Sort by time
    const sorted = [...punches].sort((a, b) =>
        (a.time || '').localeCompare(b.time || '')
    );

    const filtered = [];
    let lastMinutes = -999;

    for (const punch of sorted) {
        const currentMinutes = timeToMinutes(punch.time);

        // Only keep if more than windowMinutes apart from last kept punch
        if (currentMinutes - lastMinutes >= windowMinutes) {
            filtered.push(punch);
            lastMinutes = currentMinutes;
        }
    }

    return filtered;
};

// Parse raw_punches into work sessions (entry-exit pairs)
const getWorkSessions = (record) => {
    if (!record.raw_punches || record.raw_punches.length === 0) {
        // Fallback to check_in/check_out if no raw_punches
        if (record.check_in || record.check_out) {
            return [{
                entry: record.check_in?.substring(0, 5) || '?',
                exit: record.check_out?.substring(0, 5) || null
            }];
        }
        return [];
    }

    // Filter duplicates (punches within 5 minutes)
    const punches = filterDuplicatePunches(record.raw_punches, 5);

    // Group into sessions using simple pairing (every 2 consecutive punches)
    const sessions = [];

    for (let i = 0; i < punches.length; i += 2) {
        const entry = punches[i];
        const exit = punches[i + 1];

        sessions.push({
            entry: entry?.time?.substring(0, 5) || '?',
            exit: exit?.time?.substring(0, 5) || null,
            entryType: entry?.type || 'punch',
            exitType: exit?.type || null
        });
    }

    return sessions;
};

// Get session label based on type
const getSessionLabel = (session, index, total) => {
    if (total === 1) return '';
    if (index === 0) return 'AM';
    if (index === total - 1) return 'PM';
    return `S${index + 1}`;
};

// Calculate gross hours (check_out - check_in)
const getGrossHours = (record) => {
    if (!record.check_in || !record.check_out) return '0.00';
    const inMinutes = timeToMinutes(record.check_in);
    const outMinutes = timeToMinutes(record.check_out);
    return ((outMinutes - inMinutes) / 60).toFixed(2);
};

// Calculate net hours (gross - break)
const getNetHours = (record) => {
    const gross = parseFloat(getGrossHours(record));
    const breakHours = (record.employee?.schedule?.break_minutes || 60) / 60;
    return (gross - breakHours).toFixed(2);
};

// Format schedule times for display
const getScheduleLabel = (record) => {
    const schedule = record.employee?.schedule;
    if (!schedule) return '-';
    const entry = schedule.entry_time?.substring(0, 5) || '?';
    const exit = schedule.exit_time?.substring(0, 5) || '?';
    return `${entry} - ${exit}`;
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

                <select
                    v-model="status"
                    class="rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                >
                    <option value="">Todos los estados</option>
                    <option value="present">Presente</option>
                    <option value="late">Retardo</option>
                    <option value="absent">Ausente</option>
                    <option value="partial">Parcial</option>
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
                        <th v-if="isRangeView" class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Empleado</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Departamento</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Horario</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Sesiones de Trabajo</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Horas</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Acciones</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="record in records.data" :key="record.id" class="hover:bg-gray-50">
                        <td v-if="isRangeView" class="px-4 py-4 whitespace-nowrap text-sm text-gray-600">
                            {{ formatShortDate(record.work_date) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-full bg-pink-100 flex items-center justify-center">
                                    <span class="text-pink-600 text-sm font-medium">
                                        {{ record.employee?.full_name?.charAt(0) || '?' }}
                                    </span>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm font-medium text-gray-900">{{ record.employee?.full_name }}</p>
                                    <p class="text-xs text-gray-500">{{ record.employee?.employee_number }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            {{ record.employee?.department?.name || '-' }}
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500">
                            {{ getScheduleLabel(record) }}
                        </td>
                        <td class="px-6 py-4 text-sm">
                            <div class="flex flex-wrap gap-2">
                                <template v-if="getWorkSessions(record).length > 0">
                                    <span
                                        v-for="(session, idx) in getWorkSessions(record)"
                                        :key="idx"
                                        class="inline-flex items-center px-2 py-1 rounded text-xs"
                                        :class="getWorkSessions(record).length > 1 ? 'bg-blue-50 text-blue-800 border border-blue-200' : 'bg-gray-100 text-gray-800'"
                                    >
                                        <span
                                            v-if="getWorkSessions(record).length > 1"
                                            class="mr-1 font-medium text-blue-600"
                                        >
                                            {{ getSessionLabel(session, idx, getWorkSessions(record).length) }}:
                                        </span>
                                        <span :class="record.late_minutes > 0 && idx === 0 ? 'text-yellow-600 font-medium' : ''">
                                            {{ session.entry }}
                                        </span>
                                        <svg class="w-3 h-3 mx-1 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                                        </svg>
                                        <span :class="!session.exit ? 'text-orange-500' : ''">
                                            {{ session.exit || '?' }}
                                        </span>
                                    </span>
                                </template>
                                <template v-else>
                                    <span class="text-gray-400">-</span>
                                </template>
                            </div>
                            <div v-if="record.late_minutes > 0" class="mt-1 text-xs text-yellow-600">
                                +{{ record.late_minutes }}min tardanza
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-2 flex-wrap">
                                <!-- Regular hours box -->
                                <div class="flex items-center bg-gray-100 rounded-lg px-2 py-1">
                                    <svg class="w-4 h-4 text-gray-500 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <span class="font-semibold text-gray-800">{{ record.worked_hours }}h</span>
                                </div>

                                <!-- Extra hours box (if any) -->
                                <template v-if="record.overtime_hours > 0">
                                    <div
                                        v-if="can?.viewOvertimeDetails || record.overtime_authorized"
                                        class="flex items-center bg-green-100 text-green-700 rounded-lg px-2 py-1"
                                    >
                                        <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                        </svg>
                                        <span class="font-semibold">{{ record.overtime_hours }}h</span>
                                        <span class="text-xs ml-1 text-green-600">extra</span>
                                    </div>
                                    <div
                                        v-else-if="can?.viewOvertimeDetails === false && !record.overtime_authorized"
                                        class="flex items-center bg-yellow-50 text-yellow-600 rounded-lg px-2 py-1 text-xs"
                                    >
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        pendiente
                                    </div>
                                </template>
                            </div>

                            <!-- Calculation breakdown -->
                            <div v-if="record.check_in && record.check_out" class="text-xs text-gray-400 mt-1.5 space-y-0.5">
                                <div class="flex items-center gap-1">
                                    <span>{{ getGrossHours(record) }}h bruto</span>
                                    <span class="text-red-400">- 1h comida</span>
                                    <span>=</span>
                                    <span class="font-medium text-gray-600">{{ getNetHours(record) }}h neto</span>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center gap-2">
                                <span :class="[statusColors[record.status], 'px-2 py-1 text-xs font-medium rounded-full']">
                                    {{ statusLabels[record.status] }}
                                </span>
                                <span v-if="record.is_night_shift" class="text-indigo-500" title="Turno nocturno">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z" />
                                    </svg>
                                </span>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                            <Link
                                v-if="can?.edit"
                                :href="route('attendance.edit', record.id)"
                                class="text-pink-600 hover:text-pink-900"
                            >
                                Editar
                            </Link>
                            <span v-else class="text-gray-400">-</span>
                        </td>
                    </tr>
                    <tr v-if="records.data.length === 0">
                        <td :colspan="isRangeView ? 8 : 7" class="px-6 py-12 text-center text-gray-500">
                            No hay registros de asistencia para este periodo
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Pagination -->
            <div v-if="records.last_page > 1" class="px-6 py-4 border-t border-gray-200">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-gray-700">
                        Mostrando {{ records.from }} a {{ records.to }} de {{ records.total }}
                    </p>
                    <div class="flex space-x-2">
                        <Link
                            v-for="link in records.links"
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

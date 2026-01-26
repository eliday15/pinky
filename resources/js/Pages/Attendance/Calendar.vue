<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    employees: Array,
    selectedEmployee: [Number, String],
    month: String,
    calendarData: Array,
});

const employee = ref(props.selectedEmployee || '');
const currentMonth = ref(props.month);

const statusColors = {
    present: 'bg-green-100 text-green-800 border-green-200',
    late: 'bg-yellow-100 text-yellow-800 border-yellow-200',
    absent: 'bg-red-100 text-red-800 border-red-200',
    partial: 'bg-orange-100 text-orange-800 border-orange-200',
    holiday: 'bg-blue-100 text-blue-800 border-blue-200',
    vacation: 'bg-purple-100 text-purple-800 border-purple-200',
    sick_leave: 'bg-pink-100 text-pink-800 border-pink-200',
};

watch([employee, currentMonth], () => {
    if (employee.value) {
        router.get(route('attendance.calendar'), {
            employee: employee.value,
            month: currentMonth.value,
        }, {
            preserveState: true,
            replace: true,
        });
    }
});

const changeMonth = (delta) => {
    const date = new Date(currentMonth.value + '-01');
    date.setMonth(date.getMonth() + delta);
    currentMonth.value = date.toISOString().slice(0, 7);
};

const formatMonth = (monthStr) => {
    const date = new Date(monthStr + '-01');
    return date.toLocaleDateString('es-MX', { month: 'long', year: 'numeric' });
};

const getWeekRows = () => {
    if (!props.calendarData.length) return [];

    const rows = [];
    let currentRow = [];

    // Get the first day of month to determine starting position
    const firstDay = new Date(props.calendarData[0].date);
    const startOffset = firstDay.getDay(); // 0 = Sunday

    // Add empty cells for days before the month starts
    for (let i = 0; i < startOffset; i++) {
        currentRow.push(null);
    }

    props.calendarData.forEach((day) => {
        currentRow.push(day);
        if (currentRow.length === 7) {
            rows.push(currentRow);
            currentRow = [];
        }
    });

    // Fill remaining cells
    while (currentRow.length > 0 && currentRow.length < 7) {
        currentRow.push(null);
    }
    if (currentRow.length) {
        rows.push(currentRow);
    }

    return rows;
};

const calculateSummary = () => {
    if (!props.calendarData.length) return { worked: 0, overtime: 0, late: 0, absent: 0 };

    return props.calendarData.reduce((acc, day) => {
        if (day.record) {
            acc.worked += parseFloat(day.record.worked_hours || 0);
            acc.overtime += parseFloat(day.record.overtime_hours || 0);
            if (day.record.status === 'late') acc.late++;
            if (day.record.status === 'absent') acc.absent++;
        }
        return acc;
    }, { worked: 0, overtime: 0, late: 0, absent: 0 });
};
</script>

<template>
    <Head title="Calendario de Asistencia" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Calendario de Asistencia
            </h2>
        </template>

        <!-- Controls -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <div class="flex flex-wrap items-center gap-4">
                <Link
                    :href="route('attendance.index')"
                    class="text-pink-600 hover:text-pink-800"
                >
                    &larr; Volver a lista
                </Link>

                <select
                    v-model="employee"
                    class="flex-1 max-w-md rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                >
                    <option value="">Seleccionar empleado...</option>
                    <option v-for="emp in employees" :key="emp.id" :value="emp.id">
                        {{ emp.full_name }} ({{ emp.employee_number }})
                    </option>
                </select>

                <div class="flex items-center space-x-2">
                    <button @click="changeMonth(-1)" class="p-2 hover:bg-gray-100 rounded">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                    </button>
                    <span class="font-medium capitalize min-w-32 text-center">{{ formatMonth(currentMonth) }}</span>
                    <button @click="changeMonth(1)" class="p-2 hover:bg-gray-100 rounded">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Summary -->
        <div v-if="employee && calendarData.length" class="grid grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-gray-800">{{ calculateSummary().worked.toFixed(1) }}h</p>
                <p class="text-sm text-gray-500">Horas trabajadas</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-green-600">{{ calculateSummary().overtime.toFixed(1) }}h</p>
                <p class="text-sm text-gray-500">Horas extra</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-yellow-600">{{ calculateSummary().late }}</p>
                <p class="text-sm text-gray-500">Retardos</p>
            </div>
            <div class="bg-white rounded-lg shadow p-4 text-center">
                <p class="text-2xl font-bold text-red-600">{{ calculateSummary().absent }}</p>
                <p class="text-sm text-gray-500">Ausencias</p>
            </div>
        </div>

        <!-- Calendar -->
        <div v-if="employee" class="bg-white rounded-lg shadow overflow-hidden">
            <!-- Header -->
            <div class="grid grid-cols-7 bg-gray-50 border-b">
                <div v-for="day in ['Dom', 'Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab']" :key="day"
                     class="p-3 text-center text-sm font-medium text-gray-500">
                    {{ day }}
                </div>
            </div>

            <!-- Calendar Grid -->
            <div v-for="(week, idx) in getWeekRows()" :key="idx" class="grid grid-cols-7 border-b last:border-0">
                <div
                    v-for="(day, dayIdx) in week"
                    :key="dayIdx"
                    :class="[
                        'min-h-24 p-2 border-r last:border-0',
                        day?.isWeekend ? 'bg-gray-50' : '',
                        day?.record ? statusColors[day.record.status] : ''
                    ]"
                >
                    <template v-if="day">
                        <div class="flex justify-between items-start">
                            <span :class="['text-sm font-medium', day.isWeekend ? 'text-gray-400' : 'text-gray-700']">
                                {{ day.day }}
                            </span>
                        </div>
                        <div v-if="day.record" class="mt-1 text-xs space-y-1">
                            <p v-if="day.record.check_in">
                                <span class="text-gray-500">E:</span> {{ day.record.check_in }}
                            </p>
                            <p v-if="day.record.check_out">
                                <span class="text-gray-500">S:</span> {{ day.record.check_out }}
                            </p>
                            <p v-if="day.record.worked_hours > 0" class="font-medium">
                                {{ day.record.worked_hours }}h
                            </p>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Empty State -->
        <div v-else class="bg-white rounded-lg shadow p-12 text-center">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            <h3 class="mt-4 text-lg font-medium text-gray-900">Selecciona un empleado</h3>
            <p class="mt-2 text-gray-500">Elige un empleado del menu para ver su calendario de asistencia.</p>
        </div>

        <!-- Legend -->
        <div class="mt-6 bg-white rounded-lg shadow p-4">
            <h4 class="text-sm font-medium text-gray-700 mb-3">Leyenda</h4>
            <div class="flex flex-wrap gap-4">
                <div class="flex items-center">
                    <div class="w-4 h-4 rounded bg-green-100 border border-green-200 mr-2"></div>
                    <span class="text-sm text-gray-600">Presente</span>
                </div>
                <div class="flex items-center">
                    <div class="w-4 h-4 rounded bg-yellow-100 border border-yellow-200 mr-2"></div>
                    <span class="text-sm text-gray-600">Retardo</span>
                </div>
                <div class="flex items-center">
                    <div class="w-4 h-4 rounded bg-red-100 border border-red-200 mr-2"></div>
                    <span class="text-sm text-gray-600">Ausente</span>
                </div>
                <div class="flex items-center">
                    <div class="w-4 h-4 rounded bg-purple-100 border border-purple-200 mr-2"></div>
                    <span class="text-sm text-gray-600">Vacaciones</span>
                </div>
                <div class="flex items-center">
                    <div class="w-4 h-4 rounded bg-pink-100 border border-pink-200 mr-2"></div>
                    <span class="text-sm text-gray-600">Incapacidad</span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

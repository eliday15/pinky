<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    schedule: Object,
});

const dayLabels = {
    monday: 'Lunes',
    tuesday: 'Martes',
    wednesday: 'Miercoles',
    thursday: 'Jueves',
    friday: 'Viernes',
    saturday: 'Sabado',
    sunday: 'Domingo',
};

const allDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

const formatTime = (time) => {
    if (!time) return '-';
    return time.substring(0, 5);
};

const hasPerDaySchedules = computed(() => {
    return props.schedule.day_schedules && Object.keys(props.schedule.day_schedules).length > 0;
});

const getScheduleForDay = (day) => {
    const override = props.schedule.day_schedules?.[day] || {};
    return {
        entry_time: override.entry_time ?? props.schedule.entry_time,
        exit_time: override.exit_time ?? props.schedule.exit_time,
        break_start: override.break_start ?? props.schedule.break_start,
        break_end: override.break_end ?? props.schedule.break_end,
        break_minutes: override.break_minutes ?? props.schedule.break_minutes,
        daily_work_hours: override.daily_work_hours ?? props.schedule.daily_work_hours,
    };
};

const workingDays = computed(() => {
    return allDays.filter(d => props.schedule.working_days?.includes(d));
});

const statusColors = {
    active: 'bg-green-100 text-green-800',
    inactive: 'bg-yellow-100 text-yellow-800',
    terminated: 'bg-red-100 text-red-800',
};

const statusLabels = {
    active: 'Activo',
    inactive: 'Inactivo',
    terminated: 'Baja',
};
</script>

<template>
    <Head :title="schedule.name" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Detalle de Horario
            </h2>
        </template>

        <!-- Breadcrumb -->
        <div class="mb-6 flex items-center justify-between">
            <Link :href="route('schedules.index')" class="text-pink-600 hover:text-pink-800">
                &larr; Volver a horarios
            </Link>
            <Link
                :href="route('schedules.edit', schedule.id)"
                class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors"
            >
                Editar Horario
            </Link>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Main Info -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Schedule Header -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-start">
                        <div class="w-20 h-20 rounded-full bg-pink-100 flex items-center justify-center">
                            <svg class="w-10 h-10 text-pink-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="ml-6 flex-1">
                            <div class="flex items-center">
                                <h1 class="text-2xl font-bold text-gray-800">{{ schedule.name }}</h1>
                                <span
                                    :class="[
                                        schedule.is_active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800',
                                        'ml-3 px-3 py-1 text-sm font-medium rounded-full'
                                    ]"
                                >
                                    {{ schedule.is_active ? 'Activo' : 'Inactivo' }}
                                </span>
                                <span
                                    :class="[
                                        schedule.is_flexible ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800',
                                        'ml-2 px-3 py-1 text-sm font-medium rounded-full'
                                    ]"
                                >
                                    {{ schedule.is_flexible ? 'Flexible' : 'Fijo' }}
                                </span>
                            </div>
                            <p class="text-gray-500 mt-1">Codigo: {{ schedule.code }}</p>
                            <p v-if="schedule.description" class="text-gray-600 mt-2">{{ schedule.description }}</p>
                        </div>
                    </div>
                </div>

                <!-- Schedule Details -->
                <template v-if="hasPerDaySchedules">
                    <!-- Per-Day Schedule Table -->
                    <div class="bg-white rounded-lg shadow p-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Horarios por Dia</h3>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Dia</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Entrada</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Salida</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Descanso</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Min. Desc.</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Horas</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="day in workingDays" :key="day" class="hover:bg-gray-50">
                                        <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">
                                            {{ dayLabels[day] }}
                                            <span
                                                v-if="schedule.day_schedules?.[day]"
                                                class="ml-1 text-xs text-pink-600"
                                                title="Horario personalizado para este dia"
                                            >*</span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">{{ formatTime(getScheduleForDay(day).entry_time) }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">{{ formatTime(getScheduleForDay(day).exit_time) }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">
                                            {{ formatTime(getScheduleForDay(day).break_start) }} - {{ formatTime(getScheduleForDay(day).break_end) }}
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">{{ getScheduleForDay(day).break_minutes }} min</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">{{ getScheduleForDay(day).daily_work_hours }}h</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="mt-3 text-xs text-gray-400">
                            <span class="text-pink-600">*</span> Indica horario personalizado (diferente al predeterminado).
                            Predeterminado: {{ formatTime(schedule.entry_time) }} - {{ formatTime(schedule.exit_time) }}
                        </p>
                    </div>
                </template>

                <template v-else>
                    <!-- Single Schedule (same for all days) -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Times -->
                        <div class="bg-white rounded-lg shadow p-6">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Horarios</h3>
                            <div class="space-y-3">
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Hora de Entrada</span>
                                    <span class="font-medium">{{ formatTime(schedule.entry_time) }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Hora de Salida</span>
                                    <span class="font-medium">{{ formatTime(schedule.exit_time) }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Inicio de Descanso</span>
                                    <span class="font-medium">{{ formatTime(schedule.break_start) }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Fin de Descanso</span>
                                    <span class="font-medium">{{ formatTime(schedule.break_end) }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Configuration -->
                        <div class="bg-white rounded-lg shadow p-6">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Configuracion</h3>
                            <div class="space-y-3">
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Horas Diarias</span>
                                    <span class="font-medium">{{ schedule.daily_work_hours }}h</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Minutos de Descanso</span>
                                    <span class="font-medium">{{ schedule.break_minutes }} min</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Tolerancia de Retardo</span>
                                    <span class="font-medium">{{ schedule.late_tolerance_minutes }} min</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Empleados Asignados</span>
                                    <span class="font-medium">{{ schedule.employees_count ?? 0 }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- Configuration (shown below per-day table) -->
                <div v-if="hasPerDaySchedules" class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Configuracion</h3>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                        <div>
                            <span class="text-gray-500 text-sm">Tolerancia de Retardo</span>
                            <p class="font-medium">{{ schedule.late_tolerance_minutes }} min</p>
                        </div>
                        <div>
                            <span class="text-gray-500 text-sm">Empleados Asignados</span>
                            <p class="font-medium">{{ schedule.employees_count ?? 0 }}</p>
                        </div>
                    </div>
                </div>

                <!-- Working Days -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Dias Laborales</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
                        <div
                            v-for="day in allDays"
                            :key="day"
                            :class="[
                                'flex items-center justify-center px-4 py-3 rounded-lg border-2 text-sm font-medium',
                                schedule.working_days?.includes(day)
                                    ? 'border-pink-500 bg-pink-50 text-pink-700'
                                    : 'border-gray-200 bg-gray-50 text-gray-400'
                            ]"
                        >
                            {{ dayLabels[day] }}
                        </div>
                    </div>
                </div>

                <!-- Assigned Employees -->
                <div class="bg-white rounded-lg shadow">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">
                            Empleados Asignados
                            <span class="text-sm font-normal text-gray-500 ml-2">
                                ({{ schedule.employees?.length ?? 0 }})
                            </span>
                        </h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Empleado
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        No. Empleado
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Departamento
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Estado
                                    </th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Acciones
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <tr v-for="employee in schedule.employees" :key="employee.id" class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-8 h-8 rounded-full bg-pink-100 flex items-center justify-center">
                                                <span class="text-pink-600 text-sm font-medium">
                                                    {{ employee.full_name?.charAt(0)?.toUpperCase() || '?' }}
                                                </span>
                                            </div>
                                            <div class="ml-3">
                                                <div class="text-sm font-medium text-gray-900">{{ employee.full_name }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ employee.employee_number }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ employee.department?.name || '-' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span :class="[statusColors[employee.status] || 'bg-gray-100 text-gray-800', 'px-2 py-1 text-xs font-medium rounded-full']">
                                            {{ statusLabels[employee.status] || employee.status }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <Link
                                            :href="route('employees.show', employee.id)"
                                            class="text-pink-600 hover:text-pink-900"
                                        >
                                            Ver
                                        </Link>
                                    </td>
                                </tr>
                                <tr v-if="!schedule.employees?.length">
                                    <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                                        No hay empleados asignados a este horario
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">
                <!-- Quick Stats -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Resumen</h3>
                    <div class="text-center">
                        <div class="text-4xl font-bold text-pink-600">
                            {{ schedule.employees_count ?? 0 }}
                        </div>
                        <p class="text-gray-500 mt-1">empleados asignados</p>
                    </div>
                    <div class="mt-4 pt-4 border-t border-gray-200">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500">Horas diarias</span>
                            <span>{{ schedule.daily_work_hours }}h</span>
                        </div>
                        <div class="flex justify-between text-sm mt-2">
                            <span class="text-gray-500">Dias laborales</span>
                            <span>{{ schedule.working_days?.length ?? 0 }} dias</span>
                        </div>
                        <div class="flex justify-between text-sm mt-2">
                            <span class="text-gray-500">Horas semanales</span>
                            <span>{{ (schedule.daily_work_hours * (schedule.working_days?.length ?? 0)).toFixed(1) }}h</span>
                        </div>
                    </div>
                </div>

                <!-- Schedule Visual -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">
                        {{ hasPerDaySchedules ? 'Horario Predeterminado' : 'Jornada' }}
                    </h3>
                    <div class="space-y-3">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 text-green-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
                            </svg>
                            <div>
                                <p class="text-sm text-gray-500">Entrada</p>
                                <p class="font-medium">{{ formatTime(schedule.entry_time) }}</p>
                            </div>
                        </div>
                        <div v-if="schedule.break_start" class="flex items-center">
                            <svg class="w-5 h-5 text-yellow-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <div>
                                <p class="text-sm text-gray-500">Descanso</p>
                                <p class="font-medium">{{ formatTime(schedule.break_start) }} - {{ formatTime(schedule.break_end) }}</p>
                            </div>
                        </div>
                        <div class="flex items-center">
                            <svg class="w-5 h-5 text-red-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                            </svg>
                            <div>
                                <p class="text-sm text-gray-500">Salida</p>
                                <p class="font-medium">{{ formatTime(schedule.exit_time) }}</p>
                            </div>
                        </div>
                    </div>
                    <p v-if="hasPerDaySchedules" class="mt-3 text-xs text-gray-400">
                        Valores por defecto para dias sin horario personalizado.
                    </p>
                </div>

                <!-- Tolerance Info -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Tolerancias</h3>
                    <div class="space-y-3">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500">Retardo permitido</span>
                            <span class="font-medium">{{ schedule.late_tolerance_minutes }} min</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500">Descanso</span>
                            <span class="font-medium">{{ schedule.break_minutes }} min</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    record: Object,
});

const statusColors = {
    present: 'bg-green-100 text-green-800 border-green-300',
    late: 'bg-yellow-100 text-yellow-800 border-yellow-300',
    absent: 'bg-red-100 text-red-800 border-red-300',
    partial: 'bg-orange-100 text-orange-800 border-orange-300',
    holiday: 'bg-blue-100 text-blue-800 border-blue-300',
    vacation: 'bg-purple-100 text-purple-800 border-purple-300',
    sick_leave: 'bg-pink-100 text-pink-800 border-pink-300',
    permission: 'bg-indigo-100 text-indigo-800 border-indigo-300',
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

const formatDate = (dateStr) => {
    if (!dateStr) return '-';
    const [year, month, day] = dateStr.split('-').map(Number);
    const date = new Date(year, month - 1, day);
    return date.toLocaleDateString('es-MX', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
};

const formatDateTime = (datetime) => {
    if (!datetime) return '-';
    return new Date(datetime).toLocaleString('es-MX');
};

const formatTime = (time) => {
    if (!time) return '-';
    return time.substring(0, 5);
};

const formatHours = (hours) => {
    if (hours === null || hours === undefined) return '-';
    return `${parseFloat(hours).toFixed(2)}h`;
};
</script>

<template>
    <Head :title="`Asistencia #${record.id}`" />

    <AppLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Detalle de Asistencia
                </h2>
                <span :class="[statusColors[record.status], 'px-3 py-1 text-sm font-medium rounded-full border']">
                    {{ statusLabels[record.status] || record.status }}
                </span>
            </div>
        </template>

        <!-- Breadcrumb -->
        <div class="mb-6">
            <Link :href="route('attendance.index', { date: record.work_date })" class="text-pink-600 hover:text-pink-800">
                &larr; Volver a asistencia
            </Link>
        </div>

        <div class="max-w-4xl space-y-6">
            <!-- Employee Info Card -->
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="w-16 h-16 rounded-full bg-pink-100 flex items-center justify-center">
                        <span class="text-2xl text-pink-600 font-bold">
                            {{ record.employee?.full_name?.charAt(0) || '?' }}
                        </span>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">{{ record.employee?.full_name }}</h3>
                        <p class="text-sm text-gray-500">{{ record.employee?.employee_number }}</p>
                        <div class="flex flex-wrap gap-2 mt-1 text-sm text-gray-500">
                            <span v-if="record.employee?.department">{{ record.employee.department.name }}</span>
                            <span v-if="record.employee?.department && record.employee?.position" class="text-gray-300">|</span>
                            <span v-if="record.employee?.position">{{ record.employee.position.name }}</span>
                            <span v-if="record.employee?.schedule" class="text-gray-300">|</span>
                            <span v-if="record.employee?.schedule">{{ record.employee.schedule.name }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Attendance Details Card -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-6">Registro de Asistencia</h3>

                <dl class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Fecha de Trabajo</dt>
                        <dd class="mt-1 text-sm text-gray-900 capitalize">{{ formatDate(record.work_date) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Estado</dt>
                        <dd class="mt-1">
                            <span :class="[statusColors[record.status], 'px-2 py-1 text-xs font-medium rounded-full border']">
                                {{ statusLabels[record.status] || record.status }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Hora de Entrada</dt>
                        <dd class="mt-1 text-sm text-gray-900 font-mono">{{ formatTime(record.check_in) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Hora de Salida</dt>
                        <dd class="mt-1 text-sm text-gray-900 font-mono">{{ formatTime(record.check_out) }}</dd>
                    </div>
                </dl>
            </div>

            <!-- Hours Breakdown Card -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-6">Desglose de Horas</h3>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="bg-gray-50 rounded-lg p-4 text-center">
                        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Horas Trabajadas</p>
                        <p class="text-2xl font-bold text-gray-800">{{ formatHours(record.worked_hours) }}</p>
                    </div>
                    <div class="bg-green-50 rounded-lg p-4 text-center">
                        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Horas Extra</p>
                        <p class="text-2xl font-bold" :class="parseFloat(record.overtime_hours) > 0 ? 'text-green-600' : 'text-gray-400'">
                            {{ formatHours(record.overtime_hours) }}
                        </p>
                    </div>
                    <div class="bg-indigo-50 rounded-lg p-4 text-center">
                        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Horas Velada</p>
                        <p class="text-2xl font-bold" :class="parseFloat(record.velada_hours) > 0 ? 'text-indigo-600' : 'text-gray-400'">
                            {{ formatHours(record.velada_hours) }}
                        </p>
                    </div>
                    <div class="rounded-lg p-4 text-center" :class="record.late_minutes > 0 ? 'bg-yellow-50' : 'bg-gray-50'">
                        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Min. Retardo</p>
                        <p class="text-2xl font-bold" :class="record.late_minutes > 0 ? 'text-yellow-600' : 'text-gray-400'">
                            {{ record.late_minutes ?? 0 }}
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                    <div class="rounded-lg p-4 text-center" :class="record.early_departure_minutes > 0 ? 'bg-orange-50' : 'bg-gray-50'">
                        <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Min. Salida Temprana</p>
                        <p class="text-2xl font-bold" :class="record.early_departure_minutes > 0 ? 'text-orange-600' : 'text-gray-400'">
                            {{ record.early_departure_minutes ?? 0 }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Notes Card -->
            <div v-if="record.notes" class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Notas</h3>
                <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ record.notes }}</p>
            </div>

            <!-- Manual Edit Info Card -->
            <div v-if="record.manually_edited_at" class="bg-yellow-50 border border-yellow-200 rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-yellow-800 mb-4 flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                    Edicion Manual
                </h3>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-sm font-medium text-yellow-700">Editado por</dt>
                        <dd class="mt-1 text-sm text-yellow-900">{{ record.manually_edited_by?.name || 'Desconocido' }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-yellow-700">Fecha de edicion</dt>
                        <dd class="mt-1 text-sm text-yellow-900">{{ formatDateTime(record.manually_edited_at) }}</dd>
                    </div>
                    <div v-if="record.manual_edit_reason">
                        <dt class="text-sm font-medium text-yellow-700">Motivo</dt>
                        <dd class="mt-1 text-sm text-yellow-900 whitespace-pre-wrap">{{ record.manual_edit_reason }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </AppLayout>
</template>

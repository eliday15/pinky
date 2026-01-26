<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    record: Object,
});

const form = useForm({
    check_in: props.record.check_in?.substring(0, 5) || '',
    check_out: props.record.check_out?.substring(0, 5) || '',
    status: props.record.status,
    notes: props.record.notes || '',
});

const submit = () => {
    form.put(route('attendance.update', props.record.id));
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

// Parse time string "HH:MM:SS" to minutes
const timeToMinutes = (timeStr) => {
    if (!timeStr) return 0;
    const parts = timeStr.split(':');
    return parseInt(parts[0]) * 60 + parseInt(parts[1]);
};

// Calculate schedule daily hours
const scheduleDailyHours = computed(() => {
    const schedule = props.record.employee?.schedule;
    if (!schedule) return 9;

    if (schedule.daily_hours) return schedule.daily_hours;

    // Calculate from entry/exit times
    const entryMinutes = timeToMinutes(schedule.entry_time);
    const exitMinutes = timeToMinutes(schedule.exit_time);
    const breakMinutes = schedule.break_minutes || 60;
    return ((exitMinutes - entryMinutes) - breakMinutes) / 60;
});

// Calculate gross hours worked (reactive to form changes)
const grossHours = computed(() => {
    const checkIn = form.check_in || props.record.check_in;
    const checkOut = form.check_out || props.record.check_out;
    if (!checkIn || !checkOut) return 0;

    const inMinutes = timeToMinutes(checkIn);
    const outMinutes = timeToMinutes(checkOut);

    // Handle overnight shifts (check_out < check_in)
    let diffMinutes = outMinutes - inMinutes;
    if (diffMinutes < 0) {
        diffMinutes += 24 * 60; // Add 24 hours in minutes
    }

    return (diffMinutes / 60).toFixed(2);
});

// Get break minutes
const breakMinutes = computed(() => {
    return props.record.employee?.schedule?.break_minutes || 60;
});

// Calculate net hours (gross - break)
const netHours = computed(() => {
    const net = parseFloat(grossHours.value) - (breakMinutes.value / 60);
    return Math.max(0, net).toFixed(2);
});

// Calculate regular hours (capped at daily hours)
const regularHours = computed(() => {
    const net = parseFloat(netHours.value);
    return Math.min(net, scheduleDailyHours.value).toFixed(2);
});

// Calculate overtime hours
const overtimeHours = computed(() => {
    const net = parseFloat(netHours.value);
    const overtime = net - scheduleDailyHours.value;
    return Math.max(0, overtime).toFixed(2);
});
</script>

<template>
    <Head title="Editar Asistencia" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Editar Registro de Asistencia
            </h2>
        </template>

        <div class="max-w-2xl">
            <div class="mb-6">
                <Link
                    :href="route('attendance.index', { date: record.work_date })"
                    class="text-pink-600 hover:text-pink-800"
                >
                    &larr; Volver a asistencia
                </Link>
            </div>

            <!-- Employee Info -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <div class="flex items-center">
                    <div class="w-16 h-16 rounded-full bg-pink-100 flex items-center justify-center">
                        <span class="text-2xl text-pink-600 font-bold">
                            {{ record.employee?.full_name?.charAt(0) || '?' }}
                        </span>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">{{ record.employee?.full_name }}</h3>
                        <p class="text-gray-500">{{ record.employee?.employee_number }}</p>
                        <p class="text-sm text-gray-500 capitalize mt-1">{{ formatDate(record.work_date) }}</p>
                    </div>
                </div>
            </div>

            <form @submit.prevent="submit" class="bg-white rounded-lg shadow p-6 space-y-6">
                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Hora de Entrada
                        </label>
                        <input
                            v-model="form.check_in"
                            type="time"
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        />
                        <p class="mt-1 text-xs text-gray-500">
                            Horario esperado: {{ record.employee?.schedule?.entry_time || 'N/A' }}
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Hora de Salida
                        </label>
                        <input
                            v-model="form.check_out"
                            type="time"
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        />
                        <p class="mt-1 text-xs text-gray-500">
                            Horario esperado: {{ record.employee?.schedule?.exit_time || 'N/A' }}
                        </p>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Estado
                    </label>
                    <select
                        v-model="form.status"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    >
                        <option value="present">Presente</option>
                        <option value="late">Retardo</option>
                        <option value="absent">Ausente</option>
                        <option value="partial">Parcial</option>
                        <option value="holiday">Dia Festivo</option>
                        <option value="vacation">Vacaciones</option>
                        <option value="sick_leave">Incapacidad</option>
                        <option value="permission">Permiso</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Notas
                    </label>
                    <textarea
                        v-model="form.notes"
                        rows="3"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        placeholder="Notas adicionales..."
                    ></textarea>
                </div>

                <!-- Calculation Breakdown -->
                <div class="bg-gradient-to-br from-gray-50 to-gray-100 rounded-lg p-5 border border-gray-200">
                    <h4 class="text-sm font-semibold text-gray-800 mb-4 flex items-center">
                        <svg class="w-4 h-4 mr-2 text-pink-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                        </svg>
                        Desglose del Calculo
                    </h4>

                    <!-- Time calculation -->
                    <div class="space-y-3">
                        <!-- Gross hours -->
                        <div class="flex items-center justify-between py-2 border-b border-gray-200">
                            <div class="flex items-center">
                                <span class="w-6 h-6 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center text-xs font-bold mr-3">1</span>
                                <span class="text-sm text-gray-600">Tiempo bruto</span>
                                <span class="text-xs text-gray-400 ml-2">({{ form.check_in || '--:--' }} → {{ form.check_out || '--:--' }})</span>
                            </div>
                            <span class="font-mono font-semibold text-gray-800">{{ grossHours }}h</span>
                        </div>

                        <!-- Break deduction -->
                        <div class="flex items-center justify-between py-2 border-b border-gray-200">
                            <div class="flex items-center">
                                <span class="w-6 h-6 rounded-full bg-orange-100 text-orange-600 flex items-center justify-center text-xs font-bold mr-3">2</span>
                                <span class="text-sm text-gray-600">Descanso/Almuerzo</span>
                            </div>
                            <span class="font-mono font-semibold text-red-500">-{{ (breakMinutes / 60).toFixed(2) }}h</span>
                        </div>

                        <!-- Net hours -->
                        <div class="flex items-center justify-between py-2 border-b border-gray-200 bg-blue-50 -mx-2 px-2 rounded">
                            <div class="flex items-center">
                                <span class="w-6 h-6 rounded-full bg-blue-500 text-white flex items-center justify-center text-xs font-bold mr-3">=</span>
                                <span class="text-sm font-medium text-gray-700">Horas netas trabajadas</span>
                            </div>
                            <span class="font-mono font-bold text-blue-600">{{ netHours }}h</span>
                        </div>

                        <!-- Schedule hours -->
                        <div class="flex items-center justify-between py-2 border-b border-gray-200">
                            <div class="flex items-center">
                                <span class="w-6 h-6 rounded-full bg-gray-200 text-gray-600 flex items-center justify-center text-xs mr-3">
                                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd" />
                                    </svg>
                                </span>
                                <span class="text-sm text-gray-600">Jornada regular</span>
                                <span class="text-xs text-gray-400 ml-2">({{ record.employee?.schedule?.name }})</span>
                            </div>
                            <span class="font-mono text-gray-600">{{ scheduleDailyHours.toFixed(2) }}h</span>
                        </div>

                        <!-- Final breakdown -->
                        <div class="mt-4 pt-4 border-t-2 border-gray-300">
                            <div class="grid grid-cols-2 gap-4">
                                <div class="bg-white rounded-lg p-3 border border-gray-200 text-center">
                                    <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Horas Regulares</p>
                                    <p class="text-2xl font-bold text-gray-800">{{ regularHours }}h</p>
                                    <p class="text-xs text-gray-400">max {{ scheduleDailyHours.toFixed(0) }}h/dia</p>
                                </div>
                                <div class="bg-white rounded-lg p-3 border border-green-200 text-center" :class="parseFloat(overtimeHours) > 0 ? 'bg-green-50' : ''">
                                    <p class="text-xs text-gray-500 uppercase tracking-wide mb-1">Horas Extra</p>
                                    <p class="text-2xl font-bold" :class="parseFloat(overtimeHours) > 0 ? 'text-green-600' : 'text-gray-400'">
                                        {{ parseFloat(overtimeHours) > 0 ? '+' : '' }}{{ overtimeHours }}h
                                    </p>
                                    <p class="text-xs text-gray-400">{{ netHours }}h - {{ scheduleDailyHours.toFixed(0) }}h</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Additional Info -->
                <div class="bg-gray-50 rounded-lg p-4">
                    <h4 class="text-sm font-medium text-gray-700 mb-2">Informacion Adicional</h4>
                    <div class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <span class="text-gray-500">Minutos retardo:</span>
                            <span class="ml-2 font-medium" :class="record.late_minutes > 0 ? 'text-yellow-600' : ''">
                                {{ record.late_minutes }} min
                            </span>
                        </div>
                        <div>
                            <span class="text-gray-500">Requiere revision:</span>
                            <span class="ml-2 font-medium" :class="record.requires_review ? 'text-red-600' : 'text-green-600'">
                                {{ record.requires_review ? 'Si' : 'No' }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Raw Punches -->
                <div v-if="record.raw_punches?.length" class="bg-gray-50 rounded-lg p-4">
                    <h4 class="text-sm font-medium text-gray-700 mb-2">Checadas Originales</h4>
                    <div class="space-y-1">
                        <div v-for="(punch, idx) in record.raw_punches" :key="idx" class="text-sm flex items-center">
                            <span :class="[
                                'w-16 px-2 py-0.5 rounded text-xs font-medium mr-2',
                                punch.type === 'in' ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700'
                            ]">
                                {{ punch.type === 'in' ? 'Entrada' : 'Salida' }}
                            </span>
                            <span class="font-mono">{{ punch.time }}</span>
                            <span class="ml-2 text-gray-400 text-xs">({{ punch.method }})</span>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end space-x-4">
                    <Link
                        :href="route('attendance.index', { date: record.work_date })"
                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50"
                    >
                        Cancelar
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-6 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 disabled:opacity-50"
                    >
                        {{ form.processing ? 'Guardando...' : 'Guardar Cambios' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

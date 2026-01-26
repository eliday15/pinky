<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    entry: Object,
});

const formatDate = (date) => {
    return new Date(date).toLocaleDateString('es-MX', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
};

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
    }).format(amount || 0);
};

const breakdown = props.entry.calculation_breakdown || {};
</script>

<template>
    <Head :title="`Nomina: ${entry.employee?.full_name}`" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Detalle de Nomina Individual
            </h2>
        </template>

        <div class="mb-6">
            <Link
                :href="route('payroll.show', entry.payroll_period_id)"
                class="text-pink-600 hover:text-pink-800"
            >
                &larr; Volver al periodo
            </Link>
        </div>

        <!-- Employee Header -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <div class="flex items-center">
                <div class="w-16 h-16 rounded-full bg-pink-100 flex items-center justify-center">
                    <span class="text-2xl text-pink-600 font-bold">
                        {{ entry.employee?.full_name?.charAt(0) || '?' }}
                    </span>
                </div>
                <div class="ml-4">
                    <h1 class="text-2xl font-bold text-gray-800">{{ entry.employee?.full_name }}</h1>
                    <p class="text-gray-500">{{ entry.employee?.employee_number }}</p>
                    <p class="text-sm text-gray-500">
                        {{ entry.employee?.department?.name }} | {{ entry.employee?.position?.name }}
                    </p>
                </div>
                <div class="ml-auto text-right">
                    <p class="text-3xl font-bold text-green-600">{{ formatCurrency(entry.net_pay) }}</p>
                    <p class="text-sm text-gray-500">Pago Neto</p>
                </div>
            </div>
        </div>

        <!-- Period Info -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Periodo</h3>
            <div class="grid grid-cols-3 gap-4 text-sm">
                <div>
                    <span class="text-gray-500">Periodo:</span>
                    <p class="font-medium">{{ entry.payroll_period?.name }}</p>
                </div>
                <div>
                    <span class="text-gray-500">Fechas:</span>
                    <p class="font-medium">
                        {{ formatDate(entry.payroll_period?.start_date) }} - {{ formatDate(entry.payroll_period?.end_date) }}
                    </p>
                </div>
                <div>
                    <span class="text-gray-500">Horario:</span>
                    <p class="font-medium">{{ entry.employee?.schedule?.name || 'N/A' }}</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Hours Breakdown -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Desglose de Horas</h3>
                <div class="space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Horas regulares</span>
                        <span class="font-medium">{{ entry.regular_hours }}h</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Horas extra</span>
                        <span class="font-medium text-green-600">{{ entry.overtime_hours }}h</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Horas en dias festivos</span>
                        <span class="font-medium text-blue-600">{{ entry.holiday_hours }}h</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Horas en fin de semana</span>
                        <span class="font-medium text-purple-600">{{ entry.weekend_hours }}h</span>
                    </div>
                    <div class="border-t pt-3 flex justify-between items-center">
                        <span class="text-gray-800 font-medium">Total de horas</span>
                        <span class="font-bold text-gray-800">
                            {{ (parseFloat(entry.regular_hours) + parseFloat(entry.overtime_hours) + parseFloat(entry.holiday_hours) + parseFloat(entry.weekend_hours)).toFixed(2) }}h
                        </span>
                    </div>
                </div>
            </div>

            <!-- Days Breakdown -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Desglose de Dias</h3>
                <div class="space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Dias trabajados</span>
                        <span class="font-medium text-green-600">{{ entry.days_worked }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Dias con retardo</span>
                        <span class="font-medium text-yellow-600">{{ entry.days_late }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Dias ausente</span>
                        <span class="font-medium text-red-600">{{ entry.days_absent }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Dias de vacaciones pagados</span>
                        <span class="font-medium text-purple-600">{{ entry.vacation_days_paid }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rates Applied -->
        <div class="bg-white rounded-lg shadow p-6 mt-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Tasas Aplicadas</h3>
            <div class="grid grid-cols-3 gap-4 text-sm">
                <div class="bg-gray-50 rounded-lg p-4 text-center">
                    <p class="text-2xl font-bold text-gray-800">{{ formatCurrency(entry.hourly_rate) }}</p>
                    <p class="text-gray-500">Tarifa por hora</p>
                </div>
                <div class="bg-gray-50 rounded-lg p-4 text-center">
                    <p class="text-2xl font-bold text-green-600">{{ entry.overtime_multiplier }}x</p>
                    <p class="text-gray-500">Multiplicador horas extra</p>
                </div>
                <div class="bg-gray-50 rounded-lg p-4 text-center">
                    <p class="text-2xl font-bold text-blue-600">{{ entry.holiday_multiplier }}x</p>
                    <p class="text-gray-500">Multiplicador dias festivos</p>
                </div>
            </div>
        </div>

        <!-- Payment Breakdown -->
        <div class="bg-white rounded-lg shadow p-6 mt-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Calculo de Pago</h3>
            <div class="space-y-3">
                <div class="flex justify-between items-center py-2 border-b">
                    <div>
                        <span class="text-gray-600">Pago regular</span>
                        <span class="text-xs text-gray-400 ml-2">
                            ({{ entry.regular_hours }}h x {{ formatCurrency(entry.hourly_rate) }})
                        </span>
                    </div>
                    <span class="font-medium">{{ formatCurrency(entry.regular_pay) }}</span>
                </div>
                <div class="flex justify-between items-center py-2 border-b">
                    <div>
                        <span class="text-gray-600">Pago horas extra</span>
                        <span class="text-xs text-gray-400 ml-2">
                            ({{ entry.overtime_hours }}h x {{ formatCurrency(entry.hourly_rate) }} x {{ entry.overtime_multiplier }})
                        </span>
                    </div>
                    <span class="font-medium text-green-600">{{ formatCurrency(entry.overtime_pay) }}</span>
                </div>
                <div class="flex justify-between items-center py-2 border-b">
                    <div>
                        <span class="text-gray-600">Pago dias festivos</span>
                        <span class="text-xs text-gray-400 ml-2">
                            ({{ entry.holiday_hours }}h x {{ formatCurrency(entry.hourly_rate) }} x {{ entry.holiday_multiplier }})
                        </span>
                    </div>
                    <span class="font-medium text-blue-600">{{ formatCurrency(entry.holiday_pay) }}</span>
                </div>
                <div class="flex justify-between items-center py-2 border-b">
                    <div>
                        <span class="text-gray-600">Pago fin de semana</span>
                    </div>
                    <span class="font-medium text-purple-600">{{ formatCurrency(entry.weekend_pay) }}</span>
                </div>
                <div class="flex justify-between items-center py-2 border-b">
                    <div>
                        <span class="text-gray-600">Pago vacaciones</span>
                        <span class="text-xs text-gray-400 ml-2">
                            ({{ entry.vacation_days_paid }} dias)
                        </span>
                    </div>
                    <span class="font-medium">{{ formatCurrency(entry.vacation_pay) }}</span>
                </div>
                <div v-if="entry.bonuses > 0" class="flex justify-between items-center py-2 border-b">
                    <span class="text-gray-600">Bonos</span>
                    <span class="font-medium text-green-600">{{ formatCurrency(entry.bonuses) }}</span>
                </div>
                <div class="flex justify-between items-center py-2 bg-gray-50 px-4 rounded-lg">
                    <span class="font-medium text-gray-800">Pago Bruto</span>
                    <span class="font-bold text-gray-800">{{ formatCurrency(entry.gross_pay) }}</span>
                </div>
                <div v-if="entry.deductions > 0" class="flex justify-between items-center py-2 border-b">
                    <div>
                        <span class="text-gray-600">Deducciones</span>
                        <span class="text-xs text-gray-400 ml-2">(faltas sin goce)</span>
                    </div>
                    <span class="font-medium text-red-600">-{{ formatCurrency(entry.deductions) }}</span>
                </div>
                <div class="flex justify-between items-center py-4 bg-green-50 px-4 rounded-lg mt-4">
                    <span class="font-bold text-gray-800 text-lg">Pago Neto</span>
                    <span class="font-bold text-green-600 text-2xl">{{ formatCurrency(entry.net_pay) }}</span>
                </div>
            </div>
        </div>

        <!-- Incidents Summary (from breakdown) -->
        <div v-if="breakdown.incidents" class="bg-white rounded-lg shadow p-6 mt-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Incidencias en el Periodo</h3>
            <div class="grid grid-cols-5 gap-4">
                <div class="text-center">
                    <p class="text-xl font-bold text-purple-600">{{ breakdown.incidents.vacation_days || 0 }}</p>
                    <p class="text-xs text-gray-500">Vacaciones</p>
                </div>
                <div class="text-center">
                    <p class="text-xl font-bold text-pink-600">{{ breakdown.incidents.sick_leave_days || 0 }}</p>
                    <p class="text-xs text-gray-500">Incapacidad</p>
                </div>
                <div class="text-center">
                    <p class="text-xl font-bold text-blue-600">{{ breakdown.incidents.permission_days || 0 }}</p>
                    <p class="text-xs text-gray-500">Permisos</p>
                </div>
                <div class="text-center">
                    <p class="text-xl font-bold text-red-600">{{ breakdown.incidents.absence_days || 0 }}</p>
                    <p class="text-xs text-gray-500">Faltas</p>
                </div>
                <div class="text-center">
                    <p class="text-xl font-bold text-orange-600">{{ breakdown.incidents.unpaid_days || 0 }}</p>
                    <p class="text-xs text-gray-500">Sin Goce</p>
                </div>
            </div>
        </div>

        <!-- Back Button -->
        <div class="mt-6">
            <Link
                :href="route('payroll.show', entry.payroll_period_id)"
                class="px-6 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200"
            >
                Volver al Periodo
            </Link>
        </div>
    </AppLayout>
</template>

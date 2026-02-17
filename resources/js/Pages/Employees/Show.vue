<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    employee: Object,
    auditHistory: Array,
    can: Object,
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

const positionTypeLabels = {
    operativo: 'Operativo',
    administrativo: 'Administrativo',
    gerencial: 'Gerencial',
    directivo: 'Directivo',
};

const attendanceStatusColors = {
    present: 'bg-green-100 text-green-800',
    late: 'bg-yellow-100 text-yellow-800',
    absent: 'bg-red-100 text-red-800',
    partial: 'bg-orange-100 text-orange-800',
    vacation: 'bg-blue-100 text-blue-800',
    sick_leave: 'bg-purple-100 text-purple-800',
};

const formatDate = (date) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('es-MX');
};

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
    }).format(amount);
};

/**
 * Determine if the supervisor was auto-resolved via position or set manually.
 */
const supervisorIsAutoResolved = props.employee.position?.supervisor_position_id && props.employee.supervisor?.position_id === props.employee.position?.supervisor_position_id;
</script>

<template>
    <Head :title="employee.full_name" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Detalle de Empleado
            </h2>
        </template>

        <!-- Breadcrumb -->
        <div class="mb-6 flex items-center justify-between">
            <Link :href="route('employees.index')" class="text-pink-600 hover:text-pink-800">
                &larr; Volver a empleados
            </Link>
            <Link
                v-if="can?.edit"
                :href="route('employees.edit', employee.id)"
                class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors"
            >
                Editar Empleado
            </Link>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Main Info -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Employee Header -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-start">
                        <div class="w-20 h-20 rounded-full bg-pink-100 flex items-center justify-center overflow-hidden">
                            <img v-if="employee.photo_path" :src="`/storage/${employee.photo_path}`" class="w-full h-full object-cover" />
                            <span v-else class="text-3xl text-pink-600 font-bold">
                                {{ employee.full_name?.charAt(0)?.toUpperCase() || '?' }}
                            </span>
                        </div>
                        <div class="ml-6 flex-1">
                            <div class="flex items-center flex-wrap gap-2">
                                <h1 class="text-2xl font-bold text-gray-800">{{ employee.full_name }}</h1>
                                <span :class="[statusColors[employee.status], 'px-3 py-1 text-sm font-medium rounded-full']">
                                    {{ statusLabels[employee.status] }}
                                </span>
                                <span v-if="employee.is_minimum_wage" class="px-3 py-1 text-sm font-medium rounded-full bg-orange-100 text-orange-800">
                                    Salario Minimo
                                </span>
                                <span v-if="employee.is_trial_period" class="px-3 py-1 text-sm font-medium rounded-full bg-amber-100 text-amber-800">
                                    En Prueba
                                </span>
                            </div>
                            <p class="text-gray-500 mt-1">{{ employee.position?.name }} - {{ employee.department?.name }}</p>
                            <div class="mt-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                <div>
                                    <p class="text-gray-500">No. Empleado</p>
                                    <p class="font-medium">{{ employee.employee_number }}</p>
                                </div>
                                <div>
                                    <p class="text-gray-500">Codigo CONTPAQi</p>
                                    <p class="font-medium">{{ employee.contpaqi_code || employee.employee_number }}</p>
                                </div>
                                <div>
                                    <p class="text-gray-500">ID ZKTeco</p>
                                    <p class="font-medium">{{ employee.zkteco_user_id }}</p>
                                </div>
                                <div>
                                    <p class="text-gray-500">Fecha Ingreso</p>
                                    <p class="font-medium">{{ formatDate(employee.hire_date) }}</p>
                                </div>
                                <div v-if="employee.termination_date">
                                    <p class="text-gray-500">Fecha Baja</p>
                                    <p class="font-medium text-red-600">{{ formatDate(employee.termination_date) }}</p>
                                </div>
                                <div>
                                    <p class="text-gray-500">Tipo de Puesto</p>
                                    <p class="font-medium">{{ positionTypeLabels[employee.position?.position_type] || employee.position?.position_type || '-' }}</p>
                                </div>
                                <div>
                                    <p class="text-gray-500">Horario</p>
                                    <p class="font-medium">
                                        {{ employee.schedule?.name || '-' }}
                                        <span v-if="employee.schedule" class="text-xs text-gray-400">
                                            ({{ employee.schedule.is_flexible ? 'Flexible' : 'Fijo' }})
                                        </span>
                                    </p>
                                </div>
                                <div>
                                    <p class="text-gray-500">Jefe Directo</p>
                                    <p class="font-medium">
                                        {{ employee.supervisor?.full_name || 'Sin asignar' }}
                                        <span v-if="employee.supervisor" class="text-xs text-gray-400">
                                            ({{ supervisorIsAutoResolved ? 'Auto via puesto' : 'Manual' }})
                                        </span>
                                    </p>
                                </div>
                                <div v-if="employee.imss_number">
                                    <p class="text-gray-500">IMSS</p>
                                    <p class="font-medium">{{ employee.imss_number }}</p>
                                </div>
                                <div v-if="employee.credential_type">
                                    <p class="text-gray-500">{{ employee.credential_type }}</p>
                                    <p class="font-medium">{{ employee.credential_number || '-' }}</p>
                                </div>
                                <div v-if="employee.is_trial_period">
                                    <p class="text-gray-500">Fin Periodo Prueba</p>
                                    <p class="font-medium text-amber-600">{{ employee.trial_period_end_date ? formatDate(employee.trial_period_end_date) : 'Sin fecha' }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Contact & Compensation -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-white rounded-lg shadow p-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Contacto</h3>
                        <div class="space-y-3">
                            <div class="flex items-center">
                                <svg class="w-5 h-5 text-gray-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                </svg>
                                <span>{{ employee.email || 'Sin email' }}</span>
                            </div>
                            <div class="flex items-center">
                                <svg class="w-5 h-5 text-gray-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                </svg>
                                <span>{{ employee.phone || 'Sin telefono' }}</span>
                            </div>
                            <div v-if="employee.emergency_phone" class="flex items-center">
                                <svg class="w-5 h-5 text-gray-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" />
                                </svg>
                                <span>{{ employee.emergency_phone }}</span>
                            </div>
                            <div v-if="employee.address_street" class="text-sm text-gray-600 mt-2 pt-2 border-t border-gray-100">
                                <p class="font-medium text-gray-700 mb-1">Domicilio</p>
                                <p>{{ employee.address_street }}</p>
                                <p v-if="employee.address_city || employee.address_state">{{ [employee.address_city, employee.address_state].filter(Boolean).join(', ') }} {{ employee.address_zip }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow p-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Compensacion</h3>
                        <div class="space-y-3">
                            <div class="flex justify-between">
                                <span class="text-gray-500">Tarifa por hora</span>
                                <span class="font-medium">{{ formatCurrency(employee.hourly_rate) }}</span>
                            </div>
                            <div v-if="employee.daily_salary" class="flex justify-between">
                                <span class="text-gray-500">Salario diario integrado</span>
                                <span class="font-medium">{{ formatCurrency(employee.daily_salary) }}</span>
                            </div>
                            <div v-if="employee.is_minimum_wage" class="flex justify-between">
                                <span class="text-gray-500">Salario Minimo</span>
                                <span class="font-medium text-orange-600">Si</span>
                            </div>
                            <div v-if="employee.monthly_bonus_type !== 'none'" class="flex justify-between">
                                <span class="text-gray-500">Bono mensual ({{ employee.monthly_bonus_type === 'fixed' ? 'Fijo' : 'Variable' }})</span>
                                <span class="font-medium">{{ formatCurrency(employee.monthly_bonus_amount) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Compensation Types -->
                <div v-if="employee.compensation_types?.length" class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Conceptos de Compensacion</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div
                            v-for="ct in employee.compensation_types"
                            :key="ct.id"
                            class="flex items-center justify-between p-3 bg-gray-50 rounded-lg"
                        >
                            <span class="text-sm font-medium text-gray-700">{{ ct.name }}</span>
                            <span class="text-sm text-gray-500">
                                <template v-if="ct.calculation_type === 'fixed'">
                                    Monto: ${{ Number(ct.pivot?.custom_fixed_amount || ct.fixed_amount || 0).toFixed(2) }}
                                </template>
                                <template v-else>
                                    Porcentaje: {{ ct.pivot?.custom_percentage || ct.percentage_value || 0 }}%
                                </template>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Recent Attendance -->
                <div class="bg-white rounded-lg shadow">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">Asistencia Reciente</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Entrada</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Salida</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Horas</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <tr v-for="record in employee.attendance_records" :key="record.id">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">{{ formatDate(record.work_date) }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">{{ record.check_in || '-' }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">{{ record.check_out || '-' }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">{{ record.worked_hours }}h</td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span :class="[attendanceStatusColors[record.status] || 'bg-gray-100 text-gray-800', 'px-2 py-1 text-xs font-medium rounded-full']">
                                            {{ record.status }}
                                        </span>
                                    </td>
                                </tr>
                                <tr v-if="!employee.attendance_records?.length">
                                    <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                                        Sin registros de asistencia
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="space-y-6">
                <!-- Vacation Balance -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Vacaciones</h3>
                    <div class="text-center">
                        <div class="text-4xl font-bold text-pink-600">
                            {{ Math.max(0, employee.vacation_days_entitled - employee.vacation_days_used - (employee.vacation_days_reserved || 0)) }}
                        </div>
                        <p class="text-gray-500 mt-1">dias disponibles</p>
                    </div>
                    <div class="mt-4 pt-4 border-t border-gray-200 space-y-2">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500">Asignados</span>
                            <span>{{ employee.vacation_days_entitled }} dias</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500">Usados</span>
                            <span>{{ employee.vacation_days_used }} dias</span>
                        </div>
                        <div v-if="employee.vacation_days_reserved" class="flex justify-between text-sm">
                            <span class="text-gray-500">Apartados</span>
                            <span>{{ employee.vacation_days_reserved }} dias</span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500">Prima vacacional</span>
                            <span>{{ employee.vacation_premium_percentage ?? 25 }}%</span>
                        </div>
                    </div>
                </div>

                <!-- Recent Incidents -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Incidencias Recientes</h3>
                    <div v-if="employee.incidents?.length" class="space-y-3">
                        <div
                            v-for="incident in employee.incidents"
                            :key="incident.id"
                            class="p-3 bg-gray-50 rounded-lg"
                        >
                            <div class="flex items-center justify-between">
                                <span
                                    class="px-2 py-1 text-xs font-medium rounded-full"
                                    :style="{ backgroundColor: incident.incident_type?.color + '20', color: incident.incident_type?.color }"
                                >
                                    {{ incident.incident_type?.name }}
                                </span>
                                <span class="text-xs text-gray-500">{{ incident.days_count }} dias</span>
                            </div>
                            <p class="text-sm text-gray-600 mt-2">
                                {{ formatDate(incident.start_date) }} - {{ formatDate(incident.end_date) }}
                            </p>
                        </div>
                    </div>
                    <p v-else class="text-gray-500 text-center py-4">Sin incidencias recientes</p>
                </div>

                <!-- FASE 5.2: Audit History -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Historial de Cambios</h3>
                    <div v-if="auditHistory?.length" class="space-y-3 max-h-64 overflow-y-auto">
                        <div
                            v-for="log in auditHistory"
                            :key="log.id"
                            class="p-3 bg-gray-50 rounded-lg text-sm"
                        >
                            <div class="flex items-center justify-between mb-1">
                                <span class="font-medium text-gray-900">
                                    {{ log.action === 'create' ? 'Creado' : log.action === 'update' ? 'Actualizado' : 'Eliminado' }}
                                </span>
                                <span class="text-xs text-gray-500">{{ log.created_at }}</span>
                            </div>
                            <p class="text-gray-600 text-xs">Por: {{ log.user_name }}</p>
                            <div v-if="log.old_values && Object.keys(log.old_values).length > 0" class="mt-2 text-xs">
                                <div v-for="(value, key) in log.new_values" :key="key" class="flex justify-between py-0.5">
                                    <span class="text-gray-500">{{ key }}:</span>
                                    <span>
                                        <span class="text-red-500 line-through mr-1">{{ log.old_values[key] }}</span>
                                        <span class="text-green-600">{{ value }}</span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <p v-else class="text-gray-500 text-center py-4">Sin historial de cambios</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    position: Object,
});

const positionTypeLabels = {
    operativo: 'Operativo',
    administrativo: 'Administrativo',
    gerencial: 'Gerencial',
    directivo: 'Directivo',
};

const positionTypeColors = {
    operativo: 'bg-blue-100 text-blue-800',
    administrativo: 'bg-green-100 text-green-800',
    gerencial: 'bg-purple-100 text-purple-800',
    directivo: 'bg-red-100 text-red-800',
};

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
    }).format(amount);
};

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
    <Head :title="position.name" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Detalle de Puesto
            </h2>
        </template>

        <!-- Breadcrumb -->
        <div class="mb-6 flex items-center justify-between">
            <Link :href="route('positions.index')" class="text-pink-600 hover:text-pink-800">
                &larr; Volver a puestos
            </Link>
            <Link
                :href="route('positions.edit', position.id)"
                class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors"
            >
                Editar Puesto
            </Link>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Main Info -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Position Header -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="flex items-center space-x-3">
                                <h1 class="text-2xl font-bold text-gray-800">{{ position.name }}</h1>
                                <span :class="[positionTypeColors[position.position_type] || 'bg-gray-100 text-gray-800', 'px-3 py-1 text-sm font-medium rounded-full']">
                                    {{ positionTypeLabels[position.position_type] || position.position_type }}
                                </span>
                                <span
                                    v-if="!position.is_active"
                                    class="px-3 py-1 text-sm font-medium rounded-full bg-red-100 text-red-800"
                                >
                                    Inactivo
                                </span>
                            </div>
                            <p class="text-gray-500 mt-1">Codigo: {{ position.code }}</p>
                        </div>
                    </div>

                    <p v-if="position.description" class="mt-4 text-gray-600">
                        {{ position.description }}
                    </p>

                    <div class="mt-6 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                        <div>
                            <p class="text-gray-500">Departamento</p>
                            <p class="font-medium">{{ position.department?.name || '-' }}</p>
                        </div>
                        <div>
                            <p class="text-gray-500">Puesto Supervisor</p>
                            <p class="font-medium">{{ position.supervisor_position?.name || 'Sin supervisor' }}</p>
                        </div>
                        <div>
                            <p class="text-gray-500">Horario por Defecto</p>
                            <p class="font-medium">{{ position.default_schedule?.name || '-' }}</p>
                        </div>
                        <div>
                            <p class="text-gray-500">Empleados Asignados</p>
                            <p class="font-medium">{{ position.employees?.length || 0 }}</p>
                        </div>
                    </div>
                </div>

                <!-- Compensation Rates -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Tarifas de Compensacion</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="text-center p-4 bg-gray-50 rounded-lg">
                            <p class="text-sm text-gray-500">Tarifa Base por Hora</p>
                            <p class="text-2xl font-bold text-gray-800 mt-1">{{ formatCurrency(position.base_hourly_rate) }}</p>
                        </div>
                        <div class="text-center p-4 bg-gray-50 rounded-lg">
                            <p class="text-sm text-gray-500">Mult. Hora Extra</p>
                            <p class="text-2xl font-bold text-gray-800 mt-1">x{{ Number(position.default_overtime_rate).toFixed(1) }}</p>
                        </div>
                        <div class="text-center p-4 bg-gray-50 rounded-lg">
                            <p class="text-sm text-gray-500">Mult. Dia Festivo</p>
                            <p class="text-2xl font-bold text-gray-800 mt-1">x{{ Number(position.default_holiday_rate).toFixed(1) }}</p>
                        </div>
                    </div>
                </div>

                <!-- Compensation Types -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Conceptos de Compensacion</h3>
                    <div v-if="position.compensation_types?.length" class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Concepto</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Codigo</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tipo</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Valor Base</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Valor del Puesto</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <tr v-for="ct in position.compensation_types" :key="ct.id" class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ ct.name }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ ct.code }}</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <span
                                            :class="ct.calculation_type === 'fixed' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800'"
                                            class="px-2 py-1 text-xs font-medium rounded-full"
                                        >
                                            {{ ct.calculation_type === 'fixed' ? 'Fijo ($)' : 'Porcentaje (%)' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <template v-if="ct.calculation_type === 'fixed'">${{ Number(ct.fixed_amount).toFixed(2) }}</template>
                                        <template v-else>{{ Number(ct.percentage_value).toFixed(2) }}%</template>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <template v-if="ct.calculation_type === 'fixed'">
                                            <span v-if="ct.pivot?.default_fixed_amount != null">
                                                ${{ Number(ct.pivot.default_fixed_amount).toFixed(2) }}
                                            </span>
                                            <span v-else class="text-gray-400">Usa base</span>
                                        </template>
                                        <template v-else>
                                            <span v-if="ct.pivot?.default_percentage != null">
                                                {{ Number(ct.pivot.default_percentage).toFixed(2) }}%
                                            </span>
                                            <span v-else class="text-gray-400">Usa base</span>
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="text-gray-500 text-center py-4">Sin conceptos de compensacion asignados</p>
                </div>

                <!-- Employees with this Position -->
                <div class="bg-white rounded-lg shadow">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">Empleados con este Puesto</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Empleado</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">No. Empleado</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Departamento</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <tr v-for="emp in position.employees" :key="emp.id" class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-8 h-8 rounded-full bg-pink-100 flex items-center justify-center">
                                                <span class="text-pink-600 font-medium text-sm">
                                                    {{ emp.full_name?.charAt(0)?.toUpperCase() || '?' }}
                                                </span>
                                            </div>
                                            <div class="ml-3">
                                                <div class="text-sm font-medium text-gray-900">{{ emp.full_name }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ emp.employee_number }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ emp.department?.name || '-' }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span :class="[statusColors[emp.status] || 'bg-gray-100 text-gray-800', 'px-2 py-1 text-xs font-medium rounded-full']">
                                            {{ statusLabels[emp.status] || emp.status }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <Link
                                            :href="route('employees.show', emp.id)"
                                            class="text-pink-600 hover:text-pink-900"
                                        >
                                            Ver
                                        </Link>
                                    </td>
                                </tr>
                                <tr v-if="!position.employees?.length">
                                    <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                                        Ningun empleado tiene este puesto asignado
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
                    <div class="space-y-4">
                        <div class="text-center">
                            <div class="text-4xl font-bold text-pink-600">
                                {{ position.employees?.length || 0 }}
                            </div>
                            <p class="text-gray-500 mt-1">empleados asignados</p>
                        </div>
                        <div class="pt-4 border-t border-gray-200">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500">Estado</span>
                                <span :class="position.is_active ? 'text-green-600' : 'text-red-600'" class="font-medium">
                                    {{ position.is_active ? 'Activo' : 'Inactivo' }}
                                </span>
                            </div>
                            <div class="flex justify-between text-sm mt-2">
                                <span class="text-gray-500">Tipo</span>
                                <span class="font-medium">{{ positionTypeLabels[position.position_type] || position.position_type }}</span>
                            </div>
                            <div class="flex justify-between text-sm mt-2">
                                <span class="text-gray-500">Conceptos</span>
                                <span class="font-medium">{{ position.compensation_types?.length || 0 }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Subordinate Positions -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Puestos Subordinados</h3>
                    <div v-if="position.subordinate_positions?.length" class="space-y-3">
                        <Link
                            v-for="subPos in position.subordinate_positions"
                            :key="subPos.id"
                            :href="route('positions.show', subPos.id)"
                            class="block p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors"
                        >
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-900">{{ subPos.name }}</p>
                                    <p class="text-xs text-gray-500">{{ subPos.code }}</p>
                                </div>
                                <span :class="[positionTypeColors[subPos.position_type] || 'bg-gray-100 text-gray-800', 'px-2 py-1 text-xs font-medium rounded-full']">
                                    {{ positionTypeLabels[subPos.position_type] || subPos.position_type }}
                                </span>
                            </div>
                        </Link>
                    </div>
                    <p v-else class="text-gray-500 text-center py-4">Sin puestos subordinados</p>
                </div>

                <!-- Department Info -->
                <div v-if="position.department" class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Departamento</h3>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-500">Nombre</span>
                            <span class="font-medium">{{ position.department.name }}</span>
                        </div>
                        <div v-if="position.department.code" class="flex justify-between">
                            <span class="text-gray-500">Codigo</span>
                            <span class="font-medium">{{ position.department.code }}</span>
                        </div>
                    </div>
                </div>

                <!-- Schedule Info -->
                <div v-if="position.default_schedule" class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Horario por Defecto</h3>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-500">Nombre</span>
                            <span class="font-medium">{{ position.default_schedule.name }}</span>
                        </div>
                        <div v-if="position.default_schedule.is_flexible !== undefined" class="flex justify-between">
                            <span class="text-gray-500">Tipo</span>
                            <span class="font-medium">{{ position.default_schedule.is_flexible ? 'Flexible' : 'Fijo' }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

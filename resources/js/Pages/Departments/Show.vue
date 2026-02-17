<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    department: Object,
});
</script>

<template>
    <Head :title="department.name" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Detalle de Departamento
            </h2>
        </template>

        <!-- Breadcrumb -->
        <div class="mb-6 flex items-center justify-between">
            <Link :href="route('departments.index')" class="text-pink-600 hover:text-pink-800">
                &larr; Volver a departamentos
            </Link>
            <Link
                :href="route('departments.edit', department.id)"
                class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors"
            >
                Editar Departamento
            </Link>
        </div>

        <!-- Department Header -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <div class="flex items-start">
                <div class="w-16 h-16 rounded-full bg-pink-100 flex items-center justify-center">
                    <span class="text-2xl text-pink-600 font-bold">
                        {{ department.name?.charAt(0)?.toUpperCase() || '?' }}
                    </span>
                </div>
                <div class="ml-6 flex-1">
                    <div class="flex items-center">
                        <h1 class="text-2xl font-bold text-gray-800">{{ department.name }}</h1>
                        <span
                            :class="[
                                department.is_active
                                    ? 'bg-green-100 text-green-800'
                                    : 'bg-yellow-100 text-yellow-800',
                                'ml-3 px-3 py-1 text-sm font-medium rounded-full'
                            ]"
                        >
                            {{ department.is_active ? 'Activo' : 'Inactivo' }}
                        </span>
                    </div>
                    <p class="text-gray-500 mt-1">Codigo: {{ department.code }}</p>
                    <p v-if="department.description" class="text-gray-600 mt-2">{{ department.description }}</p>

                    <div class="mt-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                        <div>
                            <p class="text-gray-500">Empleados</p>
                            <p class="font-medium text-lg">{{ department.employees_count || 0 }}</p>
                        </div>
                        <div>
                            <p class="text-gray-500">Puestos</p>
                            <p class="font-medium text-lg">{{ department.positions_count || 0 }}</p>
                        </div>
                        <div>
                            <p class="text-gray-500">Supervisor</p>
                            <p class="font-medium">{{ department.supervisor?.name || 'Sin asignar' }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Employees List -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">
                        Empleados
                        <span class="text-sm font-normal text-gray-500">({{ department.employees?.length || 0 }})</span>
                    </h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Nombre
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Puesto
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <tr v-for="employee in department.employees" :key="employee.id" class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-8 h-8 rounded-full bg-pink-100 flex items-center justify-center">
                                            <span class="text-pink-600 text-sm font-medium">
                                                {{ employee.full_name?.charAt(0)?.toUpperCase() || '?' }}
                                            </span>
                                        </div>
                                        <div class="ml-3">
                                            <div class="text-sm font-medium text-gray-900">{{ employee.full_name }}</div>
                                            <div class="text-xs text-gray-500">{{ employee.employee_number }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    {{ employee.position?.name || '-' }}
                                </td>
                            </tr>
                            <tr v-if="!department.employees?.length">
                                <td colspan="2" class="px-6 py-8 text-center text-gray-500">
                                    Sin empleados asignados
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Positions List -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">
                        Puestos
                        <span class="text-sm font-normal text-gray-500">({{ department.positions?.length || 0 }})</span>
                    </h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Nombre
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Descripcion
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <tr v-for="position in department.positions" :key="position.id" class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    {{ position.name }}
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">
                                    {{ position.description || '-' }}
                                </td>
                            </tr>
                            <tr v-if="!department.positions?.length">
                                <td colspan="2" class="px-6 py-8 text-center text-gray-500">
                                    Sin puestos asignados
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    userRole: {
        type: String,
        default: 'employee'
    },
    employeeName: String,
    stats: {
        type: Object,
        default: () => ({})
    },
    recentAttendance: {
        type: Array,
        default: () => []
    },
    pendingApprovals: {
        type: Array,
        default: () => []
    },
    currentPayroll: Object,
    todayAttendance: Object,
    myRequests: {
        type: Array,
        default: () => []
    },
    can: {
        type: Object,
        default: () => ({})
    }
});

const statusColors = {
    pending: 'bg-yellow-100 text-yellow-800',
    approved: 'bg-green-100 text-green-800',
    rejected: 'bg-red-100 text-red-800',
    present: 'bg-green-100 text-green-800',
    late: 'bg-yellow-100 text-yellow-800',
    absent: 'bg-red-100 text-red-800',
};

const statusLabels = {
    pending: 'Pendiente',
    approved: 'Aprobado',
    rejected: 'Rechazado',
    present: 'A tiempo',
    late: 'Retardo',
    absent: 'Ausente',
};
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Dashboard
            </h2>
        </template>

        <!-- Page Header -->
        <div class="mb-8">
            <h1 class="text-2xl font-bold text-gray-800">
                {{ userRole === 'employee' && employeeName ? `Hola, ${employeeName}` : 'Sistema de Nomina' }}
            </h1>
            <p class="text-gray-600">
                {{ userRole === 'admin' ? 'Panel de Administracion' :
                   userRole === 'supervisor' ? 'Panel de Supervisor' :
                   'Mi Panel Personal' }}
            </p>
        </div>

        <!-- ADMIN/RRHH DASHBOARD -->
        <template v-if="userRole === 'admin'">
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100 text-green-600">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm text-gray-500">Presentes Hoy</p>
                            <p class="text-2xl font-semibold text-gray-800">{{ stats.present || 0 }}</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-yellow-100 text-yellow-600">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm text-gray-500">Retardos</p>
                            <p class="text-2xl font-semibold text-gray-800">{{ stats.late || 0 }}</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-red-100 text-red-600">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm text-gray-500">Ausencias</p>
                            <p class="text-2xl font-semibold text-gray-800">{{ stats.absent || 0 }}</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-pink-100 text-pink-600">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm text-gray-500">Total Empleados</p>
                            <p class="text-2xl font-semibold text-gray-800">{{ stats.total || 0 }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pending Approvals Alert -->
            <div v-if="(stats.pendingIncidents || 0) + (stats.pendingAuthorizations || 0) > 0" class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-8">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-yellow-700">
                            Tienes <strong>{{ stats.pendingIncidents || 0 }} incidencias</strong> y
                            <strong>{{ stats.pendingAuthorizations || 0 }} autorizaciones</strong> pendientes de aprobar.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Sync Status -->
            <div class="bg-white rounded-lg shadow p-6 mb-8">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Estado de Sincronizacion ZKTeco</h3>
                <div class="flex flex-wrap items-center gap-8">
                    <div>
                        <p class="text-sm text-gray-500">Ultima sincronizacion</p>
                        <div v-if="stats.syncInProgress" class="flex items-center gap-2">
                            <svg class="animate-spin h-4 w-4 text-yellow-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span class="text-yellow-600 font-medium">Sincronizando...</span>
                        </div>
                        <p v-else class="text-gray-800 font-medium">{{ stats.lastSync || 'Nunca' }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Dispositivos activos</p>
                        <p class="text-gray-800 font-medium">{{ stats.activeDevices || 0 }} / 4</p>
                    </div>
                    <div class="text-xs text-gray-400">
                        La sincronizacion se realiza automaticamente
                    </div>
                </div>
            </div>

            <!-- Two Column Layout -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Pending Approvals -->
                <div class="bg-white rounded-lg shadow">
                    <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                        <h3 class="text-lg font-semibold text-gray-800">Aprobaciones Pendientes</h3>
                        <span v-if="pendingApprovals.length > 0" class="px-2 py-1 text-xs bg-yellow-100 text-yellow-800 rounded-full">
                            {{ pendingApprovals.length }}
                        </span>
                    </div>
                    <div class="p-6">
                        <div v-if="pendingApprovals.length === 0" class="text-center py-8 text-gray-500">
                            No hay aprobaciones pendientes
                        </div>
                        <div v-else class="space-y-3">
                            <Link
                                v-for="item in pendingApprovals"
                                :key="`${item.type}-${item.id}`"
                                :href="item.route"
                                class="block p-3 border border-gray-200 rounded-lg hover:bg-gray-50"
                            >
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">{{ item.typeName }}</p>
                                        <p class="text-sm text-gray-500">{{ item.employee }}</p>
                                    </div>
                                    <span class="text-xs text-gray-500">{{ item.date }}</span>
                                </div>
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="bg-white rounded-lg shadow">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">Ultimas Checadas</h3>
                    </div>
                    <div class="p-6">
                        <div v-if="recentAttendance.length === 0" class="text-center py-8 text-gray-500">
                            Sin registros de hoy
                        </div>
                        <div v-else class="space-y-4">
                            <div v-for="record in recentAttendance" :key="record.id" class="flex items-center justify-between py-2 border-b border-gray-100 last:border-0">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center">
                                        <span class="text-gray-600 font-medium">{{ record.employee?.name?.charAt(0) || '?' }}</span>
                                    </div>
                                    <div class="ml-3">
                                        <p class="text-sm font-medium text-gray-800">{{ record.employee?.name || 'Desconocido' }}</p>
                                        <p class="text-xs text-gray-500">{{ record.type === 'in' ? 'Entrada' : 'Salida' }}</p>
                                    </div>
                                </div>
                                <span class="text-sm text-gray-500">{{ record.time }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="mt-6 bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">Acciones Rapidas</h3>
                </div>
                <div class="p-6 grid grid-cols-2 md:grid-cols-4 gap-4">
                    <Link
                        v-if="can.createEmployee"
                        :href="route('employees.create')"
                        class="flex flex-col items-center justify-center p-4 border-2 border-dashed border-gray-200 rounded-lg hover:border-pink-300 hover:bg-pink-50 transition-colors"
                    >
                        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                        </svg>
                        <span class="mt-2 text-sm text-gray-600">Nuevo Empleado</span>
                    </Link>
                    <Link
                        v-if="can.createIncident"
                        :href="route('incidents.create')"
                        class="flex flex-col items-center justify-center p-4 border-2 border-dashed border-gray-200 rounded-lg hover:border-pink-300 hover:bg-pink-50 transition-colors"
                    >
                        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <span class="mt-2 text-sm text-gray-600">Nueva Incidencia</span>
                    </Link>
                    <Link
                        v-if="can.generateReport"
                        :href="route('reports.index')"
                        class="flex flex-col items-center justify-center p-4 border-2 border-dashed border-gray-200 rounded-lg hover:border-pink-300 hover:bg-pink-50 transition-colors"
                    >
                        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <span class="mt-2 text-sm text-gray-600">Reportes</span>
                    </Link>
                    <Link
                        v-if="can.calculatePayroll"
                        :href="route('payroll.create')"
                        class="flex flex-col items-center justify-center p-4 border-2 border-dashed border-gray-200 rounded-lg hover:border-pink-300 hover:bg-pink-50 transition-colors"
                    >
                        <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                        <span class="mt-2 text-sm text-gray-600">Nueva Nomina</span>
                    </Link>
                </div>
            </div>
        </template>

        <!-- SUPERVISOR DASHBOARD -->
        <template v-else-if="userRole === 'supervisor'">
            <!-- Team Stats -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100 text-green-600">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm text-gray-500">Equipo Presente</p>
                            <p class="text-2xl font-semibold text-gray-800">{{ stats.present || 0 }}</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-yellow-100 text-yellow-600">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm text-gray-500">Retardos</p>
                            <p class="text-2xl font-semibold text-gray-800">{{ stats.late || 0 }}</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-red-100 text-red-600">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm text-gray-500">Ausencias</p>
                            <p class="text-2xl font-semibold text-gray-800">{{ stats.absent || 0 }}</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-pink-100 text-pink-600">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm text-gray-500">Mi Equipo</p>
                            <p class="text-2xl font-semibold text-gray-800">{{ stats.total || 0 }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pending Approvals Alert -->
            <div v-if="(stats.pendingIncidents || 0) + (stats.pendingAuthorizations || 0) > 0" class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-8">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-yellow-700">
                            Tu equipo tiene <strong>{{ stats.pendingIncidents || 0 }} incidencias</strong> y
                            <strong>{{ stats.pendingAuthorizations || 0 }} autorizaciones</strong> pendientes.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Two Column Layout -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Pending Approvals -->
                <div class="bg-white rounded-lg shadow">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">Aprobaciones de Mi Equipo</h3>
                    </div>
                    <div class="p-6">
                        <div v-if="pendingApprovals.length === 0" class="text-center py-8 text-gray-500">
                            No hay aprobaciones pendientes
                        </div>
                        <div v-else class="space-y-3">
                            <Link
                                v-for="item in pendingApprovals"
                                :key="`${item.type}-${item.id}`"
                                :href="item.route"
                                class="block p-3 border border-gray-200 rounded-lg hover:bg-gray-50"
                            >
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">{{ item.typeName }}</p>
                                        <p class="text-sm text-gray-500">{{ item.employee }}</p>
                                    </div>
                                    <span class="text-xs text-gray-500">{{ item.date }}</span>
                                </div>
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Team Attendance -->
                <div class="bg-white rounded-lg shadow">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">Asistencia del Equipo Hoy</h3>
                    </div>
                    <div class="p-6">
                        <div v-if="recentAttendance.length === 0" class="text-center py-8 text-gray-500">
                            Sin registros de hoy
                        </div>
                        <div v-else class="space-y-4">
                            <div v-for="record in recentAttendance" :key="record.id" class="flex items-center justify-between py-2 border-b border-gray-100 last:border-0">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center">
                                        <span class="text-gray-600 font-medium">{{ record.employee?.name?.charAt(0) || '?' }}</span>
                                    </div>
                                    <div class="ml-3">
                                        <p class="text-sm font-medium text-gray-800">{{ record.employee?.name || 'Desconocido' }}</p>
                                        <p class="text-xs text-gray-500">{{ record.type === 'in' ? 'Entrada' : 'Salida' }}</p>
                                    </div>
                                </div>
                                <span class="text-sm text-gray-500">{{ record.time }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- EMPLOYEE DASHBOARD -->
        <template v-else>
            <!-- Personal Stats -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100 text-green-600">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm text-gray-500">Dias Trabajados (Mes)</p>
                            <p class="text-2xl font-semibold text-gray-800">{{ stats.presentDays || 0 }}</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-yellow-100 text-yellow-600">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm text-gray-500">Retardos (Mes)</p>
                            <p class="text-2xl font-semibold text-gray-800">{{ stats.lateDays || 0 }}</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-red-100 text-red-600">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm text-gray-500">Faltas (Mes)</p>
                            <p class="text-2xl font-semibold text-gray-800">{{ stats.absentDays || 0 }}</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm text-gray-500">Vacaciones Disponibles</p>
                            <p class="text-2xl font-semibold text-gray-800">{{ stats.vacationBalance || 0 }} dias</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Today's Attendance -->
            <div v-if="todayAttendance" class="bg-white rounded-lg shadow p-6 mb-8">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">Mi Asistencia de Hoy</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div>
                        <p class="text-sm text-gray-500">Entrada</p>
                        <p class="text-lg font-semibold text-gray-800">{{ todayAttendance.checkIn || '-' }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Salida</p>
                        <p class="text-lg font-semibold text-gray-800">{{ todayAttendance.checkOut || '-' }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Horas Trabajadas</p>
                        <p class="text-lg font-semibold text-gray-800">{{ todayAttendance.workedHours ? `${todayAttendance.workedHours}h` : '-' }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Estado</p>
                        <span :class="[statusColors[todayAttendance.status], 'px-2 py-1 text-sm rounded-full']">
                            {{ statusLabels[todayAttendance.status] || todayAttendance.status }}
                        </span>
                    </div>
                </div>
            </div>
            <div v-else class="bg-gray-50 rounded-lg p-6 mb-8 text-center">
                <p class="text-gray-500">No tienes registro de asistencia para hoy</p>
            </div>

            <!-- My Requests -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                    <h3 class="text-lg font-semibold text-gray-800">Mis Solicitudes Recientes</h3>
                    <Link
                        v-if="can.createIncident"
                        :href="route('incidents.create')"
                        class="text-sm text-pink-600 hover:text-pink-800"
                    >
                        Nueva solicitud
                    </Link>
                </div>
                <div class="p-6">
                    <div v-if="myRequests.length === 0" class="text-center py-8 text-gray-500">
                        No tienes solicitudes recientes
                    </div>
                    <div v-else class="space-y-3">
                        <Link
                            v-for="item in myRequests"
                            :key="`${item.type}-${item.id}`"
                            :href="item.route"
                            class="block p-3 border border-gray-200 rounded-lg hover:bg-gray-50"
                        >
                            <div class="flex justify-between items-center">
                                <div>
                                    <p class="text-sm font-medium text-gray-900">{{ item.typeName }}</p>
                                    <p class="text-xs text-gray-500">{{ item.date }}</p>
                                </div>
                                <span :class="[statusColors[item.status], 'px-2 py-1 text-xs rounded-full']">
                                    {{ statusLabels[item.status] || item.status }}
                                </span>
                            </div>
                        </Link>
                    </div>
                </div>
            </div>
        </template>
    </AppLayout>
</template>

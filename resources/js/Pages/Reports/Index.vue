<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

const reports = [
    {
        category: 'Asistencia',
        icon: 'calendar',
        color: 'blue',
        items: [
            { name: 'Diario', description: 'Asistencia del dia', route: 'reports.daily', icon: 'day' },
            { name: 'Semanal', description: 'Horas por empleado', route: 'reports.weekly', icon: 'week' },
            { name: 'Mensual', description: 'Consolidado mensual', route: 'reports.monthly', icon: 'month' },
        ],
    },
    {
        category: 'Puntualidad',
        icon: 'clock',
        color: 'amber',
        items: [
            { name: 'Retardos', description: 'Minutos acumulados', route: 'reports.lateArrivals', icon: 'late' },
            { name: 'Ausencias', description: 'Faltas por empleado', route: 'reports.absences', icon: 'absent' },
            { name: 'Horas Extra', description: 'Extras y costo', route: 'reports.overtime', icon: 'overtime' },
        ],
    },
    {
        category: 'Incidencias',
        icon: 'document',
        color: 'purple',
        items: [
            { name: 'Incidencias', description: 'Por tipo y depto', route: 'reports.incidents', icon: 'incident' },
            { name: 'Vacaciones', description: 'Saldo disponible', route: 'reports.vacationBalance', icon: 'vacation' },
        ],
    },
    {
        category: 'Analisis',
        icon: 'chart',
        color: 'indigo',
        items: [
            { name: 'Departamentos', description: 'Comparativa', route: 'reports.departmentComparison', icon: 'compare' },
            { name: 'Productividad', description: 'Eficiencia', route: 'reports.productivity', icon: 'productivity' },
        ],
    },
    {
        category: 'Nomina',
        icon: 'money',
        color: 'emerald',
        items: [
            { name: 'Nomina', description: 'Detalle por periodo', route: 'reports.payroll', icon: 'payroll' },
            { name: 'Tendencias', description: 'Historico de pago', route: 'reports.payrollTrends', icon: 'trends' },
        ],
    },
];

const colorClasses = {
    blue: { bg: 'bg-blue-500', light: 'bg-blue-50', text: 'text-blue-600', hover: 'hover:bg-blue-100', border: 'border-blue-200' },
    amber: { bg: 'bg-amber-500', light: 'bg-amber-50', text: 'text-amber-600', hover: 'hover:bg-amber-100', border: 'border-amber-200' },
    purple: { bg: 'bg-purple-500', light: 'bg-purple-50', text: 'text-purple-600', hover: 'hover:bg-purple-100', border: 'border-purple-200' },
    indigo: { bg: 'bg-indigo-500', light: 'bg-indigo-50', text: 'text-indigo-600', hover: 'hover:bg-indigo-100', border: 'border-indigo-200' },
    emerald: { bg: 'bg-emerald-500', light: 'bg-emerald-50', text: 'text-emerald-600', hover: 'hover:bg-emerald-100', border: 'border-emerald-200' },
};
</script>

<template>
    <Head title="Reportes" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Centro de Reportes</h2>
        </template>

        <!-- Quick Stats -->
        <div class="grid grid-cols-5 gap-3 mb-6">
            <div v-for="section in reports" :key="section.category"
                 :class="[colorClasses[section.color].light, colorClasses[section.color].border, 'border rounded-lg p-3 text-center']">
                <p :class="[colorClasses[section.color].text, 'text-2xl font-bold']">{{ section.items.length }}</p>
                <p class="text-xs text-gray-500">{{ section.category }}</p>
            </div>
        </div>

        <!-- Reports Grid -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="grid grid-cols-1 md:grid-cols-5 divide-x divide-gray-100">
                <div v-for="section in reports" :key="section.category" class="flex flex-col">
                    <!-- Category Header -->
                    <div :class="[colorClasses[section.color].bg, 'px-4 py-3']">
                        <div class="flex items-center space-x-2">
                            <!-- Category Icons -->
                            <svg v-if="section.icon === 'calendar'" class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            <svg v-if="section.icon === 'clock'" class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <svg v-if="section.icon === 'document'" class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <svg v-if="section.icon === 'chart'" class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                            </svg>
                            <svg v-if="section.icon === 'money'" class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span class="text-white font-semibold text-sm">{{ section.category }}</span>
                        </div>
                    </div>

                    <!-- Report Items -->
                    <div class="flex-1 divide-y divide-gray-100">
                        <Link v-for="report in section.items"
                              :key="report.route"
                              :href="route(report.route)"
                              :class="[colorClasses[section.color].hover, 'block px-4 py-3 transition-colors group']">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-3">
                                    <!-- Item Icons -->
                                    <div :class="[colorClasses[section.color].light, colorClasses[section.color].text, 'w-8 h-8 rounded-lg flex items-center justify-center']">
                                        <!-- Day -->
                                        <svg v-if="report.icon === 'day'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                        <!-- Week -->
                                        <svg v-if="report.icon === 'week'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                        </svg>
                                        <!-- Month -->
                                        <svg v-if="report.icon === 'month'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                        <!-- Late -->
                                        <svg v-if="report.icon === 'late'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        <!-- Absent -->
                                        <svg v-if="report.icon === 'absent'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                        </svg>
                                        <!-- Overtime -->
                                        <svg v-if="report.icon === 'overtime'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                        </svg>
                                        <!-- Incident -->
                                        <svg v-if="report.icon === 'incident'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                        <!-- Vacation -->
                                        <svg v-if="report.icon === 'vacation'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                                        </svg>
                                        <!-- Compare -->
                                        <svg v-if="report.icon === 'compare'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                        </svg>
                                        <!-- Productivity -->
                                        <svg v-if="report.icon === 'productivity'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                                        </svg>
                                        <!-- Payroll -->
                                        <svg v-if="report.icon === 'payroll'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        <!-- Trends -->
                                        <svg v-if="report.icon === 'trends'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z" />
                                        </svg>
                                    </div>
                                    <div>
                                        <p class="font-medium text-gray-800 text-sm group-hover:text-pink-600">{{ report.name }}</p>
                                        <p class="text-xs text-gray-400">{{ report.description }}</p>
                                    </div>
                                </div>
                                <svg class="w-4 h-4 text-gray-300 group-hover:text-pink-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                </svg>
                            </div>
                        </Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer Info -->
        <div class="mt-6 flex items-center justify-between text-sm text-gray-500">
            <p>{{ reports.reduce((sum, s) => sum + s.items.length, 0) }} reportes disponibles</p>
            <p>Todos los reportes incluyen filtros de fecha y exportacion</p>
        </div>
    </AppLayout>
</template>

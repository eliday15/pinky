<script setup>
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';

const showingSidebar = ref(true);
const page = usePage();

const user = computed(() => page.props.auth?.user);
const permissions = computed(() => page.props.auth?.permissions || []);
const roles = computed(() => page.props.auth?.roles || []);

/**
 * Check if user has a specific permission.
 */
const can = (permission) => {
    return permissions.value.includes(permission);
};

/**
 * Check if user has any of the given permissions.
 */
const canAny = (perms) => {
    return perms.some(p => permissions.value.includes(p));
};

/**
 * Check if user has a specific role.
 */
const hasRole = (role) => {
    return roles.value.includes(role);
};

const navigation = computed(() => [
    {
        name: 'Dashboard',
        href: 'dashboard',
        icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
        show: !hasRole('supervisor'), // Dashboard hidden for supervisors
    },
    {
        name: 'Empleados',
        href: 'employees.index',
        icon: 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z',
        show: canAny(['employees.view_all', 'employees.view_team', 'employees.view_own']),
    },
    {
        name: 'Asistencia',
        href: 'attendance.index',
        icon: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
        show: canAny(['attendance.view_all', 'attendance.view_team', 'attendance.view_own']),
    },
    {
        name: 'Incidencias',
        href: 'incidents.index',
        icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
        show: canAny(['incidents.view_all', 'incidents.view_team', 'incidents.view_own']),
    },
    {
        name: 'Autorizaciones',
        href: 'authorizations.index',
        icon: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
        show: canAny(['authorizations.view_all', 'authorizations.view_team', 'authorizations.view_own']),
    },
    {
        name: 'Nomina',
        href: 'payroll.index',
        icon: 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z',
        show: canAny(['payroll.view_basic', 'payroll.view_complete']),
    },
    {
        name: 'Reportes',
        href: 'reports.index',
        icon: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
        show: canAny(['reports.view_all', 'reports.view_team', 'reports.view_own']),
    },
    {
        name: 'Configuracion',
        href: 'settings.index',
        icon: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z',
        show: can('settings.view'),
    },
    {
        name: 'Auditoria',
        href: 'audit-logs.index',
        icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01',
        show: can('logs.view'),
    },
]);

const visibleNavigation = computed(() => navigation.value.filter(item => item.show));

const isActiveRoute = (routeName) => {
    try {
        return route().current(routeName) || route().current(routeName + '.*');
    } catch {
        return false;
    }
};

const hasRoute = (routeName) => {
    try {
        route(routeName);
        return true;
    } catch {
        return false;
    }
};
</script>

<template>
    <div class="min-h-screen bg-gray-100">
        <!-- Sidebar -->
        <aside
            :class="[
                'fixed inset-y-0 left-0 z-50 w-64 bg-white shadow-lg transform transition-transform duration-300 ease-in-out',
                showingSidebar ? 'translate-x-0' : '-translate-x-full'
            ]"
        >
            <!-- Logo -->
            <div class="flex items-center justify-center h-16 border-b border-gray-200 bg-pink-600">
                <Link :href="route('dashboard')" class="text-xl font-bold text-white">
                    Pinky ERP
                </Link>
            </div>

            <!-- Navigation -->
            <nav class="mt-6 px-4">
                <template v-for="item in visibleNavigation" :key="item.name">
                    <Link
                        v-if="hasRoute(item.href)"
                        :href="route(item.href)"
                        :class="[
                            'flex items-center px-4 py-3 mt-2 rounded-lg transition-colors duration-200',
                            isActiveRoute(item.href)
                                ? 'bg-pink-50 text-pink-600'
                                : 'text-gray-600 hover:bg-gray-100'
                        ]"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="item.icon" />
                        </svg>
                        <span class="mx-3">{{ item.name }}</span>
                    </Link>
                    <div
                        v-else
                        class="flex items-center px-4 py-3 mt-2 rounded-lg text-gray-400 cursor-not-allowed"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="item.icon" />
                        </svg>
                        <span class="mx-3">{{ item.name }}</span>
                    </div>
                </template>
            </nav>

            <!-- User Info at Bottom -->
            <div class="absolute bottom-0 left-0 right-0 p-4 border-t border-gray-200">
                <div class="flex items-center">
                    <div class="w-10 h-10 rounded-full bg-pink-100 flex items-center justify-center">
                        <span class="text-pink-600 font-semibold">
                            {{ user?.name?.charAt(0)?.toUpperCase() || 'U' }}
                        </span>
                    </div>
                    <div class="ml-3 flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-700 truncate">
                            {{ user?.name || 'Usuario' }}
                        </p>
                        <p class="text-xs text-gray-500 truncate">
                            {{ roles[0] ? roles[0].charAt(0).toUpperCase() + roles[0].slice(1) : 'Sin rol' }}
                        </p>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Main Content Area -->
        <div :class="['transition-all duration-300', showingSidebar ? 'ml-64' : 'ml-0']">
            <!-- Top Navigation -->
            <nav class="bg-white border-b border-gray-100 shadow-sm">
                <div class="px-4 sm:px-6 lg:px-8">
                    <div class="flex h-16 justify-between">
                        <div class="flex items-center">
                            <!-- Toggle Sidebar -->
                            <button
                                @click="showingSidebar = !showingSidebar"
                                class="p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none"
                            >
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                                </svg>
                            </button>

                            <!-- Page Title -->
                            <div class="ml-4">
                                <slot name="header" />
                            </div>
                        </div>

                        <!-- Right Side -->
                        <div class="flex items-center space-x-4">
                            <!-- Notifications -->
                            <button class="p-2 rounded-full text-gray-400 hover:text-gray-500 hover:bg-gray-100">
                                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                                </svg>
                            </button>

                            <!-- User Dropdown -->
                            <Dropdown align="right" width="48">
                                <template #trigger>
                                    <button class="flex items-center text-sm font-medium text-gray-500 hover:text-gray-700 focus:outline-none">
                                        <span>{{ user?.name || 'Usuario' }}</span>
                                        <svg class="ml-2 h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                        </svg>
                                    </button>
                                </template>

                                <template #content>
                                    <DropdownLink :href="route('profile.edit')">
                                        Mi Perfil
                                    </DropdownLink>
                                    <DropdownLink :href="route('logout')" method="post" as="button">
                                        Cerrar Sesion
                                    </DropdownLink>
                                </template>
                            </Dropdown>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Page Content -->
            <main class="p-6">
                <slot />
            </main>
        </div>
    </div>
</template>

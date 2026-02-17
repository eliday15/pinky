<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import debounce from 'lodash/debounce';

const props = defineProps({
    positions: Object,
    departments: Array,
    filters: Object,
});

const search = ref(props.filters.search || '');
const department = ref(props.filters.department || '');
const status = ref(props.filters.status || '');

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

const applyFilters = debounce(() => {
    router.get(route('positions.index'), {
        search: search.value || undefined,
        department: department.value || undefined,
        status: status.value || undefined,
    }, {
        preserveState: true,
        replace: true,
    });
}, 300);

watch([search, department, status], applyFilters);

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
    }).format(amount);
};

const deletePosition = (position) => {
    if (confirm(`¿Estas seguro de eliminar el puesto "${position.name}"?`)) {
        router.delete(route('positions.destroy', position.id));
    }
};
</script>

<template>
    <Head title="Puestos" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Puestos
            </h2>
        </template>

        <!-- Header -->
        <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">Gestion de Puestos</h1>
                <p class="text-gray-600">{{ positions.total }} puestos registrados</p>
            </div>
            <Link
                :href="route('positions.create')"
                class="inline-flex items-center px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors"
            >
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                </svg>
                Nuevo Puesto
            </Link>
        </div>

        <!-- Filters -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Buscar</label>
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Nombre, codigo..."
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Departamento</label>
                    <select
                        v-model="department"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    >
                        <option value="">Todos</option>
                        <option v-for="dept in departments" :key="dept.id" :value="dept.id">
                            {{ dept.name }}
                        </option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Estado</label>
                    <select
                        v-model="status"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    >
                        <option value="">Solo Activos</option>
                        <option value="all">Todos</option>
                        <option value="inactive">Solo Inactivos</option>
                    </select>
                </div>
                <div class="flex items-end">
                    <button
                        @click="search = ''; department = ''; status = '';"
                        class="w-full px-4 py-2 text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors"
                    >
                        Limpiar filtros
                    </button>
                </div>
            </div>
        </div>

        <!-- Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Nombre
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Codigo
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Tipo
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Departamento
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Tarifa Base/Hora
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Empleados
                        </th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Acciones
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="pos in positions.data" :key="pos.id" class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900">{{ pos.name }}</div>
                            <div v-if="!pos.is_active" class="text-xs text-red-500">Inactivo</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            {{ pos.code }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span :class="[positionTypeColors[pos.position_type] || 'bg-gray-100 text-gray-800', 'px-2 py-1 text-xs font-medium rounded-full']">
                                {{ positionTypeLabels[pos.position_type] || pos.position_type }}
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            {{ pos.department?.name || '-' }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            {{ formatCurrency(pos.base_hourly_rate) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            {{ pos.employees_count }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <Link
                                :href="route('positions.show', pos.id)"
                                class="text-gray-600 hover:text-gray-900 mr-3"
                            >
                                Ver
                            </Link>
                            <Link
                                :href="route('positions.edit', pos.id)"
                                class="text-pink-600 hover:text-pink-900 mr-3"
                            >
                                Editar
                            </Link>
                            <button
                                @click="deletePosition(pos)"
                                class="text-red-600 hover:text-red-900"
                            >
                                Eliminar
                            </button>
                        </td>
                    </tr>
                    <tr v-if="positions.data.length === 0">
                        <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                            No se encontraron puestos
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Pagination -->
            <div v-if="positions.last_page > 1" class="px-6 py-4 border-t border-gray-200">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-gray-700">
                        Mostrando {{ positions.from }} a {{ positions.to }} de {{ positions.total }} resultados
                    </p>
                    <div class="flex space-x-2">
                        <Link
                            v-for="link in positions.links"
                            :key="link.label"
                            :href="link.url || '#'"
                            :class="[
                                'px-3 py-1 rounded text-sm',
                                link.active
                                    ? 'bg-pink-600 text-white'
                                    : link.url
                                        ? 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                                        : 'bg-gray-50 text-gray-400 cursor-not-allowed'
                            ]"
                            v-html="link.label"
                        />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

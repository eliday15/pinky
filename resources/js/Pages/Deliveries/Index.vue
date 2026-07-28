<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import debounce from 'lodash/debounce';

const props = defineProps({
    weekStart: String,
    weekEnd: String,
    employees: Array,
    departments: Array,
    filters: Object,
    markedCount: Number,
});

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

// Local filter state
const search = ref(props.filters.search || '');
const departmentFilter = ref(props.filters.department_id || '');
const weekInput = ref(props.weekStart);

// Track previous weekStart to detect week-vs-filter navigation
const prevWeekStart = ref(props.weekStart);

// Marked employees — a Set of IDs. Kept in memory across filter changes.
const marked = ref(new Set(
    props.employees.filter(e => e.on_delivery).map(e => e.id)
));

// Keep date input in sync when Inertia delivers new props
watch(() => props.weekStart, (newVal) => {
    weekInput.value = newVal;
});

// Sync marked set when employees prop changes (filter OR week navigation)
watch(() => props.employees, (newEmployees) => {
    const newOnDelivery = new Set(newEmployees.filter(e => e.on_delivery).map(e => e.id));
    if (props.weekStart !== prevWeekStart.value) {
        // Week changed — reinit from fresh on_delivery data
        marked.value = new Set(newOnDelivery);
        prevWeekStart.value = props.weekStart;
    } else {
        // Same week, only filters changed — MERGE to preserve hidden marks
        const merged = new Set(marked.value);
        for (const id of newOnDelivery) {
            merged.add(id);
        }
        marked.value = merged;
    }
});

// Navigate with current filters when the week date input changes
const changeWeek = () => {
    router.get(route('deliveries.index'), {
        week: weekInput.value,
        search: search.value || undefined,
        department_id: departmentFilter.value || undefined,
    }, { preserveState: true, preserveScroll: true });
};

// Navigate with current week when filters change
const applyFilters = debounce(() => {
    router.get(route('deliveries.index'), {
        week: props.weekStart,
        search: search.value || undefined,
        department_id: departmentFilter.value || undefined,
    }, { preserveState: true, preserveScroll: true });
}, 300);

watch(search, applyFilters);
watch(departmentFilter, applyFilters);

// Toggle a single employee in the marked set
const toggle = (id) => {
    const next = new Set(marked.value);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    marked.value = next;
};

// Save — sends ALL marked IDs (not just currently visible rows)
const saving = ref(false);

const save = () => {
    saving.value = true;
    router.post(route('deliveries.store'), {
        week_start: props.weekStart,
        employee_ids: [...marked.value],
    }, {
        preserveScroll: true,
        onFinish: () => { saving.value = false; },
    });
};

// Format 'YYYY-MM-DD' → 'dd/mm/yyyy' without external libs
const formatDateMX = (dateStr) => {
    if (!dateStr) return '';
    const [y, m, d] = dateStr.split('-');
    return `${d}/${m}/${y}`;
};
</script>

<template>
    <Head title="Personal de entregas" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Personal de entregas
            </h2>
        </template>

        <!-- Page heading -->
        <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">Personal de entregas por semana</h1>
                <p class="mt-1 text-sm text-gray-600 max-w-2xl">
                    Marca a quiénes salieron a entregas esta semana. A los marcados, su
                    <strong>velada y tiempo extra autorizados</strong> se pagan y reflejan completos esa semana,
                    porque andan en la calle repartiendo y su checada no los alcanza a registrar.
                    Es por semana porque se van turnando.
                </p>
            </div>
            <div class="flex items-center gap-3 flex-shrink-0">
                <span class="text-sm text-gray-600 bg-gray-100 px-3 py-1.5 rounded-full font-medium">
                    {{ marked.size }} marcados esta semana
                </span>
                <button
                    type="button"
                    @click="save"
                    :disabled="saving"
                    class="px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 disabled:opacity-50 disabled:cursor-not-allowed text-sm font-medium"
                >
                    {{ saving ? 'Guardando...' : 'Guardar' }}
                </button>
            </div>
        </div>

        <!-- Flash messages -->
        <div v-if="flash.success" class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg text-green-800 text-sm">
            {{ flash.success }}
        </div>
        <div v-if="flash.error" class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-800 text-sm">
            {{ flash.error }}
        </div>

        <!-- Week selector + filters -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <!-- Week picker -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Semana</label>
                    <input
                        v-model="weekInput"
                        type="date"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        @change="changeWeek"
                    />
                    <p class="mt-1 text-xs text-gray-500">
                        Semana del {{ formatDateMX(weekStart) }} al {{ formatDateMX(weekEnd) }}
                    </p>
                </div>

                <!-- Name search -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Buscar colaborador</label>
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Nombre..."
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    />
                </div>

                <!-- Department filter -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Departamento
                        <span class="text-xs text-gray-400 font-normal ml-1">(sugerido: Almacén PT)</span>
                    </label>
                    <select
                        v-model="departmentFilter"
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                    >
                        <option value="">Todos</option>
                        <option v-for="dept in departments" :key="dept.id" :value="dept.id">
                            {{ dept.name }}
                        </option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Employee table -->
        <div class="bg-white rounded-lg shadow overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Colaborador
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Departamento
                        </th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Salió a entregas
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <tr v-for="emp in employees" :key="emp.id" class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-full bg-pink-100 flex items-center justify-center flex-shrink-0">
                                    <span class="text-pink-600 text-sm font-medium">
                                        {{ emp.full_name?.charAt(0) || '?' }}
                                    </span>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm font-medium text-gray-900">{{ emp.full_name }}</p>
                                    <p class="text-xs text-gray-500">{{ emp.employee_number }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            {{ emp.department || '—' }}
                        </td>
                        <td class="px-6 py-4 text-center">
                            <input
                                type="checkbox"
                                :checked="marked.has(emp.id)"
                                @change="toggle(emp.id)"
                                class="w-5 h-5 rounded border-gray-300 text-pink-600 focus:ring-pink-500 cursor-pointer"
                            />
                        </td>
                    </tr>

                    <tr v-if="employees.length === 0">
                        <td colspan="3" class="px-6 py-12 text-center text-gray-500">
                            No hay colaboradores que coincidan.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

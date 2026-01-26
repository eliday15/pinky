<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    employees: Array,
    incidentTypes: Array,
});

const form = useForm({
    employee_ids: [],
    incident_type_id: '',
    start_date: new Date().toISOString().split('T')[0],
    end_date: new Date().toISOString().split('T')[0],
    reason: '',
});

const searchQuery = ref('');
const selectAll = ref(false);

const filteredEmployees = computed(() => {
    if (!searchQuery.value) return props.employees;
    const query = searchQuery.value.toLowerCase();
    return props.employees.filter(emp =>
        emp.full_name.toLowerCase().includes(query) ||
        emp.employee_number.toLowerCase().includes(query)
    );
});

const toggleSelectAll = () => {
    if (selectAll.value) {
        form.employee_ids = filteredEmployees.value.map(e => e.id);
    } else {
        form.employee_ids = [];
    }
};

const toggleEmployee = (empId) => {
    const index = form.employee_ids.indexOf(empId);
    if (index > -1) {
        form.employee_ids.splice(index, 1);
    } else {
        form.employee_ids.push(empId);
    }
    selectAll.value = form.employee_ids.length === filteredEmployees.value.length;
};

const isSelected = (empId) => form.employee_ids.includes(empId);

const selectedType = computed(() => {
    return props.incidentTypes.find(t => t.id == form.incident_type_id);
});

const submit = () => {
    form.post(route('incidents.storeBulk'));
};
</script>

<template>
    <Head title="Incidencia Masiva" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Incidencia Masiva
            </h2>
        </template>

        <div class="max-w-5xl">
            <!-- Breadcrumb -->
            <div class="mb-6">
                <Link :href="route('incidents.index')" class="text-pink-600 hover:text-pink-800">
                    &larr; Volver a incidencias
                </Link>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <!-- Employee Selection -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold text-gray-800">
                            Seleccionar Empleados
                            <span class="text-sm font-normal text-gray-500 ml-2">
                                ({{ form.employee_ids.length }} seleccionados)
                            </span>
                        </h3>
                        <input
                            v-model="searchQuery"
                            type="text"
                            placeholder="Buscar empleado..."
                            class="w-64 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                        />
                    </div>

                    <div class="border rounded-lg overflow-hidden">
                        <!-- Header -->
                        <div class="bg-gray-50 px-4 py-3 border-b flex items-center">
                            <input
                                type="checkbox"
                                v-model="selectAll"
                                @change="toggleSelectAll"
                                class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                            />
                            <span class="ml-3 text-sm font-medium text-gray-700">Seleccionar todos</span>
                        </div>

                        <!-- Employee List -->
                        <div class="max-h-64 overflow-y-auto">
                            <div
                                v-for="emp in filteredEmployees"
                                :key="emp.id"
                                class="px-4 py-3 border-b hover:bg-gray-50 flex items-center cursor-pointer"
                                @click="toggleEmployee(emp.id)"
                            >
                                <input
                                    type="checkbox"
                                    :checked="isSelected(emp.id)"
                                    class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                                    @click.stop
                                    @change="toggleEmployee(emp.id)"
                                />
                                <span class="ml-3 text-sm text-gray-900">{{ emp.full_name }}</span>
                                <span class="ml-2 text-xs text-gray-500">({{ emp.employee_number }})</span>
                            </div>
                            <div v-if="filteredEmployees.length === 0" class="px-4 py-8 text-center text-gray-500">
                                No se encontraron empleados
                            </div>
                        </div>
                    </div>

                    <p v-if="form.errors.employee_ids" class="mt-2 text-sm text-red-600">
                        {{ form.errors.employee_ids }}
                    </p>
                </div>

                <!-- Incident Type -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Tipo de Incidencia</h3>
                    <div>
                        <select
                            v-model="form.incident_type_id"
                            class="w-full md:w-1/2 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            :class="{ 'border-red-500': form.errors.incident_type_id }"
                        >
                            <option value="">Seleccionar tipo...</option>
                            <option v-for="type in incidentTypes" :key="type.id" :value="type.id">
                                {{ type.name }}
                            </option>
                        </select>
                        <p v-if="selectedType && !selectedType.requires_approval" class="mt-2 text-sm text-green-600">
                            Este tipo no requiere aprobacion - se aplicara automaticamente.
                        </p>
                        <p v-if="form.errors.incident_type_id" class="mt-1 text-sm text-red-600">
                            {{ form.errors.incident_type_id }}
                        </p>
                    </div>
                </div>

                <!-- Dates -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Fechas</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Fecha Inicio *
                            </label>
                            <input
                                v-model="form.start_date"
                                type="date"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.start_date }"
                            />
                            <p v-if="form.errors.start_date" class="mt-1 text-sm text-red-600">
                                {{ form.errors.start_date }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Fecha Fin *
                            </label>
                            <input
                                v-model="form.end_date"
                                type="date"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.end_date }"
                            />
                            <p v-if="form.errors.end_date" class="mt-1 text-sm text-red-600">
                                {{ form.errors.end_date }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Reason -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Observaciones</h3>
                    <div>
                        <textarea
                            v-model="form.reason"
                            rows="3"
                            placeholder="Observaciones o notas adicionales (aplica para todos los empleados seleccionados)..."
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                        ></textarea>
                    </div>
                </div>

                <!-- Summary -->
                <div v-if="form.employee_ids.length > 0" class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <p class="text-sm text-blue-800">
                        Se crearan <strong>{{ form.employee_ids.length }}</strong> incidencias
                        <span v-if="selectedType"> de tipo <strong>{{ selectedType.name }}</strong></span>
                        <span v-if="form.start_date"> del <strong>{{ form.start_date }}</strong></span>
                        <span v-if="form.end_date"> al <strong>{{ form.end_date }}</strong></span>
                    </p>
                </div>

                <!-- Actions -->
                <div class="flex justify-end space-x-4">
                    <Link
                        :href="route('incidents.index')"
                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors"
                    >
                        Cancelar
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing || form.employee_ids.length === 0"
                        class="px-6 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors disabled:opacity-50"
                    >
                        {{ form.processing ? 'Creando...' : `Crear ${form.employee_ids.length} Incidencias` }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

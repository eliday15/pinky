<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    employees: Array,
    types: Array,
});

const form = useForm({
    employee_ids: [],
    type: '',
    date: new Date().toISOString().split('T')[0],
    start_time: '',
    end_time: '',
    hours: '',
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

const submit = () => {
    form.post(route('authorizations.storeBulk'));
};

const typeDescriptions = {
    overtime: 'Horas adicionales trabajadas fuera del horario normal',
    night_shift: 'Turno nocturno o velada completa',
    exit_permission: 'Permiso para salir antes del horario establecido',
    entry_permission: 'Permiso para entrar despues del horario establecido',
    schedule_change: 'Cambio temporal en el horario de trabajo',
    holiday_worked: 'Trabajo realizado en dia festivo oficial',
    special: 'Autorizacion especial que no encaja en otras categorias',
};
</script>

<template>
    <Head title="Autorizacion Masiva" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Autorizacion Masiva
            </h2>
        </template>

        <div class="max-w-5xl">
            <!-- Breadcrumb -->
            <div class="mb-6">
                <Link :href="route('authorizations.index')" class="text-pink-600 hover:text-pink-800">
                    &larr; Volver a autorizaciones
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

                <!-- Authorization Type -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Tipo de Autorizacion</h3>
                    <div>
                        <select
                            v-model="form.type"
                            class="w-full md:w-1/2 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            :class="{ 'border-red-500': form.errors.type }"
                        >
                            <option value="">Seleccionar tipo...</option>
                            <option v-for="type in types" :key="type.value" :value="type.value">
                                {{ type.label }}
                            </option>
                        </select>
                        <p v-if="form.type && typeDescriptions[form.type]" class="mt-2 text-sm text-gray-500">
                            {{ typeDescriptions[form.type] }}
                        </p>
                        <p v-if="form.errors.type" class="mt-1 text-sm text-red-600">
                            {{ form.errors.type }}
                        </p>
                    </div>
                </div>

                <!-- Date & Time -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Fecha y Horario</h3>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Fecha *
                            </label>
                            <input
                                v-model="form.date"
                                type="date"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.date }"
                            />
                            <p v-if="form.errors.date" class="mt-1 text-sm text-red-600">
                                {{ form.errors.date }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Hora Inicio
                            </label>
                            <input
                                v-model="form.start_time"
                                type="time"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Hora Fin
                            </label>
                            <input
                                v-model="form.end_time"
                                type="time"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Horas Totales
                            </label>
                            <input
                                v-model="form.hours"
                                type="number"
                                step="0.5"
                                min="0"
                                max="24"
                                placeholder="Auto"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            />
                            <p class="mt-1 text-xs text-gray-500">Auto si pone inicio/fin</p>
                        </div>
                    </div>
                </div>

                <!-- Reason -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Justificacion</h3>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Razon / Motivo *
                        </label>
                        <textarea
                            v-model="form.reason"
                            rows="3"
                            placeholder="Describa el motivo de esta autorizacion (aplica para todos los empleados seleccionados)..."
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            :class="{ 'border-red-500': form.errors.reason }"
                        ></textarea>
                        <p v-if="form.errors.reason" class="mt-1 text-sm text-red-600">
                            {{ form.errors.reason }}
                        </p>
                    </div>
                </div>

                <!-- Summary -->
                <div v-if="form.employee_ids.length > 0" class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <p class="text-sm text-blue-800">
                        Se crearan <strong>{{ form.employee_ids.length }}</strong> autorizaciones
                        <span v-if="form.type"> de tipo <strong>{{ types.find(t => t.value === form.type)?.label }}</strong></span>
                        <span v-if="form.date"> para el <strong>{{ form.date }}</strong></span>
                    </p>
                </div>

                <!-- Actions -->
                <div class="flex justify-end space-x-4">
                    <Link
                        :href="route('authorizations.index')"
                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors"
                    >
                        Cancelar
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing || form.employee_ids.length === 0"
                        class="px-6 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors disabled:opacity-50"
                    >
                        {{ form.processing ? 'Creando...' : `Crear ${form.employee_ids.length} Autorizaciones` }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

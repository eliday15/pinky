<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    employee: Object,
    departments: Array,
    positions: Array,
    schedules: Array,
    employees: Array,
});

const originalScheduleId = props.employee.schedule_id;

const form = useForm({
    employee_number: props.employee.employee_number,
    contpaqi_code: props.employee.contpaqi_code || '',
    zkteco_user_id: props.employee.zkteco_user_id,
    first_name: props.employee.first_name,
    last_name: props.employee.last_name,
    email: props.employee.email || '',
    phone: props.employee.phone || '',
    hire_date: props.employee.hire_date?.split('T')[0] || '',
    termination_date: props.employee.termination_date?.split('T')[0] || '',
    department_id: props.employee.department_id,
    position_id: props.employee.position_id,
    schedule_id: props.employee.schedule_id,
    supervisor_id: props.employee.supervisor_id || '',
    hourly_rate: props.employee.hourly_rate,
    overtime_rate: props.employee.overtime_rate,
    holiday_rate: props.employee.holiday_rate,
    vacation_days_entitled: props.employee.vacation_days_entitled,
    vacation_days_used: props.employee.vacation_days_used,
    status: props.employee.status,
    schedule_change_evidence: null,
});

const scheduleChanged = computed(() => form.schedule_id != originalScheduleId);

const selectedSchedule = computed(() => {
    return props.schedules.find(s => s.id === form.schedule_id);
});

const selectedPosition = computed(() => {
    return props.positions.find(p => p.id === form.position_id);
});

const positionTypeLabels = {
    operativo: 'Operativo',
    administrativo: 'Administrativo',
    gerencial: 'Gerencial',
    directivo: 'Directivo',
};

const handleFileChange = (e) => {
    form.schedule_change_evidence = e.target.files[0];
};

const submit = () => {
    form.post(route('employees.update', props.employee.id), {
        _method: 'PUT',
        forceFormData: true,
    });
};
</script>

<template>
    <Head :title="`Editar - ${employee.full_name}`" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Editar Empleado
            </h2>
        </template>

        <div class="max-w-4xl">
            <!-- Breadcrumb -->
            <div class="mb-6">
                <Link :href="route('employees.index')" class="text-pink-600 hover:text-pink-800">
                    &larr; Volver a empleados
                </Link>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <!-- Personal Information -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Informacion Personal</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Numero de Empleado *
                            </label>
                            <input
                                v-model="form.employee_number"
                                type="text"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.employee_number }"
                            />
                            <p v-if="form.errors.employee_number" class="mt-1 text-sm text-red-600">
                                {{ form.errors.employee_number }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Codigo CONTPAQi
                            </label>
                            <input
                                v-model="form.contpaqi_code"
                                type="text"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.contpaqi_code }"
                                placeholder="Opcional - Si no se indica, usa No. Empleado"
                            />
                            <p v-if="form.errors.contpaqi_code" class="mt-1 text-sm text-red-600">
                                {{ form.errors.contpaqi_code }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                ID ZKTeco *
                            </label>
                            <input
                                v-model="form.zkteco_user_id"
                                type="number"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.zkteco_user_id }"
                            />
                            <p v-if="form.errors.zkteco_user_id" class="mt-1 text-sm text-red-600">
                                {{ form.errors.zkteco_user_id }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Nombre *
                            </label>
                            <input
                                v-model="form.first_name"
                                type="text"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Apellidos *
                            </label>
                            <input
                                v-model="form.last_name"
                                type="text"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                            <input
                                v-model="form.email"
                                type="email"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Telefono</label>
                            <input
                                v-model="form.phone"
                                type="text"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Fecha de Ingreso *
                            </label>
                            <input
                                v-model="form.hire_date"
                                type="date"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Fecha de Baja
                            </label>
                            <input
                                v-model="form.termination_date"
                                type="date"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            />
                        </div>
                    </div>
                </div>

                <!-- Work Information -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Informacion Laboral</h3>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Departamento *
                            </label>
                            <select
                                v-model="form.department_id"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            >
                                <option v-for="dept in departments" :key="dept.id" :value="dept.id">
                                    {{ dept.name }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Puesto *
                            </label>
                            <select
                                v-model="form.position_id"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            >
                                <option v-for="pos in positions" :key="pos.id" :value="pos.id">
                                    {{ pos.name }}
                                </option>
                            </select>
                            <p v-if="selectedPosition?.position_type" class="mt-1 text-sm text-blue-600">
                                Tipo: {{ positionTypeLabels[selectedPosition.position_type] || selectedPosition.position_type }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Horario *
                            </label>
                            <select
                                v-model="form.schedule_id"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-yellow-500 ring-1 ring-yellow-500': scheduleChanged }"
                            >
                                <option v-for="sched in schedules" :key="sched.id" :value="sched.id">
                                    {{ sched.name }}
                                </option>
                            </select>
                            <p v-if="selectedSchedule && !scheduleChanged" class="mt-1 text-sm text-blue-600">
                                Tipo: {{ selectedSchedule.is_flexible ? 'Flexible' : 'Fijo' }}
                            </p>
                            <p v-if="scheduleChanged" class="mt-1 text-sm text-yellow-600">
                                Cambio de horario detectado - se requiere evidencia
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Estado *
                            </label>
                            <select
                                v-model="form.status"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            >
                                <option value="active">Activo</option>
                                <option value="inactive">Inactivo</option>
                                <option value="terminated">Baja</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-6">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Jefe Directo
                        </label>
                        <select
                            v-model="form.supervisor_id"
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            :class="{ 'border-red-500': form.errors.supervisor_id }"
                        >
                            <option value="">Sin jefe directo asignado</option>
                            <option v-for="emp in employees" :key="emp.id" :value="emp.id">
                                {{ emp.full_name }}
                            </option>
                        </select>
                        <p class="mt-1 text-sm text-gray-500">
                            Supervisor o jefe inmediato del empleado
                        </p>
                        <p v-if="form.errors.supervisor_id" class="mt-1 text-sm text-red-600">
                            {{ form.errors.supervisor_id }}
                        </p>
                    </div>

                    <!-- Current Supervisor Info -->
                    <div v-if="employee.supervisor" class="mt-4 p-3 bg-gray-50 rounded-lg">
                        <p class="text-sm text-gray-600">
                            <span class="font-medium">Jefe actual:</span> {{ employee.supervisor.full_name }}
                        </p>
                    </div>

                    <!-- Schedule Change Evidence -->
                    <div v-if="scheduleChanged" class="mt-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                        <label class="block text-sm font-medium text-yellow-800 mb-2">
                            Evidencia de Cambio de Horario *
                        </label>
                        <input
                            type="file"
                            @change="handleFileChange"
                            accept=".pdf,.jpg,.jpeg,.png"
                            class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-yellow-100 file:text-yellow-700 hover:file:bg-yellow-200"
                            :class="{ 'border-red-500': form.errors.schedule_change_evidence }"
                        />
                        <p class="mt-1 text-xs text-yellow-600">
                            Sube un documento que justifique el cambio de horario (PDF, JPG, PNG - Max 5MB)
                        </p>
                        <p v-if="form.errors.schedule_change_evidence" class="mt-1 text-sm text-red-600">
                            {{ form.errors.schedule_change_evidence }}
                        </p>
                    </div>
                </div>

                <!-- Compensation -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Compensacion y Tarifas</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Tarifa por Hora (MXN) *
                            </label>
                            <input
                                v-model="form.hourly_rate"
                                type="number"
                                step="0.01"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Multiplicador Hora Extra
                            </label>
                            <input
                                v-model="form.overtime_rate"
                                type="number"
                                step="0.1"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            />
                            <p class="mt-1 text-sm text-gray-500">
                                Ej: 1.5 = 50% extra
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Multiplicador Dia Festivo
                            </label>
                            <input
                                v-model="form.holiday_rate"
                                type="number"
                                step="0.1"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            />
                            <p class="mt-1 text-sm text-gray-500">
                                Ej: 2.0 = doble pago
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Vacations -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Dias de Vacaciones</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Dias Correspondientes
                            </label>
                            <input
                                v-model="form.vacation_days_entitled"
                                type="number"
                                min="0"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            />
                            <p class="mt-1 text-sm text-gray-500">
                                Dias de vacaciones anuales
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Dias Tomados
                            </label>
                            <input
                                v-model="form.vacation_days_used"
                                type="number"
                                min="0"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            />
                            <p class="mt-1 text-sm text-gray-500">
                                Dias de vacaciones usados
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Dias Disponibles
                            </label>
                            <input
                                :value="form.vacation_days_entitled - form.vacation_days_used"
                                type="number"
                                disabled
                                class="w-full rounded-lg border-gray-300 bg-gray-50 shadow-sm"
                            />
                            <p class="mt-1 text-sm text-gray-500">
                                Calculado automaticamente
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex justify-end space-x-4">
                    <Link
                        :href="route('employees.index')"
                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors"
                    >
                        Cancelar
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-6 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors disabled:opacity-50"
                    >
                        {{ form.processing ? 'Guardando...' : 'Actualizar Empleado' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const props = defineProps({
    schedule: Object,
});

const weekDays = [
    { value: 'monday', label: 'Lunes' },
    { value: 'tuesday', label: 'Martes' },
    { value: 'wednesday', label: 'Miercoles' },
    { value: 'thursday', label: 'Jueves' },
    { value: 'friday', label: 'Viernes' },
    { value: 'saturday', label: 'Sabado' },
    { value: 'sunday', label: 'Domingo' },
];

const formatTimeForInput = (time) => {
    if (!time) return '';
    return time.substring(0, 5);
};

/** Initialize day_schedules from props, formatting times. */
const initDaySchedules = () => {
    const ds = props.schedule.day_schedules || {};
    const result = {};
    for (const [day, values] of Object.entries(ds)) {
        result[day] = {
            entry_time: formatTimeForInput(values.entry_time) || '',
            exit_time: formatTimeForInput(values.exit_time) || '',
            break_start: formatTimeForInput(values.break_start) || '',
            break_end: formatTimeForInput(values.break_end) || '',
            break_minutes: values.break_minutes ?? 0,
            daily_work_hours: values.daily_work_hours ?? 8,
        };
    }
    return result;
};

const existingDaySchedules = initDaySchedules();
const sameForAllDays = ref(Object.keys(existingDaySchedules).length === 0);

const form = useForm({
    name: props.schedule.name,
    code: props.schedule.code,
    description: props.schedule.description || '',
    entry_time: formatTimeForInput(props.schedule.entry_time),
    exit_time: formatTimeForInput(props.schedule.exit_time),
    break_start: formatTimeForInput(props.schedule.break_start),
    break_end: formatTimeForInput(props.schedule.break_end),
    break_minutes: props.schedule.break_minutes ?? 0,
    late_tolerance_minutes: props.schedule.late_tolerance_minutes ?? 0,
    daily_work_hours: props.schedule.daily_work_hours ?? 8,
    is_flexible: props.schedule.is_flexible ?? false,
    working_days: props.schedule.working_days ?? ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
    day_schedules: existingDaySchedules,
    is_active: props.schedule.is_active ?? true,
});

const toggleDay = (day) => {
    const idx = form.working_days.indexOf(day);
    if (idx > -1) {
        form.working_days.splice(idx, 1);
        if (form.day_schedules[day]) {
            delete form.day_schedules[day];
        }
    } else {
        form.working_days.push(day);
        if (!sameForAllDays.value) {
            initDayOverride(day);
        }
    }
};

const initDayOverride = (day) => {
    if (!form.day_schedules[day]) {
        form.day_schedules[day] = {
            entry_time: form.entry_time || '',
            exit_time: form.exit_time || '',
            break_start: form.break_start || '',
            break_end: form.break_end || '',
            break_minutes: form.break_minutes ?? 0,
            daily_work_hours: form.daily_work_hours ?? 8,
        };
    }
};

watch(sameForAllDays, (val) => {
    if (!val) {
        form.working_days.forEach(day => initDayOverride(day));
    } else {
        form.day_schedules = {};
    }
});

const selectedDays = computed(() => {
    return weekDays.filter(d => form.working_days.includes(d.value));
});

const submit = () => {
    if (sameForAllDays.value) {
        form.day_schedules = {};
    }
    form.put(route('schedules.update', props.schedule.id));
};
</script>

<template>
    <Head :title="`Editar - ${schedule.name}`" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Editar Horario
            </h2>
        </template>

        <div class="max-w-4xl">
            <!-- Breadcrumb -->
            <div class="mb-6">
                <Link :href="route('schedules.index')" class="text-pink-600 hover:text-pink-800">
                    &larr; Volver a horarios
                </Link>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <!-- General Information -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Informacion General</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nombre *</label>
                            <input v-model="form.name" type="text" placeholder="Ej: Turno Matutino" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" :class="{ 'border-red-500': form.errors.name }" />
                            <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Codigo *</label>
                            <input v-model="form.code" type="text" placeholder="Ej: TM-001" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" :class="{ 'border-red-500': form.errors.code }" />
                            <p v-if="form.errors.code" class="mt-1 text-sm text-red-600">{{ form.errors.code }}</p>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Descripcion</label>
                            <textarea v-model="form.description" rows="3" placeholder="Descripcion del horario..." class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" :class="{ 'border-red-500': form.errors.description }" />
                            <p v-if="form.errors.description" class="mt-1 text-sm text-red-600">{{ form.errors.description }}</p>
                        </div>
                    </div>
                </div>

                <!-- Working Days -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Dias Laborales</h3>
                    <p class="text-sm text-gray-500 mb-4">Selecciona los dias en que aplica este horario</p>
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
                        <label
                            v-for="day in weekDays"
                            :key="day.value"
                            :class="[
                                'flex items-center justify-center px-4 py-3 rounded-lg border-2 cursor-pointer transition-colors text-sm font-medium',
                                form.working_days.includes(day.value)
                                    ? 'border-pink-500 bg-pink-50 text-pink-700'
                                    : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300'
                            ]"
                        >
                            <input type="checkbox" :checked="form.working_days.includes(day.value)" @change="toggleDay(day.value)" class="sr-only" />
                            {{ day.label }}
                        </label>
                    </div>
                    <p v-if="form.errors.working_days" class="mt-2 text-sm text-red-600">{{ form.errors.working_days }}</p>
                </div>

                <!-- Schedule Times -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-800">Horarios de Entrada y Salida</h3>
                        <label class="flex items-center cursor-pointer">
                            <input v-model="sameForAllDays" type="checkbox" class="rounded border-gray-300 text-pink-600 focus:ring-pink-500" />
                            <span class="ml-2 text-sm font-medium text-gray-700">Mismo horario todos los dias</span>
                        </label>
                    </div>

                    <!-- Same schedule for all days -->
                    <div v-if="sameForAllDays">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Hora de Entrada *</label>
                                <input v-model="form.entry_time" type="time" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" :class="{ 'border-red-500': form.errors.entry_time }" />
                                <p v-if="form.errors.entry_time" class="mt-1 text-sm text-red-600">{{ form.errors.entry_time }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Hora de Salida *</label>
                                <input v-model="form.exit_time" type="time" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" :class="{ 'border-red-500': form.errors.exit_time }" />
                                <p v-if="form.errors.exit_time" class="mt-1 text-sm text-red-600">{{ form.errors.exit_time }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Inicio de Descanso</label>
                                <input v-model="form.break_start" type="time" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Fin de Descanso</label>
                                <input v-model="form.break_end" type="time" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" />
                            </div>
                        </div>
                    </div>

                    <!-- Per-day schedule -->
                    <div v-else>
                        <p class="text-sm text-gray-500 mb-3">Define horario especifico para cada dia laboral</p>

                        <div class="mb-4 p-3 bg-gray-50 rounded-lg">
                            <p class="text-xs font-medium text-gray-500 mb-2">Valores por defecto (se usan cuando un dia no tiene horario especifico)</p>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                <div>
                                    <label class="block text-xs text-gray-500 mb-1">Entrada</label>
                                    <input v-model="form.entry_time" type="time" class="w-full rounded border-gray-300 text-sm focus:border-pink-500 focus:ring-pink-500" />
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-500 mb-1">Salida</label>
                                    <input v-model="form.exit_time" type="time" class="w-full rounded border-gray-300 text-sm focus:border-pink-500 focus:ring-pink-500" />
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-500 mb-1">Inicio Descanso</label>
                                    <input v-model="form.break_start" type="time" class="w-full rounded border-gray-300 text-sm focus:border-pink-500 focus:ring-pink-500" />
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-500 mb-1">Fin Descanso</label>
                                    <input v-model="form.break_end" type="time" class="w-full rounded border-gray-300 text-sm focus:border-pink-500 focus:ring-pink-500" />
                                </div>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="border-b">
                                        <th class="text-left py-2 px-2 font-medium text-gray-700">Dia</th>
                                        <th class="text-left py-2 px-2 font-medium text-gray-700">Entrada</th>
                                        <th class="text-left py-2 px-2 font-medium text-gray-700">Salida</th>
                                        <th class="text-left py-2 px-2 font-medium text-gray-700">Descanso Inicio</th>
                                        <th class="text-left py-2 px-2 font-medium text-gray-700">Descanso Fin</th>
                                        <th class="text-left py-2 px-2 font-medium text-gray-700">Min Descanso</th>
                                        <th class="text-left py-2 px-2 font-medium text-gray-700">Hrs Trabajo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="day in selectedDays" :key="day.value" class="border-b hover:bg-gray-50">
                                        <td class="py-2 px-2 font-medium text-gray-800">{{ day.label }}</td>
                                        <td class="py-2 px-2">
                                            <input v-model="form.day_schedules[day.value].entry_time" type="time" class="w-full rounded border-gray-300 text-sm focus:border-pink-500 focus:ring-pink-500" />
                                        </td>
                                        <td class="py-2 px-2">
                                            <input v-model="form.day_schedules[day.value].exit_time" type="time" class="w-full rounded border-gray-300 text-sm focus:border-pink-500 focus:ring-pink-500" />
                                        </td>
                                        <td class="py-2 px-2">
                                            <input v-model="form.day_schedules[day.value].break_start" type="time" class="w-full rounded border-gray-300 text-sm focus:border-pink-500 focus:ring-pink-500" />
                                        </td>
                                        <td class="py-2 px-2">
                                            <input v-model="form.day_schedules[day.value].break_end" type="time" class="w-full rounded border-gray-300 text-sm focus:border-pink-500 focus:ring-pink-500" />
                                        </td>
                                        <td class="py-2 px-2">
                                            <input v-model="form.day_schedules[day.value].break_minutes" type="number" min="0" class="w-20 rounded border-gray-300 text-sm focus:border-pink-500 focus:ring-pink-500" />
                                        </td>
                                        <td class="py-2 px-2">
                                            <input v-model="form.day_schedules[day.value].daily_work_hours" type="number" min="0" step="0.5" class="w-20 rounded border-gray-300 text-sm focus:border-pink-500 focus:ring-pink-500" />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Configuration -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Configuracion</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div v-if="sameForAllDays">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Minutos de Descanso</label>
                            <input v-model="form.break_minutes" type="number" min="0" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" :class="{ 'border-red-500': form.errors.break_minutes }" />
                            <p v-if="form.errors.break_minutes" class="mt-1 text-sm text-red-600">{{ form.errors.break_minutes }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tolerancia de Retardo (min)</label>
                            <input v-model="form.late_tolerance_minutes" type="number" min="0" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" :class="{ 'border-red-500': form.errors.late_tolerance_minutes }" />
                            <p class="mt-1 text-sm text-gray-500">Minutos permitidos despues de la hora de entrada</p>
                        </div>
                        <div v-if="sameForAllDays">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Horas Diarias de Trabajo *</label>
                            <input v-model="form.daily_work_hours" type="number" min="0" step="0.5" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" :class="{ 'border-red-500': form.errors.daily_work_hours }" />
                            <p v-if="form.errors.daily_work_hours" class="mt-1 text-sm text-red-600">{{ form.errors.daily_work_hours }}</p>
                        </div>
                    </div>

                    <!-- Flexible Toggle -->
                    <div class="mt-6">
                        <label class="flex items-center">
                            <input v-model="form.is_flexible" type="checkbox" class="rounded border-gray-300 text-pink-600 focus:ring-pink-500" />
                            <span class="ml-2 text-sm font-medium text-gray-700">Horario Flexible</span>
                        </label>
                        <p class="mt-1 text-sm text-gray-500 ml-6">Permite a los empleados registrar entrada y salida en horarios variables</p>
                    </div>

                    <!-- Active Toggle -->
                    <div class="mt-4">
                        <label class="flex items-center">
                            <input v-model="form.is_active" type="checkbox" class="rounded border-gray-300 text-pink-600 focus:ring-pink-500" />
                            <span class="ml-2 text-sm font-medium text-gray-700">Activo</span>
                        </label>
                        <p class="mt-1 text-sm text-gray-500 ml-6">Si esta desactivado, no se podra asignar a nuevos empleados</p>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex justify-end space-x-4">
                    <Link :href="route('schedules.index')" class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">
                        Cancelar
                    </Link>
                    <button type="submit" :disabled="form.processing" class="px-6 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors disabled:opacity-50">
                        {{ form.processing ? 'Guardando...' : 'Actualizar Horario' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

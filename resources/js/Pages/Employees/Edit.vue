<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    employee: Object,
    departments: Array,
    positions: Array,
    schedules: Array,
    employees: Array,
    compensationTypes: Array,
    vacationTable: Array,
    roles: Array,
    canCreateUser: Boolean,
});

// User account creation form
const userForm = useForm({
    name: props.employee.full_name || '',
    email: props.employee.email || '',
    password: '',
    role: '',
    employee_id: props.employee.id,
});

const showUserPassword = ref(false);

const generateUserPassword = () => {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%';
    let password = '';
    for (let i = 0; i < 12; i++) {
        password += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    userForm.password = password;
    showUserPassword.value = true;
};

const copyUserPassword = () => {
    navigator.clipboard.writeText(userForm.password);
};

const createUserAccount = () => {
    userForm.post(route('users.store'), {
        preserveScroll: true,
    });
};

const originalScheduleId = props.employee.schedule_id;
const originalPositionId = props.employee.position_id;

/**
 * Build initial compensation_type_ids and overrides from the employee's existing relation.
 */
const initialCompensationTypeIds = (props.employee.compensation_types || []).map(ct => ct.id);
const initialCompensationTypeOverrides = {};
(props.employee.compensation_types || []).forEach(ct => {
    if (ct.pivot?.custom_percentage) {
        initialCompensationTypeOverrides[ct.id] = ct.pivot.custom_percentage;
    }
});

const form = useForm({
    employee_number: props.employee.employee_number,
    contpaqi_code: props.employee.contpaqi_code || '',
    zkteco_user_id: props.employee.zkteco_user_id,
    first_name: props.employee.first_name,
    last_name: props.employee.last_name,
    email: props.employee.email || '',
    phone: props.employee.phone || '',
    address_street: props.employee.address_street || '',
    address_city: props.employee.address_city || '',
    address_state: props.employee.address_state || '',
    address_zip: props.employee.address_zip || '',
    photo: null,
    emergency_contacts: (props.employee.emergency_contacts || []).length
        ? props.employee.emergency_contacts.map(c => ({
            name: c.name || '',
            phone: c.phone || '',
            email: c.email || '',
            relationship: c.relationship || '',
            address: c.address || '',
        }))
        : [{ name: '', phone: '', email: '', relationship: '', address: '' }],
    credential_type: props.employee.credential_type || '',
    credential_number: props.employee.credential_number || '',
    hire_date: props.employee.hire_date?.split('T')[0] || '',
    termination_date: props.employee.termination_date?.split('T')[0] || '',
    department_id: props.employee.department_id,
    position_id: props.employee.position_id,
    schedule_id: props.employee.schedule_id,
    schedule_overrides: props.employee.schedule_overrides || {},
    supervisor_id: props.employee.supervisor_id || '',
    hourly_rate: props.employee.hourly_rate,
    is_minimum_wage: props.employee.is_minimum_wage || false,
    is_trial_period: props.employee.is_trial_period || false,
    trial_period_end_date: props.employee.trial_period_end_date?.split('T')[0] || '',
    imss_number: props.employee.imss_number || '',
    daily_salary: props.employee.daily_salary || '',
    monthly_bonus_type: props.employee.monthly_bonus_type || 'none',
    monthly_bonus_amount: props.employee.monthly_bonus_amount || 0,
    vacation_days_entitled: props.employee.vacation_days_entitled,
    vacation_days_used: props.employee.vacation_days_used,
    vacation_days_reserved: props.employee.vacation_days_reserved || 0,
    vacation_premium_percentage: props.employee.vacation_premium_percentage ?? 25.00,
    status: props.employee.status,
    schedule_change_evidence: null,
    compensation_type_ids: initialCompensationTypeIds,
    compensation_type_overrides: initialCompensationTypeOverrides,
});

const photoPreview = ref(props.employee.photo_path ? `/storage/${props.employee.photo_path}` : null);
const handlePhotoChange = (e) => {
    const file = e.target.files[0];
    form.photo = file;
    if (file) {
        const reader = new FileReader();
        reader.onload = (ev) => { photoPreview.value = ev.target.result; };
        reader.readAsDataURL(file);
    }
};

const autoFilledFields = ref({});
const overrideSupervisor = ref(false);
const showPositionComparison = ref(false);
const positionTemplateValues = ref(null);

const scheduleChanged = computed(() => form.schedule_id != originalScheduleId);

const selectedSchedule = computed(() => {
    return props.schedules.find(s => s.id === form.schedule_id);
});

const weekDays = [
    { value: 'monday', label: 'Lunes', short: 'Lun' },
    { value: 'tuesday', label: 'Martes', short: 'Mar' },
    { value: 'wednesday', label: 'Miercoles', short: 'Mie' },
    { value: 'thursday', label: 'Jueves', short: 'Jue' },
    { value: 'friday', label: 'Viernes', short: 'Vie' },
    { value: 'saturday', label: 'Sabado', short: 'Sab' },
    { value: 'sunday', label: 'Domingo', short: 'Dom' },
];
const allDays = weekDays.map(d => d.value);

const scheduleFields = ref({
    entry_time: '',
    exit_time: '',
    break_minutes: '',
    daily_work_hours: '',
    late_tolerance_minutes: '',
    working_days: [],
});

const existingOverrides = form.schedule_overrides || {};
const perDayMode = ref(!!existingOverrides.day_schedules);
const daySchedules = ref(existingOverrides.day_schedules ? { ...existingOverrides.day_schedules } : {});

/** Initialize schedule fields from current schedule + overrides. */
const initScheduleFields = () => {
    const s = selectedSchedule.value;
    if (!s) return;
    const ov = form.schedule_overrides || {};
    scheduleFields.value = {
        entry_time: ov.entry_time || (s.entry_time ? s.entry_time.substring(0, 5) : ''),
        exit_time: ov.exit_time || (s.exit_time ? s.exit_time.substring(0, 5) : ''),
        break_minutes: ov.break_minutes ?? s.break_minutes ?? '',
        daily_work_hours: ov.daily_work_hours ?? s.daily_work_hours ?? '',
        late_tolerance_minutes: ov.late_tolerance_minutes ?? s.late_tolerance_minutes ?? '',
        working_days: ov.working_days || (s.working_days ? [...s.working_days] : []),
    };
};
initScheduleFields();

watch(() => form.schedule_id, () => {
    const s = selectedSchedule.value;
    if (s) {
        scheduleFields.value = {
            entry_time: s.entry_time ? s.entry_time.substring(0, 5) : '',
            exit_time: s.exit_time ? s.exit_time.substring(0, 5) : '',
            break_minutes: s.break_minutes ?? '',
            daily_work_hours: s.daily_work_hours ?? '',
            late_tolerance_minutes: s.late_tolerance_minutes ?? '',
            working_days: s.working_days ? [...s.working_days] : [],
        };
        form.schedule_overrides = {};
        perDayMode.value = false;
        daySchedules.value = {};
    }
});

const updateScheduleOverride = (field, value) => {
    scheduleFields.value[field] = value;
    syncOverrides();
};

const toggleWorkingDay = (day) => {
    const days = [...scheduleFields.value.working_days];
    const idx = days.indexOf(day);
    if (idx > -1) {
        days.splice(idx, 1);
        delete daySchedules.value[day];
    } else {
        days.push(day);
    }
    scheduleFields.value.working_days = days;
    syncOverrides();
};

const selectedWorkingDays = computed(() => {
    return weekDays.filter(d => scheduleFields.value.working_days.includes(d.value));
});

watch(perDayMode, (val) => {
    if (val) {
        const s = selectedSchedule.value;
        scheduleFields.value.working_days.forEach(day => {
            if (!daySchedules.value[day]) {
                const baseDayOverride = s?.day_schedules?.[day] || {};
                daySchedules.value[day] = {
                    entry_time: baseDayOverride.entry_time || scheduleFields.value.entry_time || '',
                    exit_time: baseDayOverride.exit_time || scheduleFields.value.exit_time || '',
                    break_minutes: baseDayOverride.break_minutes ?? scheduleFields.value.break_minutes ?? '',
                    daily_work_hours: baseDayOverride.daily_work_hours ?? scheduleFields.value.daily_work_hours ?? '',
                };
            }
        });
    }
    syncOverrides();
});

const updateDayField = (day, field, value) => {
    if (!daySchedules.value[day]) daySchedules.value[day] = {};
    daySchedules.value[day][field] = value;
    syncOverrides();
};

const syncOverrides = () => {
    const s = selectedSchedule.value;
    if (!s) return;

    const overrides = {};
    const baseDays = [...(s.working_days || [])].sort();
    const newDays = [...scheduleFields.value.working_days].sort();
    if (JSON.stringify(baseDays) !== JSON.stringify(newDays)) {
        overrides.working_days = scheduleFields.value.working_days;
    }

    if (!perDayMode.value) {
        ['entry_time', 'exit_time'].forEach(f => {
            const baseVal = (s[f] || '').substring(0, 5);
            if (scheduleFields.value[f] && scheduleFields.value[f] !== baseVal) overrides[f] = scheduleFields.value[f];
        });
        ['break_minutes', 'daily_work_hours', 'late_tolerance_minutes'].forEach(f => {
            const val = scheduleFields.value[f];
            if (val !== '' && val !== null && parseFloat(val) !== parseFloat(s[f])) overrides[f] = val;
        });
    } else {
        const ds = {};
        for (const [day, vals] of Object.entries(daySchedules.value)) {
            if (!scheduleFields.value.working_days.includes(day)) continue;
            ds[day] = { ...vals };
        }
        if (Object.keys(ds).length) overrides.day_schedules = ds;
        const tol = scheduleFields.value.late_tolerance_minutes;
        if (tol !== '' && tol !== null && parseFloat(tol) !== parseFloat(s.late_tolerance_minutes)) overrides.late_tolerance_minutes = tol;
    }

    form.schedule_overrides = Object.keys(overrides).length ? overrides : {};
};

const selectedPosition = computed(() => {
    return props.positions.find(p => p.id === form.position_id);
});

const positionTypeLabels = {
    operativo: 'Operativo',
    administrativo: 'Administrativo',
    gerencial: 'Gerencial',
    directivo: 'Directivo',
};

/**
 * Resolve the supervisor employee from the position's supervisor_position relation.
 */
const resolvedSupervisor = computed(() => {
    const position = selectedPosition.value;
    if (!position?.supervisor_position) return null;
    return props.employees.find(emp => emp.position_id === position.supervisor_position.id) || null;
});

/**
 * Template info badge text for the selected position.
 */
const positionTemplateInfo = computed(() => {
    const position = selectedPosition.value;
    if (!position) return null;
    const dept = props.departments.find(d => d.id === position.department_id);
    const sched = props.schedules.find(s => s.id === position.default_schedule_id);
    if (!dept && !sched && !position.base_hourly_rate) return null;
    const parts = [];
    if (dept) parts.push(`Depto ${dept.name}`);
    if (sched) parts.push(`Horario ${sched.name}`);
    if (position.base_hourly_rate) parts.push(`Tarifa $${position.base_hourly_rate}/hr`);
    return parts.join(', ');
});

/**
 * Calculate years of service from hire_date and determine vacation days from the vacation table.
 */
const vacationCalcInfo = computed(() => {
    if (!form.hire_date || !props.vacationTable?.length) return null;
    const hireDate = new Date(form.hire_date);
    const today = new Date();
    let years = today.getFullYear() - hireDate.getFullYear();
    const monthDiff = today.getMonth() - hireDate.getMonth();
    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < hireDate.getDate())) {
        years--;
    }
    if (years < 1) years = 1;

    const sorted = [...props.vacationTable].sort((a, b) => a.years_of_service - b.years_of_service);
    let days = sorted[0]?.vacation_days || 6;
    for (const entry of sorted) {
        if (entry.years_of_service <= years) {
            days = entry.vacation_days;
        }
    }
    return { years, days };
});

const handleFileChange = (e) => {
    form.schedule_change_evidence = e.target.files[0];
};

const onPositionChange = () => {
    const position = props.positions.find(p => p.id === form.position_id);
    if (!position) {
        showPositionComparison.value = false;
        return;
    }

    // Show comparison if position changed from original
    if (form.position_id != originalPositionId) {
        positionTemplateValues.value = {
            department: props.departments.find(d => d.id === position.department_id)?.name || '-',
            schedule: props.schedules.find(s => s.id === position.default_schedule_id)?.name || '-',
            hourly_rate: position.base_hourly_rate || '-',
        };
        showPositionComparison.value = true;
    } else {
        showPositionComparison.value = false;
    }

    const filled = {};

    if (position.department_id) {
        form.department_id = position.department_id;
        filled.department_id = true;
    }
    if (position.default_schedule_id) {
        form.schedule_id = position.default_schedule_id;
        filled.schedule_id = true;
    }
    if (position.base_hourly_rate) {
        form.hourly_rate = position.base_hourly_rate;
        filled.hourly_rate = true;
    }

    // Auto-fill compensation types from position template
    if (position.compensation_types?.length) {
        form.compensation_type_ids = position.compensation_types.map(ct => ct.id);
        const overrides = {};
        position.compensation_types.forEach(ct => {
            if (ct.calculation_type === 'fixed' && ct.pivot?.default_fixed_amount) {
                overrides[ct.id] = ct.pivot.default_fixed_amount;
            } else if (ct.pivot?.default_percentage) {
                overrides[ct.id] = ct.pivot.default_percentage;
            }
        });
        form.compensation_type_overrides = overrides;
        filled.compensation_type_ids = true;
    }

    // Auto-fill supervisor from position's supervisor_position
    if (position.supervisor_position && !overrideSupervisor.value) {
        const supervisor = props.employees.find(emp => emp.position_id === position.supervisor_position.id);
        if (supervisor) {
            form.supervisor_id = supervisor.id;
            filled.supervisor_id = true;
        }
    }

    autoFilledFields.value = filled;
};

/**
 * When department changes, merge its compensation types (only if not already filled by position).
 */
const onDepartmentChange = () => {
    if (autoFilledFields.value.compensation_type_ids) return;
    const dept = props.departments.find(d => d.id === form.department_id);
    if (!dept?.compensation_types?.length) return;

    const ids = [...form.compensation_type_ids];
    const overrides = { ...form.compensation_type_overrides };
    dept.compensation_types.forEach(ct => {
        if (!ids.includes(ct.id)) {
            ids.push(ct.id);
        }
        if (ct.calculation_type === 'fixed' && ct.pivot?.default_fixed_amount) {
            overrides[ct.id] = overrides[ct.id] || ct.pivot.default_fixed_amount;
        } else if (ct.pivot?.default_percentage) {
            overrides[ct.id] = overrides[ct.id] || ct.pivot.default_percentage;
        }
    });
    form.compensation_type_ids = ids;
    form.compensation_type_overrides = overrides;
};

const onHireDateChange = () => {
    if (vacationCalcInfo.value) {
        form.vacation_days_entitled = vacationCalcInfo.value.days;
        autoFilledFields.value = { ...autoFilledFields.value, vacation_days_entitled: true };
    }
};

const applyTemplateValues = () => {
    // Apply all template values from the new position
    const position = props.positions.find(p => p.id === form.position_id);
    if (position) {
        onPositionChange();
    }
    showPositionComparison.value = false;
};

const dismissComparison = () => {
    showPositionComparison.value = false;
};

const toggleCompensationType = (ctId) => {
    const idx = form.compensation_type_ids.indexOf(ctId);
    if (idx > -1) {
        form.compensation_type_ids.splice(idx, 1);
        const overrides = { ...form.compensation_type_overrides };
        delete overrides[ctId];
        form.compensation_type_overrides = overrides;
    } else {
        form.compensation_type_ids.push(ctId);
    }
};

const addEmergencyContact = () => {
    form.emergency_contacts.push({ name: '', phone: '', email: '', relationship: '', address: '' });
};

const removeEmergencyContact = (index) => {
    if (form.emergency_contacts.length > 1) {
        form.emergency_contacts.splice(index, 1);
    }
};

const relationshipOptions = [
    'Padre/Madre',
    'Esposo/a',
    'Hijo/a',
    'Hermano/a',
    'Otro',
];

const submit = () => {
    form.post(route('employees.update', props.employee.id), {
        _method: 'PUT',
        forceFormData: true,
    });
};

// Watch hire_date for auto-calculation
watch(() => form.hire_date, onHireDateChange);
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
                            <label class="block text-sm font-medium text-gray-700 mb-1">Numero de Empleado *</label>
                            <input v-model="form.employee_number" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" :class="{ 'border-red-500': form.errors.employee_number }" />
                            <p v-if="form.errors.employee_number" class="mt-1 text-sm text-red-600">{{ form.errors.employee_number }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Codigo CONTPAQi</label>
                            <input v-model="form.contpaqi_code" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" :class="{ 'border-red-500': form.errors.contpaqi_code }" placeholder="Opcional - Si no se indica, usa No. Empleado" />
                            <p v-if="form.errors.contpaqi_code" class="mt-1 text-sm text-red-600">{{ form.errors.contpaqi_code }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ID ZKTeco *</label>
                            <input v-model="form.zkteco_user_id" type="number" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" :class="{ 'border-red-500': form.errors.zkteco_user_id }" />
                            <p v-if="form.errors.zkteco_user_id" class="mt-1 text-sm text-red-600">{{ form.errors.zkteco_user_id }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nombre *</label>
                            <input v-model="form.first_name" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Apellidos *</label>
                            <input v-model="form.last_name" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                            <input v-model="form.email" type="email" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Telefono</label>
                            <input v-model="form.phone" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Fecha de Ingreso *</label>
                            <input v-model="form.hire_date" type="date" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Fecha de Baja</label>
                            <input v-model="form.termination_date" type="date" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" />
                        </div>
                        <!-- Photo Upload -->
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Foto</label>
                            <div class="flex items-center gap-4">
                                <div v-if="photoPreview" class="w-16 h-16 rounded-full overflow-hidden">
                                    <img :src="photoPreview" class="w-full h-full object-cover" />
                                </div>
                                <input type="file" @change="handlePhotoChange" accept="image/jpeg,image/png" class="text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-pink-50 file:text-pink-700 hover:file:bg-pink-100" />
                            </div>
                            <p class="mt-1 text-sm text-gray-500">JPG o PNG, max 5MB</p>
                            <p v-if="form.errors.photo" class="mt-1 text-sm text-red-600">{{ form.errors.photo }}</p>
                        </div>
                    </div>
                </div>

                <!-- Emergency Contacts -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-gray-800">Contactos de Emergencia *</h3>
                        <button
                            type="button"
                            @click="addEmergencyContact"
                            class="px-3 py-1.5 text-sm font-medium text-pink-600 border border-pink-300 rounded-lg hover:bg-pink-50 transition-colors"
                        >
                            + Agregar contacto
                        </button>
                    </div>
                    <p v-if="form.errors.emergency_contacts" class="mb-3 text-sm text-red-600">{{ form.errors.emergency_contacts }}</p>

                    <div
                        v-for="(contact, index) in form.emergency_contacts"
                        :key="index"
                        class="p-4 border border-gray-200 rounded-lg mb-4 last:mb-0"
                    >
                        <div class="flex items-center justify-between mb-3">
                            <span class="text-sm font-medium text-gray-600">Contacto {{ index + 1 }}</span>
                            <button
                                type="button"
                                @click="removeEmergencyContact(index)"
                                :disabled="form.emergency_contacts.length <= 1"
                                class="text-sm text-red-500 hover:text-red-700 disabled:opacity-30 disabled:cursor-not-allowed"
                            >
                                Eliminar
                            </button>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nombre *</label>
                                <input
                                    v-model="contact.name"
                                    type="text"
                                    class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                    :class="{ 'border-red-500': form.errors[`emergency_contacts.${index}.name`] }"
                                />
                                <p v-if="form.errors[`emergency_contacts.${index}.name`]" class="mt-1 text-sm text-red-600">{{ form.errors[`emergency_contacts.${index}.name`] }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Telefono *</label>
                                <input
                                    v-model="contact.phone"
                                    type="text"
                                    class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                    :class="{ 'border-red-500': form.errors[`emergency_contacts.${index}.phone`] }"
                                />
                                <p v-if="form.errors[`emergency_contacts.${index}.phone`]" class="mt-1 text-sm text-red-600">{{ form.errors[`emergency_contacts.${index}.phone`] }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                                <input
                                    v-model="contact.email"
                                    type="email"
                                    class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                    :class="{ 'border-red-500': form.errors[`emergency_contacts.${index}.email`] }"
                                />
                                <p v-if="form.errors[`emergency_contacts.${index}.email`]" class="mt-1 text-sm text-red-600">{{ form.errors[`emergency_contacts.${index}.email`] }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Parentesco *</label>
                                <select
                                    v-model="contact.relationship"
                                    class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                    :class="{ 'border-red-500': form.errors[`emergency_contacts.${index}.relationship`] }"
                                >
                                    <option value="">Seleccionar...</option>
                                    <option v-for="rel in relationshipOptions" :key="rel" :value="rel">{{ rel }}</option>
                                </select>
                                <p v-if="form.errors[`emergency_contacts.${index}.relationship`]" class="mt-1 text-sm text-red-600">{{ form.errors[`emergency_contacts.${index}.relationship`] }}</p>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Direccion</label>
                                <input
                                    v-model="contact.address"
                                    type="text"
                                    class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Address & Credentials -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Domicilio y Credenciales</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Calle y Numero</label>
                            <input v-model="form.address_street" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Ciudad</label>
                            <input v-model="form.address_city" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Estado</label>
                            <input v-model="form.address_state" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Codigo Postal</label>
                            <input v-model="form.address_zip" type="text" maxlength="10" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" />
                        </div>
                        <div></div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tipo de Credencial</label>
                            <select v-model="form.credential_type" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500">
                                <option value="">Seleccionar...</option>
                                <option value="INE">INE</option>
                                <option value="Pasaporte">Pasaporte</option>
                                <option value="Licencia">Licencia de Conducir</option>
                                <option value="Otro">Otro</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Numero de Credencial</label>
                            <input v-model="form.credential_number" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" />
                        </div>
                    </div>
                </div>

                <!-- Trial Period & IMSS -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Registro Laboral</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="inline-flex items-center">
                                <input v-model="form.is_trial_period" type="checkbox" class="rounded border-gray-300 text-pink-600 shadow-sm focus:border-pink-500 focus:ring-pink-500" />
                                <span class="ml-2 text-sm font-medium text-gray-700">Periodo de Prueba</span>
                            </label>
                            <p class="mt-1 text-sm text-gray-500">Indica si el empleado esta en periodo de prueba (antes de alta en IMSS)</p>
                        </div>
                        <div v-if="form.is_trial_period">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Fecha Fin Periodo de Prueba</label>
                            <input v-model="form.trial_period_end_date" type="date" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Numero IMSS</label>
                            <input v-model="form.imss_number" type="text" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" placeholder="Numero de seguridad social" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Salario Diario Integrado</label>
                            <input v-model="form.daily_salary" type="number" step="0.01" min="0" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" placeholder="Opcional - se calcula de tarifa/hr si no se indica" />
                            <p class="mt-1 text-sm text-gray-500">Si no se indica, se calcula como tarifa por hora x horas de jornada</p>
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
                                <span v-if="autoFilledFields.department_id" class="text-blue-500 text-xs">(Auto)</span>
                            </label>
                            <select
                                v-model="form.department_id"
                                @change="onDepartmentChange"
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
                                @change="onPositionChange"
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
                                <span v-if="autoFilledFields.schedule_id" class="text-blue-500 text-xs">(Auto)</span>
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

                    <!-- Schedule Details (editable overrides) -->
                    <div v-if="selectedSchedule" class="mt-4 p-4 bg-gray-50 border border-gray-200 rounded-lg">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="text-sm font-semibold text-gray-700">
                                Detalle de Horario — {{ selectedSchedule.name }}
                                <span class="text-xs text-gray-400 font-normal ml-1">({{ selectedSchedule.is_flexible ? 'Flexible' : 'Fijo' }})</span>
                            </h4>
                            <div class="flex items-center gap-3">
                                <span v-if="Object.keys(form.schedule_overrides).length" class="px-2 py-0.5 text-xs font-medium rounded-full bg-orange-100 text-orange-700">Personalizado</span>
                                <label class="flex items-center cursor-pointer">
                                    <input v-model="perDayMode" type="checkbox" class="rounded border-gray-300 text-pink-600 focus:ring-pink-500" />
                                    <span class="ml-1.5 text-xs font-medium text-gray-600">Horario por dia</span>
                                </label>
                            </div>
                        </div>

                        <!-- Dias laborales -->
                        <div class="mb-4">
                            <label class="block text-xs text-gray-500 mb-2">Dias laborales</label>
                            <div class="flex gap-2">
                                <button
                                    v-for="wd in weekDays"
                                    :key="wd.value"
                                    type="button"
                                    @click="toggleWorkingDay(wd.value)"
                                    :class="[
                                        scheduleFields.working_days.includes(wd.value)
                                            ? 'bg-pink-600 text-white border-pink-600'
                                            : 'bg-white text-gray-600 border-gray-300',
                                        'px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors'
                                    ]"
                                >
                                    {{ wd.short }}
                                </button>
                            </div>
                        </div>

                        <!-- Mismo horario todos los dias -->
                        <div v-if="!perDayMode">
                            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                                <div>
                                    <label class="block text-xs text-gray-500 mb-1">Entrada</label>
                                    <input type="time" :value="scheduleFields.entry_time" @input="updateScheduleOverride('entry_time', $event.target.value)" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm" />
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-500 mb-1">Salida</label>
                                    <input type="time" :value="scheduleFields.exit_time" @input="updateScheduleOverride('exit_time', $event.target.value)" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm" />
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-500 mb-1">Horas diarias</label>
                                    <input type="number" step="0.5" min="0" max="24" :value="scheduleFields.daily_work_hours" @input="updateScheduleOverride('daily_work_hours', $event.target.value)" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm" />
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-500 mb-1">Descanso (min)</label>
                                    <input type="number" min="0" max="480" :value="scheduleFields.break_minutes" @input="updateScheduleOverride('break_minutes', $event.target.value)" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm" />
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-500 mb-1">Tolerancia (min)</label>
                                    <input type="number" min="0" max="120" :value="scheduleFields.late_tolerance_minutes" @input="updateScheduleOverride('late_tolerance_minutes', $event.target.value)" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm" />
                                </div>
                            </div>
                        </div>

                        <!-- Horario por dia -->
                        <div v-else>
                            <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-3">
                                <div class="md:col-span-4"></div>
                                <div>
                                    <label class="block text-xs text-gray-500 mb-1">Tolerancia (min)</label>
                                    <input type="number" min="0" max="120" :value="scheduleFields.late_tolerance_minutes" @input="updateScheduleOverride('late_tolerance_minutes', $event.target.value)" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm" />
                                </div>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead>
                                        <tr class="border-b border-gray-300">
                                            <th class="text-left py-2 px-2 font-medium text-gray-600">Dia</th>
                                            <th class="text-left py-2 px-2 font-medium text-gray-600">Entrada</th>
                                            <th class="text-left py-2 px-2 font-medium text-gray-600">Salida</th>
                                            <th class="text-left py-2 px-2 font-medium text-gray-600">Descanso (min)</th>
                                            <th class="text-left py-2 px-2 font-medium text-gray-600">Horas</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="wd in selectedWorkingDays" :key="wd.value" class="border-b border-gray-200 hover:bg-white">
                                            <td class="py-2 px-2 font-medium text-gray-800">{{ wd.label }}</td>
                                            <td class="py-2 px-2">
                                                <input type="time" :value="daySchedules[wd.value]?.entry_time" @input="updateDayField(wd.value, 'entry_time', $event.target.value)" class="w-full rounded border-gray-300 text-sm focus:border-pink-500 focus:ring-pink-500" />
                                            </td>
                                            <td class="py-2 px-2">
                                                <input type="time" :value="daySchedules[wd.value]?.exit_time" @input="updateDayField(wd.value, 'exit_time', $event.target.value)" class="w-full rounded border-gray-300 text-sm focus:border-pink-500 focus:ring-pink-500" />
                                            </td>
                                            <td class="py-2 px-2">
                                                <input type="number" min="0" :value="daySchedules[wd.value]?.break_minutes" @input="updateDayField(wd.value, 'break_minutes', $event.target.value)" class="w-20 rounded border-gray-300 text-sm focus:border-pink-500 focus:ring-pink-500" />
                                            </td>
                                            <td class="py-2 px-2">
                                                <input type="number" min="0" step="0.5" :value="daySchedules[wd.value]?.daily_work_hours" @input="updateDayField(wd.value, 'daily_work_hours', $event.target.value)" class="w-20 rounded border-gray-300 text-sm focus:border-pink-500 focus:ring-pink-500" />
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Position Template Info Badge -->
                    <div v-if="positionTemplateInfo" class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                        <p class="text-sm text-blue-700">
                            <span class="font-medium">Este puesto pre-configura:</span> {{ positionTemplateInfo }}
                        </p>
                    </div>

                    <!-- Position Comparison (when position changes) -->
                    <div v-if="showPositionComparison && positionTemplateValues" class="mt-4 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                        <h4 class="text-sm font-semibold text-yellow-800 mb-3">Valores actuales vs Template del nuevo puesto</h4>
                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <p class="text-yellow-700 font-medium">Actual</p>
                                <ul class="mt-1 space-y-1 text-yellow-600">
                                    <li>Depto: {{ departments.find(d => d.id === employee.department_id)?.name || '-' }}</li>
                                    <li>Horario: {{ schedules.find(s => s.id === employee.schedule_id)?.name || '-' }}</li>
                                    <li>Tarifa: ${{ employee.hourly_rate }}/hr</li>
                                </ul>
                            </div>
                            <div>
                                <p class="text-yellow-700 font-medium">Template del nuevo puesto</p>
                                <ul class="mt-1 space-y-1 text-yellow-600">
                                    <li>Depto: {{ positionTemplateValues.department }}</li>
                                    <li>Horario: {{ positionTemplateValues.schedule }}</li>
                                    <li>Tarifa: ${{ positionTemplateValues.hourly_rate }}/hr</li>
                                </ul>
                            </div>
                        </div>
                        <div class="mt-3 flex gap-2">
                            <button
                                @click="applyTemplateValues"
                                type="button"
                                class="px-3 py-1 bg-yellow-600 text-white rounded text-sm hover:bg-yellow-700"
                            >
                                Aplicar valores del template
                            </button>
                            <button
                                @click="dismissComparison"
                                type="button"
                                class="px-3 py-1 text-yellow-700 hover:text-yellow-900 text-sm"
                            >
                                Mantener valores actuales
                            </button>
                        </div>
                    </div>

                    <!-- Supervisor Section -->
                    <div class="mt-6">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Jefe Directo
                            <span v-if="autoFilledFields.supervisor_id" class="text-blue-500 text-xs">(Auto)</span>
                        </label>

                        <!-- Auto-resolved supervisor from position -->
                        <div v-if="resolvedSupervisor && !overrideSupervisor" class="p-3 bg-green-50 border border-green-200 rounded-lg mb-3">
                            <p class="text-sm text-green-700">
                                <span class="font-medium">Jefe asignado automaticamente via puesto:</span> {{ resolvedSupervisor.full_name }}
                            </p>
                            <button
                                @click="overrideSupervisor = true"
                                type="button"
                                class="mt-2 text-sm text-green-600 hover:text-green-800 underline"
                            >
                                Cambiar manualmente
                            </button>
                        </div>

                        <select
                            v-if="!resolvedSupervisor || overrideSupervisor"
                            v-model="form.supervisor_id"
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            :class="{ 'border-red-500': form.errors.supervisor_id }"
                        >
                            <option value="">Sin jefe directo asignado</option>
                            <option v-for="emp in employees" :key="emp.id" :value="emp.id">
                                {{ emp.full_name }}
                            </option>
                        </select>
                        <p v-if="!resolvedSupervisor && !overrideSupervisor" class="mt-1 text-sm text-gray-500">
                            Supervisor o jefe inmediato del empleado
                        </p>
                        <button
                            v-if="overrideSupervisor && resolvedSupervisor"
                            @click="overrideSupervisor = false; form.supervisor_id = resolvedSupervisor.id;"
                            type="button"
                            class="mt-2 text-sm text-blue-600 hover:text-blue-800 underline"
                        >
                            Restaurar jefe automatico
                        </button>
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
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Tarifa por Hora (MXN) *
                                <span v-if="autoFilledFields.hourly_rate" class="text-blue-500 text-xs">(Auto)</span>
                            </label>
                            <input
                                v-model="form.hourly_rate"
                                type="number"
                                step="0.01"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            />
                        </div>
                    </div>

                    <!-- Minimum Wage Checkbox -->
                    <div class="mt-6">
                        <label class="inline-flex items-center">
                            <input
                                v-model="form.is_minimum_wage"
                                type="checkbox"
                                class="rounded border-gray-300 text-pink-600 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                            />
                            <span class="ml-2 text-sm font-medium text-gray-700">Salario Minimo</span>
                        </label>
                        <p class="mt-1 text-sm text-gray-500">
                            Indica si este empleado percibe salario minimo
                        </p>
                    </div>

                    <!-- Compensation Types -->
                    <div v-if="compensationTypes?.length" class="mt-6 border-t border-gray-200 pt-6">
                        <h4 class="text-sm font-semibold text-gray-800 mb-3">
                            Conceptos de Compensacion Asignados
                            <span v-if="autoFilledFields.compensation_type_ids" class="text-blue-500 text-xs font-normal">(Auto)</span>
                        </h4>
                        <div class="space-y-3">
                            <div
                                v-for="ct in compensationTypes"
                                :key="ct.id"
                                class="flex items-center justify-between p-3 bg-gray-50 rounded-lg"
                            >
                                <label class="flex items-center flex-1">
                                    <input
                                        type="checkbox"
                                        :checked="form.compensation_type_ids.includes(ct.id)"
                                        @change="toggleCompensationType(ct.id)"
                                        class="rounded border-gray-300 text-pink-600 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                    />
                                    <span class="ml-2 text-sm text-gray-700">{{ ct.name }}</span>
                                </label>
                                <div v-if="form.compensation_type_ids.includes(ct.id)" class="flex items-center ml-4">
                                    <template v-if="ct.calculation_type === 'fixed'">
                                        <label class="text-xs text-gray-500 mr-2">Monto:</label>
                                        <input
                                            :value="form.compensation_type_overrides[ct.id] || ct.fixed_amount"
                                            @input="form.compensation_type_overrides = { ...form.compensation_type_overrides, [ct.id]: parseFloat($event.target.value) || 0 }"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            class="w-24 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                                        />
                                    </template>
                                    <template v-else>
                                        <label class="text-xs text-gray-500 mr-2">Porcentaje (%):</label>
                                        <input
                                            :value="form.compensation_type_overrides[ct.id] || ct.percentage_value"
                                            @input="form.compensation_type_overrides = { ...form.compensation_type_overrides, [ct.id]: parseFloat($event.target.value) || 0 }"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            class="w-20 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                                        />
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Monthly Bonus -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Bonos Mensuales</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tipo de Bono</label>
                            <select v-model="form.monthly_bonus_type" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500">
                                <option value="none">Ninguno</option>
                                <option value="fixed">Fijo (no afectado por faltas)</option>
                                <option value="variable">Variable (reducido por faltas)</option>
                            </select>
                        </div>
                        <div v-if="form.monthly_bonus_type !== 'none'">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Monto del Bono (MXN)</label>
                            <input v-model="form.monthly_bonus_amount" type="number" step="0.01" min="0" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" />
                            <p class="mt-1 text-sm text-gray-500">
                                {{ form.monthly_bonus_type === 'fixed' ? 'Se paga completo sin importar faltas' : 'Se reduce proporcionalmente por dias de falta' }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Vacations -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Dias de Vacaciones</h3>

                    <!-- Auto-calculation info -->
                    <div v-if="vacationCalcInfo" class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                        <p class="text-sm text-blue-700">
                            <span class="font-medium">Calculado:</span>
                            {{ vacationCalcInfo.years }} anos de antiguedad = {{ vacationCalcInfo.days }} dias (LFT Mexico)
                        </p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Dias Correspondientes
                                <span v-if="autoFilledFields.vacation_days_entitled" class="text-blue-500 text-xs">(Auto)</span>
                            </label>
                            <input v-model="form.vacation_days_entitled" type="number" min="0" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Dias Tomados</label>
                            <input v-model="form.vacation_days_used" type="number" min="0" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Dias Apartados</label>
                            <input v-model="form.vacation_days_reserved" type="number" min="0" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" />
                            <p class="mt-1 text-sm text-gray-500">Dias reservados/comprometidos</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Dias Disponibles</label>
                            <input :value="Math.max(0, form.vacation_days_entitled - form.vacation_days_used - form.vacation_days_reserved)" type="number" disabled class="w-full rounded-lg border-gray-300 bg-gray-50 shadow-sm" />
                            <p class="mt-1 text-sm text-gray-500">Correspondientes - Tomados - Apartados</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Prima Vacacional (%)</label>
                            <input v-model="form.vacation_premium_percentage" type="number" step="0.01" min="0" max="100" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500" />
                            <p class="mt-1 text-sm text-gray-500">LFT establece 25% minimo</p>
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

        <!-- User Account Section -->
        <div v-if="canCreateUser" class="bg-white rounded-lg shadow p-6 mt-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">Cuenta de Usuario del Sistema</h3>

            <!-- Employee already has a linked user -->
            <div v-if="employee.user">
                <div class="flex items-center gap-4 p-4 bg-gray-50 rounded-lg">
                    <div class="w-10 h-10 rounded-full bg-pink-100 flex items-center justify-center">
                        <span class="text-pink-600 font-semibold">
                            {{ employee.user.name.charAt(0).toUpperCase() }}
                        </span>
                    </div>
                    <div class="flex-1">
                        <div class="font-medium text-gray-900">{{ employee.user.name }}</div>
                        <div class="text-sm text-gray-500">{{ employee.user.email }}</div>
                    </div>
                    <div class="flex gap-2">
                        <span
                            v-for="r in employee.user.roles"
                            :key="r.id"
                            :class="[
                                'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                                r.name === 'admin' ? 'bg-purple-100 text-purple-800' :
                                r.name === 'rrhh' ? 'bg-blue-100 text-blue-800' :
                                r.name === 'supervisor' ? 'bg-yellow-100 text-yellow-800' :
                                'bg-gray-100 text-gray-800'
                            ]"
                        >
                            {{ r.name.charAt(0).toUpperCase() + r.name.slice(1) }}
                        </span>
                        <span
                            :class="[
                                'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium',
                                employee.user.two_factor_enabled ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-500'
                            ]"
                        >
                            2FA: {{ employee.user.two_factor_enabled ? 'Activo' : 'Inactivo' }}
                        </span>
                    </div>
                    <Link
                        :href="route('users.edit', employee.user.id)"
                        class="text-pink-600 hover:text-pink-900 text-sm font-medium"
                    >
                        Editar Usuario
                    </Link>
                </div>
            </div>

            <!-- No user linked — show create form -->
            <div v-else>
                <p class="text-sm text-gray-500 mb-4">
                    Este empleado no tiene cuenta de usuario. Crea una para que pueda acceder al sistema.
                </p>

                <form @submit.prevent="createUserAccount" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Name -->
                        <div>
                            <label for="user_name" class="block text-sm font-medium text-gray-700">Nombre</label>
                            <input
                                id="user_name"
                                type="text"
                                v-model="userForm.name"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                                required
                            />
                            <InputError class="mt-1" :message="userForm.errors.name" />
                        </div>

                        <!-- Email -->
                        <div>
                            <label for="user_email" class="block text-sm font-medium text-gray-700">Correo Electronico</label>
                            <input
                                id="user_email"
                                type="email"
                                v-model="userForm.email"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                                required
                            />
                            <InputError class="mt-1" :message="userForm.errors.email" />
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Password -->
                        <div>
                            <label for="user_password" class="block text-sm font-medium text-gray-700">Contraseña Temporal</label>
                            <div class="mt-1 flex gap-2">
                                <input
                                    id="user_password"
                                    :type="showUserPassword ? 'text' : 'password'"
                                    v-model="userForm.password"
                                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                                    required
                                />
                                <button
                                    type="button"
                                    @click="generateUserPassword"
                                    class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-md text-xs font-medium text-gray-700 bg-white hover:bg-gray-50"
                                >
                                    Generar
                                </button>
                                <button
                                    v-if="userForm.password && showUserPassword"
                                    type="button"
                                    @click="copyUserPassword"
                                    class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-md text-xs font-medium text-gray-700 bg-white hover:bg-gray-50"
                                >
                                    Copiar
                                </button>
                            </div>
                            <div v-if="userForm.password && showUserPassword" class="mt-2 p-2 bg-yellow-50 border border-yellow-200 rounded text-xs text-yellow-800">
                                <strong>Contraseña:</strong> {{ userForm.password }}
                            </div>
                            <InputError class="mt-1" :message="userForm.errors.password" />
                        </div>

                        <!-- Role -->
                        <div>
                            <label for="user_role" class="block text-sm font-medium text-gray-700">Rol</label>
                            <select
                                id="user_role"
                                v-model="userForm.role"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                                required
                            >
                                <option value="" disabled>Seleccionar rol...</option>
                                <option v-for="r in roles" :key="r" :value="r">
                                    {{ r.charAt(0).toUpperCase() + r.slice(1) }}
                                </option>
                            </select>
                            <InputError class="mt-1" :message="userForm.errors.role" />
                        </div>
                    </div>

                    <div class="p-3 bg-blue-50 border border-blue-200 rounded text-sm text-blue-700">
                        El usuario debera cambiar su contraseña en el primer inicio de sesion.
                        <span v-if="['admin', 'rrhh', 'supervisor'].includes(userForm.role)">
                            Ademas, debera configurar la autenticacion de dos factores (2FA).
                        </span>
                    </div>

                    <div class="flex justify-end">
                        <button
                            type="submit"
                            :disabled="userForm.processing"
                            class="px-4 py-2 bg-pink-600 text-white rounded-md text-sm font-semibold hover:bg-pink-700 transition disabled:opacity-50"
                        >
                            {{ userForm.processing ? 'Creando...' : 'Crear Cuenta de Usuario' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import FormErrorBanner from '@/Components/FormErrorBanner.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    compensationType: Object,
    positions: Array,
    departments: Array,
    employees: Array,
    // Sólo el superadmin ve y edita la lista de aprobadores del concepto.
    approverCandidates: { type: Array, default: () => [] },
    canManageApprovers: { type: Boolean, default: false },
});

/**
 * Build initial employee IDs and pivot overrides from the existing relation.
 */
const initialEmployeeIds = (props.compensationType.employees || []).map(e => e.id);
const initialEmployeePercentages = {};
const initialEmployeeFixedAmounts = {};
(props.compensationType.employees || []).forEach(e => {
    if (e.pivot?.custom_percentage != null) {
        initialEmployeePercentages[e.id] = e.pivot.custom_percentage;
    }
    if (e.pivot?.custom_fixed_amount != null) {
        initialEmployeeFixedAmounts[e.id] = e.pivot.custom_fixed_amount;
    }
});

const initialApproverIds = (props.compensationType.approvers || []).map(u => u.id);

const form = useForm({
    name: props.compensationType.name,
    code: props.compensationType.code,
    description: props.compensationType.description || '',
    calculation_type: props.compensationType.calculation_type || 'percentage',
    percentage_value: props.compensationType.percentage_value || '',
    fixed_amount: props.compensationType.fixed_amount || '',
    is_active: props.compensationType.is_active,
    application_mode: props.compensationType.application_mode || 'per_hour',
    authorization_type: props.compensationType.authorization_type || '',
    attendance_pull_rule: props.compensationType.attendance_pull_rule || null,
    priority: props.compensationType.priority ?? 0,
    payment_period: props.compensationType.payment_period || 'monthly',
    is_recurring: props.compensationType.is_recurring ?? false,
    is_base_salary_concept: props.compensationType.is_base_salary_concept ?? false,
    pays_via_transfer: props.compensationType.pays_via_transfer ?? false,
    sat_perception_code: props.compensationType.sat_perception_code || '',
    employee_ids: initialEmployeeIds,
    employee_percentages: initialEmployeePercentages,
    employee_fixed_amounts: initialEmployeeFixedAmounts,
    // Sólo se manda si el usuario es superadmin; si no, el campo ni existe y
    // el backend deja la lista intacta.
    ...(props.canManageApprovers ? { approver_ids: initialApproverIds } : {}),
});

// Lo que costaría marcar "Recurrente": monto × empleados inscritos, con los
// números de este concepto. Ver la consecuencia antes de guardar es lo que evita
// el "peso fantasma" que se coló en Descuento Infonavit (Elias 2026-08-26).
const recurringPreviewAmount = computed(() => {
    const fixed = Number(form.fixed_amount || 0);
    if (form.calculation_type === 'percentage') {
        return `${Number(form.percentage_value || 0)}% del sueldo diario`;
    }

    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(fixed);
});

const recurringPreviewEmployees = computed(() => {
    const n = (form.employee_ids || []).length;
    if (!n) return 'cada empleado que le inscribas';

    return n === 1 ? '1 empleado inscrito' : `${n} empleados inscritos`;
});

const applicationModeOptions = [
    { value: 'per_hour', label: 'Por Hora' },
    { value: 'per_day', label: 'Por Dia' },
    { value: 'one_time', label: 'Monto Unico' },
];

const authorizationTypeOptions = [
    { value: '', label: 'Ninguno' },
    { value: 'overtime', label: 'Horas Extra' },
    { value: 'night_shift', label: 'Velada' },
    { value: 'holiday_worked', label: 'Dia Festivo' },
    { value: 'special', label: 'Especial' },
];

/* ---- Employee selection (department-filtered) ---- */
const departmentFilter = ref('');
const employeeSearch = ref('');

const departmentName = (deptId) => {
    const d = props.departments.find(d => d.id == deptId);
    return d ? d.name : '';
};

const filteredEmployees = computed(() => {
    let list = props.employees || [];
    if (departmentFilter.value) {
        list = list.filter(e => e.department_id == departmentFilter.value);
    }
    if (employeeSearch.value) {
        const q = employeeSearch.value.toLowerCase();
        list = list.filter(e =>
            (e.full_name || '').toLowerCase().includes(q) ||
            (e.employee_number || '').toLowerCase().includes(q)
        );
    }
    // Pin already-selected employees at the top so the user sees their picks.
    const selected = new Set(form.employee_ids);
    return [...list].sort((a, b) => {
        const sA = selected.has(a.id) ? 0 : 1;
        const sB = selected.has(b.id) ? 0 : 1;
        if (sA !== sB) return sA - sB;
        return (a.full_name || '').localeCompare(b.full_name || '');
    });
});

const visibleAllSelected = computed(() => {
    if (filteredEmployees.value.length === 0) return false;
    return filteredEmployees.value.every(e => form.employee_ids.includes(e.id));
});

const omitKey = (obj, key) => {
    const next = { ...obj };
    delete next[key];
    return next;
};

const toggleEmployee = (empId) => {
    if (form.employee_ids.includes(empId)) {
        form.employee_ids = form.employee_ids.filter(id => id !== empId);
        form.employee_percentages = omitKey(form.employee_percentages, empId);
        form.employee_fixed_amounts = omitKey(form.employee_fixed_amounts, empId);
    } else {
        form.employee_ids = [...form.employee_ids, empId];
    }
};

const toggleSelectAllVisible = () => {
    if (visibleAllSelected.value) {
        const visibleIds = new Set(filteredEmployees.value.map(e => e.id));
        form.employee_ids = form.employee_ids.filter(id => !visibleIds.has(id));
        let percentages = { ...form.employee_percentages };
        let fixed = { ...form.employee_fixed_amounts };
        visibleIds.forEach(id => {
            delete percentages[id];
            delete fixed[id];
        });
        form.employee_percentages = percentages;
        form.employee_fixed_amounts = fixed;
    } else {
        const merged = new Set(form.employee_ids);
        filteredEmployees.value.forEach(e => merged.add(e.id));
        form.employee_ids = [...merged];
    }
};

const clearAllEmployees = () => {
    form.employee_ids = [];
    form.employee_percentages = {};
    form.employee_fixed_amounts = {};
};

/* ---- Aprobadores del concepto (sólo superadmin) ---- */
const approverSearch = ref('');

const filteredApprovers = computed(() => {
    let list = props.approverCandidates || [];
    if (approverSearch.value) {
        const q = approverSearch.value.toLowerCase();
        list = list.filter(u =>
            (u.name || '').toLowerCase().includes(q) ||
            (u.email || '').toLowerCase().includes(q)
        );
    }
    // Los ya seleccionados arriba, para que el superadmin vea sus picks.
    const selected = new Set(form.approver_ids || []);
    return [...list].sort((a, b) => {
        const sA = selected.has(a.id) ? 0 : 1;
        const sB = selected.has(b.id) ? 0 : 1;
        if (sA !== sB) return sA - sB;
        return (a.name || '').localeCompare(b.name || '');
    });
});

const toggleApprover = (userId) => {
    if (form.approver_ids.includes(userId)) {
        form.approver_ids = form.approver_ids.filter(id => id !== userId);
    } else {
        form.approver_ids = [...form.approver_ids, userId];
    }
};

const clearAllApprovers = () => {
    form.approver_ids = [];
};

const submit = () => {
    form.put(route('compensation-types.update', props.compensationType.id));
};
</script>

<template>
    <Head :title="`Editar - ${compensationType.name}`" />

    <AppLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Editar Concepto de Compensacion
            </h2>
        </template>

        <div class="max-w-4xl">
            <!-- Breadcrumb -->
            <div class="mb-6">
                <Link :href="route('compensation-types.index')" class="text-pink-600 hover:text-pink-800">
                    &larr; Volver a conceptos de compensacion
                </Link>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <FormErrorBanner :errors="form.errors" />

                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Informacion del Concepto</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Nombre *
                            </label>
                            <input
                                v-model="form.name"
                                type="text"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.name }"
                            />
                            <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">
                                {{ form.errors.name }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Codigo *
                            </label>
                            <input
                                v-model="form.code"
                                type="text"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.code }"
                            />
                            <p v-if="form.errors.code" class="mt-1 text-sm text-red-600">
                                {{ form.errors.code }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Tipo de Calculo *
                            </label>
                            <div class="flex space-x-4">
                                <label class="flex items-center">
                                    <input
                                        v-model="form.calculation_type"
                                        type="radio"
                                        value="fixed"
                                        class="text-pink-600 focus:ring-pink-500"
                                    />
                                    <span class="ml-2 text-sm text-gray-700">Monto Fijo ($)</span>
                                </label>
                                <label class="flex items-center">
                                    <input
                                        v-model="form.calculation_type"
                                        type="radio"
                                        value="percentage"
                                        class="text-pink-600 focus:ring-pink-500"
                                    />
                                    <span class="ml-2 text-sm text-gray-700">Porcentaje del salario (%)</span>
                                </label>
                            </div>
                            <p v-if="form.errors.calculation_type" class="mt-1 text-sm text-red-600">
                                {{ form.errors.calculation_type }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                ¿Cuando se paga? *
                            </label>
                            <div class="flex space-x-4">
                                <label class="flex items-center">
                                    <input
                                        v-model="form.payment_period"
                                        type="radio"
                                        value="weekly"
                                        class="text-pink-600 focus:ring-pink-500"
                                    />
                                    <span class="ml-2 text-sm text-gray-700">Semanal (con el sueldo base)</span>
                                </label>
                                <label class="flex items-center">
                                    <input
                                        v-model="form.payment_period"
                                        type="radio"
                                        value="monthly"
                                        class="text-pink-600 focus:ring-pink-500"
                                    />
                                    <span class="ml-2 text-sm text-gray-700">Mensual (con los extras)</span>
                                </label>
                            </div>
                            <p v-if="form.errors.payment_period" class="mt-1 text-sm text-red-600">
                                {{ form.errors.payment_period }}
                            </p>
                        </div>

                        <div>
                            <label class="flex items-start gap-2">
                                <input
                                    v-model="form.is_recurring"
                                    type="checkbox"
                                    class="mt-0.5 rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                                />
                                <span class="text-sm text-gray-700">
                                    <span class="font-medium">Recurrente</span> — se paga solo, automáticamente, cada
                                    periodo (según arriba) a cada empleado inscrito, sin necesidad de una autorización.
                                    Úsalo para una cantidad fija que se da cada semana o cada mes.
                                </span>
                            </label>
                            <p v-if="form.errors.is_recurring" class="mt-1 text-sm text-red-600">
                                {{ form.errors.is_recurring }}
                            </p>
                            <!-- Consecuencia de "Recurrente", con números, ANTES de guardar -->
                            <div v-if="form.is_recurring" class="mt-2 rounded-lg border border-amber-300 bg-amber-50 p-3">
                                <p class="text-sm text-amber-800">
                                    Cada periodo {{ form.payment_period === 'weekly' ? 'semanal' : 'mensual' }} se le va a pagar
                                    <span class="font-semibold">{{ recurringPreviewAmount }}</span> automáticamente a
                                    <span class="font-semibold">{{ recurringPreviewEmployees }}</span>, sin autorización.
                                </p>
                                <p class="mt-1 text-xs text-amber-700">
                                    Si este concepto es para capturar cantidades (un descuento de -763, un bono por piezas),
                                    déjalo apagado: el monto es el precio de cada unidad, no un pago por periodo.
                                </p>
                            </div>

                        </div>

                        <div>
                            <label class="flex items-start gap-2">
                                <input
                                    v-model="form.is_base_salary_concept"
                                    type="checkbox"
                                    class="mt-0.5 rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                                />
                                <span class="text-sm text-gray-700">
                                    <span class="font-medium">Es el sueldo del empleado</span> — con esto marcado, el
                                    concepto NO se paga cuando la nómina ya le está pagando su sueldo base (manda el
                                    sueldo base). Úsalo en los sueldos capturados como concepto (p. ej. el personal en
                                    periodo de prueba) para que el pago unificado no los pague dos veces. Si el empleado
                                    no cobra sueldo base, el concepto se le sigue pagando igual.
                                </span>
                            </label>
                            <p v-if="form.errors.is_base_salary_concept" class="mt-1 text-sm text-red-600">
                                {{ form.errors.is_base_salary_concept }}
                            </p>
                        </div>

                        <div>
                            <label class="flex items-start gap-2">
                                <input
                                    v-model="form.pays_via_transfer"
                                    type="checkbox"
                                    class="mt-0.5 rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                                />
                                <span class="text-sm text-gray-700">
                                    <span class="font-medium">Se paga por transferencia</span> — para empleados
                                    formalizados, este concepto cae en la transferencia (banco) junto con el sueldo,
                                    no en el efectivo (como Contpaq). Úsalo para aguinaldo, gratificaciones, etc.
                                </span>
                            </label>
                            <p v-if="form.errors.pays_via_transfer" class="mt-1 text-sm text-red-600">
                                {{ form.errors.pays_via_transfer }}
                            </p>
                            <div v-if="form.pays_via_transfer" class="mt-2 ml-6">
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Clave SAT (percepción del recibo CFDI)
                                </label>
                                <input
                                    v-model="form.sat_perception_code"
                                    type="text"
                                    maxlength="3"
                                    class="w-40 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                    placeholder="029"
                                />
                                <p class="mt-1 text-xs text-gray-400">Catálogo c_TipoPercepcion: 001 sueldo, 002 aguinaldo, 019 horas extra, 021 prima vac., 029 premios/bonos. Vacío = 038 (otros ingresos).</p>
                            </div>
                        </div>

                        <div v-if="form.calculation_type === 'percentage'">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Porcentaje del Salario (%) *
                            </label>
                            <input
                                v-model="form.percentage_value"
                                type="number"
                                step="0.01"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.percentage_value }"
                            />
                            <p class="mt-1 text-sm text-gray-500">
                                Ej: 50 = 50% del salario diario, 100 = salario completo
                            </p>
                            <p v-if="form.errors.percentage_value" class="mt-1 text-sm text-red-600">
                                {{ form.errors.percentage_value }}
                            </p>
                        </div>

                        <div v-if="form.calculation_type === 'fixed'">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Monto Fijo (MXN) *
                            </label>
                            <input
                                v-model="form.fixed_amount"
                                type="number"
                                step="0.0001"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.fixed_amount }"
                            />
                            <p class="mt-1 text-sm text-gray-500">
                                Monto fijo a pagar por este concepto
                            </p>
                            <p v-if="form.errors.fixed_amount" class="mt-1 text-sm text-red-600">
                                {{ form.errors.fixed_amount }}
                            </p>
                        </div>

                        <div class="flex items-end">
                            <label class="flex items-center space-x-3">
                                <input
                                    v-model="form.is_active"
                                    type="checkbox"
                                    class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                                />
                                <span class="text-sm font-medium text-gray-700">Concepto activo</span>
                            </label>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Modo de Aplicacion *
                            </label>
                            <div class="flex flex-wrap gap-3">
                                <label
                                    v-for="opt in applicationModeOptions"
                                    :key="opt.value"
                                    class="flex items-center"
                                >
                                    <input
                                        v-model="form.application_mode"
                                        type="radio"
                                        :value="opt.value"
                                        class="text-pink-600 focus:ring-pink-500"
                                    />
                                    <span class="ml-2 text-sm text-gray-700">{{ opt.label }}</span>
                                </label>
                            </div>
                            <p v-if="form.errors.application_mode" class="mt-1 text-sm text-red-600">
                                {{ form.errors.application_mode }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Tipo de Autorizacion
                            </label>
                            <select
                                v-model="form.authorization_type"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.authorization_type }"
                            >
                                <option v-for="opt in authorizationTypeOptions" :key="opt.value" :value="opt.value">
                                    {{ opt.label }}
                                </option>
                            </select>
                            <p class="mt-1 text-sm text-gray-500">
                                Vincula este concepto a un tipo de autorizacion
                            </p>
                            <p v-if="form.errors.authorization_type" class="mt-1 text-sm text-red-600">
                                {{ form.errors.authorization_type }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Jalar desde checadas
                            </label>
                            <select
                                v-model="form.attendance_pull_rule"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.attendance_pull_rule }"
                            >
                                <option :value="null">Ninguna</option>
                                <option value="meal">Cena (12h, velada o fin de semana)</option>
                                <option value="weekend">Fin de semana (trabajo sab/dom fuera de horario)</option>
                                <option value="comida">Comida (solo trabajo en fin de semana)</option>
                                <option value="velada">Velada (noche detectada en checadas)</option>
                            </select>
                            <p class="mt-1 text-sm text-gray-500">
                                Al jalar desde checadas se generan entradas automaticas por cada dia que califique segun la regla elegida. "Cena": jornada minima, cruzo medianoche o fin de semana. "Fin de semana": trabajo en sabado/domingo fuera de su horario. "Comida": lunch solo por trabajar fin de semana. "Velada": noche detectada en las marcas (reingreso nocturno o que cruza medianoche). No se auto-aprueban.
                            </p>
                            <p v-if="form.errors.attendance_pull_rule" class="mt-1 text-sm text-red-600">
                                {{ form.errors.attendance_pull_rule }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Prioridad
                            </label>
                            <input
                                v-model="form.priority"
                                type="number"
                                min="0"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.priority }"
                            />
                            <p class="mt-1 text-sm text-gray-500">
                                Menor = mayor prioridad (ej: HE=10, HED=20, HET=30)
                            </p>
                            <p v-if="form.errors.priority" class="mt-1 text-sm text-red-600">
                                {{ form.errors.priority }}
                            </p>
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Descripcion
                            </label>
                            <textarea
                                v-model="form.description"
                                rows="3"
                                class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500"
                                :class="{ 'border-red-500': form.errors.description }"
                            ></textarea>
                            <p v-if="form.errors.description" class="mt-1 text-sm text-red-600">
                                {{ form.errors.description }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Quien puede aprobar (exclusivo del superadmin) -->
                <div v-if="canManageApprovers" class="bg-white rounded-lg shadow p-6">
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-2 gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">
                                Quien puede aprobar este concepto
                                <span class="text-sm font-normal text-gray-500 ml-2">
                                    ({{ form.approver_ids.length }} seleccionado{{ form.approver_ids.length === 1 ? '' : 's' }})
                                </span>
                            </h3>
                            <p class="text-sm text-gray-500 mt-1">
                                Solo el superadmin puede cambiar esta lista.
                            </p>
                        </div>
                        <button
                            v-if="form.approver_ids.length > 0"
                            type="button"
                            @click="clearAllApprovers"
                            class="px-3 py-2 text-xs border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 whitespace-nowrap"
                        >
                            Quitar todos
                        </button>
                    </div>

                    <div
                        class="mb-4 rounded-lg px-4 py-3 text-sm"
                        :class="form.approver_ids.length === 0
                            ? 'bg-gray-50 text-gray-600 border border-gray-200'
                            : 'bg-pink-50 text-pink-900 border border-pink-200'"
                    >
                        <template v-if="form.approver_ids.length === 0">
                            <span class="font-medium">Sin restriccion.</span>
                            Hoy puede aprobar este concepto cualquier usuario que ya tenga permiso de aprobar
                            (admin, RRHH, o el supervisor de su equipo). Selecciona usuarios abajo para poner el candado.
                        </template>
                        <template v-else>
                            <span class="font-medium">Restringido.</span>
                            Solo los usuarios seleccionados —y el superadmin, que nunca se queda fuera— pueden
                            aprobar o rechazar autorizaciones de este concepto. Nadie mas podra, aunque tenga permiso
                            de aprobar. Estar en la lista faculta al usuario para este concepto aunque no tenga el
                            permiso general.
                        </template>
                    </div>

                    <div class="mb-4">
                        <input
                            v-model="approverSearch"
                            type="text"
                            placeholder="Buscar usuario..."
                            class="w-full rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                        />
                    </div>

                    <div v-if="(approverCandidates || []).length === 0" class="text-center py-6 text-gray-500">
                        No hay usuarios registrados
                    </div>

                    <div v-else class="border rounded-lg overflow-hidden">
                        <div class="max-h-80 overflow-y-auto divide-y divide-gray-100">
                            <div
                                v-for="user in filteredApprovers"
                                :key="user.id"
                                class="px-4 py-3 flex items-center hover:bg-gray-50 cursor-pointer"
                                :class="{ 'bg-pink-50': form.approver_ids.includes(user.id) }"
                                @click="toggleApprover(user.id)"
                            >
                                <input
                                    type="checkbox"
                                    :checked="form.approver_ids.includes(user.id)"
                                    class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                                    @click.stop
                                    @change="toggleApprover(user.id)"
                                />
                                <div class="ml-3 min-w-0">
                                    <p class="text-sm font-medium text-gray-900 truncate">{{ user.name }}</p>
                                    <p class="text-xs text-gray-500 truncate">
                                        {{ user.email }}
                                        <span v-if="user.roles && user.roles.length" class="ml-1">
                                            - {{ user.roles.join(', ') }}
                                        </span>
                                    </p>
                                </div>
                            </div>
                            <div v-if="filteredApprovers.length === 0" class="px-4 py-8 text-center text-gray-500 text-sm">
                                No hay usuarios que coincidan con el filtro.
                            </div>
                        </div>
                    </div>

                    <p v-if="form.errors.approver_ids" class="mt-2 text-sm text-red-600">
                        {{ form.errors.approver_ids }}
                    </p>
                </div>

                <!-- Employee Assignments -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-2 gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">
                                Empleados Asignados
                                <span class="text-sm font-normal text-gray-500 ml-2">
                                    ({{ form.employee_ids.length }} seleccionados)
                                </span>
                            </h3>
                            <p class="text-sm text-gray-500 mt-1">
                                Filtra por departamento, luego selecciona los empleados a los que aplica este concepto.
                            </p>
                        </div>
                        <button
                            v-if="form.employee_ids.length > 0"
                            type="button"
                            @click="clearAllEmployees"
                            class="px-3 py-2 text-xs border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 whitespace-nowrap"
                        >
                            Quitar todos
                        </button>
                    </div>

                    <div class="flex flex-col md:flex-row gap-3 mb-4">
                        <select
                            v-model="departmentFilter"
                            class="md:w-1/3 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                        >
                            <option value="">Todos los departamentos</option>
                            <option v-for="dept in departments" :key="dept.id" :value="dept.id">
                                {{ dept.name }}
                            </option>
                        </select>
                        <input
                            v-model="employeeSearch"
                            type="text"
                            placeholder="Buscar empleado..."
                            class="flex-1 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                        />
                    </div>

                    <div v-if="(employees || []).length === 0" class="text-center py-6 text-gray-500">
                        No hay empleados activos registrados
                    </div>

                    <div v-else class="border rounded-lg overflow-hidden">
                        <div class="bg-gray-50 px-4 py-3 border-b flex items-center">
                            <input
                                type="checkbox"
                                :checked="visibleAllSelected"
                                @change="toggleSelectAllVisible"
                                class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                            />
                            <span class="ml-3 text-sm font-medium text-gray-700">
                                Seleccionar todos los visibles
                            </span>
                            <span class="ml-2 text-xs text-gray-500">
                                ({{ filteredEmployees.length }} resultado{{ filteredEmployees.length === 1 ? '' : 's' }})
                            </span>
                        </div>

                        <div class="max-h-96 overflow-y-auto divide-y divide-gray-100">
                            <div
                                v-for="emp in filteredEmployees"
                                :key="emp.id"
                                class="px-4 py-3 flex items-center justify-between hover:bg-gray-50 cursor-pointer"
                                :class="{ 'bg-pink-50': form.employee_ids.includes(emp.id) }"
                                @click="toggleEmployee(emp.id)"
                            >
                                <div class="flex items-center flex-1 min-w-0">
                                    <input
                                        type="checkbox"
                                        :checked="form.employee_ids.includes(emp.id)"
                                        class="rounded border-gray-300 text-pink-600 focus:ring-pink-500"
                                        @click.stop
                                        @change="toggleEmployee(emp.id)"
                                    />
                                    <div class="ml-3 min-w-0">
                                        <p class="text-sm font-medium text-gray-900 truncate">{{ emp.full_name }}</p>
                                        <p class="text-xs text-gray-500 truncate">
                                            {{ emp.employee_number }}
                                            <span v-if="emp.department_id" class="ml-1">
                                                - {{ departmentName(emp.department_id) }}
                                            </span>
                                        </p>
                                    </div>
                                </div>
                                <div
                                    v-if="form.employee_ids.includes(emp.id)"
                                    class="flex items-center space-x-2 ml-3"
                                    @click.stop
                                >
                                    <template v-if="form.calculation_type === 'percentage'">
                                        <label class="text-xs text-gray-500">% especifico:</label>
                                        <input
                                            v-model="form.employee_percentages[emp.id]"
                                            type="number"
                                            step="0.01"
                                            :placeholder="form.percentage_value || '0.00'"
                                            class="w-24 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                                        />
                                    </template>
                                    <template v-else>
                                        <label class="text-xs text-gray-500">Monto:</label>
                                        <input
                                            v-model="form.employee_fixed_amounts[emp.id]"
                                            type="number"
                                            step="0.0001"
                                            :placeholder="form.fixed_amount || '0.00'"
                                            class="w-28 rounded-lg border-gray-300 shadow-sm focus:border-pink-500 focus:ring-pink-500 text-sm"
                                        />
                                    </template>
                                </div>
                            </div>
                            <div v-if="filteredEmployees.length === 0" class="px-4 py-8 text-center text-gray-500 text-sm">
                                No hay empleados que coincidan con el filtro.
                            </div>
                        </div>
                    </div>

                    <p v-if="form.errors.employee_ids" class="mt-2 text-sm text-red-600">
                        {{ form.errors.employee_ids }}
                    </p>
                </div>

                <!-- Actions -->
                <div class="flex justify-end space-x-4">
                    <Link
                        :href="route('compensation-types.index')"
                        class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors"
                    >
                        Cancelar
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="px-6 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors disabled:opacity-50"
                    >
                        {{ form.processing ? 'Guardando...' : 'Actualizar Concepto' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

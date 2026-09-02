<script setup>
import { computed } from 'vue';

// Muestra los "otros conceptos aprobados" sin columna fija (p. ej. Producción,
// bonos, o un descuento) como "Nombre (conteo): $valor" — uno por línea — y una
// SUMA total al final (Luis 2026-07-09: "que me dé el valor y al final la suma").
const props = defineProps({
    items: { type: Array, default: () => [] },
    showAmounts: { type: Boolean, default: true },
});

const total = computed(() =>
    (props.items || []).reduce((acc, c) => acc + (Number(c.amount) || 0), 0),
);

// Formato $1,234.56 (negativo para deducciones: -$300.00).
const money = (v) => {
    const n = Number(v) || 0;
    const abs = Math.abs(n).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return `${n < 0 ? '-' : ''}$${abs}`;
};
</script>

<template>
    <div v-if="items && items.length" class="text-xs text-gray-700 space-y-0.5">
        <div v-for="(c, i) in items" :key="i" :class="{ 'text-red-600': Number(c.amount) < 0 }">
            {{ c.name }} ({{ c.count }})<template v-if="showAmounts">: {{ money(c.amount) }}</template>
        </div>
        <div v-if="showAmounts" class="pt-0.5 mt-0.5 border-t border-gray-200 font-semibold" :class="{ 'text-red-600': total < 0 }">
            Total: {{ money(total) }}
        </div>
    </div>
    <span v-else class="text-gray-300">—</span>
</template>

<script setup>
// Muestra los "otros conceptos aprobados" sin columna fija (p. ej. una
// compensación creada a mano) como "Nombre (conteo)" — uno por línea.
defineProps({ items: { type: Array, default: () => [] } });
</script>

<template>
    <div v-if="items && items.length" class="text-xs text-gray-700 space-y-0.5">
        <div v-for="(c, i) in items" :key="i">{{ c.name }} ({{ c.count }})</div>
    </div>
    <span v-else class="text-gray-300">—</span>
</template>

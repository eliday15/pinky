<script setup>
import CorteTable from './CorteTable.vue';

defineProps({ report: Object });
</script>

<template>
    <CorteTable :report="report" />
</template>

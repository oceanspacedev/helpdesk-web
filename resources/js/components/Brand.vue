<script setup>
defineProps({
    src: { type: String, required: true },
    alt: { type: String, default: 'Helpdesk' },
});
</script>

<template>
    <img :src="src" :alt="alt" class="mx-auto size-12" />
</template>

<script setup>
defineProps({
    href: { type: String, default: '' },
    color: { type: String, default: 'primary' },
    size: { type: String, default: 'sm' },
    tag: { type: String, default: 'a' },
    type: { type: String, default: 'button' },
    disabled: { type: Boolean, default: false },
});
</script>

<template>
    <a
        v-if="tag === 'a'"
        :href="href"
        :class="['fi-link', `fi-size-${size}`, 'fi-color', `fi-color-${color}`]"
    >
        <slot />
    </a>
    <button
        v-else
        :type="type"
        :disabled="disabled"
        :class="['fi-link', `fi-size-${size}`, 'fi-color', `fi-color-${color}`, disabled ? 'fi-disabled' : null]"
    >
        <slot />
    </button>
</template>

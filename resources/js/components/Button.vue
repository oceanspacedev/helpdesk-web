<script setup>
defineProps({
    type: { type: String, default: 'button' },
    color: { type: String, default: 'primary' },
    outlined: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    processing: { type: Boolean, default: false },
    block: { type: Boolean, default: false },
});
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || processing"
        :aria-busy="processing ? 'true' : 'false'"
        :class="[
            'fi-btn',
            color !== 'gray' ? ['fi-color', `fi-color-${color}`] : null,
            outlined ? 'fi-outlined' : null,
            disabled || processing ? 'fi-disabled' : null,
            processing ? 'fi-processing' : null,
            block ? 'w-full' : null,
        ]"
    >
        <svg
            v-if="processing"
            class="fi-icon fi-loading-indicator"
            viewBox="0 0 24 24"
            fill="none"
            aria-hidden="true"
        >
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path
                class="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
            />
        </svg>
        <span><slot /></span>
    </button>
</template>

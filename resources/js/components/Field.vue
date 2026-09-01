<script setup>
defineProps({
    id: { type: String, required: true },
    label: { type: String, required: true },
    required: { type: Boolean, default: false },
    error: { type: String, default: '' },
    hint: { type: String, default: '' },
});
</script>

<template>
    <div class="fi-fo-field">
        <div class="fi-fo-field-label-col">
            <div class="fi-fo-field-label-ctn">
                <label :for="id" class="fi-fo-field-label">
                    <span class="fi-fo-field-label-content">
                        {{ label }}<sup v-if="required" class="fi-fo-field-label-required-mark">*</sup>
                    </span>
                </label>
            </div>
        </div>
        <div class="fi-fo-field-content-col">
            <slot />
            <p v-if="hint && !error" class="text-sm text-gray-500 dark:text-gray-400">{{ hint }}</p>
            <p v-if="error" class="fi-fo-field-wrp-error-message" data-validation-error>
                {{ error }}
            </p>
        </div>
    </div>
</template>

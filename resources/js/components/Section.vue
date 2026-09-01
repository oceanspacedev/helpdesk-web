<script setup>
import Icon from './Icon.vue';

defineProps({
    heading: { type: String, required: true },
    icon: { type: String, required: true },
});
</script>

<template>
    <section class="fi-section fi-section-has-header">
        <header class="fi-section-header">
            <span class="fi-icon fi-size-lg fi-color fi-color-primary">
                <Icon :name="icon" />
            </span>
            <div class="fi-section-header-text-ctn">
                <h2 class="fi-section-header-heading font-heading">{{ heading }}</h2>
            </div>
        </header>
        <div class="fi-section-content-ctn">
            <div class="fi-section-content">
                <slot />
            </div>
        </div>
    </section>
</template>

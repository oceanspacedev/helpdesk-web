<script setup>
import Icon from './Icon.vue';

const props = defineProps({
    color: { type: String, default: 'info' },
    heading: { type: String, required: true },
    description: { type: String, default: '' },
});

const icons = {
    success: 'check-circle',
    danger: 'exclamation-triangle',
    info: 'information-circle',
};
</script>

<template>
    <div :class="['fi-callout', 'fi-color', `fi-color-${props.color}`]">
        <span class="fi-icon fi-callout-icon fi-color fi-size-lg">
            <Icon :name="icons[color] ?? icons.info" />
        </span>
        <div class="fi-callout-main">
            <div class="fi-callout-text">
                <h4 class="fi-callout-heading">{{ heading }}</h4>
                <p v-if="description" class="fi-callout-description">{{ description }}</p>
            </div>
            <div v-if="$slots.default" class="fi-callout-footer">
                <slot />
            </div>
        </div>
    </div>
</template>

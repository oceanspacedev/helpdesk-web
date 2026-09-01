<script setup>
defineOptions({ inheritAttrs: false });

defineProps({
    invalid: { type: Boolean, default: false },
});

const model = defineModel();
</script>

<template>
    <div class="fi-input-wrp fi-fo-text-input" :class="{ 'fi-invalid': invalid }">
        <div class="fi-input-wrp-content-ctn">
            <input class="fi-input" v-bind="$attrs" v-model="model" />
        </div>
    </div>
</template>

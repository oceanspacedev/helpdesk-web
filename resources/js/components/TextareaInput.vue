<script setup>
defineOptions({ inheritAttrs: false });

defineProps({
    invalid: { type: Boolean, default: false },
});

const model = defineModel();
</script>

<template>
    <div class="fi-input-wrp fi-fo-textarea" :class="{ 'fi-invalid': invalid }">
        <div class="fi-input-wrp-content-ctn">
            <textarea
                class="block w-full resize-y border-none bg-transparent px-3 py-1.5 text-base text-gray-950 outline-none placeholder:text-gray-400 focus:ring-0 sm:text-sm dark:text-white"
                v-bind="$attrs"
                v-model="model"
            />
        </div>
    </div>
</template>

<script setup>
import ThemeSwitcher from './ThemeSwitcher.vue';
</script>

<template>
    <div class="fi-simple-layout">
        <div class="fi-simple-layout-header">
            <ThemeSwitcher />
        </div>
        <div class="fi-simple-main-ctn">
            <main class="fi-simple-main fi-width-full">
                <slot />
            </main>
        </div>
    </div>
</template>

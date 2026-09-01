<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import Icon from './Icon.vue';

const theme = ref('system');
const media = window.matchMedia('(prefers-color-scheme: dark)');

const options = [
    { id: 'light', icon: 'sun', label: 'Terang' },
    { id: 'dark', icon: 'moon', label: 'Gelap' },
    { id: 'system', icon: 'computer-desktop', label: 'Sistem' },
];

function resolved(value) {
    if (value === 'system') {
        return media.matches ? 'dark' : 'light';
    }

    return value;
}

function apply(value, persist = true) {
    theme.value = value;

    if (persist) {
        localStorage.setItem('theme', value);
        window.dispatchEvent(new CustomEvent('theme-changed', { detail: value }));
    }

    document.documentElement.classList.toggle('dark', resolved(value) === 'dark');
}

function onSystemChange() {
    if ((localStorage.getItem('theme') ?? 'system') === 'system') {
        apply('system', false);
    }
}

onMounted(() => {
    apply(localStorage.getItem('theme') ?? 'system', false);
    media.addEventListener('change', onSystemChange);
});

onUnmounted(() => {
    media.removeEventListener('change', onSystemChange);
});
</script>

<template>
    <div
        class="fi-theme-switcher rounded-lg bg-white p-0.5 ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10"
        role="radiogroup"
        aria-label="Tema"
    >
        <button
            v-for="option in options"
            :key="option.id"
            type="button"
            class="fi-theme-switcher-btn"
            :class="{ 'fi-active': theme === option.id }"
            :aria-label="option.label"
            :aria-pressed="theme === option.id"
            @click="apply(option.id)"
        >
            <span class="fi-icon">
                <Icon :name="option.icon" />
            </span>
        </button>
    </div>
</template>

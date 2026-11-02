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
        class="inline-flex items-center gap-0.5 rounded-lg bg-white p-0.5 ring-1 ring-gray-950/10 dark:bg-white/5 dark:ring-white/10"
        role="radiogroup"
        aria-label="Tema"
    >
        <button
            v-for="option in options"
            :key="option.id"
            type="button"
            role="radio"
            class="flex size-7 items-center justify-center rounded-md outline-hidden transition duration-75 focus-visible:ring-2 focus-visible:ring-primary-500"
            :class="
                theme === option.id
                    ? 'bg-gray-100 text-gray-950 dark:bg-white/10 dark:text-white'
                    : 'text-gray-400 hover:text-gray-700 dark:text-gray-500 dark:hover:text-gray-300'
            "
            :aria-label="option.label"
            :aria-checked="theme === option.id"
            :title="option.label"
            @click="apply(option.id)"
        >
            <Icon :name="option.icon" class="size-4" />
        </button>
    </div>
</template>

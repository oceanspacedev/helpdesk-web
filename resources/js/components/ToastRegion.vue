<script setup>
import Icon from './Icon.vue';
import { useToast } from '../support/toast.js';

const { toasts, dismiss, pause, resume } = useToast();

const toneIcon = {
    success: { name: 'check-circle', color: 'text-success-600 dark:text-success-400' },
    info: { name: 'information-circle', color: 'text-info-600 dark:text-info-400' },
    danger: { name: 'exclamation-triangle', color: 'text-danger-600 dark:text-danger-400' },
};

function iconFor(color) {
    return toneIcon[color] ?? toneIcon.info;
}
</script>

<template>
    <Teleport to="body">
        <div
            aria-live="assertive"
            class="pointer-events-none fixed inset-x-0 bottom-4 z-50 flex flex-col items-center gap-2 px-4 sm:inset-x-auto sm:end-5 sm:bottom-5 sm:w-full sm:max-w-sm sm:items-end sm:px-0"
        >
            <TransitionGroup
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="translate-y-2 opacity-0"
                enter-to-class="translate-y-0 opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="translate-y-0 opacity-100"
                leave-to-class="translate-y-1 opacity-0"
            >
                <div
                    v-for="item in toasts"
                    :key="item.id"
                    role="status"
                    class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl bg-white px-3.5 py-3 shadow-lg shadow-gray-950/10 ring-1 ring-gray-950/10 dark:bg-gray-900 dark:shadow-black/40 dark:ring-white/10"
                    @mouseenter="pause(item.id)"
                    @mouseleave="resume(item.id)"
                    @focusin="pause(item.id)"
                    @focusout="resume(item.id)"
                >
                    <Icon
                        :name="iconFor(item.color).name"
                        class="mt-0.5 size-5 shrink-0"
                        :class="iconFor(item.color).color"
                    />

                    <div class="min-w-0 flex-1">
                        <p class="font-heading text-sm font-medium text-gray-950 dark:text-white">
                            {{ item.title }}
                        </p>
                        <p
                            v-if="item.description"
                            class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400"
                        >
                            {{ item.description }}
                        </p>
                        <a
                            v-if="item.actionUrl && item.actionLabel"
                            :href="item.actionUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="mt-1.5 inline-flex items-center gap-1 text-xs font-medium text-gray-700 underline decoration-gray-300 underline-offset-2 hover:text-gray-950 dark:text-gray-200 dark:decoration-white/30 dark:hover:text-white"
                        >
                            <span>{{ item.actionLabel }}</span>
                            <Icon name="arrow-top-right-on-square" class="size-3.5" />
                        </a>
                    </div>

                    <button
                        type="button"
                        class="-me-1 -mt-0.5 shrink-0 rounded-md p-1 text-gray-400 transition hover:text-gray-600 focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-gray-500 dark:hover:text-gray-300"
                        aria-label="Tutup notifikasi"
                        @click="dismiss(item.id)"
                    >
                        <Icon name="x-mark" class="size-4" />
                    </button>
                </div>
            </TransitionGroup>
        </div>
    </Teleport>
</template>

<script setup>
import { onBeforeUnmount, ref } from 'vue';
import Icon from './Icon.vue';
import { useToast } from '../support/toast.js';

defineProps({
    ticket: { type: Object, required: true },
    compact: { type: Boolean, default: false },
});

defineEmits(['dismiss']);

const toast = useToast();
const copiedField = ref(null);
let copyTimer = null;

async function copyValue(field, value) {
    if (!value) {
        return;
    }

    const text = String(value);

    try {
        if (navigator.clipboard?.writeText) {
            await navigator.clipboard.writeText(text);
        } else {
            fallbackCopy(text);
        }
    } catch {
        fallbackCopy(text);
    }

    copiedField.value = field;
    if (copyTimer) {
        clearTimeout(copyTimer);
    }
    copyTimer = setTimeout(() => {
        copiedField.value = null;
        copyTimer = null;
    }, 1800);

    toast.success({
        title: field === 'number' ? 'Nomor tiket disalin' : 'Kode referensi disalin',
        description: text,
        duration: 2000,
        dedupeKey: `copy:${field}`,
    });
}

function fallbackCopy(text) {
    if (typeof document === 'undefined') {
        return;
    }

    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    textarea.style.pointerEvents = 'none';
    document.body.appendChild(textarea);
    textarea.select();
    try {
        document.execCommand('copy');
    } finally {
        document.body.removeChild(textarea);
    }
}

onBeforeUnmount(() => {
    if (copyTimer) {
        clearTimeout(copyTimer);
        copyTimer = null;
    }
});
</script>

<template>
    <div
        :class="
            compact
                ? ''
                : 'rounded-xl bg-gray-50 p-1.5 ring-1 ring-gray-200 dark:bg-gray-950 dark:ring-white/10'
        "
    >
        <div
            role="status"
            aria-live="polite"
            :class="
                compact
                    ? 'overflow-hidden rounded-lg bg-gray-50/70 ring-1 ring-gray-200 dark:bg-white/[0.03] dark:ring-white/10'
                    : 'overflow-hidden rounded-lg bg-white ring-1 ring-gray-200 dark:bg-gray-900 dark:ring-white/10'
            "
        >
            <div
                class="flex flex-col gap-3.5 px-4 py-3.5"
                :class="!compact ? 'sm:flex-row sm:items-center sm:justify-between' : ''"
            >
                <div class="flex min-w-0 flex-1 items-start gap-3">
                    <span
                        class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg bg-success-50 text-success-600 ring-1 ring-inset ring-success-600/15 dark:bg-success-500/10 dark:text-success-400 dark:ring-success-400/20"
                    >
                        <Icon name="check-circle" class="size-4.5" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex items-start justify-between gap-2">
                            <h2 class="font-heading text-sm font-medium text-gray-950 dark:text-white">
                                Laporan berhasil dikirim
                            </h2>
                            <button
                                type="button"
                                :class="compact ? 'inline-flex' : 'inline-flex sm:hidden'"
                                class="-me-1 -mt-1 rounded-md p-1 text-gray-400 transition hover:text-gray-600 focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-gray-500 dark:hover:text-gray-300"
                                aria-label="Tutup notifikasi"
                                @click="$emit('dismiss')"
                            >
                                <Icon name="x-mark" class="size-4" />
                            </button>
                        </div>
                        <p class="mt-0.5 break-words text-xs text-gray-500 dark:text-gray-400">
                            {{ ticket.title || 'Laporan Anda sudah diterima dan diteruskan ke unit terkait.' }}
                        </p>
                    </div>
                </div>

                <div
                    v-if="ticket.track_url || !compact"
                    :class="compact ? 'flex' : 'flex items-center gap-2 sm:shrink-0'"
                >
                    <a
                        v-if="ticket.track_url"
                        :href="ticket.track_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        :class="[
                            'inline-flex h-8 items-center justify-center gap-1.5 rounded-lg bg-white px-3 text-xs font-medium text-gray-700 ring-1 ring-gray-950/10 transition duration-75 outline-hidden hover:bg-gray-50 hover:text-gray-950 focus-visible:ring-2 focus-visible:ring-primary-500 dark:bg-white/5 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-white/10 dark:hover:text-white',
                            compact ? 'w-full' : 'w-full sm:w-auto',
                        ]"
                    >
                        <span>Lacak progres</span>
                        <Icon name="arrow-top-right-on-square" class="size-3.5 text-gray-400 dark:text-gray-400" />
                    </a>

                    <button
                        v-if="!compact"
                        type="button"
                        class="hidden rounded-lg p-1.5 text-gray-400 transition hover:text-gray-600 focus-visible:ring-2 focus-visible:ring-primary-500 sm:inline-flex dark:text-gray-500 dark:hover:text-gray-300"
                        title="Tutup"
                        aria-label="Tutup notifikasi"
                        @click="$emit('dismiss')"
                    >
                        <Icon name="x-mark" class="size-4" />
                    </button>
                </div>
            </div>

            <div
                v-if="ticket.number || ticket.external_ref || ticket.unit"
                class="border-t border-gray-100 px-4 py-2.5 text-xs dark:border-white/[0.06]"
            >
                <div
                    :class="
                        compact
                            ? 'flex flex-col gap-1.5'
                            : 'flex flex-col gap-1.5 sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-3 sm:gap-y-1.5'
                    "
                >
                    <div
                        v-if="ticket.number"
                        class="flex items-center justify-between gap-2 sm:justify-start"
                    >
                        <span class="text-gray-400 dark:text-gray-500">Tiket</span>
                        <button
                            type="button"
                            class="group inline-flex items-center gap-1 rounded px-1.5 py-0.5 font-mono text-xs font-medium text-gray-800 transition hover:bg-gray-100 focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-gray-200 dark:hover:bg-white/[0.06]"
                            :title="copiedField === 'number' ? 'Disalin' : 'Salin nomor tiket'"
                            @click="copyValue('number', ticket.number)"
                        >
                            <span>{{ ticket.number }}</span>
                            <Icon
                                :name="copiedField === 'number' ? 'check' : 'square-2-stack'"
                                class="size-3.5 transition"
                                :class="
                                    copiedField === 'number'
                                        ? 'text-success-600 dark:text-success-400'
                                        : 'text-gray-400 group-hover:text-gray-600 dark:text-gray-500 dark:group-hover:text-gray-300'
                                "
                            />
                        </button>
                    </div>

                    <span
                        v-if="!compact && ticket.number && (ticket.external_ref || ticket.unit)"
                        class="hidden text-gray-300 sm:inline dark:text-gray-700"
                        aria-hidden="true"
                    >
                        ·
                    </span>

                    <div
                        v-if="ticket.external_ref"
                        class="flex items-center justify-between gap-2 sm:justify-start"
                    >
                        <span class="text-gray-400 dark:text-gray-500">Referensi</span>
                        <button
                            type="button"
                            class="group inline-flex items-center gap-1 rounded px-1.5 py-0.5 font-mono text-xs font-medium text-gray-800 transition hover:bg-gray-100 focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-gray-200 dark:hover:bg-white/[0.06]"
                            :title="copiedField === 'external_ref' ? 'Disalin' : 'Salin kode referensi'"
                            @click="copyValue('external_ref', ticket.external_ref)"
                        >
                            <span>{{ ticket.external_ref }}</span>
                            <Icon
                                :name="copiedField === 'external_ref' ? 'check' : 'square-2-stack'"
                                class="size-3.5 transition"
                                :class="
                                    copiedField === 'external_ref'
                                        ? 'text-success-600 dark:text-success-400'
                                        : 'text-gray-400 group-hover:text-gray-600 dark:text-gray-500 dark:group-hover:text-gray-300'
                                "
                            />
                        </button>
                    </div>

                    <span
                        v-if="!compact && ticket.external_ref && ticket.unit"
                        class="hidden text-gray-300 sm:inline dark:text-gray-700"
                        aria-hidden="true"
                    >
                        ·
                    </span>

                    <div
                        v-if="ticket.unit"
                        class="flex items-center justify-between gap-2 sm:justify-start"
                    >
                        <span class="text-gray-400 dark:text-gray-500">Unit</span>
                        <span class="font-medium text-gray-800 dark:text-gray-200">
                            {{ ticket.unit }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

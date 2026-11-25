<script setup>
import { computed } from 'vue';
import Button from '../Button.vue';
import Callout from '../Callout.vue';
import ExternalFields from './ExternalFields.vue';
import { autoLabels } from '../../support/externalForm.js';

// Renderer generik definisi form sistem eksternal (kontrak v1 §3.2).
const props = defineProps({
    // { key, title, description, downloads[], fields[] } atau null saat memuat/gagal.
    schema: { type: Object, default: null },
    values: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    loading: { type: Boolean, default: false },
    loadError: { type: String, default: '' },
});

defineEmits(['retry']);

// Hanya label: nilai identitas pelapor tidak pernah dikirim ke browser.
// Mengikuti visible_if dan baris repeater, seperti yang dikirim server.
const autoFieldLabels = computed(() => autoLabels(props.schema?.fields ?? [], props.values));
</script>

<template>
    <div class="space-y-6">
        <div v-if="loading" class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400" role="status">
            <svg class="fi-icon fi-loading-indicator size-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            Memuat form dari sistem tujuan…
        </div>

        <Callout v-else-if="loadError" color="danger" :heading="loadError">
            <Button color="gray" outlined @click="$emit('retry')">Coba lagi</Button>
        </Callout>

        <template v-else-if="schema">
            <p v-if="schema.description" class="whitespace-pre-line text-sm text-gray-600 dark:text-gray-300">
                {{ schema.description }}
            </p>

            <ul v-if="schema.downloads?.length" class="flex flex-wrap gap-x-4 gap-y-2 text-sm">
                <li v-for="download in schema.downloads" :key="download.url">
                    <a
                        :href="download.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="fi-link fi-size-sm fi-color fi-color-primary"
                    >
                        Unduh: {{ download.label }}
                    </a>
                </li>
            </ul>

            <p
                v-if="autoFieldLabels.length"
                class="rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-600 ring-1 ring-gray-200 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10"
                data-testid="external-auto-note"
            >
                Diisi otomatis dari data karyawan: {{ autoFieldLabels.join(', ') }}.
            </p>

            <ExternalFields :fields="schema.fields ?? []" :values="values" :errors="errors" />
        </template>
    </div>
</template>

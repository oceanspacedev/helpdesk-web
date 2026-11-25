<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import Button from '../Button.vue';
import Callout from '../Callout.vue';
import Icon from '../Icon.vue';
import ExternalFields from './ExternalFields.vue';
import { autoLabels, isVisible, levelScope } from '../../support/externalForm.js';
import { firstErrorStep, pickerErrors, submitVisible, validateStep, visibleSteps } from '../../support/externalWizard.js';

// Form sistem eksternal sebagai wizard (kontrak §3.2 "Langkah"): stepper,
// seksi berjudul, kartu pilihan kategori, tombol Kembali/Lanjut dengan
// validasi per langkah, lalu "Kirim pengajuan". Nilai identitas pelapor tidak
// pernah ada di browser: field otomatis hanya disebut labelnya.
const props = defineProps({
    // Form yang sedang dirender (dengan ticket_value), atau null saat memuat.
    schema: { type: Object, default: null },
    // Kartu kategori: [{ id, code, name, description }].
    categories: { type: Array, default: () => [] },
    categoryId: { type: [Number, String], default: '' },
    // Seksi langkah kartu kategori dan langkah sesudahnya tampil: form
    // kategori terpilih sudah dimuat (atau form sebelumnya selama memuat).
    chosen: { type: Boolean, default: false },
    // Nama unit helpdesk (catatan observasi di kotak "Apa itu").
    unitName: { type: String, default: '' },
    values: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    generalError: { type: String, default: '' },
    loading: { type: Boolean, default: false },
    loadError: { type: String, default: '' },
    processing: { type: Boolean, default: false },
    // () => { [key]: pesan } untuk pertanyaan badan usaha helpdesk.
    validateEntity: { type: Function, default: () => ({}) },
});

const emit = defineEmits(['select-category', 'submit', 'retry', 'errors']);

const wizardEl = ref(null);
const currentKey = ref('');
// Langkah yang sudah dilewati dengan validasi (boleh diklik di stepper).
const reached = ref(0);

const fields = computed(() => props.schema?.fields ?? []);
const steps = computed(() => (props.schema ? visibleSteps(props.schema, props.values, { chosen: props.chosen }) : []));
const currentIndex = computed(() => {
    const index = steps.value.findIndex((step) => step.key === currentKey.value);

    return index >= 0 ? index : Math.min(reached.value, Math.max(steps.value.length - 1, 0));
});
const current = computed(() => steps.value[currentIndex.value] ?? null);
const isLast = computed(() => currentIndex.value >= steps.value.length - 1);
// Sebelum form kategori terpilih dimuat, langkah sesudah kartu kategori belum diketahui.
const showSubmit = computed(() =>
    submitVisible(steps.value, currentIndex.value, { categoryId: props.categoryId, chosen: props.chosen }),
);
// Form kategori terpilih gagal dimuat: tidak bisa lanjut dari kartu kategori.
const blocked = computed(() => Boolean(current.value?.category_picker && props.loadError));
const scopes = computed(() => [levelScope(fields.value, props.values)]);

const selectedCard = computed(
    () => props.categories.find((card) => String(card.id) === String(props.categoryId)) ?? null,
);

const hasCategoryInfo = computed(() =>
    Boolean(
        selectedCard.value &&
            props.chosen &&
            (selectedCard.value.description ||
                props.schema?.description ||
                selectedCard.value.needs_observation ||
                props.schema?.downloads?.length),
    ),
);

watch(steps, (list) => {
    if (list.length && !list.some((step) => step.key === currentKey.value)) {
        currentKey.value = list[Math.min(currentIndex.value, list.length - 1)].key;
    }
});

function go(index, { scroll = true } = {}) {
    const step = steps.value[index];
    if (!step) {
        return;
    }
    currentKey.value = step.key;
    if (scroll && typeof window !== 'undefined') {
        if (typeof wizardEl.value?.scrollIntoView === 'function') {
            wizardEl.value.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    }
}

function focusFirstError() {
    nextTick(() => {
        const root = wizardEl.value;
        if (!root) {
            return;
        }
        const errorEl = root.querySelector('[data-validation-error]');
        const fieldWrap = errorEl?.closest('.fi-fo-field, fieldset') ?? errorEl;
        const focusable = fieldWrap?.querySelector?.(
            'input:not(.fi-sr-only), select, textarea, button:not([disabled])',
        );
        (fieldWrap ?? errorEl)?.scrollIntoView?.({ behavior: 'smooth', block: 'center' });
        if (typeof focusable?.focus === 'function') {
            focusable.focus({ preventScroll: true });
        }
    });
}

function stepErrors(step) {
    const errors = {
        ...validateStep(step, fields.value, props.values),
        ...pickerErrors(step, { categoryId: props.categoryId, chosen: props.chosen }),
    };
    if (step.hostsEntity) {
        Object.assign(errors, props.validateEntity());
    }

    return errors;
}

function next() {
    if (!current.value || props.loading || blocked.value) {
        return;
    }
    const errors = stepErrors(current.value);
    // Hanya error langkah ini yang diganti (error server langkah lain tetap).
    emit('errors', errors, { steps: steps.value, index: currentIndex.value });
    if (Object.keys(errors).length) {
        focusFirstError();

        return;
    }
    if (isLast.value) {
        emit('submit');

        return;
    }
    reached.value = Math.max(reached.value, currentIndex.value + 1);
    go(currentIndex.value + 1);
}

function back() {
    go(Math.max(0, currentIndex.value - 1));
}

function jumpTo(index) {
    if (index <= Math.max(reached.value, currentIndex.value)) {
        go(index);
    }
}

// Error dari server (422) atau validasi penuh: buka langkah pertama yang
// memuat error.
function jumpToErrors(errors = props.errors) {
    const index = firstErrorStep(steps.value, errors);
    if (index >= 0) {
        go(index, { scroll: false });
        focusFirstError();
    }

    return index;
}

function reset() {
    reached.value = 0;
    currentKey.value = steps.value[0]?.key ?? '';
}

defineExpose({ jumpToErrors, next, reset, steps });

function stepStatus(index) {
    return index < currentIndex.value ? 'Selesai' : `Langkah ${index + 1} dari ${steps.value.length}`;
}

// Semua field otomatis yang tampil di langkah ini (termasuk seksi/langkah
// otomatis yang dilewati), disebut sekali di atas langkah.
const stepAutoLabels = computed(() => {
    if (!current.value || (current.value.category_picker && !props.chosen)) {
        return [];
    }
    const sourceFields = [...(current.value.fields ?? []), ...(current.value.skippedAutoFields ?? [])];

    return autoLabels(sourceFields, props.values, scopes.value);
});

// Seksi yang punya isian untuk pelapor (atau memuat pertanyaan badan usaha).
const shownSections = computed(() =>
    (current.value?.sections ?? []).filter(
        (section) =>
            section.visible &&
            (section.isHost ||
                section.fields.some((field) => !field.auto && !field.ticket && isVisible(field, scopes.value))),
    ),
);

function cardLabel(card) {
    return card.code && card.code !== card.name ? card.name : '';
}

function downloadIcon(download) {
    return /template|unduh/i.test(String(download?.label ?? '')) ? 'arrow-down-tray' : 'arrow-top-right-on-square';
}
</script>

<template>
    <div ref="wizardEl" class="space-y-6 scroll-mt-4" data-testid="external-wizard">
        <div v-if="loading && !schema" class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400" role="status">
            <svg class="fi-icon fi-loading-indicator size-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            Memuat form dari sistem tujuan…
        </div>

        <Callout v-else-if="loadError && !schema" color="danger" :heading="loadError">
            <Button color="gray" outlined @click="$emit('retry')">Coba lagi</Button>
        </Callout>

        <section v-else-if="schema && current" class="fi-section fi-section-has-header">
            <!-- Stepper (seperti form asli sistem tujuan): nomor, judul, garis penghubung. -->
            <header class="fi-section-header flex-col gap-3 sm:flex-row">
                <div class="flex w-full flex-col gap-2 sm:hidden">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-1.5">
                            <button
                                v-for="(step, index) in steps"
                                :key="step.key"
                                type="button"
                                class="flex size-7 items-center justify-center rounded-full text-xs font-semibold transition disabled:cursor-default"
                                :class="
                                    index < currentIndex
                                        ? 'bg-primary-600 text-white'
                                        : index === currentIndex
                                            ? 'bg-primary-600 text-white ring-4 ring-primary-600/20'
                                            : 'bg-white text-gray-500 ring-1 ring-gray-300 dark:bg-gray-900 dark:ring-white/20'
                                "
                                :disabled="index > Math.max(reached, currentIndex) || index === currentIndex"
                                :aria-label="`${step.title} (${stepStatus(index)})`"
                                @click="jumpTo(index)"
                            >
                                <Icon v-if="index < currentIndex" name="check" class="size-3.5" />
                                <template v-else>{{ index + 1 }}</template>
                            </button>
                        </div>
                        <div class="text-end">
                            <div class="text-sm font-semibold text-gray-950 dark:text-white">{{ current.title }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                Langkah {{ currentIndex + 1 }} dari {{ steps.length }}
                            </div>
                        </div>
                    </div>
                    <div class="h-1 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10" aria-hidden="true">
                        <div
                            class="h-full rounded-full bg-primary-600 transition-all duration-200"
                            :style="{ width: `${((currentIndex + 1) / Math.max(steps.length, 1)) * 100}%` }"
                        />
                    </div>
                </div>

                <ol class="hidden w-full items-center gap-3 sm:flex" aria-label="Langkah">
                    <template v-for="(step, index) in steps" :key="step.key">
                        <li class="flex min-w-0">
                            <button
                                type="button"
                                class="flex min-w-0 items-center gap-3 text-start disabled:cursor-default"
                                :disabled="index > Math.max(reached, currentIndex) || index === currentIndex"
                                :aria-current="index === currentIndex ? 'step' : null"
                                @click="jumpTo(index)"
                            >
                                <span
                                    class="flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold"
                                    :class="
                                        index < currentIndex
                                            ? 'bg-primary-600 text-white'
                                            : index === currentIndex
                                                ? 'bg-primary-600 text-white ring-4 ring-primary-600/20'
                                                : 'bg-white text-gray-500 ring-1 ring-gray-300 dark:bg-gray-900 dark:ring-white/20'
                                    "
                                >
                                    <Icon v-if="index < currentIndex" name="check" class="size-4" />
                                    <template v-else>{{ index + 1 }}</template>
                                </span>
                                <span class="flex min-w-0 flex-col">
                                    <span
                                        class="truncate text-sm font-semibold"
                                        :class="index <= currentIndex ? 'text-gray-950 dark:text-white' : 'text-gray-500 dark:text-gray-400'"
                                    >
                                        {{ step.title }}
                                    </span>
                                    <small class="text-xs text-gray-500 dark:text-gray-400">{{ stepStatus(index) }}</small>
                                </span>
                            </button>
                        </li>
                        <li
                            v-if="index < steps.length - 1"
                            aria-hidden="true"
                            class="hidden h-0.5 min-w-6 flex-1 rounded-full sm:block"
                            :class="index < currentIndex ? 'bg-primary-600' : 'bg-gray-200 dark:bg-white/10'"
                        />
                    </template>
                </ol>
            </header>

            <div class="fi-section-content-ctn">
                <div class="fi-section-content space-y-6">
                    <p v-if="current.help" class="text-sm text-gray-600 dark:text-gray-300">{{ current.help }}</p>

                    <!-- Isian yang diambil dari data karyawan: cukup disebut sekali per langkah. -->
                    <div
                        v-if="stepAutoLabels.length"
                        class="flex flex-wrap items-center gap-2 rounded-lg bg-success-50 px-3 py-2 text-sm text-success-700 ring-1 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400/20"
                        data-testid="external-auto-summary"
                    >
                        <Icon name="check" class="size-4 shrink-0" />
                        <span>Terisi otomatis dari data karyawan:</span>
                        <span class="font-medium">{{ stepAutoLabels.join(', ') }}</span>
                    </div>

                    <!-- Pilihan kategori -->
                    <div v-if="current.category_picker" class="space-y-4">
                        <div>
                            <h3 class="font-heading text-base font-semibold text-gray-950 dark:text-white">
                                Kategori<sup class="fi-fo-field-label-required-mark">*</sup>
                            </h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Pilih satu jenis permintaan.</p>
                        </div>

                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3" role="radiogroup" aria-label="Kategori">
                            <label
                                v-for="card in categories"
                                :key="card.id"
                                class="relative flex cursor-pointer flex-col gap-0.5 rounded-xl px-4 py-3 ring-1 transition"
                                :class="
                                    String(card.id) === String(categoryId)
                                        ? 'bg-primary-50 ring-2 ring-primary-600 dark:bg-primary-500/10'
                                        : 'bg-white ring-gray-950/10 hover:bg-gray-50 dark:bg-white/5 dark:ring-white/10 dark:hover:bg-white/10'
                                "
                            >
                                <input
                                    class="fi-sr-only"
                                    type="radio"
                                    name="external_wizard_category"
                                    :value="card.id"
                                    :checked="String(card.id) === String(categoryId)"
                                    @change="$emit('select-category', card.id)"
                                />
                                <span
                                    v-if="String(card.id) === String(categoryId)"
                                    class="absolute end-3 top-3 flex size-5 items-center justify-center rounded-full bg-primary-600 text-white"
                                >
                                    <Icon name="check" class="size-3.5" />
                                </span>
                                <span class="pe-6 font-heading text-base font-semibold text-gray-950 dark:text-white">{{ card.code || card.name }}</span>
                                <span v-if="cardLabel(card)" class="text-xs text-gray-500 dark:text-gray-400">{{ cardLabel(card) }}</span>
                            </label>
                        </div>
                        <p v-if="!categories.length" class="text-sm text-gray-500 dark:text-gray-400">Belum ada kategori yang dibuka.</p>
                        <p v-if="errors.problem_category_id" class="fi-fo-field-wrp-error-message" data-validation-error>
                            {{ errors.problem_category_id }}
                        </p>

                        <div v-if="loading" class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400" role="status">
                            <svg class="fi-icon fi-loading-indicator size-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                            </svg>
                            Memuat form kategori…
                        </div>
                        <Callout v-else-if="loadError" color="danger" :heading="loadError">
                            <Button color="gray" outlined @click="$emit('retry')">Coba lagi</Button>
                        </Callout>

                        <div
                            v-else-if="hasCategoryInfo"
                            class="flex gap-3 rounded-xl bg-primary-50 px-4 py-3 text-sm ring-1 ring-primary-600/20 dark:bg-primary-400/10 dark:ring-primary-400/20"
                            data-testid="external-category-info"
                        >
                            <Icon name="light-bulb" class="size-5 shrink-0 text-primary-600 dark:text-primary-400" />
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-gray-950 dark:text-white">Apa itu {{ selectedCard.name }}?</p>
                                <p
                                    v-if="selectedCard.description || schema.description"
                                    class="mt-1 whitespace-pre-line text-gray-600 dark:text-gray-300"
                                >
                                    {{ selectedCard.description || schema.description }}
                                </p>
                                <p v-if="selectedCard.needs_observation" class="mt-1 text-gray-600 dark:text-gray-300" data-testid="external-observation-note">
                                    Termasuk observasi langsung oleh tim {{ unitName || 'pelaksana' }}.
                                </p>
                                <div v-if="schema.downloads?.length" class="mt-3 flex flex-wrap gap-2">
                                    <a
                                        v-for="download in schema.downloads"
                                        :key="download.url"
                                        :href="download.url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="fi-btn fi-size-sm fi-outlined"
                                    >
                                        <Icon :name="downloadIcon(download)" class="fi-icon size-4" />
                                        <span>{{ download.label }}</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Seksi langkah; seksi yang isinya hanya field otomatis tidak ditampilkan. -->
                    <template v-for="(section, index) in shownSections" :key="section.key">
                        <div
                            class="space-y-4"
                            :class="{ 'border-t border-gray-200 pt-6 dark:border-white/10': index > 0 || current.category_picker }"
                        >
                            <div v-if="section.title || section.help">
                                <h3 v-if="section.title" class="font-heading text-base font-semibold text-gray-950 dark:text-white">
                                    {{ section.title }}
                                </h3>
                                <p v-if="section.help" class="text-sm text-gray-500 dark:text-gray-400">{{ section.help }}</p>
                            </div>

                            <ExternalFields
                                :fields="section.fields"
                                :values="values"
                                :parent-scopes="scopes"
                                :errors="errors"
                                :columns="2"
                            >
                                <template v-if="section.isHost" #entity>
                                    <slot name="entity" />
                                </template>
                            </ExternalFields>
                        </div>
                    </template>

                    <Callout v-if="generalError" color="danger" :heading="generalError" role="alert" />

                    <!-- Tombol: menempel di bawah layar karena langkahnya bisa panjang. -->
                    <div
                        class="sticky bottom-0 z-10 -mx-6 -mb-6 flex items-center justify-between gap-3 rounded-b-xl border-t border-gray-200 bg-white px-6 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] dark:border-white/10 dark:bg-gray-900"
                    >
                        <Button v-if="currentIndex > 0" color="gray" outlined :disabled="processing" @click="back">
                            <span class="inline-flex items-center gap-1.5"><Icon name="arrow-left" class="size-4" /> Kembali</span>
                        </Button>
                        <span v-else />
                        <Button v-if="!showSubmit" :disabled="loading || blocked" @click="next">
                            <span class="inline-flex items-center gap-1.5">Lanjut <Icon name="arrow-right" class="size-4" /></span>
                        </Button>
                        <Button v-else :disabled="loading || blocked" :processing="processing" @click="next">
                            <span class="inline-flex items-center gap-1.5">
                                <Icon name="paper-airplane" class="size-4" />
                                {{ processing ? 'Mengirim…' : 'Kirim pengajuan' }}
                            </span>
                        </Button>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>

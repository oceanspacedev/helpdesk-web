<script setup>
import { computed, reactive, toRaw, useSlots, watch } from 'vue';
import Button from '../Button.vue';
import Field from '../Field.vue';
import Input from '../Input.vue';
import SelectInput from '../SelectInput.vue';
import TextareaInput from '../TextareaInput.vue';
import {
    acceptAttribute,
    errorFor,
    fileErrors,
    formatKb,
    initialRow,
    isSingleRow,
    isVisible,
    levelScope,
    minDate,
    rulesHint,
} from '../../support/externalForm.js';

defineOptions({ name: 'ExternalFields' });

const props = defineProps({
    fields: { type: Array, required: true },
    // Nilai level ini; diubah langsung (objek reaktif milik form induk).
    values: { type: Object, required: true },
    // Nilai level-level di atas untuk visible_if.
    parentScopes: { type: Array, default: () => [] },
    path: { type: String, default: 'answers' },
    errors: { type: Object, default: () => ({}) },
    idPrefix: { type: String, default: 'answers' },
    // 2 = isian pendek berdampingan di layar lebar (seperti form asli sistem tujuan).
    columns: { type: Number, default: 1 },
});

// Tipe yang tetap selebar form saat dua kolom.
const wideTypes = ['textarea', 'repeater', 'radio', 'info', 'file', 'files'];

// Isian pendek berpasangan dua-dua; yang tidak punya pasangan tetap selebar form.
const spans = computed(() => {
    const result = {};
    let run = [];
    const flush = () => {
        if (run.length % 2 === 1) {
            result[run[run.length - 1]] = true;
        }
        run = [];
    };
    for (const field of visibleFields.value) {
        if (wideTypes.includes(field.type)) {
            flush();
            result[field.key] = true;
        } else {
            run.push(field.key);
        }
    }
    flush();

    return result;
});

function span(field) {
    return props.columns > 1 && spans.value[field.key] ? 'sm:col-span-2' : '';
}

const inputTypes = {
    text: 'text',
    email: 'email',
    phone: 'tel',
    number: 'number',
    date: 'date',
};

const scopes = computed(() => [levelScope(props.fields, props.values), ...props.parentScopes]);

// Field yang diisi helpdesk saat dikirim tidak ditampilkan: identitas
// otomatis (labelnya disebut di catatan ringkas ExternalForm) dan badan
// usaha (`ticket`, dijawab lewat pertanyaan badan usaha di form tiket).
const slots = useSlots();

// Pertanyaan badan usaha helpdesk (slot `entity`) dirender di posisi field
// badan usaha form sistem tujuan, agar urutannya sama dengan form aslinya.
function isEntitySlot(field) {
    return Boolean(field.business_entity && slots.entity);
}

// Pertanyaan badan usaha selalu tampil (wajib dijawab), apa pun visible_if-nya.
const visibleFields = computed(() =>
    props.fields.filter(
        (field) => isEntitySlot(field) || (!field.auto && !field.ticket && isVisible(field, scopes.value)),
    ),
);

// Form tanpa field badan usaha: pertanyaan badan usaha helpdesk di awal seksi.
const entityFirst = computed(() => Boolean(slots.entity) && !props.fields.some((field) => field.business_entity));

// Repeater satu baris selalu punya barisnya (mis. setelah ganti kategori).
watch(
    visibleFields,
    (list) => {
        for (const field of list) {
            if (isSingleRow(field) && !rows(field).length) {
                props.values[field.key] = [initialRow(field.fields)];
            }
        }
    },
    { immediate: true },
);

// Kunci stabil per baris repeater agar input file tidak tertukar saat baris dihapus.
const rowKeys = new WeakMap();
let rowCounter = 0;

function rowKey(row) {
    const raw = toRaw(row);
    if (!rowKeys.has(raw)) {
        rowCounter += 1;
        rowKeys.set(raw, rowCounter);
    }

    return rowKeys.get(raw);
}

function fieldId(field) {
    return `${props.idPrefix}_${field.key}`;
}

function fieldPath(field) {
    return `${props.path}.${field.key}`;
}

const localFileErrors = reactive({});

function fieldError(field) {
    return errorFor(props.errors, fieldPath(field), field) || localFileErrors[field.key] || '';
}

function hint(field) {
    // Repeater: teks bantuan sistem tujuan biasanya sudah menyebut batas baris.
    if (field.type === 'repeater' && field.help) {
        return field.help;
    }

    return [field.help, rulesHint(field)].filter(Boolean).join(' · ');
}

function selectOptions(field) {
    return (field.options ?? []).map((option) => ({ id: option.value, name: option.label }));
}

function textAttributes(field) {
    const rules = field.rules ?? {};
    if (['text', 'textarea'].includes(field.type)) {
        return {
            minlength: rules.min || undefined,
            maxlength: rules.max || undefined,
        };
    }
    if (field.type === 'number') {
        return { min: rules.min, max: rules.max, step: 'any', inputmode: 'decimal' };
    }
    if (field.type === 'date') {
        return { min: minDate(field) };
    }
    if (field.type === 'phone') {
        return { inputmode: 'tel', autocomplete: 'tel' };
    }
    if (field.type === 'email') {
        return { autocomplete: 'email' };
    }

    return {};
}

function rows(field) {
    const value = props.values[field.key];

    return Array.isArray(value) ? value : [];
}

function canAddRow(field) {
    return !Number.isInteger(field.max_items) || rows(field).length < field.max_items;
}

function canRemoveRow(field) {
    return rows(field).length > Math.max(field.min_items ?? 0, 0);
}

function addRow(field) {
    if (!canAddRow(field)) {
        return;
    }
    if (!Array.isArray(props.values[field.key])) {
        props.values[field.key] = [];
    }
    props.values[field.key].push(initialRow(field.fields));
}

function removeRow(field, index) {
    if (canRemoveRow(field)) {
        rows(field).splice(index, 1);
    }
}

function filesOf(field) {
    const value = props.values[field.key];
    if (field.type === 'file') {
        return value ? [value] : [];
    }

    return Array.isArray(value) ? value : [];
}

function onFilesSelected(field, event) {
    const picked = Array.from(event.target.files ?? []);
    let next;
    if (field.type === 'file') {
        next = picked[0] ?? null;
    } else {
        const existing = filesOf(field);
        const seen = new Set(existing.map((file) => `${file.name}:${file.size}:${file.lastModified}`));
        next = [...existing, ...picked.filter((file) => !seen.has(`${file.name}:${file.size}:${file.lastModified}`))];
    }
    props.values[field.key] = next;
    const list = field.type === 'file' ? (next ? [next] : []) : next;
    const message = list.length ? fileErrors(field, list) : null;
    if (message) {
        localFileErrors[field.key] = message;
    } else {
        delete localFileErrors[field.key];
    }
    event.target.value = '';
}

function removeFile(field, index) {
    if (field.type === 'file') {
        props.values[field.key] = null;
        delete localFileErrors[field.key];

        return;
    }

    const files = [...filesOf(field)];
    files.splice(index, 1);
    props.values[field.key] = files;
    const message = files.length ? fileErrors(field, files) : null;
    if (message) {
        localFileErrors[field.key] = message;
    } else {
        delete localFileErrors[field.key];
    }
}

function applySuggestion(field, chip) {
    const current = String(props.values[field.key] ?? '').trim();
    props.values[field.key] = current ? `${current}, ${chip.toLowerCase()}` : chip;
}

function pickFiles(field) {
    document.getElementById(fieldId(field))?.click();
}

function formatSize(bytes) {
    return formatKb(Math.max(1, Math.round((bytes ?? 0) / 1024)));
}
</script>

<template>
    <div :class="columns > 1 ? 'grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2' : 'space-y-6'">
        <div v-if="entityFirst" :class="columns > 1 ? 'sm:col-span-2' : ''">
            <slot name="entity" />
        </div>
        <div v-for="field in visibleFields" :key="field.key" :class="span(field)">
            <slot v-if="isEntitySlot(field)" name="entity" />

            <!-- info: hanya label + teks bantuan -->
            <div
                v-else-if="field.type === 'info'"
                class="rounded-lg bg-gray-50 px-3 py-2.5 text-sm ring-1 ring-gray-200 dark:bg-white/5 dark:ring-white/10"
            >
                <p class="font-medium text-gray-950 dark:text-white">{{ field.label }}</p>
                <p v-if="field.help" class="mt-1 whitespace-pre-line text-gray-600 dark:text-gray-300">{{ field.help }}</p>
            </div>

            <Field
                v-else-if="inputTypes[field.type]"
                :id="fieldId(field)"
                :label="field.label"
                :required="field.required"
                :error="fieldError(field)"
                :hint="hint(field)"
            >
                <Input
                    :id="fieldId(field)"
                    v-model="values[field.key]"
                    :type="inputTypes[field.type]"
                    :required="field.required"
                    :placeholder="field.placeholder"
                    :invalid="Boolean(fieldError(field))"
                    v-bind="textAttributes(field)"
                />
                <div v-if="field.suggestions?.length" class="mt-2 flex flex-wrap gap-1.5">
                    <button
                        v-for="chip in field.suggestions"
                        :key="chip"
                        type="button"
                        class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 ring-1 ring-gray-950/10 transition hover:bg-gray-200 dark:bg-white/10 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-white/15"
                        @click="applySuggestion(field, chip)"
                    >
                        {{ chip }}
                    </button>
                </div>
            </Field>

            <Field
                v-else-if="field.type === 'textarea'"
                :id="fieldId(field)"
                :label="field.label"
                :required="field.required"
                :error="fieldError(field)"
                :hint="hint(field)"
            >
                <TextareaInput
                    :id="fieldId(field)"
                    v-model="values[field.key]"
                    rows="4"
                    :required="field.required"
                    :placeholder="field.placeholder"
                    :invalid="Boolean(fieldError(field))"
                    v-bind="textAttributes(field)"
                />
                <div v-if="field.suggestions?.length" class="mt-2 flex flex-wrap gap-1.5">
                    <button
                        v-for="chip in field.suggestions"
                        :key="chip"
                        type="button"
                        class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 ring-1 ring-gray-950/10 transition hover:bg-gray-200 dark:bg-white/10 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-white/15"
                        @click="applySuggestion(field, chip)"
                    >
                        {{ chip }}
                    </button>
                </div>
            </Field>

            <Field
                v-else-if="field.type === 'select'"
                :id="fieldId(field)"
                :label="field.label"
                :required="field.required"
                :error="fieldError(field)"
                :hint="hint(field)"
            >
                <SelectInput
                    :id="fieldId(field)"
                    v-model="values[field.key]"
                    :required="field.required"
                    :placeholder="field.placeholder || 'Pilih'"
                    :options="selectOptions(field)"
                    :invalid="Boolean(fieldError(field))"
                />
            </Field>

            <fieldset v-else-if="field.type === 'radio'" class="fi-fo-field">
                <legend class="fi-fo-field-label">
                    <span class="fi-fo-field-label-content">
                        {{ field.label }}<sup v-if="field.required" class="fi-fo-field-label-required-mark">*</sup>
                    </span>
                </legend>
                <div class="mt-2 flex gap-2" :class="(field.options ?? []).length <= 5 ? 'flex-wrap' : 'flex-col'">
                    <label
                        v-for="option in field.options"
                        :key="option.value"
                        class="flex cursor-pointer items-center gap-2 rounded-lg px-3 py-2 text-sm text-gray-950 ring-1 ring-gray-950/10 has-checked:bg-primary-50 has-checked:ring-primary-600 dark:text-white dark:ring-white/10 dark:has-checked:bg-primary-500/10 dark:has-checked:ring-primary-500"
                    >
                        <input
                            v-model="values[field.key]"
                            class="fi-radio-input"
                            type="radio"
                            :name="fieldId(field)"
                            :value="option.value"
                            :required="field.required"
                        />
                        <span>{{ option.label }}</span>
                    </label>
                </div>
                <p v-if="hint(field) && !fieldError(field)" class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ hint(field) }}</p>
                <p v-if="fieldError(field)" class="fi-fo-field-wrp-error-message" data-validation-error>{{ fieldError(field) }}</p>
            </fieldset>

            <Field
                v-else-if="field.type === 'file' || field.type === 'files'"
                :id="fieldId(field)"
                :label="field.label"
                :required="field.required"
                :error="fieldError(field)"
                :hint="hint(field)"
            >
                <input
                    :id="fieldId(field)"
                    class="fi-sr-only"
                    type="file"
                    :multiple="field.type === 'files'"
                    :accept="acceptAttribute(field)"
                    @change="onFilesSelected(field, $event)"
                />
                <div class="flex flex-wrap items-center gap-3">
                    <Button color="gray" outlined @click="pickFiles(field)">
                        {{ field.type === 'files' ? 'Tambah file' : filesOf(field).length ? 'Ganti file' : 'Pilih file' }}
                    </Button>
                    <span v-if="!filesOf(field).length" class="text-sm text-gray-500 dark:text-gray-400">
                        Belum ada file.
                    </span>
                </div>
                <ul
                    v-if="filesOf(field).length"
                    class="mt-2 divide-y divide-gray-200 text-sm text-gray-600 dark:divide-white/10 dark:text-gray-300"
                >
                    <li
                        v-for="(file, index) in filesOf(field)"
                        :key="`${file.name}-${file.size}-${index}`"
                        class="flex items-center justify-between gap-3 py-1.5"
                    >
                        <span class="truncate">{{ file.name }}</span>
                        <span class="flex shrink-0 items-center gap-3">
                            <span>{{ formatSize(file.size) }}</span>
                            <button
                                type="button"
                                class="fi-link fi-size-sm fi-color fi-color-danger"
                                @click="removeFile(field, index)"
                            >
                                Hapus
                            </button>
                        </span>
                    </li>
                </ul>
            </Field>

            <!-- Repeater satu baris: isian baris itu langsung, tanpa kartu. -->
            <div v-else-if="isSingleRow(field)" class="space-y-3">
                <ExternalFields
                    v-if="rows(field)[0]"
                    :fields="field.fields ?? []"
                    :values="rows(field)[0]"
                    :parent-scopes="scopes"
                    :path="`${fieldPath(field)}.0`"
                    :errors="errors"
                    :id-prefix="`${fieldId(field)}_0`"
                    :columns="columns"
                />
                <p v-if="fieldError(field)" class="fi-fo-field-wrp-error-message" data-validation-error>
                    {{ fieldError(field) }}
                </p>
            </div>

            <div v-else-if="field.type === 'repeater'" class="space-y-3">
                <div>
                    <p class="fi-fo-field-label">
                        <span class="fi-fo-field-label-content">
                            {{ field.label }}<sup v-if="field.required" class="fi-fo-field-label-required-mark">*</sup>
                        </span>
                    </p>
                    <p v-if="hint(field)" class="text-sm text-gray-500 dark:text-gray-400">{{ hint(field) }}</p>
                </div>

                <div
                    v-for="(row, index) in rows(field)"
                    :key="rowKey(row)"
                    class="rounded-xl p-4 ring-1 ring-gray-200 dark:ring-white/10"
                >
                    <div class="mb-4 flex items-center justify-between gap-3">
                        <span class="text-sm font-medium text-gray-950 dark:text-white">{{ field.label }} #{{ index + 1 }}</span>
                        <button
                            v-if="canRemoveRow(field)"
                            type="button"
                            class="fi-link fi-size-sm fi-color fi-color-danger"
                            @click="removeRow(field, index)"
                        >
                            Hapus baris
                        </button>
                    </div>
                    <ExternalFields
                        :fields="field.fields ?? []"
                        :values="row"
                        :parent-scopes="scopes"
                        :path="`${fieldPath(field)}.${index}`"
                        :errors="errors"
                        :id-prefix="`${fieldId(field)}_${rowKey(row)}`"
                        :columns="columns"
                    />
                </div>

                <Button v-if="canAddRow(field)" color="gray" outlined @click="addRow(field)">
                    + Tambah {{ field.label.charAt(0).toLowerCase() + field.label.slice(1) }}
                </Button>
                <p v-if="fieldError(field)" class="fi-fo-field-wrp-error-message" data-validation-error>
                    {{ fieldError(field) }}
                </p>
            </div>
        </div>
    </div>
</template>

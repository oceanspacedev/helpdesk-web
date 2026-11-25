<script setup>
import { computed, toRaw } from 'vue';
import Button from '../Button.vue';
import Field from '../Field.vue';
import Input from '../Input.vue';
import SelectInput from '../SelectInput.vue';
import TextareaInput from '../TextareaInput.vue';
import {
    acceptAttribute,
    errorFor,
    formatKb,
    initialRow,
    isVisible,
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
});

const inputTypes = {
    text: 'text',
    email: 'email',
    phone: 'tel',
    number: 'number',
    date: 'date',
};

const scopes = computed(() => [props.values, ...props.parentScopes]);

const visibleFields = computed(() => props.fields.filter((field) => isVisible(field, scopes.value)));

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

function fieldError(field) {
    return errorFor(props.errors, fieldPath(field), field);
}

function hint(field) {
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
    if (field.type === 'file') {
        props.values[field.key] = picked[0] ?? null;
    } else {
        props.values[field.key] = [...filesOf(field), ...picked];
    }
    event.target.value = '';
}

function removeFile(field, index) {
    if (field.type === 'file') {
        props.values[field.key] = null;

        return;
    }

    const files = [...filesOf(field)];
    files.splice(index, 1);
    props.values[field.key] = files;
}

function pickFiles(field) {
    document.getElementById(fieldId(field))?.click();
}

function formatSize(bytes) {
    return formatKb(Math.max(1, Math.round((bytes ?? 0) / 1024)));
}
</script>

<template>
    <div class="space-y-6">
        <template v-for="field in visibleFields" :key="field.key">
            <!-- info: hanya label + teks bantuan -->
            <div
                v-if="field.type === 'info'"
                class="rounded-lg bg-gray-50 px-3 py-2.5 text-sm ring-1 ring-gray-200 dark:bg-white/5 dark:ring-white/10"
            >
                <p class="font-medium text-gray-950 dark:text-white">{{ field.label }}</p>
                <p v-if="field.help" class="mt-1 whitespace-pre-line text-gray-600 dark:text-gray-300">{{ field.help }}</p>
            </div>

            <!-- Diisi server dari identitas pelapor; nilainya tidak dikirim ke browser. -->
            <Field
                v-else-if="field.auto"
                :id="fieldId(field)"
                :label="field.label"
                :required="field.required"
                :error="fieldError(field)"
                :hint="field.help"
            >
                <div
                    :id="fieldId(field)"
                    class="rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-600 ring-1 ring-gray-200 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10"
                >
                    Diisi otomatis dari data pelapor terdaftar.
                </div>
            </Field>

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
                <div class="mt-2 flex flex-col gap-2">
                    <label
                        v-for="option in field.options"
                        :key="option.value"
                        class="flex items-center gap-2 text-sm text-gray-950 dark:text-white"
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
                    />
                </div>

                <Button v-if="canAddRow(field)" color="gray" outlined @click="addRow(field)">
                    Tambah {{ field.label }}
                </Button>
                <p v-if="fieldError(field)" class="fi-fo-field-wrp-error-message" data-validation-error>
                    {{ fieldError(field) }}
                </p>
            </div>
        </template>
    </div>
</template>

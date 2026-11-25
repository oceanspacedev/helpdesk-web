// Logika wizard form sistem eksternal (kontrak §3.2 "Langkah", v1.1) untuk
// form publik. Murni (tanpa Vue) agar bisa diuji dengan node:test. Sama
// dengan ExternalTicketForm (panel): field level atas punya `step`/`section`
// dari server; field tanpa seksi yang dikenal masuk seksi tanpa judul di akhir
// langkahnya.

import { initialRow, isVisible, levelScope, validateAnswers } from './externalForm.js';

export function hasSteps(schema) {
    return Array.isArray(schema?.steps) && schema.steps.length > 0;
}

/**
 * Seksi satu langkah beserta field-nya.
 */
export function stepSections(step, fields) {
    const sections = (step.sections ?? []).map((section) => ({ ...section, fields: [] }));
    const loose = { key: '_', title: '', fields: [] };
    for (const field of fields ?? []) {
        if (field.step !== step.key) {
            continue;
        }
        const section = sections.find((item) => item.key === field.section);
        (section ?? loose).fields.push(field);
    }
    if (loose.fields.length) {
        sections.push(loose);
    }

    return sections;
}

/**
 * [langkah, seksi] tempat pertanyaan badan usaha helpdesk dirender: seksi
 * field `ticket.business_entity` level atas pertama, selain itu seksi pertama
 * langkah pertama.
 */
export function entityHost(schema) {
    const steps = schema?.steps ?? [];
    if (!steps.length) {
        return null;
    }
    const fields = schema.fields ?? [];
    const field = fields.find((item) => item.ticket);
    if (field) {
        const step = steps.find((item) => item.key === field.step);
        const section = step ? stepSections(step, fields).find((item) => item.fields.includes(field)) : null;
        if (section) {
            return [step.key, section.key];
        }
    }
    const first = stepSections(steps[0], fields)[0];

    return first ? [steps[0].key, first.key] : null;
}

/**
 * Apakah salah satu field (otomatis, badan usaha, atau input) tampil menurut
 * visible_if; info tidak dihitung. `fields` = semua field level atas.
 */
export function hasVisibleFields(subset, fields, values) {
    const scopes = [levelScope(fields, values)];

    return (subset ?? []).some((field) => field.type !== 'info' && isVisible(field, scopes));
}

/**
 * Apakah salah satu field dapat diisi pelapor (bukan auto, ticket, atau info)
 * dan tampil menurut visible_if.
 */
export function hasEditableFields(subset, fields, values) {
    const scopes = [levelScope(fields, values)];

    return (subset ?? []).some(
        (field) => field.type !== 'info' && !field.auto && !field.ticket && isVisible(field, scopes),
    );
}

/**
 * Semua langkah dengan seksinya dan status tampil. Langkah/seksi yang hanya
 * berisi field otomatis dilewati agar pelapor tidak melewati halaman kosong;
 * label otomatisnya diringkas di langkah host badan usaha (atau langkah tampil
 * pertama). Sebelum kategori dipilih (`chosen` = false), seksi langkah pilihan
 * kategori dan langkah sesudahnya (kecuali pemilik pertanyaan badan usaha)
 * belum tampil.
 *
 * `schema.fields` sebaiknya sudah berisi `ticket_value` (withTicketValues).
 */
export function wizardSteps(schema, values, { chosen = true } = {}) {
    const fields = schema?.fields ?? [];
    const host = entityHost(schema);
    let afterPicker = false;

    const mapped = (schema?.steps ?? []).map((step) => {
        const picker = Boolean(step.category_picker);
        const hostStep = host?.[0] === step.key;
        const hidden = afterPicker && !chosen && !hostStep;
        afterPicker = afterPicker || picker;
        const sections = stepSections(step, fields).map((section) => {
            const isHost = hostStep && host[1] === section.key;

            return {
                ...section,
                isHost,
                visible: (!picker || chosen) && (isHost || hasEditableFields(section.fields, fields, values)),
            };
        });
        const stepFields = fields.filter((field) => field.step === step.key);

        return {
            key: step.key,
            title: step.title,
            help: step.help ?? '',
            category_picker: picker,
            sections,
            fields: stepFields,
            skippedAutoFields: [],
            hostsEntity: hostStep,
            visible: !hidden && (picker || hostStep || hasEditableFields(stepFields, fields, values)),
        };
    });

    const skippedAuto = mapped
        .filter((step) => !step.visible && !step.category_picker)
        .flatMap((step) => step.fields.filter((field) => field.auto));
    if (skippedAuto.length) {
        const receiver = mapped.find((step) => step.visible && step.hostsEntity) ?? mapped.find((step) => step.visible);
        if (receiver) {
            receiver.skippedAutoFields = skippedAuto;
        }
    }

    return mapped;
}

export function visibleSteps(schema, values, options = {}) {
    return wizardSteps(schema, values, options).filter((step) => step.visible);
}

/**
 * Validasi ringan satu langkah (field input langkah itu saja; visible_if
 * tetap dievaluasi terhadap semua field). Kunci error = path kontrak.
 */
export function validateStep(step, fields, values) {
    if (step.category_picker && !step.sections.some((section) => section.visible)) {
        return {};
    }

    return validateAnswers(step.fields, values, [levelScope(fields, values)]);
}

/**
 * Indeks langkah (di daftar `steps`) yang memuat error berkunci `key`, atau -1.
 */
export function stepIndexForError(steps, key) {
    if (key === 'problem_category_id') {
        return steps.findIndex((step) => step.category_picker);
    }
    if (key === 'external_business_entity' || key === 'business_entities_id') {
        return steps.findIndex((step) => step.hostsEntity);
    }
    const match = /^answers\.([a-z0-9_]+)/.exec(key);
    if (!match) {
        return -1;
    }

    return steps.findIndex((step) => step.fields.some((field) => field.key === match[1]));
}

/**
 * Langkah pertama yang memuat salah satu error, atau -1.
 */
export function firstErrorStep(steps, errors) {
    const indexes = Object.keys(errors ?? {})
        .filter((key) => errors[key])
        .map((key) => stepIndexForError(steps, key))
        .filter((index) => index >= 0);

    return indexes.length ? Math.min(...indexes) : -1;
}

function carryValue(field, old) {
    switch (field.type) {
        case 'repeater':
            return Array.isArray(old) && old.length
                ? old.map((row) => carryAnswers(field.fields, row ?? {}))
                : undefined;
        case 'file':
            return old && typeof old === 'object' && !Array.isArray(old) ? old : undefined;
        case 'files':
            return Array.isArray(old) ? old.filter(Boolean) : undefined;
        default:
            return old !== null && old !== undefined && typeof old !== 'object' ? String(old) : undefined;
    }
}

/**
 * Isian awal form kategori baru dengan isian lama berkey sama dipertahankan
 * (kontrak §3.2: mengganti kategori memuat ulang form; isian dengan key sama
 * dipertahankan). Field otomatis/badan usaha tidak pernah punya isian.
 */
export function carryAnswers(fields, previous = {}) {
    const fresh = initialRow(fields);
    for (const field of fields ?? []) {
        if (!(field.key in fresh) || !previous || !Object.prototype.hasOwnProperty.call(previous, field.key)) {
            continue;
        }
        const value = carryValue(field, previous[field.key]);
        if (value !== undefined) {
            fresh[field.key] = value;
        }
    }

    return fresh;
}

/**
 * Error yang tersisa setelah tombol Lanjut di langkah `index`: error lama
 * milik langkah itu diganti `stepErrors` (validasi klien langkah itu), error
 * langkah lain dan error umum (mis. 422 dari sistem tujuan yang tidak punya
 * pemeriksaan di browser) tetap sampai pelapor sampai di langkahnya atau
 * mengirim ulang.
 */
export function keepOtherStepErrors(current, stepErrors, steps, index) {
    const kept = {};
    for (const [key, message] of Object.entries(current ?? {})) {
        if (message && stepIndexForError(steps, key) !== index) {
            kept[key] = message;
        }
    }

    return { ...kept, ...(stepErrors ?? {}) };
}

/**
 * Form kategori terpilih sudah dimuat (form yang dirender milik kartu yang
 * dipilih, bukan kategori sebelumnya).
 */
export function categoryReady({ categoryId, schemaCategoryId, schema }) {
    return Boolean(categoryId) && Boolean(schema) && String(schemaCategoryId) === String(categoryId);
}

/**
 * Seksi langkah kartu kategori dan langkah sesudahnya tampil: form kategori
 * terpilih sudah dimuat, atau form kategori sebelumnya tetap tampil selama
 * form baru dimuat. Bila form baru gagal dimuat, langkah kategori lama tidak
 * tampil lagi.
 */
export function showCategorySteps({ categoryId, schemaCategoryId, schema }) {
    return categoryReady({ categoryId, schemaCategoryId, schema });
}

/**
 * Error langkah kartu kategori: belum memilih, atau form kategori yang
 * dipilih belum/gagal dimuat (`chosen` = langkah kategori tampil).
 */
export function pickerErrors(step, { categoryId, chosen }) {
    if (!step?.category_picker) {
        return {};
    }
    if (!categoryId) {
        return { problem_category_id: 'Pilih kategori terlebih dahulu.' };
    }
    if (!chosen) {
        return { problem_category_id: 'Form kategori ini belum dapat dimuat. Coba lagi atau pilih kategori lain.' };
    }

    return {};
}

/**
 * Tombol "Kirim pengajuan" tampil di langkah terakhir, kecuali langkah kartu
 * kategori sebelum form kategori terpilih dimuat (langkah sesudahnya belum
 * diketahui).
 */
export function submitVisible(steps, index, { categoryId, chosen }) {
    if (index < steps.length - 1) {
        return false;
    }

    return !(steps[index]?.category_picker && !(categoryId && chosen));
}

/**
 * Pesan bila pengajuan wizard belum bisa dikirim karena kategori: belum
 * dipilih, atau formnya gagal/belum dimuat. String kosong bila siap.
 */
export function wizardSubmitBlocker({ categoryId, ready, loadError = '' }) {
    if (!categoryId) {
        return 'Pilih kategori terlebih dahulu.';
    }
    if (!ready) {
        return loadError || 'Form kategori ini belum dimuat. Tunggu sebentar lalu coba lagi.';
    }

    return '';
}

/**
 * Form kategori yang dirender di dalam wizard unit (sama dengan
 * ExternalFormSchema::asWizard). Form tanpa `steps` memakai langkah form
 * pembuka: field yang key-nya ada di form pembuka masuk langkah/seksi field
 * itu, field lain masuk langkah kartu kategori (tanpa seksi).
 */
export function asWizardSchema(schema, opening) {
    if (!schema || hasSteps(schema) || !hasSteps(opening)) {
        return schema;
    }

    const target = opening.steps.find((step) => step.category_picker) ?? opening.steps[opening.steps.length - 1];
    const placed = new Map(
        (opening.fields ?? []).filter((field) => field.step).map((field) => [field.key, field]),
    );

    return {
        ...schema,
        steps: opening.steps,
        fields: (schema.fields ?? []).map((field) => {
            const { section: _section, ...rest } = field;
            const source = placed.get(field.key);

            return source
                ? { ...rest, step: source.step, ...(source.section ? { section: source.section } : {}) }
                : { ...rest, step: target.key };
        }),
    };
}

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import Brand from '../../components/Brand.vue';
import Button from '../../components/Button.vue';
import Callout from '../../components/Callout.vue';
import Card from '../../components/Card.vue';
import ExternalForm from '../../components/ExternalForm/ExternalForm.vue';
import ExternalWizard from '../../components/ExternalForm/ExternalWizard.vue';
import Field from '../../components/Field.vue';
import Icon from '../../components/Icon.vue';
import Input from '../../components/Input.vue';
import Section from '../../components/Section.vue';
import SelectInput from '../../components/SelectInput.vue';
import SimpleLayout from '../../components/SimpleLayout.vue';
import TextareaInput from '../../components/TextareaInput.vue';
import TextLink from '../../components/TextLink.vue';
import TicketSuccessCard from '../../components/TicketSuccessCard.vue';
import ToastRegion from '../../components/ToastRegion.vue';
import { useToast } from '../../support/toast.js';
import {
    initialValues,
    levelScope,
    matchBusinessEntityOption,
    newIdempotencyKey,
    pruneAnswers,
    ticketEntityTexts,
    validateAnswers,
    withTicketValues,
} from '../../support/externalForm.js';
import {
    asWizardSchema,
    carryAnswers,
    categoryReady,
    hasSteps,
    keepOtherStepErrors,
    showCategorySteps,
    visibleSteps,
    wizardSubmitBlocker,
} from '../../support/externalWizard.js';

const props = defineProps({
    screen: { type: String, required: true },
    reporter: { type: Object, default: null },
    submissionToken: { type: String, default: '' },
    options: { type: Object, required: true },
    defaultPriorityId: { type: [Number, String], default: null },
    old: { type: Object, default: () => ({}) },
    urls: { type: Object, required: true },
    brandUrl: { type: String, required: true },
    appName: { type: String, default: 'Helpdesk' },
});

const page = usePage();
const toast = useToast();
const flash = computed(() => page.props.flash ?? {});
const dismissedSuccess = ref(false);
const visibleTicketSuccess = computed(() =>
    dismissedSuccess.value ? null : (flash.value.ticket_success ?? null),
);

function notifyTicketSuccess(success) {
    if (!success) {
        return;
    }

    const code = success.number || success.external_ref;
    const parts = [code, success.unit ? `Unit ${success.unit}` : null].filter(Boolean);
    toast.success({
        dedupeKey: `ticket-success:${code ?? ''}:${success.title ?? ''}`,
        title: 'Laporan berhasil dikirim',
        description: parts.join(' · '),
        duration: 4500,
    });
}

function notifySubmitError() {
    toast.danger({
        dedupeKey: 'ticket-submit-error',
        title: 'Laporan belum dapat dikirim',
        description: 'Periksa kembali bagian yang ditandai merah.',
        duration: 4500,
    });
}

watch(
    () => flash.value.ticket_success,
    (success) => {
        dismissedSuccess.value = false;
        if (success) {
            notifyTicketSuccess(success);
        }
    },
    { immediate: true },
);

watch(
    () => flash.value.status,
    (status) => {
        if (status && !flash.value.ticket_success) {
            toast.info({
                dedupeKey: `flash-status:${status}`,
                title: status,
                duration: 4500,
            });
        }
    },
    { immediate: true },
);
const maxFiles = 5;
const maxBytes = 10 * 1024 * 1024;
const fileInput = ref(null);

const identityForm = useForm({
    phone: props.old.phone ?? '',
    website: '',
});

const ticketForm = useForm({
    submission_token: props.submissionToken,
    website: '',
    business_entities_id: props.old.business_entities_id ?? '',
    // Pertanyaan badan usaha versi sistem tujuan: nilai opsi field badan
    // usaha form eksternal (bila form punya field itu).
    external_business_entity: props.old.external_business_entity ?? '',
    unit_id: props.old.unit_id ?? '',
    problem_category_id: props.old.problem_category_id ?? '',
    priority_id: props.old.priority_id ?? props.defaultPriorityId ?? '',
    title: props.old.title ?? '',
    description: props.old.description ?? '',
    supporting_attachments: [],
    // Hanya untuk kategori unit berintegrasi (form sistem eksternal).
    idempotency_key: newIdempotencyKey(),
    answers: {},
});

// Form dinamis sistem eksternal untuk kategori yang dipilih.
const externalSchema = ref(null);
// Kategori pemilik externalSchema (form wizard: kategori sudah dipilih dan dimuat).
const externalSchemaCategoryId = ref('');
const externalLoading = ref(false);
const externalLoadError = ref('');
let externalRequestId = 0;

// Form pembuka unit berintegrasi (kontrak §3.2 "Langkah"): bila punya
// `steps`, pengajuan memakai wizard seperti form asli sistem tujuan.
// { schema, categoryId } atau null.
const unitWizard = ref(null);
const unitLoading = ref(false);
let unitRequestId = 0;
const wizard = ref(null);

const changeForm = useForm({});

const categoriesForUnit = computed(() => {
    const unitId = String(ticketForm.unit_id || '');
    if (!unitId) {
        return [];
    }

    return (props.options.problem_categories ?? []).filter(
        (category) => String(category.unit_id) === unitId,
    );
});

const selectedCategory = computed(() =>
    (props.options.problem_categories ?? []).find(
        (category) => String(category.id) === String(ticketForm.problem_category_id),
    ) ?? null,
);

const isExternal = computed(() => Boolean(selectedCategory.value?.is_external));

const selectedUnit = computed(
    () => (props.options.units ?? []).find((unit) => String(unit.id) === String(ticketForm.unit_id)) ?? null,
);

// Unit berintegrasi yang formnya wizard: kategori dipilih sebagai kartu di
// dalam wizard, judul tidak ditanyakan (diturunkan server).
const wizardMode = computed(() => Boolean(selectedUnit.value?.is_external) && hasSteps(unitWizard.value?.schema));

// Form unit sedang dimuat: belum diketahui wizard atau bukan.
const unitPending = computed(() => Boolean(selectedUnit.value?.is_external) && unitLoading.value);

const categoryState = computed(() => ({
    categoryId: ticketForm.problem_category_id,
    schemaCategoryId: externalSchemaCategoryId.value,
    schema: externalSchema.value,
    loading: externalLoading.value,
}));

// Kategori terpilih dan formnya (milik kartu itu) sudah dimuat.
const wizardChosen = computed(() => wizardMode.value && categoryReady(categoryState.value));

// Untuk tampilan wizard: seksi kartu kategori dan langkah sesudahnya tampil
// bila form kategori terpilih sudah dimuat; selama form baru dimuat, form
// kategori sebelumnya tetap tampil. Bila form baru gagal dimuat, langkah
// kategori lama tidak tampil lagi.
const wizardShowsCategory = computed(() => wizardMode.value && showCategorySteps(categoryState.value));

// Form yang sedang dirender: form kategori terpilih, atau (wizard sebelum
// kategori dipilih / form kategori gagal dimuat) form pembuka unit.
const activeSchema = computed(() => {
    if (!wizardMode.value) {
        return externalSchema.value;
    }

    return wizardShowsCategory.value ? externalSchema.value : unitWizard.value?.schema ?? null;
});

const wizardCategories = computed(() => activeSchema.value?.categories ?? unitWizard.value?.schema?.categories ?? []);

const externalActive = computed(() => isExternal.value || wizardMode.value);

const selectedBusinessEntityName = computed(
    () =>
        (props.options.business_entities ?? []).find(
            (entity) => String(entity.id) === String(ticketForm.business_entities_id),
        )?.name ?? '',
);

// Form sistem tujuan yang punya field badan usaha: pertanyaan "Badan Usaha
// yang Dilaporkan" memakai opsi field itu (satu pertanyaan saja, milik
// helpdesk). { key, label, required, options[], default } atau null.
const externalEntity = computed(() => (externalActive.value ? activeSchema.value?.business_entity ?? null : null));

const externalEntityOptions = computed(() =>
    (externalEntity.value?.options ?? []).map((option) => ({ id: option.value, name: option.label })),
);

// Pilihan badan usaha helpdesk tampil untuk unit internal, dan untuk form
// sistem tujuan tanpa field badan usaha (setelah formnya dimuat).
const showInternalEntity = computed(() => !externalActive.value || (Boolean(activeSchema.value) && !externalEntity.value));

// Field badan usaha di form sistem tujuan tidak dirender; nilainya (untuk
// visible_if) diturunkan dari jawaban pertanyaan badan usaha di atas.
const externalFormView = computed(() => {
    if (!activeSchema.value) {
        return null;
    }

    const option = externalEntity.value ? ticketForm.external_business_entity : '';

    return {
        ...activeSchema.value,
        fields: withTicketValues(activeSchema.value.fields, {
            option,
            texts: ticketEntityTexts(externalEntity.value, option, selectedBusinessEntityName.value),
        }),
    };
});

function externalEntityDefault(schema, current = '') {
    const entity = schema?.business_entity;
    if (!entity) {
        return '';
    }
    if (current !== '' && (entity.options ?? []).some((option) => String(option.value) === String(current))) {
        return current;
    }

    if (selectedBusinessEntityName.value) {
        const matched = matchBusinessEntityOption(entity.options ?? [], selectedBusinessEntityName.value);
        if (matched !== null) {
            return matched;
        }
    }

    return entity.default ?? '';
}

const attachmentTotalBytes = computed(() =>
    ticketForm.supporting_attachments.reduce((sum, file) => sum + (file.size ?? 0), 0),
);

const attachmentsOverLimit = computed(
    () =>
        ticketForm.supporting_attachments.length > maxFiles ||
        attachmentTotalBytes.value > maxBytes,
);

const attachmentSummary = computed(() => {
    const files = ticketForm.supporting_attachments;
    if (!files.length) {
        return 'Opsional · maks. 5 file, 10 MB';
    }

    const label = `${files.length} file · ${formatSize(attachmentTotalBytes.value)}`;

    return attachmentsOverLimit.value ? `${label} — melebihi batas` : label;
});

const ticketErrors = computed(() => Object.values(ticketForm.errors).filter(Boolean));
const identityErrors = computed(() => Object.values(identityForm.errors).filter(Boolean));
const changeErrors = computed(() => Object.values(changeForm.errors).filter(Boolean));
const wizardGeneralError = computed(
    () =>
        ticketForm.errors.external ||
        ticketForm.errors.answers ||
        ticketForm.errors.ticket ||
        ticketForm.errors.submission_token ||
        '',
);

const attachmentErrors = computed(() =>
    Object.entries(ticketForm.errors)
        .filter(([key]) => key === 'supporting_attachments' || key.startsWith('supporting_attachments.'))
        .map(([, message]) => message)
        .filter(Boolean),
);

watch(
    () => props.submissionToken,
    (token) => {
        ticketForm.submission_token = token;
    },
);

// Kembali (tombol Back) dari halaman lacak sistem tujuan: halaman dari riwayat
// browser membawa isian dan token kiriman lama yang sudah dipakai. Muat ulang
// dari server agar form bersih dengan token baru.
function reloadIfRestored(event) {
    if (event?.persisted) {
        window.location.reload();
    }
}
onMounted(() => {
    window.addEventListener('pageshow', reloadIfRestored);
    if (performance.getEntriesByType?.('navigation')?.[0]?.type === 'back_forward') {
        window.location.reload();
    }
});
onBeforeUnmount(() => window.removeEventListener('pageshow', reloadIfRestored));

// Pelapor berganti ("Ganti nomor"): form sistem tujuan dan isian otomatisnya
// milik pelapor lama, jadi pengisian dimulai lagi dari pilihan divisi.
// Kembali ke layar nomor lalu memasukkan nomor yang sama (mis. identitas
// perangkat kedaluwarsa) tidak menghapus isian.
let lastReporterPhone = props.reporter?.phone ?? '';
watch(
    () => props.reporter?.phone ?? '',
    (phone) => {
        if (!phone) {
            return;
        }
        const changed = lastReporterPhone !== '' && phone !== lastReporterPhone;
        lastReporterPhone = phone;
        if (!changed) {
            return;
        }
        // Form yang masih dimuat untuk pelapor lama diabaikan saat tiba.
        unitRequestId += 1;
        externalRequestId += 1;
        unitLoading.value = false;
        externalLoading.value = false;
        externalLoadError.value = '';
        ticketForm.unit_id = '';
        ticketForm.problem_category_id = '';
        ticketForm.answers = {};
        ticketForm.external_business_entity = '';
        ticketForm.business_entities_id = props.old.business_entities_id ?? '';
        ticketForm.clearErrors();
        unitWizard.value = null;
        externalSchema.value = null;
        externalSchemaCategoryId.value = '';
    },
);

watch(
    () => ticketForm.unit_id,
    (unitId, previous) => {
        const selected = (props.options.problem_categories ?? []).find(
            (category) => String(category.id) === String(ticketForm.problem_category_id),
        );
        if (selected && String(selected.unit_id) !== String(unitId)) {
            ticketForm.problem_category_id = '';
        }
        if (previous !== undefined || unitId) {
            loadUnitWizard();
        }
    },
    { immediate: true },
);

watch(
    () => ticketForm.problem_category_id,
    (categoryId, previous) => {
        // Kategori berganti = pengisian form baru: kunci idempotensi baru.
        ticketForm.idempotency_key = newIdempotencyKey();
        externalLoadError.value = '';
        if (wizardMode.value) {
            // Wizard: isian berkey sama dipertahankan saat kategori berganti
            // (kontrak §3.2); form lama tetap tampil sampai form baru dimuat.
            if (!categoryId) {
                externalSchema.value = null;
                externalSchemaCategoryId.value = '';
                ticketForm.answers = carryAnswers(unitWizard.value?.schema?.fields ?? [], ticketForm.answers);
            } else {
                loadExternalSchema({ carry: true });
            }

            return;
        }

        ticketForm.answers = {};
        // Pilihan badan usaha sistem tujuan mulai lagi dari pilihan awal form
        // baru (isian lama dipertahankan hanya saat halaman dimuat ulang).
        // Pilihan badan usaha helpdesk tidak disentuh.
        if (previous !== undefined) {
            ticketForm.external_business_entity = '';
        }
        externalSchema.value = null;
        externalSchemaCategoryId.value = '';
        if (isExternal.value) {
            loadExternalSchema();
        }
    },
    { immediate: true },
);

async function loadUnitWizard() {
    const unit = selectedUnit.value;
    const requestId = ++unitRequestId;
    unitWizard.value = null;
    if (!unit?.is_external || !props.urls.externalUnitForm) {
        unitLoading.value = false;

        return;
    }

    unitLoading.value = true;
    try {
        const response = await fetch(props.urls.externalUnitForm.replace('{unit}', encodeURIComponent(unit.id)), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const body = await response.json().catch(() => ({}));
        if (requestId !== unitRequestId) {
            return;
        }
        // Gagal dimuat: alur satu halaman (dropdown kategori) yang
        // menampilkan pesannya sendiri.
        if (!response.ok || !body?.data) {
            return;
        }

        unitWizard.value = { schema: body.data, categoryId: body.category_id ?? '' };
        if (hasSteps(body.data)) {
            const chosenId = String(ticketForm.problem_category_id || '');
            if (!chosenId || String(externalSchemaCategoryId.value) !== chosenId) {
                ticketForm.answers = carryAnswers(body.data.fields ?? [], ticketForm.answers);
            }
            ticketForm.external_business_entity = externalEntityDefault(
                activeSchema.value ?? body.data,
                ticketForm.external_business_entity,
            );
        }
    } catch {
        // Lihat di atas: tetap memakai alur satu halaman.
    } finally {
        if (requestId === unitRequestId) {
            unitLoading.value = false;
        }
    }
}

async function loadExternalSchema({ carry = false } = {}) {
    const category = selectedCategory.value;
    if (!category?.is_external || !props.urls.externalForm) {
        return;
    }

    const requestId = ++externalRequestId;
    externalLoading.value = true;
    externalLoadError.value = '';

    try {
        const response = await fetch(props.urls.externalForm.replace('{category}', encodeURIComponent(category.id)), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const body = await response.json().catch(() => ({}));
        if (requestId !== externalRequestId) {
            return;
        }
        if (!response.ok || !body?.data) {
            externalLoadError.value =
                response.status === 429
                    ? 'Terlalu banyak permintaan. Tunggu sebentar lalu coba lagi.'
                    : body?.message || 'Form sistem tujuan belum dapat dimuat.';
            return;
        }

        if (!unitWizard.value && hasSteps(body.data)) {
            unitWizard.value = { schema: body.data, categoryId: String(category.id) };
        }

        // Wizard: form kategori tanpa `steps` memakai langkah form pembuka.
        const schema = wizardMode.value ? asWizardSchema(body.data, unitWizard.value?.schema) : body.data;
        externalSchema.value = schema;
        externalSchemaCategoryId.value = String(category.id);
        ticketForm.answers = carry
            ? carryAnswers(schema.fields ?? [], ticketForm.answers)
            : initialValues(schema.fields ?? []);
        ticketForm.external_business_entity = externalEntityDefault(schema, ticketForm.external_business_entity);
    } catch {
        if (requestId === externalRequestId) {
            externalLoadError.value = 'Form sistem tujuan belum dapat dimuat. Periksa koneksi lalu coba lagi.';
        }
    } finally {
        if (requestId === externalRequestId) {
            externalLoading.value = false;
        }
    }
}

function formatSize(bytes) {
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

function onFilesSelected(event) {
    ticketForm.supporting_attachments = Array.from(event.target.files ?? []);
}

function pickFiles() {
    fileInput.value?.click();
}

function submitIdentity() {
    identityForm.post(props.urls.identify);
}

function entityErrors() {
    const entity = externalEntity.value;
    if (entity ? entity.required && !ticketForm.external_business_entity : !ticketForm.business_entities_id) {
        return { [entity ? 'external_business_entity' : 'business_entities_id']: 'Badan usaha wajib dipilih.' };
    }

    return {};
}

// Tombol Lanjut: error langkah yang dibuka diganti hasil validasinya; error
// server (422) langkah lain tetap sampai pelapor sampai di langkahnya.
function onWizardErrors(errors, context = null) {
    const next = context ? keepOtherStepErrors(ticketForm.errors, errors, context.steps, context.index) : errors;
    ticketForm.clearErrors();
    if (Object.keys(next).length) {
        ticketForm.setError(next);
    }
}

function selectWizardCategory(id) {
    ticketForm.clearErrors('problem_category_id');
    ticketForm.problem_category_id = id;
}

function submitWizardTicket() {
    const blocker = wizardSubmitBlocker({
        categoryId: ticketForm.problem_category_id,
        ready: wizardChosen.value && Boolean(externalFormView.value),
        loadError: externalLoadError.value,
    });
    if (blocker) {
        ticketForm.setError('problem_category_id', blocker);
        nextTick(() => wizard.value?.jumpToErrors(ticketForm.errors));
        return;
    }

    const view = externalFormView.value;
    const fields = view.fields ?? [];
    // Validasi semua langkah yang tampil; langkah pertama yang bermasalah dibuka.
    const errors = {
        ...validateAnswers(
            visibleSteps(view, ticketForm.answers).flatMap((step) => step.fields),
            ticketForm.answers,
            [levelScope(fields, ticketForm.answers)],
        ),
        ...entityErrors(),
    };
    ticketForm.clearErrors();
    if (Object.keys(errors).length) {
        ticketForm.setError(errors);
        nextTick(() => wizard.value?.jumpToErrors(errors));
        return;
    }

    const entity = externalEntity.value;
    ticketForm
        .transform((data) => ({
            submission_token: data.submission_token,
            website: data.website,
            ...(entity
                ? { external_business_entity: data.external_business_entity }
                : { business_entities_id: data.business_entities_id }),
            unit_id: data.unit_id,
            problem_category_id: data.problem_category_id,
            // Judul tidak ditanyakan: diturunkan server dari isian.
            idempotency_key: data.idempotency_key,
            answers: pruneAnswers(fields, data.answers),
        }))
        .post(props.urls.store, {
            forceFormData: true,
            preserveScroll: (page) => Object.keys(page.props.errors ?? {}).length > 0,
            onError: () => {
                notifySubmitError();
                // Error 422 dari server: buka langkah pertama yang memuat error,
                // atau scroll ke banner error umum bila tidak terikat ke field.
                nextTick(() => {
                    const stepIndex = wizard.value?.jumpToErrors(ticketForm.errors) ?? -1;
                    if (stepIndex < 0) {
                        document
                            .querySelector('[data-testid="external-wizard"] [role="alert"], .fi-callout')
                            ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                });
            },
            onSuccess: (page) => {
                if (page.props.flash?.ticket_success) {
                    dismissedSuccess.value = false;
                    notifyTicketSuccess(page.props.flash.ticket_success);
                    ticketForm.idempotency_key = newIdempotencyKey();
                    ticketForm.problem_category_id = '';
                    ticketForm.answers = {};
                    externalSchema.value = null;
                    externalSchemaCategoryId.value = '';
                    ticketForm.external_business_entity = externalEntityDefault(unitWizard.value?.schema);
                    wizard.value?.reset();
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            },
        });
}

function submitExternalTicket() {
    if (!externalSchema.value) {
        ticketForm.setError('external', externalLoadError.value || 'Form sistem tujuan belum dimuat.');
        return;
    }

    const fields = externalFormView.value?.fields ?? [];
    const errors = { ...validateAnswers(fields, ticketForm.answers), ...entityErrors() };
    const entity = externalEntity.value;
    if (!ticketForm.title?.trim()) {
        errors.title = 'Judul laporan wajib diisi.';
    }
    ticketForm.clearErrors();
    if (Object.keys(errors).length) {
        ticketForm.setError(errors);
        return;
    }

    // Kunci idempotensi yang sama dipakai ulang bila pengiriman gagal dan dicoba lagi.
    ticketForm
        .transform((data) => ({
            submission_token: data.submission_token,
            website: data.website,
            // Hanya satu pertanyaan badan usaha yang dikirim.
            ...(entity
                ? { external_business_entity: data.external_business_entity }
                : { business_entities_id: data.business_entities_id }),
            unit_id: data.unit_id,
            problem_category_id: data.problem_category_id,
            title: data.title,
            idempotency_key: data.idempotency_key,
            answers: pruneAnswers(fields, data.answers),
        }))
        .post(props.urls.store, {
            forceFormData: true,
            preserveScroll: (page) => Object.keys(page.props.errors ?? {}).length > 0,
            onError: () => {
                notifySubmitError();
            },
            onSuccess: (page) => {
                if (page.props.flash?.ticket_success) {
                    dismissedSuccess.value = false;
                    notifyTicketSuccess(page.props.flash.ticket_success);
                    ticketForm.idempotency_key = newIdempotencyKey();
                    ticketForm.title = '';
                    ticketForm.answers = initialValues(externalSchema.value?.fields ?? []);
                    ticketForm.external_business_entity = externalEntityDefault(externalSchema.value);
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            },
        });
}

function submitTicket() {
    if (wizardMode.value) {
        // Tombol Enter di dalam input wizard memicu langkah berikutnya / kirim.
        wizard.value?.next();
        return;
    }
    if (unitPending.value) {
        return;
    }
    if (isExternal.value) {
        submitExternalTicket();
        return;
    }

    // Alur non-eksternal: payload sama persis seperti sebelum ada integrasi.
    ticketForm.transform(({ idempotency_key, answers, external_business_entity, ...data }) => data);

    if (attachmentsOverLimit.value) {
        ticketForm.setError(
            'supporting_attachments',
            'Lampiran maksimal 5 file, total 10 MB.',
        );
        return;
    }

    ticketForm.post(props.urls.store, {
        forceFormData: true,
        onError: () => {
            notifySubmitError();
        },
        onSuccess: (page) => {
            if (page.props.flash?.ticket_success) {
                dismissedSuccess.value = false;
                notifyTicketSuccess(page.props.flash.ticket_success);
                ticketForm.title = '';
                ticketForm.description = '';
                ticketForm.supporting_attachments = [];
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        },
    });
}

function changeReporter() {
    changeForm.post(props.urls.changeReporter);
}
</script>

<template>
    <Head :title="reporter ? 'Kirim laporan' : 'Buat laporan'" />

    <SimpleLayout>
    <ToastRegion />
    <template #header>
        <a
            :href="urls.login"
            class="inline-flex h-8 items-center gap-1.5 rounded-lg bg-white px-2.5 text-sm font-medium text-gray-700 ring-1 ring-gray-950/10 transition duration-75 outline-hidden hover:bg-gray-50 hover:text-gray-950 focus-visible:ring-2 focus-visible:ring-primary-500 dark:bg-white/5 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-white/10 dark:hover:text-white"
            title="Masuk ke panel petugas helpdesk"
        >
            <Icon name="arrow-right-end-on-rectangle" class="size-4" />
            Masuk
        </a>
    </template>
    <div class="relative isolate flex min-h-dvh flex-col p-4 pt-16">
        <div
            class="flex flex-1 flex-col items-center"
            :class="reporter ? 'justify-start py-6 sm:py-10' : 'justify-center'"
        >
        <div
            class="relative w-full"
            :class="reporter ? 'max-w-3xl space-y-6' : 'max-w-sm space-y-10'"
        >
            <Brand :src="brandUrl" :alt="appName" />

            <template v-if="reporter">
                <div
                    v-if="visibleTicketSuccess || (!flash.ticket_success && flash.status) || ticketErrors.length || changeErrors.length"
                    class="space-y-4"
                >
                    <TicketSuccessCard
                        v-if="visibleTicketSuccess"
                        :ticket="visibleTicketSuccess"
                        @dismiss="dismissedSuccess = true"
                    />
                    <Callout v-else-if="!flash.ticket_success && flash.status" color="info" :heading="flash.status" />
                    <Callout
                        v-if="ticketErrors.length || changeErrors.length"
                        color="danger"
                        heading="Ada bagian yang perlu diperiksa."
                    >
                        <ul class="list-disc space-y-1 ps-4 text-sm">
                            <li v-for="error in [...changeErrors, ...ticketErrors]" :key="error">{{ error }}</li>
                        </ul>
                    </Callout>
                </div>

                <div class="flex items-center justify-between gap-3 rounded-xl bg-gray-50 px-3 py-2.5 ring-1 ring-gray-200 dark:bg-gray-950 dark:ring-white/10">
                    <div class="min-w-0">
                        <div class="text-xs text-gray-500 dark:text-gray-400">Nomor WhatsApp pelapor</div>
                        <div class="truncate font-medium text-gray-950 dark:text-white">{{ reporter.phone }}</div>
                    </div>
                    <form @submit.prevent="changeReporter">
                        <TextLink tag="button" type="submit" :disabled="changeForm.processing">
                            Ganti nomor
                        </TextLink>
                    </form>
                </div>

                <form class="space-y-6" novalidate @submit.prevent="submitTicket">
                    <input type="hidden" name="submission_token" :value="ticketForm.submission_token" />
                    <div class="fi-sr-only" aria-hidden="true">
                        <label for="website">Website</label>
                        <input id="website" v-model="ticketForm.website" type="text" tabindex="-1" autocomplete="off" />
                    </div>

                    <Section heading="Kirim ke" icon="paper-airplane">
                        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                            <Field
                                id="unit_id"
                                label="Unit / Divisi Tujuan"
                                required
                                :class="{ 'sm:col-span-2': wizardMode || unitPending }"
                                :error="ticketForm.errors.unit_id"
                                :hint="wizardMode ? `Pengajuan ${selectedUnit?.name ?? ''} diisi lewat langkah di bawah.` : 'Divisi yang akan menangani tiket ini.'"
                            >
                                <SelectInput
                                    id="unit_id"
                                    v-model="ticketForm.unit_id"
                                    required
                                    placeholder="Pilih divisi"
                                    :options="options.units"
                                    :invalid="Boolean(ticketForm.errors.unit_id)"
                                />
                            </Field>

                            <p
                                v-if="unitPending"
                                class="flex items-center gap-2 self-end text-sm text-gray-500 dark:text-gray-400"
                                role="status"
                            >
                                Memuat form divisi…
                            </p>

                            <Field
                                v-else-if="!wizardMode"
                                id="problem_category_id"
                                label="Kategori Tiket"
                                required
                                :error="ticketForm.errors.problem_category_id"
                                hint="Jenis tiket yang akan ditangani."
                            >
                                <SelectInput
                                    id="problem_category_id"
                                    v-model="ticketForm.problem_category_id"
                                    required
                                    :disabled="!ticketForm.unit_id"
                                    :placeholder="ticketForm.unit_id ? 'Pilih kategori' : 'Pilih divisi terlebih dahulu'"
                                    :options="categoriesForUnit"
                                    :invalid="Boolean(ticketForm.errors.problem_category_id)"
                                />
                            </Field>

                            <Field
                                v-if="!wizardMode && !unitPending && showInternalEntity"
                                id="business_entities_id"
                                label="Badan Usaha yang Dilaporkan"
                                required
                                :error="ticketForm.errors.business_entities_id"
                                hint="Badan usaha tempat kendala terjadi. Ganti bila bukan badan usaha kontrak Anda."
                            >
                                <SelectInput
                                    id="business_entities_id"
                                    v-model="ticketForm.business_entities_id"
                                    required
                                    placeholder="Pilih badan usaha"
                                    :options="options.business_entities"
                                    :invalid="Boolean(ticketForm.errors.business_entities_id)"
                                />
                            </Field>

                            <Field
                                v-else-if="!wizardMode && !unitPending && externalEntity"
                                id="external_business_entity"
                                label="Badan Usaha yang Dilaporkan"
                                :required="externalEntity.required"
                                :error="ticketForm.errors.external_business_entity"
                                hint="Badan usaha tempat kendala terjadi. Ganti bila bukan badan usaha kontrak Anda."
                            >
                                <SelectInput
                                    id="external_business_entity"
                                    v-model="ticketForm.external_business_entity"
                                    :required="externalEntity.required"
                                    placeholder="Pilih badan usaha"
                                    :options="externalEntityOptions"
                                    :invalid="Boolean(ticketForm.errors.external_business_entity)"
                                />
                            </Field>

                            <Field
                                v-if="!externalActive && !unitPending"
                                id="priority_id"
                                label="Prioritas"
                                required
                                :error="ticketForm.errors.priority_id"
                                hint="Tingkat urgensi tiket ini."
                            >
                                <SelectInput
                                    id="priority_id"
                                    v-model="ticketForm.priority_id"
                                    required
                                    placeholder="Pilih prioritas"
                                    :options="options.priorities"
                                    :invalid="Boolean(ticketForm.errors.priority_id)"
                                />
                            </Field>
                        </div>
                    </Section>

                    <!-- Unit berintegrasi dengan form wizard (kontrak §3.2 "Langkah"):
                         kategori, badan usaha, dan isian mengikuti form asli sistem tujuan. -->
                    <ExternalWizard
                        v-if="wizardMode"
                        ref="wizard"
                        :schema="externalFormView"
                        :categories="wizardCategories"
                        :category-id="ticketForm.problem_category_id"
                        :chosen="wizardShowsCategory"
                        :unit-name="selectedUnit?.name ?? ''"
                        :values="ticketForm.answers"
                        :errors="ticketForm.errors"
                        :general-error="wizardGeneralError"
                        :loading="externalLoading"
                        :load-error="externalLoadError"
                        :processing="ticketForm.processing"
                        :validate-entity="entityErrors"
                        @select-category="selectWizardCategory"
                        @errors="onWizardErrors"
                        @retry="loadExternalSchema({ carry: true })"
                        @submit="submitWizardTicket"
                    >
                        <template #entity>
                            <!-- Mengikuti label form sistem tujuan, di posisi aslinya. -->
                            <Field
                                v-if="externalEntity"
                                id="external_business_entity"
                                :label="externalEntity.label || 'Badan usaha'"
                                :required="externalEntity.required"
                                :error="ticketForm.errors.external_business_entity"
                            >
                                <SelectInput
                                    id="external_business_entity"
                                    v-model="ticketForm.external_business_entity"
                                    :required="externalEntity.required"
                                    placeholder="Pilih badan usaha"
                                    :options="externalEntityOptions"
                                    :invalid="Boolean(ticketForm.errors.external_business_entity)"
                                />
                            </Field>
                            <Field
                                v-else
                                id="business_entities_id"
                                label="Badan Usaha yang Dilaporkan"
                                required
                                :error="ticketForm.errors.business_entities_id"
                                hint="Badan usaha tempat kendala terjadi. Ganti bila bukan badan usaha kontrak Anda."
                            >
                                <SelectInput
                                    id="business_entities_id"
                                    v-model="ticketForm.business_entities_id"
                                    required
                                    placeholder="Pilih badan usaha"
                                    :options="options.business_entities"
                                    :invalid="Boolean(ticketForm.errors.business_entities_id)"
                                />
                            </Field>
                        </template>
                    </ExternalWizard>

                    <Section v-if="!wizardMode && !unitPending" heading="Kendala" icon="chat-bubble-left-right">
                        <div class="space-y-6">
                            <Field id="title" label="Judul" required :error="ticketForm.errors.title">
                                <Input
                                    id="title"
                                    v-model="ticketForm.title"
                                    type="text"
                                    maxlength="255"
                                    required
                                    autocomplete="off"
                                    placeholder="Printer kasir tidak bisa mencetak"
                                    :invalid="Boolean(ticketForm.errors.title)"
                                />
                            </Field>

                            <Field
                                v-if="!isExternal"
                                id="description"
                                label="Uraian"
                                required
                                :error="ticketForm.errors.description"
                            >
                                <TextareaInput
                                    id="description"
                                    v-model="ticketForm.description"
                                    rows="6"
                                    maxlength="60000"
                                    required
                                    placeholder="Apa yang terjadi, sejak kapan, dampaknya, dan yang sudah dicoba."
                                    :invalid="Boolean(ticketForm.errors.description)"
                                />
                            </Field>
                        </div>
                    </Section>

                    <template v-if="wizardMode || unitPending" />

                    <Section
                        v-else-if="isExternal"
                        :heading="externalSchema?.title || selectedCategory?.name || 'Detail permintaan'"
                        icon="paper-clip"
                    >
                        <ExternalForm
                            :schema="externalFormView"
                            :values="ticketForm.answers"
                            :errors="ticketForm.errors"
                            :loading="externalLoading"
                            :load-error="externalLoadError"
                            @retry="loadExternalSchema"
                        />
                    </Section>

                    <Section v-else heading="Lampiran" icon="paper-clip">
                        <label class="fi-sr-only" for="supporting_attachments">Lampiran</label>
                        <input
                            id="supporting_attachments"
                            ref="fileInput"
                            class="fi-sr-only"
                            type="file"
                            multiple
                            accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.jpg,.jpeg,.png"
                            @change="onFilesSelected"
                        />
                        <div class="flex flex-wrap items-center gap-3">
                            <Button color="gray" outlined @click="pickFiles">Pilih file</Button>
                            <span
                                class="text-sm text-gray-500 dark:text-gray-400"
                                :class="{ 'text-danger-600 dark:text-danger-400': attachmentsOverLimit }"
                            >
                                {{ attachmentSummary }}
                            </span>
                        </div>
                        <ul
                            v-if="ticketForm.supporting_attachments.length"
                            class="mt-2 divide-y divide-gray-200 text-sm text-gray-600 dark:divide-white/10 dark:text-gray-300"
                        >
                            <li
                                v-for="file in ticketForm.supporting_attachments"
                                :key="file.name + file.size"
                                class="flex justify-between gap-3 py-1.5"
                                :class="{ 'text-danger-600': attachmentsOverLimit }"
                            >
                                <span class="truncate">{{ file.name }}</span>
                                <span>{{ formatSize(file.size) }}</span>
                            </li>
                        </ul>
                        <p
                            v-for="error in attachmentErrors"
                            :key="error"
                            class="fi-fo-field-wrp-error-message"
                            data-validation-error
                        >
                            {{ error }}
                        </p>
                    </Section>

                    <!-- Formulirnya panjang, jadi tombol kirim menempel di bawah layar agar selalu terjangkau. -->
                    <div
                        v-if="!wizardMode && !unitPending"
                        class="sticky bottom-0 z-10 -mx-4 bg-linear-to-t from-gray-50 from-70% to-gray-50/0 px-4 pt-6 pb-[max(1rem,env(safe-area-inset-bottom))] dark:from-gray-950 dark:to-gray-950/0"
                    >
                        <Button type="submit" block :processing="ticketForm.processing">
                            {{ ticketForm.processing ? 'Mengirim…' : 'Kirim laporan' }}
                        </Button>
                    </div>
                </form>
            </template>

            <Card v-else>
                <header class="flex flex-col items-center justify-center py-3">
                    <div class="flex items-center justify-center rounded-lg bg-white p-2 shadow ring-1 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700/80">
                        <span class="fi-icon">
                            <Icon name="device-phone-mobile" />
                        </span>
                    </div>
                    <h1 class="mt-4 font-heading text-lg font-medium text-gray-950 dark:text-white">
                        Buat laporan
                    </h1>
                </header>

                <div class="mt-8 space-y-6">
                    <TicketSuccessCard
                        v-if="visibleTicketSuccess"
                        :ticket="visibleTicketSuccess"
                        compact
                        @dismiss="dismissedSuccess = true"
                    />
                    <Callout v-else-if="!flash.ticket_success && flash.status" color="info" :heading="flash.status" />
                    <Callout
                        v-if="identityErrors.length"
                        color="danger"
                        heading="Ada bagian yang perlu diperiksa."
                    >
                        <ul class="list-disc space-y-1 ps-4 text-sm">
                            <li v-for="error in identityErrors" :key="error">{{ error }}</li>
                        </ul>
                    </Callout>

                    <form class="space-y-6" @submit.prevent="submitIdentity">
                        <div class="fi-sr-only" aria-hidden="true">
                            <label for="identity_website">Website</label>
                            <input
                                id="identity_website"
                                v-model="identityForm.website"
                                type="text"
                                tabindex="-1"
                                autocomplete="off"
                            />
                        </div>

                        <Field
                            id="phone"
                            label="Nomor WhatsApp"
                            required
                            :error="identityForm.errors.phone"
                            hint="Gunakan format 08… atau 62…"
                        >
                            <Input
                                id="phone"
                                v-model="identityForm.phone"
                                type="tel"
                                inputmode="tel"
                                maxlength="30"
                                required
                                autocomplete="tel"
                                autofocus
                                placeholder="0812 3456 7890"
                                :invalid="Boolean(identityForm.errors.phone)"
                            />
                        </Field>

                        <Button type="submit" block :processing="identityForm.processing">
                            {{ identityForm.processing ? 'Memeriksa…' : 'Lanjut isi laporan' }}
                        </Button>
                    </form>
                </div>
            </Card>
        </div>
        </div>
    </div>
    </SimpleLayout>
</template>

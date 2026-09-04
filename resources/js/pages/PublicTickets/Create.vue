<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import Brand from '../../components/Brand.vue';
import Button from '../../components/Button.vue';
import Callout from '../../components/Callout.vue';
import Card from '../../components/Card.vue';
import Field from '../../components/Field.vue';
import Icon from '../../components/Icon.vue';
import Input from '../../components/Input.vue';
import Section from '../../components/Section.vue';
import SelectInput from '../../components/SelectInput.vue';
import SimpleLayout from '../../components/SimpleLayout.vue';
import TextareaInput from '../../components/TextareaInput.vue';
import TextLink from '../../components/TextLink.vue';

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
const flash = computed(() => page.props.flash ?? {});
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
    unit_id: props.old.unit_id ?? '',
    problem_category_id: props.old.problem_category_id ?? '',
    priority_id: props.old.priority_id ?? props.defaultPriorityId ?? '',
    title: props.old.title ?? '',
    description: props.old.description ?? '',
    supporting_attachments: [],
});

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

watch(
    () => ticketForm.unit_id,
    (unitId) => {
        const selected = (props.options.problem_categories ?? []).find(
            (category) => String(category.id) === String(ticketForm.problem_category_id),
        );
        if (selected && String(selected.unit_id) !== String(unitId)) {
            ticketForm.problem_category_id = '';
        }
    },
);

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

function submitTicket() {
    if (attachmentsOverLimit.value) {
        ticketForm.setError(
            'supporting_attachments',
            'Lampiran maksimal 5 file, total 10 MB.',
        );
        return;
    }

    ticketForm.post(props.urls.store, { forceFormData: true });
}

function changeReporter() {
    changeForm.post(props.urls.changeReporter);
}
</script>

<template>
    <Head :title="reporter ? 'Kirim laporan' : 'Buat laporan'" />

    <SimpleLayout>
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
                    v-if="flash.ticket_success || flash.status || ticketErrors.length || changeErrors.length"
                    class="space-y-4"
                >
                    <Callout
                        v-if="flash.ticket_success"
                        color="success"
                        :heading="`Laporan ${flash.ticket_success.number} berhasil dibuat`"
                        :description="`${flash.ticket_success.title} sudah diteruskan ke unit ${flash.ticket_success.unit || 'tujuan'}.`"
                    />
                    <Callout v-else-if="flash.status" color="info" :heading="flash.status" />
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

                <form class="space-y-6" @submit.prevent="submitTicket">
                    <input type="hidden" name="submission_token" :value="ticketForm.submission_token" />
                    <div class="fi-sr-only" aria-hidden="true">
                        <label for="website">Website</label>
                        <input id="website" v-model="ticketForm.website" type="text" tabindex="-1" autocomplete="off" />
                    </div>

                    <Section heading="Kirim ke" icon="paper-airplane">
                        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                            <Field
                                id="business_entities_id"
                                label="Badan Usaha"
                                required
                                :error="ticketForm.errors.business_entities_id"
                                hint="Badan usaha yang akan menangani tiket ini."
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
                                id="unit_id"
                                label="Unit / Divisi Tujuan"
                                required
                                :error="ticketForm.errors.unit_id"
                                hint="Divisi yang akan menangani tiket ini."
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

                            <Field
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

                    <Section heading="Kendala" icon="chat-bubble-left-right">
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

                            <Field id="description" label="Uraian" required :error="ticketForm.errors.description">
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

                    <Section heading="Lampiran" icon="paper-clip">
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

                    <Button type="submit" block :processing="ticketForm.processing">
                        {{ ticketForm.processing ? 'Mengirim…' : 'Kirim laporan' }}
                    </Button>
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
                    <Callout
                        v-if="flash.ticket_success"
                        color="success"
                        :heading="`Laporan ${flash.ticket_success.number} berhasil dibuat`"
                        :description="`${flash.ticket_success.title} sudah diteruskan ke unit ${flash.ticket_success.unit || 'tujuan'}.`"
                    />
                    <Callout v-else-if="flash.status" color="info" :heading="flash.status" />
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
        <div class="flex justify-center py-4">
            <TextLink :href="urls.login" color="gray">Masuk/Login</TextLink>
        </div>
    </div>
    </SimpleLayout>
</template>

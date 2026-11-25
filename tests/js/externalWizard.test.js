// Uji logika wizard form eksternal (kontrak §3.2 "Langkah"): `npm run test:js`.
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { initialValues, withTicketValues } from '../../resources/js/support/externalForm.js';
import {
    asWizardSchema,
    carryAnswers,
    categoryReady,
    entityHost,
    keepOtherStepErrors,
    pickerErrors,
    showCategorySteps,
    submitVisible,
    wizardSubmitBlocker,
    firstErrorStep,
    stepIndexForError,
    stepSections,
    validateStep,
    visibleSteps,
    wizardSteps,
} from '../../resources/js/support/externalWizard.js';

const steps = [
    { key: 'pemohon', title: 'Data pemohon', category_picker: false, sections: [
        { key: 'identitas', title: 'Identitas', help: 'Isi sesuai data karyawan.' },
        { key: 'kontak', title: 'Kontak' },
    ] },
    { key: 'dokumen', title: 'Dokumen', category_picker: true, sections: [{ key: 'detail', title: 'Detail dokumen' }] },
    { key: 'observasi', title: 'Jadwal observasi', category_picker: false, sections: [{ key: 'jadwal', title: 'Jadwal observasi' }] },
];

// Bentuk dari server (withPrefill, pelapor dikenal dari data karyawan).
function schema(key = 'IK') {
    const fields = [
        { key: 'nama', type: 'text', label: 'Nama lengkap', required: true, auto: true, step: 'pemohon', section: 'identitas' },
        { key: 'jabatan', type: 'select', label: 'Jabatan', required: true, auto: true, options: [], step: 'pemohon', section: 'identitas' },
        {
            key: 'badan_usaha', type: 'select', label: 'Badan usaha', required: true, ticket: true, business_entity: true,
            options: [{ value: 'CS', label: 'Complete Selular' }], step: 'pemohon', section: 'identitas',
        },
        { key: 'atasan', type: 'text', label: 'Atasan langsung', required: false, step: 'pemohon', section: 'identitas' },
        { key: 'email', type: 'email', label: 'Email perusahaan', required: true, auto: true, step: 'pemohon', section: 'kontak' },
        {
            key: 'proses', type: 'repeater', label: 'Dokumen', required: true, min_items: 1, step: 'dokumen', section: 'detail',
            fields: key === 'JD'
                ? [
                    { key: 'judul', type: 'text', label: 'Judul dokumen', required: true },
                    { key: 'template', type: 'file', label: 'Template terisi', required: true },
                ]
                : [
                    { key: 'judul', type: 'text', label: 'Judul dokumen', required: true },
                    { key: 'tujuan', type: 'textarea', label: 'Uraian', required: true, rules: { min: 5 } },
                ],
        },
    ];
    if (key === 'IK') {
        fields.push(
            { key: 'obs_info', type: 'info', label: 'Jadwal observasi', step: 'observasi', section: 'jadwal' },
            { key: 'obs_tanggal_1', type: 'date', label: 'Tanggal observasi', required: true, step: 'observasi', section: 'jadwal' },
        );
    } else {
        // Hanya info: tidak dihitung sebagai isi langkah.
        fields.push({ key: 'obs_info', type: 'info', label: 'Jadwal observasi', step: 'observasi', section: 'jadwal' });
    }

    return { key, steps, fields: withTicketValues(fields, { option: 'CS' }), business_entity: { key: 'badan_usaha' } };
}

test('field dikelompokkan per seksi; field tanpa seksi masuk seksi tanpa judul di akhir', () => {
    const fields = [
        { key: 'a', type: 'text', step: 'pemohon', section: 'kontak' },
        { key: 'b', type: 'text', step: 'pemohon' },
        { key: 'c', type: 'text', step: 'pemohon', section: 'identitas' },
        { key: 'd', type: 'text', step: 'dokumen' },
    ];

    assert.deepEqual(
        stepSections(steps[0], fields).map((section) => [section.key, section.fields.map((field) => field.key)]),
        [['identitas', ['c']], ['kontak', ['a']], ['_', ['b']]],
    );
});

test('pertanyaan badan usaha helpdesk dirender di seksi field badan usaha', () => {
    assert.deepEqual(entityHost(schema()), ['pemohon', 'identitas']);
    // Tanpa field badan usaha: seksi pertama langkah pertama.
    const plain = { steps, fields: schema().fields.filter((field) => !field.ticket) };
    assert.deepEqual(entityHost(plain), ['pemohon', 'identitas']);
    assert.equal(entityHost({ steps: [], fields: [] }), null);
});

test('langkah observasi dilewati untuk kategori tanpa observasi; langkah sesudah kartu menunggu kategori', () => {
    const ik = schema('IK');
    const values = initialValues(ik.fields);

    assert.deepEqual(visibleSteps(ik, values, { chosen: false }).map((step) => step.key), ['pemohon', 'dokumen']);
    // Sebelum kategori dipilih, seksi langkah kartu kategori belum tampil.
    assert.equal(wizardSteps(ik, values, { chosen: false })[1].sections[0].visible, false);
    assert.deepEqual(visibleSteps(ik, values).map((step) => step.key), ['pemohon', 'dokumen', 'observasi']);

    const sop = schema('SOP');
    assert.deepEqual(visibleSteps(sop, initialValues(sop.fields)).map((step) => step.key), ['pemohon', 'dokumen']);
});

test('langkah yang hanya berisi field otomatis dilewati dan labelnya dibawa ke langkah host', () => {
    const only = {
        steps: [
            { key: 'pemohon', title: 'Data pemohon', sections: [{ key: 'identitas', title: 'Identitas' }] },
            { key: 'kontak', title: 'Kontak', sections: [{ key: 'kontak', title: 'Kontak' }] },
        ],
        fields: [
            { key: 'badan_usaha', type: 'select', label: 'Badan usaha', ticket: true, business_entity: true, step: 'pemohon', section: 'identitas' },
            { key: 'atasan', type: 'text', label: 'Atasan', step: 'pemohon', section: 'identitas' },
            { key: 'email', type: 'email', label: 'Email', auto: true, step: 'kontak', section: 'kontak' },
            { key: 'catatan', type: 'text', label: 'Catatan', visible_if: { field: 'atasan', equals: 'x' }, step: 'kontak', section: 'kontak' },
        ],
    };

    const stepsWithoutCatatan = visibleSteps(only, {});
    assert.deepEqual(stepsWithoutCatatan.map((step) => step.key), ['pemohon']);
    assert.deepEqual(stepsWithoutCatatan[0].skippedAutoFields.map((field) => field.key), ['email']);

    const stepsWithCatatan = visibleSteps(only, { atasan: 'x' });
    assert.deepEqual(stepsWithCatatan.map((step) => step.key), ['pemohon', 'kontak']);
    assert.equal(stepsWithCatatan[1].sections[0].visible, true);
});

test('urutan dokumen dulu tetap menampilkan langkah pemohon pemilik badan usaha sebelum kategori dipilih', () => {
    const docFirst = {
        ...schema('IK'),
        steps: [steps[1], steps[0], steps[2]],
    };
    const list = visibleSteps(docFirst, initialValues(docFirst.fields), { chosen: false });
    assert.deepEqual(list.map((step) => step.key), ['dokumen', 'pemohon']);
});

test('validasi per langkah hanya memeriksa field langkah itu', () => {
    const ik = schema('IK');
    const values = { atasan: '', proses: [{ judul: '', tujuan: '' }], obs_tanggal_1: '' };
    const [pemohon, dokumen, observasi] = visibleSteps(ik, values);

    // Field otomatis dan badan usaha tidak divalidasi di browser.
    assert.deepEqual(validateStep(pemohon, ik.fields, values), {});
    assert.deepEqual(validateStep(dokumen, ik.fields, values), {
        'answers.proses.0.judul': 'Judul dokumen wajib diisi.',
        'answers.proses.0.tujuan': 'Uraian wajib diisi.',
    });
    assert.deepEqual(validateStep(observasi, ik.fields, values), {
        'answers.obs_tanggal_1': 'Tanggal observasi wajib diisi.',
    });

    // Sebelum kategori dipilih, isian langkah kartu kategori belum diperiksa.
    const [, picker] = visibleSteps(ik, values, { chosen: false });
    assert.deepEqual(validateStep(picker, ik.fields, values), {});
});

test('visible_if lintas langkah dievaluasi terhadap semua field level atas', () => {
    const form = {
        steps: [
            { key: 'a', title: 'A', sections: [] },
            { key: 'b', title: 'B', sections: [] },
        ],
        fields: [
            { key: 'jenis', type: 'radio', label: 'Jenis', required: true, options: [{ value: 'revisi', label: 'Revisi' }], step: 'a' },
            { key: 'nomor_lama', type: 'text', label: 'Nomor lama', required: true, visible_if: { field: 'jenis', equals: 'revisi' }, step: 'b' },
        ],
    };

    // Langkah B tidak punya field tampil selama jenis ≠ revisi: dilewati.
    assert.deepEqual(visibleSteps(form, { jenis: '' }).map((step) => step.key), ['a']);
    const [, b] = visibleSteps(form, { jenis: 'revisi', nomor_lama: '' });
    assert.deepEqual(validateStep(b, form.fields, { jenis: 'revisi', nomor_lama: '' }), {
        'answers.nomor_lama': 'Nomor lama wajib diisi.',
    });
});

test('error server membuka langkah pertama yang memuat error', () => {
    const ik = schema('IK');
    const list = visibleSteps(ik, initialValues(ik.fields));

    assert.equal(stepIndexForError(list, 'answers.proses.0.judul'), 1);
    assert.equal(stepIndexForError(list, 'answers.obs_tanggal_1'), 2);
    assert.equal(stepIndexForError(list, 'external_business_entity'), 0);
    assert.equal(stepIndexForError(list, 'business_entities_id'), 0);
    assert.equal(stepIndexForError(list, 'problem_category_id'), 1);
    assert.equal(stepIndexForError(list, 'external'), -1);
    assert.equal(firstErrorStep(list, { 'answers.obs_tanggal_1': 'x', 'answers.proses.0.judul': 'y' }), 1);
    assert.equal(firstErrorStep(list, { 'answers.badan_usaha': '', external: 'Sistem tujuan tidak tersedia.' }), -1);
    assert.equal(firstErrorStep(list, { external_business_entity: 'Badan usaha wajib dipilih.', 'answers.obs_tanggal_1': 'x' }), 0);
});

test('isian berkey sama dipertahankan saat kategori berganti', () => {
    const file = { name: 'alur.pdf', size: 10 };
    const previous = {
        atasan: 'Budi',
        proses: [{ judul: 'Terima barang', tujuan: 'Standarisasi alur' }, { judul: 'Simpan barang', tujuan: '' }],
        obs_tanggal_1: '2026-12-01',
        nama: 'tidak pernah ada di browser',
    };

    const jd = carryAnswers(schema('JD').fields, previous);
    assert.equal(jd.atasan, 'Budi');
    assert.deepEqual(jd.proses, [
        { judul: 'Terima barang', template: null },
        { judul: 'Simpan barang', template: null },
    ]);
    // Field yang tidak ada di form baru dan field otomatis tidak dibawa.
    assert.equal('obs_tanggal_1' in jd, false);
    assert.equal('nama' in jd, false);

    // Kembali ke IK: file dan isian lain yang masih ada dipertahankan.
    const ik = carryAnswers(schema('IK').fields, { ...jd, proses: [{ judul: 'Terima barang', template: file }] });
    assert.deepEqual(ik.proses, [{ judul: 'Terima barang', tujuan: '' }]);
    assert.equal(ik.obs_tanggal_1, '');

    // Repeater kosong memakai baris awal form baru.
    assert.deepEqual(carryAnswers(schema('IK').fields, { proses: [] }).proses, [{ judul: '', tujuan: '' }]);
    const withFile = carryAnswers(
        [{ key: 'lampiran', type: 'files', label: 'Lampiran' }, { key: 'dok', type: 'file', label: 'Dok' }],
        { lampiran: [file, null], dok: file },
    );
    assert.deepEqual(withFile, { lampiran: [file], dok: file });
});

test('Lanjut hanya mengganti error langkah yang sedang dibuka; error server langkah lain tetap', () => {
    const ik = schema('IK');
    const list = visibleSteps(ik, initialValues(ik.fields));
    const server = {
        'answers.atasan': 'Atasan wajib diisi.',
        'answers.proses.0.template': 'Template tidak sesuai.',
        'answers.obs_tanggal_1': 'Tanggal sudah lewat.',
        external: 'Isian ditolak sistem tujuan.',
    };

    // Pelapor memperbaiki Atasan di langkah 1 lalu menekan Lanjut (tanpa error klien).
    assert.deepEqual(keepOtherStepErrors(server, {}, list, 0), {
        'answers.proses.0.template': 'Template tidak sesuai.',
        'answers.obs_tanggal_1': 'Tanggal sudah lewat.',
        external: 'Isian ditolak sistem tujuan.',
    });
    // Error klien langkah ini menggantikan error lama langkah ini saja.
    assert.deepEqual(keepOtherStepErrors(server, { 'answers.proses.0.judul': 'Judul wajib diisi.' }, list, 1), {
        'answers.atasan': 'Atasan wajib diisi.',
        'answers.obs_tanggal_1': 'Tanggal sudah lewat.',
        external: 'Isian ditolak sistem tujuan.',
        'answers.proses.0.judul': 'Judul wajib diisi.',
    });
    // Error kategori dan badan usaha milik langkah kartu / langkah seksi identitas.
    assert.deepEqual(keepOtherStepErrors({ problem_category_id: 'x', external_business_entity: 'y' }, {}, list, 1), {
        external_business_entity: 'y',
    });
    assert.deepEqual(keepOtherStepErrors({ problem_category_id: 'x', external_business_entity: 'y' }, {}, list, 0), {
        problem_category_id: 'x',
    });
});

test('form kategori lama tidak tampil selama kategori baru dimuat atau gagal dimuat', () => {
    // IK dimuat, lalu SOP dipilih.
    const loadingSop = { categoryId: '2', schemaCategoryId: '1', schema: schema('IK'), loading: true };
    assert.equal(categoryReady(loadingSop), false);
    assert.equal(showCategorySteps(loadingSop), false);

    // SOP gagal dimuat: langkah dan seksi IK tidak lagi tampil.
    const failed = { ...loadingSop, loading: false };
    assert.equal(showCategorySteps(failed), false);
    const steps = visibleSteps(schema('IK'), initialValues(schema('IK').fields), { chosen: showCategorySteps(failed) });
    assert.deepEqual(steps.map((step) => step.key), ['pemohon', 'dokumen']);
    assert.equal(steps[1].sections.some((section) => section.visible), false);

    // Form yang dimuat untuk kategori terpilih.
    const ready = { categoryId: '2', schemaCategoryId: '2', schema: schema('SOP'), loading: false };
    assert.equal(categoryReady(ready), true);
    assert.equal(showCategorySteps(ready), true);
    assert.equal(categoryReady({ ...ready, schema: null }), false);
});

test('langkah kartu kategori: tidak bisa lanjut/kirim selama form kategori belum dimuat', () => {
    const ik = schema('IK');
    const list = visibleSteps(ik, initialValues(ik.fields), { chosen: false });
    const picker = list[1];

    assert.deepEqual(pickerErrors(picker, { categoryId: '', chosen: false }), {
        problem_category_id: 'Pilih kategori terlebih dahulu.',
    });
    assert.deepEqual(pickerErrors(picker, { categoryId: '2', chosen: false }), {
        problem_category_id: 'Form kategori ini belum dapat dimuat. Coba lagi atau pilih kategori lain.',
    });
    assert.deepEqual(pickerErrors(picker, { categoryId: '2', chosen: true }), {});
    assert.deepEqual(pickerErrors(list[0], { categoryId: '', chosen: false }), {});

    // Tombol "Kirim pengajuan" di langkah kartu hanya bila form kategori sudah dimuat.
    assert.equal(submitVisible(list, 1, { categoryId: '2', chosen: false }), false);
    assert.equal(submitVisible(list, 1, { categoryId: '', chosen: false }), false);
    const sop = visibleSteps(schema('SOP'), initialValues(schema('SOP').fields), { chosen: true });
    assert.equal(submitVisible(sop, 1, { categoryId: '2', chosen: true }), true);
    assert.equal(submitVisible(sop, 0, { categoryId: '2', chosen: true }), false);
});

test('pesan kirim: kategori sudah dipilih tetapi formnya gagal dimuat', () => {
    assert.equal(wizardSubmitBlocker({ categoryId: '', ready: false, loadError: '' }), 'Pilih kategori terlebih dahulu.');
    assert.equal(
        wizardSubmitBlocker({ categoryId: '2', ready: false, loadError: 'Sistem tujuan sedang tidak tersedia.' }),
        'Sistem tujuan sedang tidak tersedia.',
    );
    assert.equal(
        wizardSubmitBlocker({ categoryId: '2', ready: false, loadError: '' }),
        'Form kategori ini belum dimuat. Tunggu sebentar lalu coba lagi.',
    );
    assert.equal(wizardSubmitBlocker({ categoryId: '2', ready: true, loadError: '' }), '');
});

test('form kategori tanpa steps di unit wizard memakai langkah form pembuka', () => {
    const opening = schema('IK');
    const single = {
        key: 'SOP',
        steps: [],
        fields: [
            { key: 'nama', type: 'text', label: 'Nama lengkap', required: true, auto: true },
            { key: 'atasan', type: 'text', label: 'Atasan langsung', required: false },
            { key: 'perihal', type: 'text', label: 'Perihal', required: true },
        ],
    };

    const wizard = asWizardSchema(single, opening);
    assert.equal(wizard.steps, opening.steps);
    // Field yang ada di form pembuka tetap di langkah/seksinya; lainnya di langkah kartu kategori.
    assert.deepEqual(wizard.fields.map((field) => [field.key, field.step, field.section]), [
        ['nama', 'pemohon', 'identitas'],
        ['atasan', 'pemohon', 'identitas'],
        ['perihal', 'dokumen', undefined],
    ]);
    const list = visibleSteps(wizard, initialValues(wizard.fields));
    assert.deepEqual(list.map((step) => step.key), ['pemohon', 'dokumen']);
    assert.equal(categoryReady({ categoryId: '2', schemaCategoryId: '2', schema: wizard }), true);

    // Form dengan steps dipakai apa adanya; tanpa form pembuka wizard tidak diubah.
    assert.equal(asWizardSchema(opening, schema('SOP')), opening);
    assert.equal(asWizardSchema(single, null), single);
});

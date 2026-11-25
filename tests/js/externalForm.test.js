// Uji utilitas form eksternal di browser: `npm run test:js` (node:test, tanpa dependensi).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    autoLabels,
    initialValues,
    isSingleRow,
    isVisible,
    matchBusinessEntityOption,
    matchPrefill,
    rulesHint,
    pruneAnswers,
    ticketEntityTexts,
    validateAnswers,
    withTicketValues,
} from '../../resources/js/support/externalForm.js';

// Bentuk dari server (ExternalFormSchema::withPrefill): field badan usaha
// ditandai `ticket` + `business_entity`, tanpa `prefill`.
const badanUsaha = {
    key: 'badan_usaha',
    type: 'select',
    label: 'Badan usaha',
    required: true,
    ticket: true,
    business_entity: true,
    options: [
        { value: 'CS', label: 'Complete Selular' },
        { value: 'LAIN', label: 'Lainnya' },
    ],
};

const entity = { key: 'badan_usaha', label: 'Badan usaha', required: true, options: badanUsaha.options, default: 'CS' };

test('visible_if atas field otomatis yang sudah dihitung server', () => {
    const fields = [
        { key: 'jabatan', type: 'select', label: 'Jabatan', auto: true, options: [] },
        { key: 'approval_direksi', type: 'text', label: 'Approval direksi', required: true, visible: true },
        { key: 'catatan_staff', type: 'text', label: 'Catatan staff', required: true, visible: false },
    ];

    assert.equal(isVisible(fields[1], [{}]), true);
    assert.equal(isVisible(fields[2], [{}]), false);
    assert.deepEqual(validateAnswers(fields, {}), { 'answers.approval_direksi': 'Approval direksi wajib diisi.' });
    assert.deepEqual(pruneAnswers(fields, { approval_direksi: 'Ya', catatan_staff: 'x' }), { approval_direksi: 'Ya' });
});

test('field badan usaha tidak dirender, divalidasi, maupun dikirim dari form eksternal', () => {
    const fields = withTicketValues([badanUsaha], { option: '', texts: [] });

    assert.deepEqual(initialValues(fields), {});
    assert.deepEqual(validateAnswers(fields, {}), {});
    assert.deepEqual(pruneAnswers(fields, { badan_usaha: 'LAIN' }), {});
    assert.deepEqual(autoLabels(fields, {}), [], 'Dijawab di pertanyaan badan usaha, bukan "diisi otomatis".');
});

test('visible_if atas field badan usaha memakai jawaban pertanyaan badan usaha helpdesk', () => {
    const nomor = { key: 'nomor_cs', type: 'text', label: 'Nomor CS', required: true, visible_if: { field: 'badan_usaha', equals: 'CS' } };
    const view = (option) => withTicketValues([badanUsaha, nomor], { option, texts: ticketEntityTexts(entity, option, '') });

    assert.equal(view('CS')[0].ticket_value, 'CS');
    assert.deepEqual(validateAnswers(view('CS'), {}), { 'answers.nomor_cs': 'Nomor CS wajib diisi.' });
    assert.deepEqual(pruneAnswers(view('CS'), { nomor_cs: 'CS-1' }), { nomor_cs: 'CS-1' });
    // Isian browser untuk field badan usaha tidak dipercaya untuk visible_if.
    assert.deepEqual(validateAnswers(view('LAIN'), { badan_usaha: 'CS' }), {});
    assert.equal(view('BUKAN-OPSI')[0].ticket_value, null);

    // Di dalam repeater, acuan ke level atas juga ikut.
    const nested = withTicketValues([badanUsaha, { key: 'baris', type: 'repeater', label: 'Baris', fields: [nomor] }], { option: 'CS' });
    assert.deepEqual(validateAnswers(nested, { baris: [{}] }), { 'answers.baris.0.nomor_cs': 'Nomor CS wajib diisi.' });
});

test('field badan usaha lain diisi dari label/nilai opsi atau nama badan usaha helpdesk', () => {
    const teks = { key: 'bu_teks', type: 'text', label: 'BU', ticket: true, rules: { max: 20 } };
    const pendek = { key: 'bu_kode', type: 'select', label: 'Kode', ticket: true, options: [{ value: 'x', label: 'cs' }] };

    assert.deepEqual(ticketEntityTexts(entity, 'CS', 'diabaikan'), ['Complete Selular', 'CS']);
    assert.deepEqual(ticketEntityTexts(entity, '', 'diabaikan'), []);
    assert.deepEqual(ticketEntityTexts(null, '', 'PT Mitra Lain'), ['PT Mitra Lain']);

    const [a, b] = withTicketValues([teks, pendek], { option: 'CS', texts: ticketEntityTexts(entity, 'CS', '') });
    assert.equal(a.ticket_value, 'Complete Selular');
    assert.equal(b.ticket_value, 'x');

    // Tanpa field badan usaha: nama badan usaha helpdesk; melanggar rules → kosong.
    const [c] = withTicketValues([teks], { texts: ticketEntityTexts(null, '', 'PT Nama Yang Sangat Panjang Sekali') });
    assert.equal(c.ticket_value, null);
});

test('catatan otomatis hanya menyebut field yang tampil dan dikirim', () => {
    const fields = [
        { key: 'perlu_approval', type: 'radio', label: 'Perlu approval', options: [] },
        { key: 'email_atasan', type: 'email', label: 'Email atasan', auto: true, visible_if: { field: 'perlu_approval', equals: 'ya' } },
        { key: 'nama', type: 'text', label: 'Nama', auto: true },
        { key: 'direksi', type: 'text', label: 'Direksi', auto: true, visible: false },
        { key: 'proses', type: 'repeater', label: 'Proses', fields: [{ key: 'pic', type: 'email', label: 'Email PIC', auto: true }] },
    ];

    assert.deepEqual(autoLabels(fields, { perlu_approval: 'tidak', proses: [] }), ['Nama']);
    assert.deepEqual(autoLabels(fields, { perlu_approval: 'ya', proses: [{}] }), ['Email atasan', 'Nama', 'Email PIC']);
});

test('nilai yang melanggar aturan field tidak diisi otomatis', () => {
    assert.equal(matchPrefill({ type: 'text', rules: { max: 5 } }, 'Complete Selular'), null);
    assert.equal(matchPrefill({ type: 'text', rules: { max: 5 } }, ' CS '), 'CS');
    assert.equal(matchPrefill({ type: 'email' }, 'bukan-email'), null);
    assert.equal(matchPrefill({ type: 'number', rules: { max: 3 } }, '4'), null);
});

test('repeater max_items 1 tampil sebagai satu baris tanpa petunjuk jumlah baris', () => {
    const judul = { key: 'judul', type: 'text', label: 'Judul', required: true };
    const single = { key: 'proses', type: 'repeater', label: 'Dokumen', required: true, min_items: 1, max_items: 1, fields: [judul] };
    const many = { ...single, max_items: 10 };

    assert.equal(isSingleRow(single), true);
    assert.equal(isSingleRow(many), false);
    assert.equal(isSingleRow({ ...judul, max_items: 1 }), false);
    assert.equal(rulesHint(single), '');
    assert.equal(rulesHint(many), 'min. 1 baris · maks. 10 baris');
    assert.equal(initialValues([single]).proses.length, 1);
    assert.deepEqual(Object.keys(validateAnswers([single], { proses: [{ judul: '' }] })), ['answers.proses.0.judul']);
});

test('pencocokan badan usaha mengenali inti nama tanpa awalan dan akronim yang tidak ambigu', () => {
    const options = [
        { value: 'CV CS', label: 'CV CS' },
        { value: 'PT CSN', label: 'PT CSN' },
        { value: 'PT SMI', label: 'PT SMI' },
    ];

    assert.equal(matchBusinessEntityOption(options, 'Complete Selular'), 'CV CS');
    assert.equal(matchBusinessEntityOption(options, 'PT Complete Selular Nusantara'), 'PT CSN');
    assert.equal(matchBusinessEntityOption(options, 'PT Selular Media Infotama'), 'PT SMI');
    assert.equal(matchBusinessEntityOption(options, 'PT Tidak Ada'), null);
    assert.equal(
        matchBusinessEntityOption(
            [
                { value: 'CV CS', label: 'CV CS' },
                { value: 'PT CS', label: 'PT CS' },
            ],
            'Complete Selular',
        ),
        null,
    );
});

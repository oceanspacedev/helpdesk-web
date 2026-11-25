// Utilitas form dinamis sistem eksternal (kontrak v1 §3.2). Dipakai renderer
// ExternalForm dan halaman form publik. Validasi di sini hanya bantuan UX;
// sistem tujuan tetap memvalidasi ulang dan errornya ditampilkan per field.

export const INPUT_TYPES = ['text', 'textarea', 'email', 'phone', 'number', 'date', 'select', 'radio'];

export function newIdempotencyKey() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    const bytes = new Uint8Array(16);
    if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
        crypto.getRandomValues(bytes);
    } else {
        for (let i = 0; i < bytes.length; i++) {
            bytes[i] = Math.floor(Math.random() * 256);
        }
    }

    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
}

function scalarDefault(field) {
    const value = field.default;
    if (value === null || value === undefined || typeof value === 'object') {
        return '';
    }

    return String(value);
}

export function initialRow(fields, source = {}) {
    const row = {};
    for (const field of fields ?? []) {
        const value = initialValue(field, source?.[field.key]);
        if (value !== undefined) {
            row[field.key] = value;
        }
    }

    return row;
}

function initialValue(field, provided) {
    switch (field.type) {
        case 'info':
            return undefined;
        case 'file':
            return null;
        case 'files':
            return [];
        case 'repeater': {
            const source = Array.isArray(provided)
                ? provided
                : Array.isArray(field.default)
                    ? field.default
                    : null;
            if (source) {
                return source.map((row) => initialRow(field.fields, row ?? {}));
            }

            const max = Number.isInteger(field.max_items) ? field.max_items : Infinity;
            const count = Math.min(Math.max(field.min_items ?? 0, 1), max);

            return Array.from({ length: count }, () => initialRow(field.fields));
        }
        default:
            if (field.auto || field.ticket) {
                return undefined;
            }
            if (provided !== undefined && provided !== null && typeof provided !== 'object') {
                return String(provided);
            }

            return scalarDefault(field);
    }
}

export function initialValues(fields) {
    return initialRow(fields);
}

function normalized(value) {
    return value === null || value === undefined ? '' : String(value).trim().toLowerCase();
}

/**
 * Aturan dasar yang juga diperiksa server (ExternalFormSchema::passesRules)
 * sebelum sebuah nilai boleh diisi otomatis.
 */
export function passesRules(field, value) {
    const rules = field.rules ?? {};
    if (['select', 'radio'].includes(field.type)) {
        return true;
    }
    if (field.type === 'number') {
        const number = Number(value);

        return (
            value !== '' &&
            !Number.isNaN(number) &&
            (rules.min === undefined || number >= rules.min) &&
            (rules.max === undefined || number <= rules.max)
        );
    }
    if (field.type === 'date') {
        const min = minDate(field);

        return /^\d{4}-\d{2}-\d{2}$/.test(value) && (!min || value >= min);
    }
    if (field.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
        return false;
    }

    const length = [...value].length;

    return (rules.min === undefined || length >= rules.min) && (rules.max === undefined || length <= rules.max);
}

function entityCoreAndInitials(name) {
    const normalizedName = String(name ?? '')
        .toLowerCase()
        .replace(/[.,\-/]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    if (!normalizedName) {
        return ['', ''];
    }

    const words = normalizedName.split(' ');
    const prefixes = ['pt', 'cv', 'ud', 'yayasan', 'koperasi'];
    while (words.length > 1 && prefixes.includes(words[0])) {
        words.shift();
    }

    const core = words.join(' ');
    const initials = words.length > 1 ? words.map((word) => word[0] ?? '').join('') : '';

    return [core, initials];
}

function entityCoreMatches(a, b) {
    const [coreA, initialsA] = entityCoreAndInitials(a);
    const [coreB, initialsB] = entityCoreAndInitials(b);
    if (!coreA || !coreB) {
        return false;
    }
    if (coreA === coreB) {
        return true;
    }
    if (!coreA.includes(' ') && coreA.length >= 2 && initialsB && coreA === initialsB) {
        return true;
    }

    return !coreB.includes(' ') && coreB.length >= 2 && Boolean(initialsA) && coreB === initialsA;
}

/**
 * Cocokkan nama badan usaha helpdesk ke opsi field badan usaha sistem tujuan.
 */
export function matchBusinessEntityOption(options, text) {
    const needle = normalized(text);
    if (!needle) {
        return null;
    }

    const list = options ?? [];
    const exact = list.find(
        (item) => normalized(item.value) === needle || normalized(item.label) === needle,
    );
    if (exact) {
        return String(exact.value);
    }

    const matched = [];
    for (const option of list) {
        if (entityCoreMatches(needle, option.label) || entityCoreMatches(needle, option.value)) {
            const value = String(option.value);
            if (!matched.includes(value)) {
                matched.push(value);
            }
        }
    }

    return matched.length === 1 ? matched[0] : null;
}

/**
 * Nilai opsi field (select/radio) yang cocok dengan teks, tanpa membedakan
 * huruf besar/kecil, terhadap nilai atau label opsi. Field teks: teks itu,
 * selama lolos aturan field. Null bila tidak bisa diisi otomatis.
 */
export function matchPrefill(field, text) {
    const needle = normalized(text);
    if (!needle) {
        return null;
    }
    if (!['select', 'radio'].includes(field.type)) {
        const value = String(text).trim();

        return passesRules(field, value) ? value : null;
    }

    const option = (field.options ?? []).find(
        (item) => normalized(item.value) === needle || normalized(item.label) === needle,
    );

    return option ? String(option.value) : null;
}

/**
 * Teks sumber field bertanda `ticket` selain field badan usaha itu sendiri
 * (sama dengan ExternalFormSchema::ticketEntityTexts): label lalu nilai opsi
 * terpilih bila form punya field badan usaha (`entity` = ringkasan
 * `business_entity` dari server), atau nama badan usaha helpdesk terpilih.
 */
export function ticketEntityTexts(entity, option, businessEntityName) {
    if (entity) {
        const chosen = (entity.options ?? []).find((item) => String(item.value) === String(option ?? ''));

        return chosen ? [chosen.label, chosen.value] : [];
    }

    return normalized(businessEntityName) ? [businessEntityName] : [];
}

/**
 * Isi `ticket_value` setiap field bertanda `ticket` dengan nilai yang akan
 * dikirim server: field badan usaha = opsi terpilih, field lain = teks
 * sumber pertama yang cocok. Field ini tidak dirender (dijawab lewat
 * pertanyaan badan usaha helpdesk); nilainya hanya dipakai untuk visible_if.
 */
export function withTicketValues(fields, { option = '', texts = [] } = {}) {
    return (fields ?? []).map((field) => {
        if (field.type === 'repeater') {
            return { ...field, fields: withTicketValues(field.fields, { option, texts }) };
        }
        if (!field.ticket) {
            return field;
        }
        if (field.business_entity) {
            const chosen = (field.options ?? []).find((item) => String(item.value) === String(option ?? ''));

            return { ...field, ticket_value: chosen ? String(chosen.value) : null };
        }

        const value = texts.map((text) => matchPrefill(field, text)).find((item) => item !== null) ?? null;

        return { ...field, ticket_value: value };
    });
}

/**
 * Nilai satu level untuk visible_if: isian browser, dengan field bertanda
 * `ticket` diganti nilai yang akan dikirim server (`ticket_value`).
 */
export function levelScope(fields, values) {
    const scope = { ...(values ?? {}) };
    for (const field of fields ?? []) {
        if (field.ticket) {
            scope[field.key] = field.ticket_value ?? null;
        }
    }

    return scope;
}

/**
 * Label field yang diisi otomatis helpdesk dan benar-benar dikirim: tampil
 * menurut visible_if, dan di dalam repeater hanya bila ada barisnya. Unik.
 */
export function autoLabels(fields, values = {}, parentScopes = []) {
    const scopes = [levelScope(fields, values), ...parentScopes];
    const labels = [];
    const add = (label) => {
        if (!labels.includes(label)) {
            labels.push(label);
        }
    };
    for (const field of fields ?? []) {
        if (!isVisible(field, scopes)) {
            continue;
        }
        if (field.type === 'repeater') {
            const rows = Array.isArray(values?.[field.key]) ? values[field.key] : [];
            for (const row of rows) {
                autoLabels(field.fields, row ?? {}, scopes).forEach(add);
            }
        } else if (field.auto) {
            add(field.label);
        }
    }

    return labels;
}

function comparable(value) {
    if (typeof value === 'boolean') {
        return value ? '1' : '0';
    }
    if (value === null || value === undefined || typeof value === 'object') {
        return '';
    }

    return String(value).trim();
}

/**
 * scopes: nilai level saat ini lebih dulu, lalu level-level di atasnya.
 */
export function isVisible(field, scopes) {
    // Dihitung server: visible_if mengacu ke field otomatis yang nilainya
    // tidak dikirim ke browser.
    if (typeof field.visible === 'boolean') {
        return field.visible;
    }

    const condition = field.visible_if;
    if (!condition || typeof condition.field !== 'string') {
        return true;
    }

    for (const values of scopes) {
        if (values && Object.prototype.hasOwnProperty.call(values, condition.field)) {
            return comparable(values[condition.field]) === comparable(condition.equals);
        }
    }

    return comparable(null) === comparable(condition.equals);
}

/**
 * Isian yang dikirim: field tersembunyi, info, dan field yang diisi server
 * (identitas otomatis, badan usaha) tidak ikut.
 */
export function pruneAnswers(fields, values, parentScopes = []) {
    const scopes = [levelScope(fields, values), ...parentScopes];
    const result = {};
    for (const field of fields ?? []) {
        if (field.type === 'info' || field.auto || field.ticket || !isVisible(field, scopes)) {
            continue;
        }

        const value = values?.[field.key];
        if (field.type === 'repeater') {
            const rows = Array.isArray(value) ? value : [];
            result[field.key] = rows.map((row) => pruneAnswers(field.fields, row, scopes));
        } else if (field.type === 'files') {
            const files = Array.isArray(value) ? value.filter(Boolean) : [];
            if (files.length) {
                result[field.key] = files;
            }
        } else if (field.type === 'file') {
            if (value) {
                result[field.key] = value;
            }
        } else {
            result[field.key] = value ?? '';
        }
    }

    return result;
}

export function todayIso() {
    const now = new Date();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');

    return `${now.getFullYear()}-${month}-${day}`;
}

export function minDate(field) {
    const value = field.rules?.min_date;
    if (!value) {
        return undefined;
    }

    return value === 'today' ? todayIso() : value;
}

export function acceptAttribute(field) {
    const accept = field.rules?.accept;

    return Array.isArray(accept) && accept.length ? accept.map((ext) => `.${ext}`).join(',') : undefined;
}

export function formatKb(kb) {
    return kb >= 1024 ? `${(kb / 1024).toFixed(kb % 1024 === 0 ? 0 : 1)} MB` : `${kb} KB`;
}

export function rulesHint(field) {
    const rules = field.rules ?? {};
    const parts = [];
    if (Array.isArray(rules.accept) && rules.accept.length) {
        parts.push(`Format: ${rules.accept.join(', ')}`);
    }
    if (rules.max_kb) {
        parts.push(`maks. ${formatKb(rules.max_kb)} per file`);
    }
    if (rules.max_files && field.type === 'files') {
        parts.push(`maks. ${rules.max_files} file`);
    }
    if (rules.max_total_kb) {
        parts.push(`total maks. ${formatKb(rules.max_total_kb)}`);
    }
    if (['text', 'textarea'].includes(field.type)) {
        if (rules.min && rules.max) {
            parts.push(`${rules.min}–${rules.max} karakter`);
        } else if (rules.max) {
            parts.push(`maks. ${rules.max} karakter`);
        } else if (rules.min) {
            parts.push(`min. ${rules.min} karakter`);
        }
    }
    if (field.type === 'number') {
        if (rules.min !== undefined && rules.max !== undefined) {
            parts.push(`antara ${rules.min} dan ${rules.max}`);
        } else if (rules.min !== undefined) {
            parts.push(`min. ${rules.min}`);
        } else if (rules.max !== undefined) {
            parts.push(`maks. ${rules.max}`);
        }
    }
    if (field.type === 'repeater' && !isSingleRow(field)) {
        if (field.min_items) {
            parts.push(`min. ${field.min_items} baris`);
        }
        if (Number.isInteger(field.max_items)) {
            parts.push(`maks. ${field.max_items} baris`);
        }
    }

    return parts.join(' · ');
}

function extensionOf(name) {
    const index = String(name ?? '').lastIndexOf('.');

    return index >= 0 ? name.slice(index + 1).toLowerCase() : '';
}

export function fileErrors(field, files) {
    const rules = field.rules ?? {};
    if (Array.isArray(rules.accept) && rules.accept.length) {
        const invalid = files.find((file) => !rules.accept.includes(extensionOf(file.name)));
        if (invalid) {
            return `Format ${invalid.name} tidak didukung. Gunakan ${rules.accept.join(', ')}.`;
        }
    }
    if (rules.max_kb) {
        const tooBig = files.find((file) => file.size > rules.max_kb * 1024);
        if (tooBig) {
            return `${tooBig.name} melebihi ${formatKb(rules.max_kb)}.`;
        }
    }
    if (rules.max_files && files.length > rules.max_files) {
        return `Maksimal ${rules.max_files} file.`;
    }
    if (rules.max_total_kb) {
        const total = files.reduce((sum, file) => sum + (file.size ?? 0), 0);
        if (total > rules.max_total_kb * 1024) {
            return `Total ukuran file maksimal ${formatKb(rules.max_total_kb)}.`;
        }
    }

    return null;
}

/**
 * Validasi ringan di browser. Mengembalikan peta error berkunci path kontrak,
 * mis. { "answers.proses.0.judul": "..." }.
 */
export function validateAnswers(fields, values, parentScopes = [], path = 'answers') {
    const scopes = [levelScope(fields, values), ...parentScopes];
    const errors = {};
    for (const field of fields ?? []) {
        if (field.type === 'info' || field.auto || field.ticket || !isVisible(field, scopes)) {
            continue;
        }

        const key = `${path}.${field.key}`;
        const value = values?.[field.key];
        const rules = field.rules ?? {};

        if (field.type === 'repeater') {
            const rows = Array.isArray(value) ? value : [];
            const min = Math.max(field.min_items ?? 0, field.required ? 1 : 0);
            if (rows.length < min) {
                errors[key] = `Isi minimal ${min} baris.`;
            } else if (Number.isInteger(field.max_items) && rows.length > field.max_items) {
                errors[key] = `Maksimal ${field.max_items} baris.`;
            }
            rows.forEach((row, index) => {
                Object.assign(errors, validateAnswers(field.fields, row, scopes, `${key}.${index}`));
            });
            continue;
        }

        if (field.type === 'file' || field.type === 'files') {
            const files = field.type === 'file' ? (value ? [value] : []) : Array.isArray(value) ? value : [];
            if (field.required && !files.length) {
                errors[key] = `${field.label} wajib diunggah.`;
                continue;
            }
            const message = fileErrors(field, files);
            if (message) {
                errors[key] = message;
            }
            continue;
        }

        const text = value === null || value === undefined ? '' : String(value).trim();
        if (!text) {
            if (field.required) {
                errors[key] = `${field.label} wajib diisi.`;
            }
            continue;
        }

        if (['text', 'textarea'].includes(field.type)) {
            if (rules.min && text.length < rules.min) {
                errors[key] = `${field.label} minimal ${rules.min} karakter.`;
            } else if (rules.max && text.length > rules.max) {
                errors[key] = `${field.label} maksimal ${rules.max} karakter.`;
            }
        } else if (field.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(text)) {
            errors[key] = `${field.label} harus berupa email yang valid.`;
        } else if (field.type === 'number') {
            const number = Number(text);
            if (Number.isNaN(number)) {
                errors[key] = `${field.label} harus berupa angka.`;
            } else if (rules.min !== undefined && number < rules.min) {
                errors[key] = `${field.label} minimal ${rules.min}.`;
            } else if (rules.max !== undefined && number > rules.max) {
                errors[key] = `${field.label} maksimal ${rules.max}.`;
            }
        } else if (field.type === 'date') {
            const min = minDate(field);
            if (min && text < min) {
                errors[key] = `${field.label} tidak boleh sebelum ${min}.`;
            }
        } else if (['select', 'radio'].includes(field.type)) {
            if (!(field.options ?? []).some((option) => String(option.value) === text)) {
                errors[key] = `Pilihan ${field.label} tidak valid.`;
            }
        }
    }

    return errors;
}

/**
 * Error untuk satu field. File/files juga menampilkan error per file
 * (mis. "answers.dok.0"); repeater hanya error miliknya sendiri.
 */
export function errorFor(errors, path, field) {
    if (!errors) {
        return '';
    }
    if (errors[path]) {
        return errors[path];
    }
    if (field.type === 'repeater') {
        return '';
    }

    const prefix = `${path}.`;
    const nested = Object.keys(errors).find((key) => key.startsWith(prefix));

    return nested ? errors[nested] : '';
}

/**
 * Repeater berisi tepat satu baris (`max_items: 1`): ditampilkan sebagai
 * isian biasa, tanpa kartu baris, tombol tambah, atau hapus.
 */
export function isSingleRow(field) {
    return field?.type === 'repeater' && field.max_items === 1;
}

# Integrasi Sistem Eksternal (Custom Integration) — Kontrak v1

Helpdesk adalah **pintu depan** untuk semua permintaan. Unit yang punya sistem internal sendiri
(contoh: BUSDEV → Modul OD) bisa memasang sistemnya ke helpdesk. Pelapor tetap membuat dan memantau
tiket di helpdesk, tetapi **form, data, dan proses pengerjaan dimiliki sistem eksternal**.

- Unit **tanpa** integrasi: tidak berubah, semua data di helpdesk.
- Unit **dengan** integrasi: helpdesk mengambil definisi form dari sistem eksternal, menampilkannya,
  meneruskan isian (termasuk file) ke sistem eksternal, dan hanya menyimpan **tiket tipis**
  (nomor tiket, judul, pelapor, referensi eksternal, status cermin, snapshot terakhir).

Sistem apa pun bisa dipasang selama memenuhi kontrak di bawah ini.

---

## 1. Konfigurasi di helpdesk

| Tempat | Isi |
|---|---|
| Master **Integrasi** | nama, `base_url`, `token` (Bearer, terenkripsi), `webhook_secret` (terenkripsi), aktif/tidak, timeout |
| **Unit** | `integration_id` (kosong = data di helpdesk) |
| **Kategori tiket** | `external_key` = kode kategori di sistem eksternal (wajib untuk kategori di unit berintegrasi) |

URL webhook yang diberikan ke sistem eksternal: `POST {APP_URL}/api/integrations/{integration_id}/events`.

## 2. Aturan umum

- Semua request helpdesk → eksternal memakai header `Authorization: Bearer {token}` dan `Accept: application/json`.
- `base_url` sudah termasuk prefix versi, contoh `https://modul.example.com/api/helpdesk/v1`.
- Respons JSON, UTF-8. Error validasi = HTTP **422** format Laravel: `{ "message": "...", "errors": { "<path>": ["pesan"] } }`.
- Path error memakai notasi titik terhadap `answers`, contoh `answers.proses.0.judul`.
- Status dinormalisasi ke 4 kode:

| `status.code` | Arti | Status helpdesk |
|---|---|---|
| `open` | diterima, belum dikerjakan | OPEN |
| `in_progress` | sedang dikerjakan | IN_PROGRESS |
| `done` | selesai | CLOSED |
| `cancelled` | dibatalkan | CANCEL |

`status.label` = teks bebas yang ditampilkan ke pelapor (mis. "Drafting", "Proses Esign").

## 3. Endpoint yang WAJIB disediakan sistem eksternal

### 3.1 `GET {base}/categories`
Dipakai untuk "Tes koneksi" dan dropdown pemetaan kategori.

```json
{ "data": [ { "key": "IK", "name": "Instruksi Kerja", "active": true } ] }
```

### 3.2 `GET {base}/forms/{category_key}`
Definisi form untuk satu kategori. 404 bila kategori tidak ada / tidak aktif.

```json
{
  "data": {
    "key": "IK",
    "title": "Instruksi Kerja (IK)",
    "description": "Teks pengantar (plain text).",
    "downloads": [ { "label": "Contoh IK", "url": "https://..." } ],
    "fields": [ /* Field[] */ ]
  }
}
```

**Field**

| Properti | Tipe | Keterangan |
|---|---|---|
| `key` | string | `[a-z0-9_]+`, unik di level yang sama |
| `type` | enum | `text`, `textarea`, `email`, `phone`, `number`, `date`, `select`, `radio`, `file`, `files`, `repeater`, `info` |
| `label` | string | |
| `required` | bool | |
| `help` | string? | teks bantuan |
| `placeholder` | string? | |
| `default` | any? | nilai awal |
| `prefill` | string? | ambil nilai awal dari identitas pelapor: `reporter.name`, `reporter.phone`, `reporter.email`, `reporter.unit`, `reporter.job_title`, `reporter.business_entity`. Untuk `select`/`radio`, dipakai hanya bila cocok (case-insensitive) dengan salah satu opsi. |
| `options` | `{value,label}[]`? | untuk `select`/`radio` |
| `rules` | object? | `min`, `max` (panjang teks / nilai angka), `min_date` (`today` atau Y-m-d), `accept` (daftar ekstensi tanpa titik), `max_kb` (per file), `max_files`, `max_total_kb` |
| `visible_if` | `{field, equals}`? | tampil hanya bila field lain (di level yang sama, atau level atas) sama dengan nilai tsb. Field tersembunyi **tidak dikirim**. |
| `fields` | Field[]? | hanya `repeater`: field per baris |
| `min_items`, `max_items` | int? | hanya `repeater` |

`info` hanya menampilkan `label` + `help` (tanpa input).

Validasi di helpdesk hanya bantuan UX; **sistem eksternal tetap memvalidasi ulang** dan helpdesk
menampilkan error 422 di field terkait.

### 3.3 `POST {base}/tickets`
`multipart/form-data`.

| Field | Keterangan |
|---|---|
| `idempotency_key` | string ≤ 64, dibuat helpdesk per pengisian form. Kirim ulang dengan key sama → kembalikan tiket yang sama (HTTP 200). |
| `helpdesk_ticket_number` | nomor tiket helpdesk yang dicadangkan |
| `category_key` | kode kategori eksternal |
| `title` | judul tiket di helpdesk |
| `reporter[nik]`, `reporter[name]`, `reporter[phone]`, `reporter[email]`, `reporter[unit]`, `reporter[job_title]`, `reporter[business_entity]` | identitas pelapor dari helpdesk (bisa kosong kecuali `name` dan `phone`) |
| `answers[...]` | isian form sesuai `key`; repeater = `answers[proses][0][judul]`; `files` = `answers[...][]` |

Respons **201** (baru) / **200** (replay idempotensi):

```json
{ "data": { /* TicketSnapshot */ } }
```

### 3.4 `GET {base}/tickets/{ref}`
```json
{ "data": { /* TicketSnapshot */ } }
```

**TicketSnapshot**

```json
{
  "ref": "IK-20261125-AB3D",
  "url": "https://... (opsional, halaman internal sistem eksternal)",
  "status": { "code": "in_progress", "label": "Drafting" },
  "summary": [ { "label": "Kategori", "value": "Instruksi Kerja" } ],
  "items": [ { "title": "IK Penerimaan Barang", "status": { "code": "in_progress", "label": "Drafting" }, "pic": "Nama PIC" } ],
  "timeline": [ { "at": "2026-11-25T10:00:00+07:00", "label": "Pengajuan diterima", "note": null } ],
  "comments": [ { "id": "12", "author": "Tim OD", "body": "…", "at": "2026-11-25T11:00:00+07:00" } ],
  "files": [ { "name": "IK-Final.pdf", "url": "https://... (URL bertanda tangan/sementara)" } ],
  "updated_at": "2026-11-25T11:00:00+07:00"
}
```

## 4. Webhook sistem eksternal → helpdesk

`POST {helpdesk}/api/integrations/{integration_id}/events`

Header:
- `X-Helpdesk-Timestamp`: unix detik
- `X-Helpdesk-Signature`: `sha256=` + hex `HMAC_SHA256(webhook_secret, "{timestamp}.{raw_body}")`

Body:
```json
{
  "event_id": "uuid unik",
  "type": "ticket.updated",
  "occurred_at": "2026-11-25T11:00:00+07:00",
  "data": { /* TicketSnapshot */ }
}
```

Helpdesk:
- menolak tanda tangan salah atau selisih waktu > 300 detik (401);
- `event_id` yang sama diproses sekali (200 tanpa efek);
- snapshot dengan `updated_at` lebih lama dari yang tersimpan diabaikan;
- memperbarui status tiket, menyimpan snapshot, mencatat riwayat, dan memberi notifikasi ke pelapor
  bila status berubah atau ada komentar baru;
- merespons 2xx secepatnya. Sistem eksternal wajib retry dengan backoff untuk respons non-2xx.

## 5. Perilaku helpdesk

- **Buat tiket (form publik & panel):** pilih unit → kategori. Bila unit berintegrasi, helpdesk
  memuat `forms/{external_key}` dan merender field. Saat submit, helpdesk memanggil `POST tickets`
  secara **sinkron**: 422 → error tampil di form; 5xx/timeout → pesan "sistem tujuan sedang tidak
  tersedia" dan isian tetap; 2xx → tiket tipis dibuat dengan `external_ref`.
- File tidak disimpan di helpdesk; diteruskan langsung ke sistem eksternal.
- **Lihat tiket:** menampilkan snapshot (status, item, timeline, komentar, file). Tombol "Muat ulang"
  mengambil `GET tickets/{ref}`.
- Tiket eksternal tidak bisa diproses/diselesaikan/dipindah dari helpdesk; status hanya berubah
  lewat webhook / muat ulang.
- **MCP intake** tidak membuat tiket ke unit berintegrasi (form dinamis tidak didukung lewat chat);
  pelapor diarahkan ke form web.

## 6. Catatan implementasi v1

- Error 422 di luar `answers.*` juga mungkin: `reporter.name`, `reporter.phone`, `category_key`,
  `idempotency_key`, `helpdesk_ticket_number`. Helpdesk menampilkannya sebagai error umum form.
- `idempotency_key` **wajib** di API. Helpdesk mengirim `sha256(kanal:pelapor:kunci_form)` agar
  satu pelapor tidak bisa memakai ulang kunci pelapor lain. Replay mengembalikan tiket yang sama
  tanpa validasi ulang (termasuk bila tiketnya sudah dibatalkan/dihapus).
- Nomor tiket helpdesk dicadangkan sebelum POST (baris tertunda, soft-deleted) sehingga nomor tidak
  berubah saat retry.
- Webhook: integrasi/ref tidak dikenal → 404 (sistem eksternal retry); integrasi nonaktif → 403;
  tanda tangan salah/kedaluwarsa → 401.
- Komentar dari helpdesk ke sistem eksternal belum didukung di v1; komentar lokal pada tiket
  eksternal dinonaktifkan.
- Modul: tiket bersumber helpdesk tidak mengirim notifikasi WhatsApp ke pemohon (helpdesk yang
  memberi tahu pelapor). Link file rilis berlaku 7 hari dan diperbarui setiap webhook / muat ulang.
  Ringkasan tiket di helpdesk diambil dari snapshot terakhir, bukan dari deskripsi.

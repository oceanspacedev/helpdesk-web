# Integrasi Sistem Eksternal (Custom Integration) — Kontrak v1

Helpdesk adalah **pintu depan** untuk semua permintaan. Unit yang punya sistem internal sendiri
(contoh: BUSDEV → Modul OD) bisa memasang sistemnya ke helpdesk. Pelapor tetap mengisi form
dari helpdesk, tetapi **form, data, penyimpanan pengajuan, pelacakan progres, dan notifikasi
WhatsApp ke pelapor sepenuhnya ditangani sistem eksternal** agar data tidak ganda di dua aplikasi.

- Unit **tanpa** integrasi: tidak berubah, semua data di helpdesk.
- Unit **dengan** integrasi: helpdesk mengambil definisi form dari sistem eksternal, menampilkannya,
  meneruskan isian (termasuk file) langsung ke sistem eksternal (`POST {base}/tickets`), dan
  menampilkan kode referensi eksternal beserta tombol **"Lacak progres"** tanpa menyimpan baris
  tiket (`HD-...`) di database helpdesk.

Sistem apa pun bisa dipasang selama memenuhi kontrak di bawah ini.

---

## 1. Konfigurasi di helpdesk

| Tempat | Isi |
|---|---|
| Master **Integrasi** | nama, `base_url`, `token` (Bearer, terenkripsi), `webhook_secret` (terenkripsi), aktif/tidak, timeout |
| **Unit** | `integration_id` (kosong = data di helpdesk) |
| **Kategori tiket** | `external_key` = kode kategori di sistem eksternal (wajib untuk kategori di unit berintegrasi) |

URL webhook yang diberikan ke sistem eksternal: `POST {APP_URL}/api/integrations/{integration_id}/events`.

### 1.1 Kode koneksi (cara termudah, v1.2)

Admin cukup menyalin **satu kode** dari sistem eksternal dan menempelkannya di helpdesk
(**Pengaturan → Integrasi → Hubungkan sistem**). Helpdesk lalu, dalam satu langkah:

1. membuat (atau memperbarui, bila `base_url` sama) master Integrasi dari isi kode;
2. memasang integrasi itu ke unit yang dipilih;
3. mengimpor kategori aktif dari `GET {base}/categories` sebagai kategori tiket unit tsb, lengkap
   dengan `external_key` (kategori dengan nama sama dipetakan, tidak digandakan);
4. mendaftarkan URL dan rahasia webhook-nya di sistem eksternal lewat `PUT {base}/webhook` (§4.1).

Format kode: `hd1_` + base64url (tanpa `=`) dari JSON:

```json
{ "v": 1, "name": "Modul", "url": "https://modul.example.com/api/helpdesk/v1", "token": "<Bearer token>" }
```

Kode berisi token, jadi tampilkan sekali saja dan buat token baru setiap kali kode dibuat ulang.
Agar rotasi kode tidak memutus helpdesk, token lama sebaiknya tetap diterima selama masa tenggang
(Modul: 24 jam) atau sampai helpdesk memanggil API dengan token baru, dan webhook lama tetap dipakai
sampai helpdesk mendaftarkan webhook baru. Sistem tanpa kode koneksi tetap bisa dipasang manual lewat
**Tambah manual**.

### 1.2 Perawatan koneksi

- **Pemantauan otomatis.** `php artisan integrations:check` (terjadwal tiap 15 menit) mengetes setiap
  integrasi aktif lewat `GET {base}/categories` dan menyimpan hasilnya di kolom **Status koneksi**.
  Admin global (Super Admin / Master Admin) dikabari lewat WhatsApp dan notifikasi panel hanya saat
  keadaannya berubah: terputus atau tersambung kembali.
- **Selisih kategori.** Setiap tes juga membandingkan kategori sistem tujuan dengan kategori tiket unit:
  kategori aktif baru yang belum diimpor dan kategori yang tidak ada lagi di sistem tujuan ditampilkan
  di status. Kategori yang sengaja dihapus admin tidak dianggap baru.
- **Tes koneksi** menjalankan tes menyeluruh: token dan kategori, form setiap kategori yang dipetakan
  (dimuat dan divalidasi), lalu webhook bolak-balik (§4.2).
- **Pengajuan Saya.** Setiap pengajuan pass-through meninggalkan jejak ringan di helpdesk
  (`external_submissions`: kode referensi, judul, status terakhir, `track_url`), bukan isi tiketnya.
  Pelapor melihat miliknya sendiri, admin global melihat semua; "Perbarui status" memanggil
  `GET {base}/tickets/{ref}`.
- **Sinkron kategori** (aksi per integrasi) menambahkan kategori aktif baru ke setiap unit yang memakai
  integrasi itu. Kategori yang dihapus admin tidak dipulihkan, dan kategori yang hilang di sistem tujuan
  tidak dihapus, hanya dilaporkan.

## 2. Aturan umum

- Semua request helpdesk → eksternal memakai header `Authorization: Bearer {token}` dan `Accept: application/json`.
- Redirect (3xx) tidak diikuti dan dianggap gagal. `base_url` dan URL webhook tidak boleh berisi
  kredensial atau mengarah ke alamat link-local / metadata cloud (169.254.0.0/16, fe80::/10).
- `key` kategori maksimal 100 karakter dan unik (duplikat diabaikan); `name` dipotong ke 255 karakter.
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

Opsional (v1.1, kartu pilihan kategori wizard): `description` (string) dan `needs_observation`
(bool), lihat §3.2 "Langkah".

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
| `prefill` | string? | nilai diambil otomatis oleh helpdesk. Identitas pelapor (data karyawan Talenta): `reporter.name`, `reporter.phone`, `reporter.email`, `reporter.nik`, `reporter.unit`, `reporter.job_title`, `reporter.job_level`, `reporter.business_entity`. Isian tiket helpdesk: `ticket.business_entity` (badan usaha yang dilaporkan; lihat §5 — field ini tidak pernah dirender sebagai input di form eksternal). Untuk `select`/`radio`, dipakai hanya bila cocok (case-insensitive, nilai atau label) dengan salah satu opsi. **Bila helpdesk punya nilainya, field tidak ditampilkan ke pelapor** (hanya disebut sebagai "diisi otomatis") dan nilainya diisi helpdesk saat submit; bila tidak punya / tidak cocok / nilainya melanggar `rules` field itu (mis. lebih panjang dari `max`, format email salah), field tampil seperti biasa. Pelapor baru yang tidak ada di Talenta maupun helpdesk (akun bernama sementara "Pelapor WhatsApp 1234") tidak punya `reporter.name`: field nama tampil untuk diisi, dan isiannya juga dikirim sebagai `reporter[name]`. `visible_if` yang mengacu ke field otomatis dievaluasi helpdesk dengan nilai yang diisi, sama di form dan saat submit. |
| `options` | `{value,label}[]`? | untuk `select`/`radio` |
| `rules` | object? | `min`, `max` (panjang teks / nilai angka), `min_date` (`today` atau Y-m-d), `accept` (daftar ekstensi tanpa titik), `max_kb` (per file), `max_files`, `max_total_kb` |
| `suggestions` | string[]? | untuk `text`/`textarea`: tombol pilihan cepat di bawah input (maks. 10 item) |
| `visible_if` | `{field, equals}`? | tampil hanya bila field lain (di level yang sama, atau level atas) sama dengan nilai tsb. Field tersembunyi **tidak dikirim**. |
| `fields` | Field[]? | hanya `repeater`: field per baris |
| `min_items`, `max_items` | int? | hanya `repeater`. `max_items: 1` = tepat satu baris: helpdesk menampilkan isian baris itu langsung (tanpa kartu baris, tombol tambah, atau hapus); kirimannya tetap `answers[key][0][...]` |

`info` hanya menampilkan `label` + `help` (tanpa input).

Data yang wajib tetapi belum tentu dimiliki helpdesk (mis. nomor WhatsApp pelapor) sebaiknya dijadikan
field ber-`prefill` (`reporter.phone`), bukan hanya dibaca dari `reporter[...]`: field itu tersembunyi
bila helpdesk punya nilainya, dan tampil untuk diisi bila tidak, sehingga pelapor tidak pernah terhenti
oleh error pada data yang tidak bisa ia ubah.

**Langkah (wizard) — opsional, v1.1**

Sistem eksternal boleh menyusun form sebagai wizard agar tampilan di helpdesk sama dengan
form aslinya. Tanpa `steps`, helpdesk merender satu halaman seperti biasa.

```json
"steps": [
  { "key": "pemohon",   "title": "Data pemohon",     "sections": [
      { "key": "identitas", "title": "Identitas", "help": "Isi sesuai data karyawan." },
      { "key": "kontak",    "title": "Kontak",    "help": "Kami menghubungi Anda lewat kontak ini." } ] },
  { "key": "dokumen",   "title": "Dokumen", "category_picker": true, "sections": [
      { "key": "detail", "title": "Detail dokumen" } ] },
  { "key": "observasi", "title": "Jadwal observasi", "sections": [
      { "key": "jadwal", "title": "Jadwal observasi", "help": "…" } ] }
]
```

- Setiap field level atas memakai `step` (key langkah) dan `section` (key seksi). Field tanpa
  `step` masuk langkah terakhir.
- `category_picker: true`: di awal langkah ini helpdesk menampilkan pilihan kategori unit tsb
  sebagai kartu (nama + `description` dari `GET categories`). Mengganti kategori memuat ulang
  form kategori itu; isian dengan `key` yang sama dipertahankan.
- Langkah sebelum `category_picker` (atau langkah `pemohon` yang konsisten) dirender dari form
  pembuka unit, jadi field di langkah-langkah umum harus identik di semua kategori.
- Langkah/seksi yang tidak memiliki input untuk diisi pelapor (semua field-nya tersembunyi atau
  otomatis) dilewati agar pelapor tidak melewati halaman kosong; label field otomatisnya tetap
  diringkas di badge "Terisi otomatis dari data karyawan" pada langkah pemilik badan usaha.
- `GET {base}/categories` boleh menambahkan `description` (string) dan `needs_observation` (bool)
  per kategori untuk kartu pilihan. `needs_observation: true` menambahkan catatan "Termasuk
  observasi langsung oleh tim {unit helpdesk}." di kotak "Apa itu {kategori}?". Properti lain
  (mis. `has_template`) diabaikan.
- Kategori yang form-nya **tanpa** `steps` di unit yang form pembukanya wizard tetap diajukan
  lewat wizard: helpdesk memakai `steps` form pembuka; field yang key-nya ada di form pembuka masuk
  langkah/seksi field itu, field lain masuk langkah `category_picker`. Judulnya juga diturunkan
  (§5).

Validasi di helpdesk hanya bantuan UX; **sistem eksternal tetap memvalidasi ulang** dan helpdesk
menampilkan error 422 di field terkait.

### 3.3 `POST {base}/tickets`
`multipart/form-data`.

| Field | Keterangan |
|---|---|
| `idempotency_key` | string ≤ 64, dibuat helpdesk per pengisian form. Kirim ulang dengan key sama → kembalikan pengajuan yang sama (HTTP 200). |
| `helpdesk_ticket_number` | opsional/kosong (`null`); helpdesk pass-through tidak lagi mencadangkan nomor `HD-...` |
| `category_key` | kode kategori eksternal |
| `title` | judul pengajuan yang diturunkan helpdesk |
| `reporter[nik]`, `reporter[name]`, `reporter[phone]`, `reporter[email]`, `reporter[unit]`, `reporter[job_title]`, `reporter[business_entity]` | identitas pelapor dari helpdesk (bisa kosong kecuali `name` dan `phone`; untuk pelapor baru, `name` = isian field ber-prefill `reporter.name` bila ada, selain itu nama sementara akunnya) |
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
  "track_url": "https://... (opsional, halaman lacak untuk pelapor)",
  "status": { "code": "in_progress", "label": "Drafting" },
  "summary": [ { "label": "Kategori", "value": "Instruksi Kerja" } ],
  "items": [ { "title": "IK Penerimaan Barang", "status": { "code": "in_progress", "label": "Drafting" }, "pic": "Nama PIC" } ],
  "timeline": [ { "at": "2026-11-25T10:00:00+07:00", "label": "Pengajuan diterima", "note": null } ],
  "comments": [ { "id": "12", "author": "Tim OD", "body": "…", "at": "2026-11-25T11:00:00+07:00" } ],
  "files": [ { "name": "IK-Final.pdf", "url": "https://... (URL bertanda tangan/sementara)" } ],
  "updated_at": "2026-11-25T11:00:00+07:00"
}
```

`track_url` (opsional): halaman lacak yang bisa dibuka pelapor tanpa login. Setelah pengajuan
berhasil diteruskan, helpdesk menampilkan konfirmasi sukses berisi kode referensi eksternal
serta tombol **"Lacak progres"** ke URL ini. Hanya dipakai bila berskema http(s) dan host-nya sama
dengan `base_url` integrasi. Bangun `track_url` dari alamat publik sistem eksternal (mis. `APP_URL`),
bukan host permintaan API, dan pakai host publik yang sama untuk `base_url`.

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

### 4.1 `PUT {base}/webhook` (opsional, v1.2)

Dipanggil helpdesk setelah "Hubungkan sistem", supaya URL dan rahasia webhook tidak perlu disalin
manual ke konfigurasi sistem eksternal. Body JSON (header Bearer seperti biasa):

```json
{ "url": "https://helpdesk.example.com/api/integrations/3/events", "secret": "<rahasia HMAC, min. 16 karakter>" }
```

Sistem eksternal menyimpan keduanya (rahasia terenkripsi), memakainya untuk semua webhook
berikutnya, dan membalas 2xx. Pendaftaran berikutnya menimpa yang lama. Bila endpoint ini tidak ada
(404), helpdesk tetap terhubung dan meminta admin mengatur webhook secara manual.

### 4.2 `POST {base}/webhook/test` (opsional, v1.2)

Dipanggil helpdesk dari **Tes koneksi** dan di akhir "Hubungkan sistem" untuk membuktikan arah sistem
eksternal → helpdesk **tanpa membuat tiket**. Sistem eksternal langsung (tanpa antrean) mengirim satu
event bertipe `ping` ke URL webhook terdaftar, ditandatangani seperti §4, dengan `data` berupa objek
apa pun, lalu membalas:

```json
{ "data": { "delivered": true, "event_id": "uuid ping", "status": 200, "error": null } }
```

Helpdesk mengabaikan isi `ping`, tetapi mencatat `event_id`-nya; tes dianggap lulus hanya bila
`event_id` yang dilaporkan benar-benar tiba dan lolos verifikasi tanda tangan di helpdesk itu. Dengan
begitu URL yang salah alamat atau rahasia yang tidak cocok langsung ketahuan. 404 = belum didukung
(langkahnya dilewati).

## 5. Perilaku helpdesk

- **Buat pengajuan (form publik & panel):** urutan pertanyaan unit/divisi → kategori → badan usaha yang
  dilaporkan → prioritas (prioritas tidak ditanyakan untuk unit berintegrasi). Bila unit berintegrasi,
  helpdesk memuat `forms/{external_key}` dan merender field. Saat submit, helpdesk memanggil
  `POST tickets` secara **sinkron**: 422 → error tampil di form; 5xx/timeout → pesan "sistem tujuan
  sedang tidak tersedia" dan isian tetap; 2xx → konfirmasi referensi eksternal ditampilkan tanpa
  menyimpan baris `tickets` di helpdesk (`HD-...`).
- **Badan usaha (satu pertanyaan, milik helpdesk):** bila form eksternal punya field `select`/`radio`
  level atas dengan `prefill: ticket.business_entity` (yang pertama dipakai), pertanyaan "Badan Usaha
  yang Dilaporkan" helpdesk memakai **opsi field itu** (value/label dari sistem eksternal), pilihan
  awalnya opsi yang cocok (case-insensitive, nilai atau label) dengan badan usaha kontrak pelapor,
  dan wajib bila field itu `required`. Field itu tidak dirender di form eksternal dan tidak disebut
  di catatan "diisi otomatis". Saat submit, helpdesk memvalidasi pilihan terhadap opsi form terbaru
  dan mengirimkannya sebagai `answers[<key>]`. Field `ticket.business_entity` lain (mis. teks, atau di
  dalam repeater) diisi dari label/nilai opsi itu. Bila form tidak punya field select/radio tersebut,
  pertanyaan memakai daftar badan usaha helpdesk seperti unit internal, dan field
  `ticket.business_entity` diisi dari nama badan usaha terpilih. Error 422 pada field badan usaha
  ditampilkan di pertanyaan badan usaha helpdesk.
- **Form wizard (v1.1, form dengan `steps`):** begitu unit berintegrasi dipilih, helpdesk memuat
  form pembuka unit (form kategori terpetakan pertama, urut nama; form publik lewat
  `GET /lapor/unit/{unit}/form`). Bila form itu punya `steps`, dropdown kategori dan pertanyaan
  judul diganti wizard seperti form asli sistem tujuan:
  - stepper bernomor ("Langkah n dari N", langkah yang sudah dilewati "Selesai" dan bisa diklik),
    seksi dengan judul + bantuan, tombol **Kembali** / **Lanjut** (validasi ringan per langkah)
    dan **Kirim pengajuan** di langkah terakhir;
  - langkah `category_picker` diawali kartu kategori (kode `external_key` + nama kategori helpdesk,
    urutan dan `description` dari `GET categories`; kategori nonaktif tidak ditawarkan) dan kotak
    "Apa itu {kategori}?" berisi deskripsi, catatan observasi (`needs_observation`), serta
    `downloads` sebagai tombol (kotak tidak ditampilkan bila semuanya kosong). Memilih kartu memuat
    form kategori itu; isian berkey sama dipertahankan. Seksi langkah itu dan langkah sesudahnya
    baru tampil setelah form kategori terpilih dimuat (selama memuat kategori lain, seksi kategori
    sebelumnya disembunyikan agar tidak membingungkan). Bila form kategori terpilih gagal dimuat,
    pesan gagal dimuat (dengan "Coba lagi") tampil di bawah kartu, dan **Lanjut** / **Kirim
    pengajuan** tidak bisa dipakai sampai form itu dimuat atau kategori lain dipilih;
  - langkah/seksi tanpa input untuk pelapor dilewati (field `info` tidak dihitung; field otomatis
    diringkas di badge "Terisi otomatis dari data karyawan" — form publik: label dengan tanda
    centang tanpa nilai; panel: label beserta nilainya);
  - pertanyaan "Badan Usaha yang Dilaporkan" dirender di seksi tempat field `ticket.business_entity`
    berada (bila tidak ada: seksi pertama langkah pertama), dengan pencocokan nama & akronim badan
    usaha secara otomatis bila tidak ambigu; prioritas tetap tidak ditanyakan;
  - error 422 membuka langkah pertama yang memuat error (field `answers.*` → langkah field level
    atasnya; badan usaha → seksi identitas; kategori → langkah kartu kategori). **Lanjut** hanya
    mengganti error langkah yang sedang dibuka; error server langkah lain tetap tampil sampai
    pelapor sampai di langkahnya atau mengirim ulang.
  - Panel memakai Filament Wizard dengan langkah/seksi yang sama; kategori dipilih lewat radio
    berdeskripsi di langkah `category_picker`, tombol "Buat" halaman diganti "Kirim pengajuan" di
    langkah terakhir, dan error 422 otomatis mengarahkan wizard ke langkah terkait (`go-to-wizard-step`).
  - Panel: form pembuka unit diambil sekali per request; bila gagal dimuat, kegagalannya diingat
    30 detik sehingga halaman langsung memakai alur satu halaman tanpa menunggu sistem tujuan di
    setiap interaksi. (Form kategori tetap tidak di-cache saat gagal; dalam satu request hanya
    dicoba sekali.)
  Form tanpa `steps` (atau form pembuka yang gagal dimuat) tetap memakai alur satu halaman di atas.
- **Judul tiket form wizard (diturunkan, tidak ditanyakan):** `"{external_key} · {teks}"`, dengan
  teks = isian pertama yang tidak kosong dari (1) field `text` berkey `judul`/`title` di baris
  pertama repeater level atas pertama, (2) field `text` wajib pertama di level atas, lalu di baris
  pertama repeater itu; field otomatis (`prefill`) dan badan usaha tidak dipakai. Bila tidak ada,
  judul = nama kategori helpdesk. Dipotong ke 250 karakter dan dikirim sebagai `title`
  (§3.3). Contoh: `IK · IK Penerimaan Barang`. Judul isian pelapor untuk form wizard diabaikan;
  form satu halaman tetap wajib berjudul. "Form wizard" = form kategori punya `steps` **atau** unit
  yang form pembukanya punya `steps` (kategori tanpa `steps` di unit wizard juga diturunkan).
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
- Helpdesk tidak menyimpan baris `tickets` (`HD-...`) untuk pengajuan unit berintegrasi; replay di
  browser dalam 30 menit dilayani dari cache atau pengulangan `POST {base}/tickets` yang bersifat
  idempoten di sistem eksternal.
- Webhook (opsional untuk tiket historis yang masih memiliki referensi di helpdesk): integrasi/ref
  tidak dikenal → 404 (sistem eksternal retry); integrasi nonaktif → 403; tanda tangan
  salah/kedaluwarsa → 401.
- Modul: semua notifikasi WhatsApp & in-app pemohon (penerimaan awal, perubahan progres, komentar,
  dan file rilis) dikirim langsung oleh sistem eksternal (Modul OD). SendHelpdeskWebhook hanya
  berjalan untuk tiket warisan yang memiliki `helpdesk_ticket_number`. Link file rilis berlaku
  7 hari.

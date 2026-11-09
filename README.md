<p align="center">
  <img src="public/images/icon.svg" alt="Logo Helpdesk" width="150">
</p>

<h1 align="center">Helpdesk</h1>

<p align="center">
  Sistem helpdesk berbasis web untuk pengajuan, penugasan, dan penyelesaian
  tiket dukungan terkait produk dan layanan organisasi.
</p>

Helpdesk menyediakan tiga jalur penggunaan:

- **Form tiket publik** di `/` dan `/lapor` untuk pelapor tanpa masuk ke panel.
- **Panel staf Filament** di `/admin` untuk operasional tiket.
- **MCP opsional/legacy** di `/mcp/helpdesk` untuk klien atau gateway tepercaya; bukan alur utama pengguna.

Bahasa utama aplikasi adalah Bahasa Indonesia (locale `id`) dan seluruh perhitungan waktu menggunakan zona waktu `Asia/Jakarta`.

> [!IMPORTANT]
> Form publik **tidak** memverifikasi kepemilikan nomor WhatsApp. Nomor berfungsi sebagai identitas pencocokan dan kontak, bukan bukti bahwa perangkat milik pemilik nomor. Ikatan browser (`helpdesk_reporter_bindings`) juga bukan bukti kepemilikan. Jangan membuka `/mcp/helpdesk` ke klien yang tidak tepercaya: endpoint itu memakai bearer token dan bukan pengganti form publik.

## Daftar isi

- [Tujuan dan scope](#tujuan-dan-scope)
- [Fitur utama](#fitur-utama)
- [Role dan hak akses](#role-dan-hak-akses)
- [Cara kerja aplikasi](#cara-kerja-aplikasi)
- [Arsitektur](#arsitektur)
- [Tech stack](#tech-stack)
- [Persiapan development](#persiapan-development)
- [Konfigurasi environment](#konfigurasi-environment)
- [Menjalankan aplikasi](#menjalankan-aplikasi)
- [MCP opsional](#mcp-opsional)
- [Sinkron data karyawan Talenta](#sinkron-data-karyawan-talenta)
- [Scheduler SLA](#scheduler-sla)
- [Workflow development](#workflow-development)
- [Testing dan quality check](#testing-dan-quality-check)
- [Batasan dan technical debt](#batasan-dan-technical-debt)
- [Troubleshooting](#troubleshooting)

## Tujuan dan scope

Helpdesk menyatukan pengajuan tiket oleh pelapor dan penanganan tiket oleh staf unit. Alur utamanya dimulai dari form publik, identitas pelapor berbasis nomor WhatsApp pada perangkat, pembuatan tiket, lalu pengelolaan di panel staf sampai tiket diproses atau ditutup.

### Termasuk dalam scope

- Form pengajuan tiket publik di `/` dan `/lapor` (pengalaman yang sama).
- Identitas pelapor berbasis nomor WhatsApp kanonik (`users.phone_normalized`) dan ikatan perangkat.
- Panel staf Filament di `/admin`: tiket, unit, kategori masalah, status, entitas bisnis, pengguna, SLA unit, dan pengaturan MCP.
- Prioritas tiket, komentar, lampiran, dan riwayat tiket.
- Role `Super Admin`, `Admin Unit`, `Staff Unit`, `Master Admin`, dan `User`.
- Login panel dengan email/kata sandi, OTP WhatsApp di `/phone-login`, dan kode OTP atau magic link email di `/email-login`.
- Peringatan SLA terjadwal (`sla:check-warnings` setiap 15 menit).
- Integrasi MCP opsional di `/mcp/helpdesk` (intake) dan `/mcp/helpdesk/staff` (perintah alur staf).

### Di luar scope implementasi saat ini

- Aplikasi mobile terpisah; pelapor memakai form Inertia di repositori ini, staf memakai panel Filament.
- Registrasi mandiri di panel dan reset password panel. Keduanya dinonaktifkan; akun staf dibuat oleh administrator atau dari data Talenta.
- Login Google (Socialite). Sudah dicabut dan diganti login email lewat kode OTP atau magic link.
- Produk REST API domain. `routes/api.php` hanya mengekspos `GET /api/user` untuk user terautentikasi Sanctum.
- Pipeline CI/CD, image container, atau orkestrasi Compose di repositori ini.

## Fitur utama

Ketersediaan modul pada setiap surface saat ini:

| Modul | Form `/` `/lapor` | Panel `/admin` | MCP `/mcp/helpdesk` | Keterangan |
|---|:---:|:---:|:---:|---|
| Identitas pelapor (nomor WhatsApp) | Ya | Tidak | Ya | Form tidak memakai OTP; MCP memverifikasi lewat OTP atau assertion gateway |
| Buat tiket | Ya | Ya | Ya | `owner_id` tiket ditentukan server, bukan dari hidden input |
| Kelola tiket (assign, proses, tutup) | Tidak | Ya | Tidak | Status: Open, In Progress, Cancel, Closed. Perintah staf ada di `/mcp/helpdesk/staff` |
| Komentar dan lampiran | Lampiran saat buat | Ya | Tidak | MCP intake tidak mengekspos komentar atau update |
| Master unit, kategori, status, entitas | Tidak | Ya | Tidak | Dipakai sebagai opsi form setelah identitas terikat |
| Prioritas tiket | Ya | Ya | Default Medium | Seed: Critical/Urgent, High, Medium, Low, Enhancement/Feature Request |
| Dashboard dan widget SLA | Tidak | Ya | Tidak | Peringatan SLA juga dikirim lewat scheduler |
| Pengguna dan role | Tidak | Ya | Tidak | Permission Filament Shield |
| Pengaturan MCP | Tidak | Super Admin | — | `/admin/pengaturan-mcp` |
| Login OTP WhatsApp | Tidak | `/phone-login` | — | Membutuhkan `WAG_URL` dan `WAG_TOKEN` |
| Login email (kode OTP / magic link) | Tidak | `/email-login` | — | Membutuhkan mailer yang benar-benar mengirim (`MAIL_MAILER` bukan `log`/`array`) |

> [!NOTE]
> MCP adalah jalur integrasi opsional/legacy. Pengajuan tiket utama tetap form publik. Kontrak alat, token, gateway, dan cutover production ada di [`docs/mcp/README.md`](docs/mcp/README.md).

<img src="screenshot/public-form.png" width="100%" alt="Form laporan publik di /lapor setelah nomor WhatsApp pelapor tersimpan" />

## Role dan hak akses

Role awal dari seeder adalah `Super Admin`, `Admin Unit`, `Staff Unit`, `Master Admin`, dan `User`.

Hak akses panel ditentukan permission Filament Shield pada masing-masing role, lalu dipersempit lagi oleh policy dan keanggotaan unit. Nama role saja tidak cukup; periksa [`app/Policies`](app/Policies) dan helper di [`app/Models/User.php`](app/Models/User.php).

### Akses efektif panel web

| Aktor | Akses utama |
|---|---|
| `Super Admin` | Seluruh permission, termasuk Pengaturan MCP. Akses tiket global. |
| `Master Admin` | Master data (unit, kategori, status, user, SLA unit) dan tiket lintas unit. Bukan Super Admin: halaman Pengaturan MCP menolak akses. |
| Anggota divisi (role apa pun) | Menerima notifikasi dan memproses tiket yang masuk ke divisinya. |
| `Admin Unit` | Seperti anggota divisi, plus kelola kategori, entitas bisnis, hapus tiket, dan tampilan SLA pada unitnya. |
| `Staff Unit` | Sama dengan anggota divisi; dipertahankan untuk data lama. |
| `User` | Permission pelapor (lihat/buat tiket dan komentar). Role di-seed, tetapi akun contoh `user@cs.com` **tidak** di-assign role ini. |
| Pelapor form publik | Tidak memakai panel. Akun minimal berbasis nomor telepon; `tickets.owner_id` menghubungkan tiket ke pelapor. |

Hanya user aktif yang dapat masuk panel. `User::canAccessPanel()` cukup memeriksa `is_active`, setara dnd-web dan gais-web: login lewat kata sandi, OTP WhatsApp, atau email semuanya membuka panel. Verifikasi email (`email_verified_at` + `email_verified_via`) hanya menentukan apakah user menerima notifikasi email.

`Super Admin` dan `Master Admin` memiliki akses tiket global (`hasGlobalTicketAccess()`). Selain itu, **setiap anggota aktif sebuah divisi adalah penindak** tiket yang masuk ke divisi itu (`user_entities`): ikut di-blast saat tiket baru masuk dan boleh memproses selama belum ada PIC. Divisi asal (`users.unit_id`) selalu ikut menjadi keanggotaan.

### Akun uji development

Kredensial berikut hanya dibuat oleh `php artisan db:seed` / `migrate --seed`. Jangan membuat atau mempertahankan kata sandi tetap ini pada lingkungan production.

| Nama seed | Email | Role yang di-assign | Kata sandi |
|---|---|---|---|
| Super Admin | `superadmin@cs.com` | `Super Admin` | `password` |
| Admin Unit | `adminunit@cs.com` | `Admin Unit` (unit id 1) | `password` |
| Staff Unit | `staffunit@cs.com` | `Staff Unit` (unit id 1) | `password` |
| User | `user@cs.com` | *tidak di-assign* | `password` |

Role `Master Admin` di-seed tanpa akun contoh. Unit seed: `BUSDEV` dan `IT`.

<img src="screenshot/admin-dashboard.png" width="100%" alt="Dasbor panel staf sebagai Super Admin: pencapaian SLA, tiket overdue, ringkasan master data, dan grafik status tiket" />

## Cara kerja aplikasi

```mermaid
flowchart LR
    A[Pelapor buka / atau /lapor] --> B{Perangkat sudah terikat?}
    B -- Tidak --> C[Masukkan nomor WhatsApp]
    C --> D[Pakai atau buat akun pelapor]
    D --> E[Form tiket]
    B -- Ya --> E
    E --> F[Kirim tiket]
    F --> G[Panel /admin]
    G --> H[Proses atau tutup]
    F --> I[Notifikasi staf]
    J[Scheduler 15 menit] --> K[sla:check-warnings]
```

### 1. Form publik dan identitas pelapor

Pengajuan tiket utama dilakukan melalui form publik pada dua alamat berikut:

```text
https://helpdesk.example.com/
https://helpdesk.example.com/lapor
```

Kedua alamat menampilkan pengalaman pengajuan yang sama. `GET /` dan `GET /lapor` merender form; `POST /lapor` menyimpan tiket. Panel staf tetap terpisah di `/admin`.

#### Pelapor pertama kali pada suatu perangkat

1. Pelapor memasukkan nomor WhatsApp.
2. Sistem menormalkan nomor ke format kanonik dan memakai pengguna Helpdesk aktif yang sudah memiliki nomor tersebut.
3. Jika nomor belum ditemukan, sistem membuat akun pelapor minimal berbasis nomor telepon di basis data Helpdesk. Pelapor tidak perlu mengisi nama atau kode OTP.
4. Browser/perangkat diikat ke akun pelapor dan form tiket ditampilkan.

Alur publik ini sengaja tidak melakukan verifikasi kepemilikan nomor. Pelapor harus memastikan nomor yang dimasukkan benar dan dapat dihubungi.

#### Pelapor yang sudah pernah menggunakan perangkat tersebut

Sistem mengenali ikatan browser yang masih valid, menampilkan nomor WhatsApp tersamarkan, dan langsung membuka bagian laporan tiket. Pelapor tidak perlu mengisi ulang informasi penginput sehingga dapat berfokus pada tujuan unit, kategori, judul, deskripsi, prioritas, entitas bisnis, dan lampiran tiket.

`owner_id` tiket selalu ditentukan oleh server dari ikatan nomor pada perangkat. Form tidak menerima identitas pelapor dari hidden input atau parameter URL.

#### Mengganti pelapor

Tautan **Ganti nomor** mengakhiri ikatan akun pada perangkat tersebut dan menghapus identitas lokalnya. Pengguna berikutnya harus memasukkan nomor WhatsApp sebelum mengirim tiket. Gunakan tindakan ini pada perangkat bersama atau ketika kontak pelapor perlu diganti.

#### Menggunakan basis data yang sudah ada

Form publik tidak memerlukan tabel reporter baru. Implementasinya memakai struktur yang sudah tersedia:

- `users.phone_normalized` menyimpan nomor WhatsApp kanonik untuk pencocokan identitas, termasuk saat migrasi data dilakukan kemudian.
- `helpdesk_reporter_bindings` menyimpan pemetaan akun pelapor yang diingat per browser/perangkat, termasuk waktu pengikatan, penggunaan terakhir, dan pencabutan. Ikatan ini bukan bukti kepemilikan nomor.
- `tickets.owner_id` menghubungkan setiap laporan ke pelapor yang benar.

Data nama dan nomor tidak disalin ke tabel tiket. Pencocokan identitas pada migrasi mendatang dapat menggunakan `users.phone_normalized`; data resmi harus memperbarui baris pengguna yang sama, bukan membuat pengguna baru. Dengan begitu riwayat tiket yang sudah ada tetap terhubung melalui `tickets.owner_id`.

### 2. Pengelolaan tiket di panel

1. Administrator menyiapkan unit, kategori masalah, status, prioritas, entitas bisnis, SLA unit, dan akun staf.
2. Tiket masuk berstatus `Open`.
3. Staf unit yang berwenang memproses tiket menjadi `In Progress` (PIC), menambahkan komentar/lampiran, lalu menutup (`Closed`) atau membatalkan (`Cancel`).
4. Policy membatasi siapa yang boleh melihat, mengubah, dan memproses tiket: pemilik dapat mengubah tiket miliknya selama masih `Open`; staf memproses tiket unitnya; Super Admin / Master Admin lintas unit.

Alur transisi staf yang sama dipakai perintah MCP staf (`proses` / `done`) di `/mcp/helpdesk/staff`.

<img src="screenshot/admin-tickets.png" width="100%" alt="Daftar tiket di panel: tab Semua Tiket dan Tiket Keluar, pengirim, unit tujuan, penanggung jawab, status, dan status SLA" />

<img src="screenshot/admin-ticket-detail.png" width="100%" alt="Detail tiket: aksi Ubah, Ubah kategori, Pindah unit, Selesai, Batalkan, data pelapor, SLA, dan komentar" />

### 3. Login staf

- Email dan kata sandi di `/admin/login`.
- OTP WhatsApp di `/phone-login` (membutuhkan gateway `WAG_URL` / `WAG_TOKEN`). Registrasi akun baru dari alur OTP hanya untuk karyawan yang cocok di berkas Talenta; pelapor publik tidak memakai halaman ini.
- Email di `/email-login`: satu email berisi kode 6 digit dan magic link. Hanya untuk akun aktif yang sudah memakai alamat itu; login email tidak membuat akun baru.

Perilaku login email, mengikuti dnd-web dan gais-web:

- Kode dan link berlaku `EMAIL_LOGIN_EXPIRES_IN` detik (default 600), sekali pakai, dan saling menghanguskan: memakai salah satunya mematikan yang lain. Permintaan baru menghanguskan kode dan link sebelumnya, begitu juga mengganti email akun.
- Kode disimpan sebagai hash bcrypt dan token link sebagai SHA-256 di tabel `email_otps`. Lima kali salah kode menghanguskan record tersebut.
- Membuka URL magic link tidak langsung login. Token ditukar lewat `wire:init` setelah halaman tampil di browser, agar pemindai link di email tidak menghabiskannya.
- Respons langkah pertama sama untuk email terdaftar, tidak terdaftar, akun nonaktif, atau akun terhapus, jadi status akun tidak bocor. Email hanya dikirim ke satu-satunya akun aktif pemilik alamat itu.
- Batas permintaan: kirim kode 3 kali per 5 menit dan 10 kali per jam per email, serta 20 kali per menit per IP; verifikasi kode 15 kali per 5 menit per email dan 20 kali per menit per IP; magic link 20 kali per menit per IP.
- Login email yang berhasil membuktikan kepemilikan alamat, jadi email ditandai terverifikasi (`email_verified_via = email_link`) bila belum punya sumber verifikasi tepercaya. Akibatnya notifikasi email ikut aktif; akses panel tidak bergantung pada tanda ini.
- Email dikirim langsung, tidak lewat antrean, agar kegagalan SMTP langsung tampil di halaman login.

Reset password dan halaman profil default Filament dimatikan. Staf mengubah data pribadi lewat My Profile Breezy yang didaftarkan panel.

## Arsitektur

```mermaid
flowchart TB
    Pelapor[Browser pelapor] --> Form[Inertia Vue / dan /lapor]
    Staf[Browser staf] --> Filament[Filament Livewire /admin]
    Staf --> PhoneLogin["/phone-login OTP"]
    Staf --> EmailLogin["/email-login OTP / magic link"]
    McpClient[Klien MCP tepercaya] --> Mcp["/mcp/helpdesk"]
    Form --> Identity[PublicReporterIdentityService]
    Form --> Submit[PublicTicketSubmissionService]
    Filament --> Policies[Policies dan Shield]
    Filament --> Workflow[TicketWorkflowService]
    Scheduler[Laravel Scheduler] --> Sla[sla:check-warnings]
    Identity --> Models[Eloquent Models]
    Submit --> Models
    Workflow --> Models
    Mcp --> Intake[helpdesk_intake]
    Intake --> Models
    Sla --> WA[WhatsApp gateway]
    Sla --> Models
    Models --> DB[(MySQL)]
    Filament --> Files[(Filesystem disk)]
    Form --> Files
```

### Peta source code

| Lokasi | Tanggung jawab |
|---|---|
| `app/Filament/Resources` | CRUD tiket, unit, kategori, status, user, entitas bisnis, SLA |
| `app/Filament/Pages` | Dashboard, My Profile, Pengaturan MCP |
| `app/Filament/Widgets` | Grafik status tiket dan SLA |
| `app/Filament/Auth` | Halaman login OTP WhatsApp dan email |
| `app/Http/Controllers` | Form publik |
| `app/Mcp` | Server dan alat MCP intake/staf |
| `app/Models` | Model dan relasi Eloquent |
| `app/Policies` | Authorization panel |
| `app/Services` | Identitas pelapor, pembuatan tiket, workflow, OTP, WhatsApp, Talenta |
| `app/Console/Commands` | `sla:check-warnings` |
| `database/migrations` | Evolusi schema |
| `database/seeders` | Data awal development |
| `routes/web.php` | Form publik, phone-login, email-login |
| `routes/ai.php` | Endpoint MCP HTTP |
| `routes/console.php` | Jadwal SLA |
| `routes/api.php` | `GET /api/user` (Sanctum) |
| `resources/js/pages/PublicTickets` | Halaman Inertia form publik |
| `tests` | Unit dan feature test |
| `docs/mcp/README.md` | Kontrak dan operasional MCP |
| `screenshot/` | Screenshot UI untuk README ini (data demo fiktif, bukan data production) |

### Desain basis data

Tabel domain utama. Tabel framework dan paket (permission, notifikasi, antrean, sesi), tabel MCP (`helpdesk_mcp_settings`, `mcp_ticket_creation_requests`, `helpdesk_identity_assertion_uses`), dan tabel lama `socialite_users` tidak digambar.

```mermaid
erDiagram
    business_entities ||--o{ users : "kontrak (Talenta)"
    units ||--o{ users : "divisi asal"
    users ||--o{ user_entities : "divisi yang ditangani"
    units ||--o{ user_entities : "entity_type = Unit"
    units ||--o{ problem_categories : punya
    units ||--o{ unit_slas : "target SLA"
    priorities ||--o{ unit_slas : per
    units ||--o{ tickets : "divisi tujuan"
    units |o--o{ tickets : "divisi pelapor"
    problem_categories ||--o{ tickets : kategori
    priorities ||--o{ tickets : prioritas
    ticket_statuses ||--o{ tickets : status
    business_entities |o--o{ tickets : "dilaporkan"
    business_entities |o--o{ tickets : "kontrak pelapor"
    users ||--o{ tickets : "pelapor (owner)"
    users |o--o{ tickets : "PIC (responsible)"
    users |o--o{ problem_categories : "PIC default"
    tickets ||--o{ comments : ""
    users ||--o{ comments : menulis
    tickets ||--o{ ticket_histories : riwayat
    ticket_statuses ||--o{ ticket_histories : ""
    users ||--o{ ticket_histories : mengubah
    users ||--o{ helpdesk_reporter_bindings : "ikatan perangkat"
    users ||--o{ email_otps : "login email"

    users {
        bigint id PK
        string name
        string email "nullable, unik"
        string email_verified_via
        string phone
        string phone_normalized "identitas pelapor"
        string identity "NIK Talenta"
        bigint unit_id FK
        bigint business_entity_id FK
        string job_title
        string job_level
        timestamp talenta_synced_at
        boolean is_active
    }
    units {
        bigint id PK
        string name
    }
    business_entities {
        bigint id PK
        string name
    }
    user_entities {
        bigint id PK
        bigint user_id FK
        string entity_type
        bigint entity_id FK
    }
    problem_categories {
        bigint id PK
        bigint unit_id FK
        string name
        bigint default_responsible_id FK "nullable, PIC default"
    }
    priorities {
        bigint id PK
        string name
    }
    ticket_statuses {
        bigint id PK
        string name "Open, In Progress, Cancel, Closed"
    }
    unit_slas {
        bigint id PK
        bigint unit_id FK
        bigint priority_id FK
        int target_hours
    }
    tickets {
        bigint id PK
        bigint unit_id FK
        bigint problem_category_id FK
        bigint priority_id FK
        bigint ticket_statuses_id FK
        bigint owner_id FK
        bigint responsible_id FK "nullable"
        bigint business_entities_id FK
        bigint reporter_business_entity_id FK
        bigint reporter_unit_id FK
        string reporter_job_title
        string title
        text description
        json supporting_attachments
        timestamp approved_at
        timestamp solved_at
        datetime sla_due_at
        boolean is_sla_met
        datetime sla_warning_sent_at
    }
    comments {
        bigint id PK
        bigint tiket_id FK
        bigint user_id FK
        text comment
        string attachments
    }
    ticket_histories {
        bigint id PK
        bigint ticket_id FK
        bigint ticket_statuses_id FK
        bigint user_id FK
    }
    helpdesk_reporter_bindings {
        bigint id PK
        bigint user_id FK
        string client_key
        string channel
        string verified_phone_hash
        timestamp last_seen_at
        timestamp revoked_at
    }
    email_otps {
        bigint id PK
        bigint user_id FK
        string email
        string otp_hash "bcrypt"
        string token_hash "SHA-256"
        timestamp expires_at
        tinyint attempt_count
    }
```

### Diagram kelas model

Relasi Eloquent di `app/Models` beserta method domain yang dipakai policy dan workflow.

```mermaid
classDiagram
    direction LR
    class User {
        +unit() BelongsTo~Unit~
        +businessEntity() BelongsTo~BusinessEntity~
        +units() MorphToMany~Unit~
        +tickets() HasMany~Ticket~
        +ticektResponsibility() HasMany~Ticket~
        +comments() HasMany~Comment~
        +hasGlobalTicketAccess() bool
        +canProcessTicketsForUnit(unitId) bool
        +isActiveTicketProcessorForUnit(unitId) bool
        +canAdministerUnit(unitId) bool
        +canAccessPanel(panel) bool
    }
    class Ticket {
        +unit() BelongsTo~Unit~
        +reporterUnit() BelongsTo~Unit~
        +problemCategory() BelongsTo~ProblemCategory~
        +priority() BelongsTo~Priority~
        +ticketStatus() BelongsTo~TicketStatus~
        +owner() BelongsTo~User~
        +responsible() BelongsTo~User~
        +businessEntity() BelongsTo~BusinessEntity~
        +reporterBusinessEntity() BelongsTo~BusinessEntity~
        +comments() HasMany~Comment~
        +ticketHistories() HasMany~TicketHistory~
        +eligibleResponsible() User
        +scopeVisibleTo(user)
        +scopeIncomingFor(user)
        +scopeOutgoingFor(user)
    }
    class Unit {
        +problemCategories() HasMany~ProblemCategory~
        +slas() HasMany~UnitSla~
        +tickets() HasMany~Ticket~
        +users() MorphToMany~User~
    }
    class TicketStatus {
        +OPEN = 1
        +IN_PROGRESS = 2
        +CANCEL = 3
        +CLOSED = 4
    }
    class Priority {
        +CRITICAL = 1
        +HIGHT = 2
        +MEDIUM = 3
        +LOW = 4
        +ENHANCEMENT = 5
    }
    class ProblemCategory {
        +defaultResponsible() BelongsTo~User~
        +eligibleDefaultResponsible() User
    }
    class UnitSla
    class BusinessEntity
    class Comment
    class TicketHistory
    class HelpdeskReporterBinding {
        +scopeActive()
    }
    class EmailOtp

    User "1" --> "*" Ticket : owner / responsible
    User "*" --> "*" Unit : units() via user_entities
    User "*" --> "1" Unit : unit() divisi asal
    User "*" --> "0..1" BusinessEntity : kontrak
    Ticket "*" --> "1" Unit : tujuan
    Ticket "*" --> "1" ProblemCategory
    Ticket "*" --> "1" Priority
    Ticket "*" --> "1" TicketStatus
    Ticket "*" --> "0..1" BusinessEntity : dilaporkan / kontrak pelapor
    Ticket "1" *-- "*" Comment
    Ticket "1" *-- "*" TicketHistory
    TicketHistory "*" --> "1" TicketStatus
    Unit "1" *-- "*" ProblemCategory
    Unit "1" *-- "*" UnitSla
    UnitSla "*" --> "1" Priority
    HelpdeskReporterBinding "*" --> "1" User
    EmailOtp "*" --> "1" User
```

## Tech stack

| Komponen | Teknologi |
|---|---|
| Backend | PHP `^8.2`, Laravel 12 |
| Admin UI | Filament 4, Livewire, Mekaya Theme |
| Form publik | Inertia.js 3, Vue 3 |
| Frontend build | Vite 8, Tailwind CSS 4, Axios |
| Database | MySQL (default `.env.example`); SQLite in-memory untuk test |
| Filesystem | Disk Laravel `local` / `public`; disk `s3` opsional |
| Auth panel | Filament login, Breezy profile, OTP WhatsApp, OTP / magic link email |
| Authorization | Filament Shield / Spatie Permission |
| MCP | Laravel MCP (`/mcp/helpdesk`, stdio `helpdesk`) |
| Notifikasi | Mail + WhatsApp gateway (`WAG_URL`, `WAG_TOKEN`) |
| Queue | Laravel Horizon 5 (`php artisan horizon`, `QUEUE_CONNECTION=redis`) |
| Logs | [opcodesio/log-viewer](https://github.com/opcodesio/log-viewer) di `/log-viewer` |
| Test | PHPUnit 11 / `php artisan test` |
| Formatter | Laravel Pint |

## Persiapan development

### Prasyarat

- Git.
- PHP 8.2 atau lebih baru.
- Composer 2.
- Node.js 20.19+ atau 22.12+ (Vite 8).
- MySQL 8+ (atau MariaDB yang kompatibel) untuk development sesuai `.env.example`.
- Extension PHP yang biasa dipakai Laravel/Filament, termasuk `curl`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, dan `xml`.

Gateway WhatsApp, SMTP, S3, dan Redis bersifat opsional untuk menjalankan form publik dan panel dasar. OTP login dan notifikasi WhatsApp membutuhkan `WAG_URL` serta `WAG_TOKEN`. Login email membutuhkan mailer yang benar-benar mengirim, misalnya SMTP.

### Clone dan dependency

```bash
git clone https://github.com/oceanspacedev/helpdesk-web.git
cd helpdesk-web
git switch staging

composer install
cp .env.example .env
php artisan key:generate
npm ci
```

Jangan menjalankan `composer update` hanya untuk setup; gunakan versi dependency yang dikunci oleh `composer.lock`.

### Konfigurasi database

`.env.example` memakai MySQL. Buat database kosong lalu sesuaikan koneksi:

```dotenv
APP_NAME=Helpdesk
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=helpdesk
DB_USERNAME=root
DB_PASSWORD=
```

#### Fresh onboarding

Pastikan `.env` menunjuk ke database development yang kosong, lalu jalankan:

```bash
php artisan migrate --seed
php artisan storage:link
```

Perintah di atas setara dengan migrasi lalu seeder terpisah:

```bash
php artisan migrate
php artisan db:seed
php artisan storage:link
```

> [!WARNING]
> Jangan menjalankan `php artisan migrate:fresh`, `migrate:refresh`, atau `db:wipe` pada database yang berisi data. Perintah tersebut menghapus tabel/data. Jangan menjalankan seeder development di production.

Seeder membuat data contoh lokal (role, unit, prioritas, status, tiket contoh, dan akun di tabel di atas). Seeder tidak untuk dijalankan berulang atau di production.

## Konfigurasi environment

Jangan commit `.env` atau credential apa pun ke Git. Daftar berikut mengikuti [`.env.example`](.env.example).

| Variabel | Wajib | Fungsi |
|---|:---:|---|
| `APP_KEY` | Ya | Kunci enkripsi Laravel; dibuat dengan `php artisan key:generate` |
| `APP_URL` | Ya | Base URL aplikasi, asset, dan tautan |
| `APP_NAME` | Tidak | Nama tampilan; default `Helpdesk` |
| `APP_ENV` / `APP_DEBUG` | Ya | Environment dan debug |
| `DB_CONNECTION` / `DB_*` | Ya | Driver dan koneksi database; default proyek `mysql` |
| `CACHE_STORE` | Ya | Cache default; `.env.example` memakai `file`. Multi-instance production untuk MCP/OTP membutuhkan store bersama, biasanya Redis |
| `FILESYSTEM_DISK` | Ya | Disk filesystem default; `local`, `public`, atau `s3` |
| `SESSION_DRIVER` | Ya | Penyimpanan session; default `file` |
| `QUEUE_CONNECTION` | Ya | Backend queue; default `redis`. Worker: `php artisan horizon` |
| `HORIZON_PATH` | Tidak | UI Horizon; default `horizon` |
| `LOG_VIEWER_ENABLED` / `LOG_VIEWER_PATH` | Tidak | UI [Log Viewer](https://github.com/opcodesio/log-viewer); default `/log-viewer` |
| `REDIS_HOST` / `REDIS_PASSWORD` / `REDIS_PORT` | Ya | Koneksi Redis untuk Horizon; cache/session/lock bersama di multi-instance |
| `MAIL_*` | Untuk email | SMTP dan identitas pengirim; `.env.example` menunjuk Mailpit |
| `MAIL_TIMEOUT` | Tidak | Timeout SMTP dalam detik; default `10` agar hang mail tidak memakan `max_execution_time` |
| `EMAIL_LOGIN_EXPIRES_IN` | Tidak | Masa berlaku kode dan magic link login email dalam detik; default `600`, minimum `60`. Tombol login Email hanya muncul bila `MAIL_MAILER` bukan `log`/`array` |
| `WAG_URL` | Untuk WhatsApp | Endpoint gateway WhatsApp (OTP, notifikasi, peringatan SLA, MCP) |
| `WAG_TOKEN` | Untuk WhatsApp | Bearer credential gateway WhatsApp |
| `WA_CONNECT_TIMEOUT` | Tidak | Timeout koneksi gateway WhatsApp; default `5` detik |
| `WA_API_TIMEOUT` | Tidak | Timeout request gateway WhatsApp; default `8` detik |
| `PHONE_OTP_LOGIN_TTL_MINUTES` | Tidak | Umur tantangan OTP login; default `5` |
| `WHATSAPP_OTP_TTL_MINUTES` | Tidak | Umur OTP WhatsApp; default `5` |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` / `AWS_DEFAULT_REGION` / `AWS_BUCKET` | Untuk S3 | Disk `s3` di `config/filesystems.php` |
| `AWS_USE_PATH_STYLE_ENDPOINT` | Tidak | Path-style S3; default `false`. Set `true` plus `AWS_ENDPOINT` untuk MinIO atau S3-compatible |
| `INERTIA_SSR_ENABLED` | Tidak | SSR Inertia; default `false` |
| `HELPDESK_MCP_LOCAL_CLIENT_ID` | Tidak | ID klien MCP stdio lokal; unik per mesin klien |
| `TALENTA_EMPLOYEE_FILE` | Tidak | Override path JSON karyawan; default `storage/app/talenta-list-employee.json` (di-ignore Git) |

Proyek ini membaca cache lewat `CACHE_STORE` di `config/cache.php`. Setelah migration, token MCP server-wide dikelola di **Admin → Pengaturan → Pengaturan MCP**. Environment MCP lama tetap didukung sebagai fallback rollout; daftar lengkapnya ada di [`docs/mcp/README.md`](docs/mcp/README.md).

Untuk local development tanpa SMTP:

```dotenv
MAIL_MAILER=log
MAIL_FROM_ADDRESS=dev@example.test
MAIL_FROM_NAME="${APP_NAME}"
```

Dengan `MAIL_MAILER=log`, tombol login Email disembunyikan. Untuk mencoba login email secara lokal, pakai Mailpit sesuai `.env.example` lalu buka kotak masuk Mailpit (biasanya `http://localhost:8025`).

Form publik dan panel dasar tetap berjalan tanpa WhatsApp. Tanpa `WAG_TOKEN`, OTP login, notifikasi WhatsApp, dan intake MCP yang membutuhkan OTP tidak dapat menyelesaikan verifikasi.

## Menjalankan aplikasi

Jalankan backend dan Vite pada terminal terpisah:

```bash
php artisan serve
```

```bash
npm run dev
```

Buka:

- Form publik: `http://127.0.0.1:8000/` atau `http://127.0.0.1:8000/lapor`
- Panel staf: `http://127.0.0.1:8000/admin`
- Login OTP WhatsApp: `http://127.0.0.1:8000/phone-login`
- Login email: `http://127.0.0.1:8000/email-login`
- Health check: `http://127.0.0.1:8000/up`

Scheduler tidak wajib untuk UI, tetapi harus dijalankan bila sedang mengembangkan peringatan SLA:

```bash
php artisan schedule:work
```

Queue default adalah `redis`. Jalankan Horizon agar notifikasi tiket tidak menumpuk:

```bash
php artisan horizon
```

Untuk frontend production-like:

```bash
npm run build
```

## MCP opsional

Endpoint HTTP:

```text
POST /mcp/helpdesk
POST /mcp/helpdesk/staff
```

Alat intake adalah `helpdesk_intake`. Stdio lokal (bukan daemon production):

```bash
php artisan mcp:start helpdesk
```

MCP **bukan** alur utama pelapor. Jangan mengarahkan pengguna akhir ke `/mcp/helpdesk` dari browser (GET mengembalikan HTTP 405; klien menginisialisasi dengan JSON-RPC `POST`).

Untuk instalasi klien, kontrak alat, token, gateway WhatsApp, percobaan ulang, dan cutover database production, lihat [dokumentasi MCP](docs/mcp/README.md). Use case terdahulu: [uc-004-mcp-create-ticket](docs/chain-of-truth/ucic/uc-004-mcp-create-ticket.md).

## Sinkron data karyawan Talenta

Karyawan holding terikat kontrak ke **satu** badan usaha dan satu divisi asal, tetapi pekerjaannya bisa menyentuh banyak badan usaha. Karena itu data dipisah dua lapis:

| Lapis | Sumber | Isi |
|---|---|---|
| Kontrak | Talenta | Badan usaha (`users.business_entity_id`), divisi asal (`users.unit_id`), jabatan, level. Dikunci di form user bila user punya ID Karyawan. |
| Penugasan | Panel Helpdesk | Divisi yang ditangani (`user_entities`). Tiket masuk ke semua anggota aktif divisi tujuan, apa pun badan usahanya. |

Tiket menyimpan keduanya terpisah: `reporter_business_entity_id` (badan usaha kontrak pelapor, bagian "Dari" seperti kop surat) dan `business_entities_id` (badan usaha yang dilaporkan, dipilih pelapor dengan isian awal badan usaha kontraknya).

Sinkron dijalankan dari menu **Organisasi → Users → Import JSON (Talenta)** (khusus `Super Admin`/`Master Admin`; matikan "Simpan perubahan" untuk preview) atau dari CLI:

```bash
php artisan helpdesk:sync-employees /path/ke/list-employee.json          # preview, tidak menyimpan
php artisan helpdesk:sync-employees /path/ke/list-employee.json --apply  # simpan
```

- Akun dicocokkan per NIK (`users.identity`), lalu per nomor HP. Karyawan tanpa akun dibuat dengan role `User`.
- `branch` → badan usaha, `organization` → divisi, `job` → jabatan, `title` → level. Nama yang belum ada dibuat otomatis.
- Divisi Talenta yang namanya berbeda dari divisi Helpdesk dipetakan di `services.talenta.division_aliases` (default `IT SUPPORT` → `IT`, `HUMAN CAPITAL` → `HR`). Selain itu, ganti nama divisi atau badan usaha di panel agar cocok sebelum `--apply`.
- Akun yang punya NIK tetapi tidak ada lagi di file dinonaktifkan. `Super Admin` dan `Master Admin` tidak disentuh.
- Hanya divisi yang punya kategori masalah yang muncul sebagai tujuan laporan.

### Berkas karyawan untuk login OTP WhatsApp

Registrasi akun dari `/phone-login` dan argumen default `helpdesk:sync-employees` membaca berkas ekspor Talenta di `TALENTA_EMPLOYEE_FILE`, default `storage/app/talenta-list-employee.json`.

Cara termudah memperbaruinya tanpa terminal: buka **Users → Import JSON (Talenta)**, upload ekspor terbaru, aktifkan **Simpan perubahan**, lalu klik **Proses**. Selain menyinkronkan akun, berkas yang di-upload langsung menggantikan berkas di atas. Mode preview tidak mengubah berkas. Tombol ini hanya tampil untuk admin global.

> [!CAUTION]
> Berkas ini berisi data pribadi karyawan (email, nomor HP, tanggal lahir, status pernikahan, status pajak). **Jangan pernah meng-commit-nya.** `storage/app` dan `/talenta-list-employee.json` sudah di-ignore Git. Perbarui berkas lewat tombol import di panel atau salin langsung ke server (misalnya lewat `scp`), bukan lewat repositori.

## Scheduler SLA

Scheduler menjalankan `sla:check-warnings` **setiap 15 menit**. Command mencari tiket aktif yang `sla_due_at`-nya tersisa ≤ 2 jam dan belum dikirimi peringatan, lalu memberi notifikasi Filament dan WhatsApp kepada PIC atau staf unit.

### Production scheduler

Laravel scheduler harus dipanggil setiap menit agar jadwal 15 menit berjalan:

```cron
* * * * * cd /absolute/path/to/helpdesk-web && /absolute/path/to/php artisan schedule:run >> storage/logs/scheduler.log 2>&1
```

Temukan binary PHP dengan `command -v php`. Pantau dan rotasi log scheduler. Command operasional lain:

| Command | Fungsi |
|---|---|
| `php artisan sla:check-warnings` | Cek dan kirim peringatan SLA sekarang |
| `php artisan helpdesk:sync-employees` | Preview/simpan sinkron data karyawan Talenta |
| `php artisan schedule:work` | Menjalankan scheduler di proses foreground (local) |
| `php artisan optimize:clear` | Menghapus cache config, route, event, dan view |

## Workflow development

Konvensi branch (sama seperti dnd-web):

- `staging`: branch integrasi. Semua perubahan masuk ke sini dulu dan diuji di staging.
- `main`: branch release untuk production. `main` diproteksi: tidak menerima push langsung, dan hanya menerima pull request **dari `staging`** (check GitHub Actions `PR dari staging` di [`.github/workflows/pr-to-main.yml`](.github/workflows/pr-to-main.yml) wajib hijau, berlaku juga untuk admin). Tidak ada perubahan yang masuk `main` tanpa lewat staging dulu.
- Branch pekerjaan: buat dari `staging` dengan pola `feat/<scope>`, `fix/<scope>`, atau `docs/<scope>`; PR ke `staging`, bukan ke `main`.

Alurnya: branch pekerjaan → PR ke `staging` → uji di staging → PR `staging` → `main`.

### Memulai pekerjaan

```bash
git switch staging
git pull --ff-only origin staging
git switch -c feat/<nama-fitur>
```

Gunakan scope kecil dan satu tujuan per branch.

### Lokasi perubahan berdasarkan jenis fitur

| Kebutuhan | Lokasi umum |
|---|---|
| Tambah/ubah tabel | `database/migrations` dan `app/Models` |
| Identitas pelapor / form publik | `app/Http/Controllers/PublicTicketController.php`, `app/Services/Integrations`, `resources/js/pages/PublicTickets` |
| Logika tiket reusable | `app/Services` (`TicketWorkflowService`, layanan integrasi) |
| Hak akses panel | `app/Policies`, seeder permission/role, scoped query Filament |
| Fitur panel web | `app/Filament/Resources`, `Pages`, atau `Widgets` |
| MCP | `app/Mcp` dan `docs/mcp/README.md` |
| Background operation | `app/Console/Commands` dan `routes/console.php` |
| Verifikasi | `tests/Unit` atau `tests/Feature` |

### Aturan implementasi

- Jangan mengubah schema melalui migration yang sudah pernah berjalan di shared environment; tambahkan migration baru.
- Identitas pelapor selalu diselesaikan di server; jangan menerima `owner_id` dari klien.
- Perubahan konfigurasi wajib diikuti update `.env.example` dan README tanpa memasukkan secret.
- Tambahkan test regresi untuk setiap perbaikan bug pada form publik, policy, MCP, atau SLA.

### Definition of Done

Sebelum membuka PR, pastikan:

- Scope bisnis dan aktor yang boleh mengakses sudah jelas.
- Test terkait ditambahkan dan `php artisan test` berhasil.
- `npm run build` berhasil bila ada perubahan frontend/Filament asset.
- Tidak ada `.env`, token, dump database, data pribadi, atau credential di commit.

## Testing dan quality check

`phpunit.xml` mengunci test ke SQLite in-memory (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`), `CACHE_STORE=array`, session array, queue sync, dan mail array. Full test suite tidak menggunakan database development dari `.env`.

```bash
php artisan test
php artisan test --filter=NamaTest
```

Quality check yang didukung repositori:

```bash
vendor/bin/pint --test
composer validate --strict
npm run build
```

## Batasan dan technical debt

Daftar ini adalah batas perilaku aktual, bukan fitur yang dijanjikan:

1. **Form publik tidak membuktikan kepemilikan nomor.** Siapa pun yang mengetahui atau mengetik nomor dapat diikat ke akun pelapor pada perangkat itu.
2. **Akun seed `user@cs.com` tidak mendapat role `User`.** Role `User` ada di `RoleSeeder`; `UserSeeder` membuat user itu tanpa `syncRoles`.
3. **Role `Master Admin` tidak memiliki akun seed.** Hanya Super Admin, Admin Unit, dan Staff Unit yang punya login contoh.
4. **MCP bukan produk utama.** Dokumentasi operasional MCP tetap berbahasa Inggris di `docs/mcp/README.md` dan tidak diganti oleh README ini.
5. **Tidak ada REST API domain.** Jangan mengasumsikan klien mobile/API terpisah; satu-satunya route `routes/api.php` adalah `GET /api/user`.
6. **Queue default `redis` + Horizon.** Notifikasi tiket mengantri di `notifications`. Tanpa Redis dan `php artisan horizon`, notifikasi tidak terkirim. Tes memakai `QUEUE_CONNECTION=sync` di `phpunit.xml`.
7. **Multi-instance membutuhkan store bersama.** Komentar di `.env.example` menyatakan Redis diperlukan agar draft MCP, OTP, registrasi, rate limit, dan lock terbagi.
8. **Registrasi panel dan reset password dimatikan; login Google dicabut.** Onboarding staf tetap manual / seeder / Talenta OTP. Tabel lama `socialite_users` masih ada dan tidak dipakai lagi.

## Troubleshooting

### Composer menolak versi PHP

Pastikan CLI memakai PHP 8.2 atau lebih baru:

```bash
php -v
composer check-platform-reqs
```

### Vite manifest not found

```bash
npm ci
npm run build
php artisan optimize:clear
```

### Perubahan config atau route tidak terbaca

```bash
php artisan optimize:clear
```

### Lampiran atau asset `/storage` 404

```bash
php artisan storage:link
```

### Form publik tidak mengingat pelapor

Periksa cookie `helpdesk_reporter_device`, session, dan bahwa `APP_URL` cocok dengan URL yang dibuka. Ganti nomor memakai tautan **Ganti nomor** pada form.

### OTP WhatsApp atau notifikasi tidak terkirim

1. Isi `WAG_URL` dan `WAG_TOKEN`.
2. Pastikan nomor pelapor/staf valid (format `08…` atau `62…`).
3. Periksa `storage/logs/laravel.log`.
4. Untuk peringatan SLA, jalankan `php artisan sla:check-warnings` dan pastikan scheduler aktif.

### Kode login email tidak terkirim

1. Pastikan `MAIL_MAILER` bukan `log`/`array` dan `MAIL_FROM_ADDRESS` terisi; tanpa itu tombol Email tidak muncul.
2. Pastikan email tersebut dipakai tepat satu akun aktif. Email tak dikenal atau akun nonaktif sengaja tidak dikirimi kode, tanpa pesan berbeda.
3. Periksa `storage/logs/laravel.log` untuk pesan `Gagal mengirim email login.`

### `/mcp/helpdesk` 405 di browser

Itu disengaja. Endpoint hanya menerima `POST` JSON-RPC dari klien MCP. Lihat [`docs/mcp/README.md`](docs/mcp/README.md).

### Login panel ditolak

Pastikan user `is_active` dan tidak terhapus. Akses panel tidak lagi bergantung pada verifikasi email. Untuk login kata sandi, pastikan akun punya kata sandi; akun tanpa kata sandi masuk lewat `/phone-login` atau `/email-login`.

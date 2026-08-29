<img src="screenshot/create-ticket.png" width="100%" />

# Helpdesk Laravel

Repositori Helpdesk Laravel menyediakan sistem helpdesk berbasis web menggunakan [**Laravel 12**](https://laravel.com) dan [**Filament 4**](https://github.com/filamentphp/filament). Aplikasi ini memungkinkan pengguna mengajukan pertanyaan, meminta bantuan, atau melaporkan masalah terkait produk dan layanan perusahaan.

Repositori ini berisi kode sumber lengkap aplikasi Helpdesk. Struktur aplikasinya dirancang agar sistem mudah dikembangkan, disesuaikan, dan dikelola sesuai kebutuhan organisasi.

Fitur utama Helpdesk Laravel meliputi:

1. **Pembuatan tiket:** pengguna dapat mengirim pertanyaan, permintaan bantuan, atau laporan masalah.
2. **Pengelolaan tiket:** admin dapat melihat, menugaskan, memproses, dan menutup tiket.
3. **Prioritas tiket:** tingkat urgensi tiket dapat ditentukan agar penanganannya lebih terarah.
4. **Riwayat dan pelacakan:** aktivitas dan percakapan tiket dicatat untuk kebutuhan pemantauan dan audit.

## Prasyarat Akun dan Pengajuan Tiket melalui MCP

Aplikasi menyediakan satu alat MCP yang netral terhadap vendor, yaitu `helpdesk_intake`. Alat ini memiliki dua alur bisnis:

1. Menggunakan akun pelapor yang sudah ada dan memenuhi syarat, kemudian membuat tiket.
2. Membuat akun pelapor yang diperlukan terlebih dahulu, lalu melanjutkan pengajuan yang sama untuk membuat tiket.

Pembuatan akun hanya tersedia sebagai prasyarat pembuatan tiket. MCP tidak menyediakan pencarian tiket, komentar, perubahan tiket, aksi alur kerja, atau fungsi administrasi.

Codex, Atlas yang meneruskan pesan WhatsApp, aplikasi AI lain, dan penghubung kanal lainnya bertindak sebagai klien atau gateway MCP generik. Semua klien menggunakan kontrak yang sama dan tidak mengubah perilaku Helpdesk.

Kepemilikan tiket tetap terikat pada nomor WhatsApp yang sudah diverifikasi dan dicocokkan dengan direktori pengguna Helpdesk atau Talenta. Kanal generik tanpa identitas tepercaya akan melakukan verifikasi melalui OTP WhatsApp apabila mengirimkan `external_user_id` yang stabil. Gateway webhook WhatsApp tepercaya dapat menggunakan pernyataan identitas bertanda tangan untuk satu peristiwa.

Jika nomor terverifikasi tidak ditemukan pada kedua direktori, MCP akan meminta persetujuan pendaftaran dan nama lengkap yang diketik langsung oleh pengguna. Sistem kemudian membuat akun khusus nomor telepon dengan email dan kata sandi bernilai `null` secara atomik, lalu melanjutkan pengajuan tiket semula. Jika pengguna menolak pembuatan akun, proses dibatalkan tanpa membuat akun atau tiket.

Klien MCP langsung yang tidak mengirimkan `external_user_id` harus melakukan verifikasi OTP pada setiap pengajuan baru dan tidak mendapatkan ikatan identitas pelapor permanen.

Panduan instalasi, kontrak alat, kebutuhan gateway, mekanisme percobaan ulang, dan catatan keamanan tersedia di [dokumentasi MCP](docs/mcp/README.md).

### Mulai Cepat MCP Produksi

Titik akses MCP produksi berjalan di dalam aplikasi web Laravel yang sama sehingga tidak memerlukan daemon MCP terpisah. Pasang aplikasi di belakang HTTPS, jalankan migrasi, lalu buka **Admin → Pengaturan → Pengaturan MCP** untuk menambahkan token bearer dan mengatur proses pengajuan. Gateway OTP WhatsApp tetap dikelola sebagai konfigurasi penerapan biasa.

Titik akses yang dipublikasikan:

```text
https://helpdesk.example.com/mcp/helpdesk
```

Panduan siap salin tersedia untuk:

- [Codex](docs/mcp/README.md#connect-codex)
- [Cursor](docs/mcp/README.md#connect-cursor)
- [Google Antigravity](docs/mcp/README.md#connect-google-antigravity)
- [Atlas yang berjalan langsung/Docker dan persyaratan gateway WhatsApp](docs/mcp/README.md#connect-atlas-or-a-whatsapp-gateway)
- [Klien Streamable HTTP lainnya](docs/mcp/README.md#connect-another-mcp-client)

Daftar periksa produksi lengkap tersedia pada [panduan mulai cepat MCP produksi](docs/mcp/README.md#production-quickstart). Peningkatan basis data yang sudah digunakan juga wajib mengikuti [panduan peralihan aman](docs/mcp/README.md#install).

Setelah klien menampilkan alat `helpdesk_intake`, coba instruksi berikut:

```text
Buat laporan helpdesk: printer kasir tidak bisa mencetak sejak pagi.
```

Perintah `/helpdesk printer kasir tidak bisa mencetak` juga dapat memulai pengajuan apabila aplikasi klien meneruskannya sebagai pesan teks biasa. Menghubungkan server MCP tidak otomatis memasang perintah garis miring (`slash command`) pada setiap klien AI. Gunakan instruksi bahasa alami apabila `/helpdesk` ditangani sebagai perintah internal oleh antarmuka klien.

<hr/>

## Desain Basis Data

<img src="screenshot/database-design.png" width="100%" />

<hr/>

## Diagram UML

<img src="screenshot/uml.png" width="100%" />

<hr/>

## Persyaratan

- PHP 8.2 atau lebih baru
- Laravel 12.x
- Filament 4.x
- Basis data, misalnya MySQL, PostgreSQL, atau SQLite
- Server web, misalnya Apache, Nginx, atau IIS

<hr/>

## Instalasi

Perintah berikut hanya untuk pengembangan lokal. Peningkatan produksi tidak boleh menggunakan penerapan bergulir dengan versi aplikasi campuran atau menjalankan pengisi data uji. Ikuti prosedur jendela pemeliharaan, audit migrasi, inisialisasi admin yang sudah ditinjau, penghapusan sesi, dan uji cepat pada [panduan instalasi produksi](docs/mcp/README.md#install).

1. Pasang [Composer](https://getcomposer.org/download).
2. Kloning repositori: `git clone https://github.com/CS-BusinessDev/web-helpdesk.git`.
3. Pasang dependensi PHP: `composer install`.
4. Siapkan konfigurasi: `cp .env.example .env`.
5. Buat kunci aplikasi: `php artisan key:generate`.
6. Buat basis data dan sesuaikan konfigurasinya.
7. Jalankan migrasi basis data: `php artisan migrate`.
8. Jalankan pengisian data pengembangan: `php artisan db:seed`.
9. Buat tautan simbolis untuk direktori penyimpanan: `php artisan storage:link`.
10. Jalankan server pengembangan: `php artisan serve`.

<hr/>

## Akun Uji Pengembangan

Kredensial berikut hanya dibuat oleh proses pengisian data pengembangan. Jangan membuat atau mempertahankan kata sandi tetap ini pada lingkungan produksi.

### Super Admin

> - Email: superadmin@cs.com
> - Kata sandi: password

### Admin Unit

> - Email: adminunit@cs.com
> - Kata sandi: password

### Staff Unit

> - Email: staffunit@cs.com
> - Kata sandi: password

### Pengguna Umum

> - Email: user@cs.com
> - Kata sandi: password

## Pratinjau Super Admin

<img src="screenshot/super-admin.png" width="100%" />

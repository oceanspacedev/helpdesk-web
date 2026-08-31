<img src="screenshot/create-ticket.png" width="100%" />

# Helpdesk Laravel

Repositori Helpdesk Laravel menyediakan sistem helpdesk berbasis web menggunakan [**Laravel 12**](https://laravel.com) dan [**Filament 4**](https://github.com/filamentphp/filament). Aplikasi ini memungkinkan pengguna mengajukan pertanyaan, meminta bantuan, atau melaporkan masalah terkait produk dan layanan perusahaan.

Repositori ini berisi kode sumber lengkap aplikasi Helpdesk. Struktur aplikasinya dirancang agar sistem mudah dikembangkan, disesuaikan, dan dikelola sesuai kebutuhan organisasi.

Fitur utama Helpdesk Laravel meliputi:

1. **Form publik:** pengguna dapat mengirim pertanyaan, permintaan bantuan, atau laporan masalah tanpa masuk ke panel admin.
2. **Pengelolaan tiket:** admin dapat melihat, menugaskan, memproses, dan menutup tiket.
3. **Prioritas tiket:** tingkat urgensi tiket dapat ditentukan agar penanganannya lebih terarah.
4. **Riwayat dan pelacakan:** aktivitas dan percakapan tiket dicatat untuk kebutuhan pemantauan dan audit.

## Alur Utama: Form Publik

Pengajuan tiket utama dilakukan melalui form publik pada dua alamat berikut:

```text
https://helpdesk.example.com/
https://helpdesk.example.com/lapor
```

Kedua alamat menampilkan pengalaman pengajuan yang sama. Panel pengelolaan staf tetap tersedia terpisah di `/admin`.

### Pelapor pertama kali pada suatu perangkat

1. Pelapor memasukkan nomor WhatsApp.
2. Sistem menormalkan nomor ke format kanonik dan menggunakan pengguna Helpdesk aktif yang sudah memiliki nomor tersebut.
3. Jika nomor belum ditemukan, sistem otomatis membuat akun pelapor minimal berbasis nomor telepon langsung di basis data Helpdesk. Pelapor tidak perlu mengisi nama atau kode OTP.
4. Browser/perangkat diikat ke akun pelapor dan form tiket langsung ditampilkan.

Alur publik ini sengaja tidak melakukan verifikasi kepemilikan nomor. Nomor WhatsApp berfungsi sebagai identitas pencocokan sekaligus kontak agar tim Helpdesk dapat menghubungi pelapor setelah tiket dibuat. Pelapor harus memastikan nomor yang dimasukkan benar dan dapat dihubungi.

### Pelapor yang sudah pernah menggunakan perangkat tersebut

Sistem mengenali ikatan browser yang masih valid, menampilkan nomor WhatsApp tersamarkan, dan langsung membuka bagian laporan tiket. Pelapor tidak perlu mengisi ulang informasi penginput sehingga dapat berfokus pada tujuan unit, kategori, judul, deskripsi, prioritas, entitas bisnis, dan lampiran tiket.

`owner_id` tiket selalu ditentukan oleh server dari ikatan nomor pada perangkat. Form tidak menerima identitas pelapor dari hidden input atau parameter URL.

### Mengganti pelapor

Tautan **Ganti nomor** mengakhiri ikatan akun pada perangkat tersebut dan menghapus identitas lokalnya. Pengguna berikutnya harus memasukkan nomor WhatsApp sebelum mengirim tiket. Gunakan tindakan ini pada perangkat bersama atau ketika kontak pelapor perlu diganti.

### Menggunakan basis data yang sudah ada

Form publik tidak memerlukan migration atau tabel reporter baru. Implementasinya memaksimalkan struktur yang sudah tersedia:

- `users.phone_normalized` menyimpan nomor WhatsApp kanonik untuk pencocokan identitas, termasuk saat migrasi data dilakukan kemudian.
- `helpdesk_reporter_bindings` menyimpan pemetaan akun pelapor yang diingat per browser/perangkat, termasuk waktu pengikatan, penggunaan terakhir, dan pencabutan. Ikatan ini bukan bukti kepemilikan nomor.
- `tickets.owner_id` menghubungkan setiap laporan ke pelapor yang benar.

Data nama dan nomor tidak disalin ke tabel tiket. Pencocokan identitas pada migrasi mendatang dapat menggunakan `users.phone_normalized`; data resmi harus memperbarui baris pengguna yang sama, bukan membuat pengguna baru. Dengan begitu riwayat tiket yang sudah ada tetap terhubung melalui `tickets.owner_id`.

## Integrasi MCP Opsional/Legacy

Kode MCP tetap dipertahankan untuk integrasi operasional yang masih memerlukannya, tetapi bukan lagi alur utama pengguna. Endpoint `/mcp/helpdesk` dan alat `helpdesk_intake` dapat digunakan oleh klien atau gateway tepercaya untuk membuat tiket melalui kontrak integrasi yang sudah ada.

Panduan instalasi, kontrak alat, konfigurasi klien, kebutuhan gateway, mekanisme percobaan ulang, dan catatan keamanan tersedia di [dokumentasi MCP](docs/mcp/README.md). Dokumentasi alur terdahulu juga tersedia pada [use case MCP](docs/chain-of-truth/ucic/uc-004-mcp-create-ticket.md).

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

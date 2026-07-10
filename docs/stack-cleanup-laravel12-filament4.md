# Stack Cleanup — Laravel 12 + Filament 4 + Tailwind v4 + Vite

Dokumen ini mencatat langkah-langkah pembersihan yang sudah dilakukan pada proyek **web-sam** untuk memastikan basis kode sepenuhnya selaras dengan stack modern:

| Komponen | Versi target |
|---|---|
| Laravel Framework | **12.41.1** |
| Filament | **v4** |
| Tailwind CSS | **v4** (CSS-first, plugin `@tailwindcss/vite`) |
| Vite | **6** + `laravel-vite-plugin` |

Dokumen ini bisa dipakai oleh siapa pun (tim lain / proyek lain) sebagai **checklist verifikasi** bahwa proyek benar-benar berjalan di Laravel 12 & Filament 4, dan tidak menyisakan artefak dari Laravel 9/10/11 atau Filament v3.

---

## 0. Verifikasi versi stack (jalankan dulu)

```bash
php artisan --version          # => Laravel Framework 12.41.1
php artisan about              # kolom Packages: filament, forms, tables, notifications, ...
composer show filament/filament | grep ^versions   # => v4.x
cat composer.json | grep -E '"laravel/framework"|"filament/filament"'
node -v && npm ls tailwindcss @tailwindcss/vite vite
```

Pada kondisi yang sudah dibersihkan, output `php artisan about` memuat:
`Packages  filament, forms, notifications, support, tables, actions, infolists, schemas, widgets`
(kehadiran `schemas` dan `infolists` adalah penanda Filament **v4**).

---

## 1. Tailwind v4 + Vite — migrasi ke setup standar Laravel 12

> **Konteks:** Sebelumnya pipeline Tailwind memakai PostCSS (`postcss.config.js` + `@tailwindcss/postcss`) dan `@tailwindcss/vite` terpasang tapi **tidak terdaftar** di `vite.config.js`. Setup standar Laravel 12 + Tailwind v4 justru memakai plugin `@tailwindcss/vite` (CSS-first, tanpa JS config).

### Yang dilakukan

1. **`vite.config.js`** — daftarkan plugin `@tailwindcss/vite`:
   ```js
   import tailwindcss from '@tailwindcss/vite'

   export default defineConfig({
     plugins: [
       tailwindcss(),
       laravel({ input: [...], refresh: true }),
       VitePWA({ ... }),
     ],
     ...
   })
   ```

2. **Hapus file:**
   - `postcss.config.js` — redundant dengan plugin `@tailwindcss/vite`.
   - `tailwind.config.js` — dead under Tailwind v4 (CSS-first via `@theme`/`@plugin`/`@source`). File ini bahkan menunjuk `vendor/laravel/jetstream` yang **tidak terpasang** dan memakai font `Nunito` yang tidak dipakai. Tidak ada direktif `@config` di mana pun, jadi file ini tidak pernah di-load.

3. **`package.json` — hapus devDependencies yang redundant:**
   - `@tailwindcss/postcss`
   - `autoprefixer` (sudah built-in Tailwind v4 / Lightning CSS)
   - `postcss-import` (Tailwind v4 menangani `@import` native)
   - `postcss`

4. **Pertahankan** (jangan dihapus — aktif dipakai):
   - `@tailwindcss/vite`, `tailwindcss`, `vite`, `laravel-vite-plugin`, `vite-plugin-pwa`
   - `@tailwindcss/forms` & `@tailwindcss/typography` — dipakai via direktif `@plugin '@tailwindcss/forms';` dan `@plugin '@tailwindcss/typography';` di `vendor/apriansyahrs/mekaya-theme/resources/css/base.css`.

### Verifikasi

```bash
npm install
npm run build      # harus sukses; menghasilkan public/build/assets/*.css + manifest.webmanifest
ls public/build/assets/ | grep -E '\.css$'   # ada app-*.css dan theme-*.css
```

---

## 2. Broadcasting + file mati (broadcasting tidak dipakai sama sekali)

> **Konteks:** Tidak ada `ShouldBroadcast`, tidak ada `Broadcast::` aktif, Echo/Pusher dikomentari, `BROADCAST_CONNECTION=log`, dan `withRouting()` di `bootstrap/app.php` tidak menerima argumen `channels:` sehingga `routes/channels.php` tidak pernah di-load.

### Yang dilakukan

- **Hapus:**
  - `routes/channels.php` — tidak ter-load (tidak ada `channels:` di `withRouting()`).
  - `config/broadcasting.php` — framework Laravel 12 menyediakan default config broadcasting; file publish tidak wajib. Hapus aman.
  - blok `'broadcasting' => [...]` di `config/filament.php` (stub Laravel Echo / `VITE_PUSHER_*` yang dikomentar).
  - `resources/js/bootstrap.js` — 100% boilerplate Echo/Pusher era Laravel-Mix (`MIX_PUSHER_APP_KEY`).
  - baris `import './bootstrap'` di `resources/js/app.js` (harus dihapus bersamaan, jika tidak build gagal resolve modul).
- **`.env.example`** — `BROADCAST_CONNECTION=log` → `BROADCAST_CONNECTION=null`.

### Verifikasi

```bash
ls routes/                     # hanya api.php, console.php, web.php (tidak ada channels.php)
ls config/ | grep broadcasting # kosong
php artisan config:cache       # sukses tanpa error (framework default menggantikan)
php artisan tinker --execute="var_export(config('broadcasting.default'));"   # resolve via default
```

> Catatan: `.env` lokal (gitignored) mungkin masih `BROADCAST_CONNECTION=log`. Update manual ke `null` bila ingin konsisten — broadcasting inert kok (tidak ada yang memicu).

---

## 3. File skeleton legacy (dihapus dari skeleton sejak Laravel 11)

### Yang dilakukan

- **Hapus `server.php`** — dihapus dari skeleton Laravel 11+. `php artisan serve` tidak lagi membutuhkannya. Deploy target proyek ini FrankenPHP/Caddy (Octane).
- **Hapus `public/web.config`** — konfigurasi IIS URL Rewrite. Tidak relevan untuk deploy non-IIS.

### Verifikasi

```bash
ls server.php public/web.config 2>&1   # "No such file or directory"
php artisan serve                     # tetap jalan tanpa server.php
```

---

## 4. Middleware — modernisasi

> **Konteks:** Laravel 11+ menghapus stub middleware custom dari skeleton. Beberapa class custom di proyek ini hanya mendeklarasikan ulang default framework (redundan), dan `AuthenticateSession` sudah deprecated sejak Laravel 10 tanpa konsumen (`logoutOtherDevices` tidak dipakai).

### Yang dilakukan

1. **Hapus 3 wrapper redundan** + swap ke class framework di `bootstrap/app.php`:
   - `app/Http/Middleware/TrimStrings.php` → `\Illuminate\Foundation\Http\Middleware\TrimStrings::class` (framework sudah `except` password — identik).
   - `app/Http/Middleware/PreventRequestsDuringMaintenance.php` → `\Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class` (`$except` kosong — identik).
   - `app/Http/Middleware/TrustProxies.php` → di-collapse jadi one-liner di `bootstrap/app.php` (bitmask header AWS-ELB dipertahankan):
     ```php
     $middleware->trustProxies(
         at: '*',
         headers: Request::HEADER_X_FORWARDED_FOR
             | Request::HEADER_X_FORWARDED_HOST
             | Request::HEADER_X_FORWARDED_PORT
             | Request::HEADER_X_FORWARDED_PROTO
             | Request::HEADER_X_FORWARDED_AWS_ELB,
     );
     ```

2. **Hapus `AuthenticateSession`** dari 3 file:
   - `bootstrap/app.php` (grup `web(append: [...])`)
   - `routes/web.php` (route `phone-login`, hapus juga `use` import-nya)
   - `app/Providers/Filament/AdminPanelProvider.php` (hapus `use` import + entry di `->middleware([...])`)

   Global middleware `use([...])` sekarang memakai FQCN framework:
   ```php
   $middleware->use([
       \Illuminate\Http\Middleware\TrustProxies::class,
       \Illuminate\Http\Middleware\HandleCors::class,
       \Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class,
       \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
       \Illuminate\Foundation\Http\Middleware\TrimStrings::class,
       \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
   ]);
   ```

### Verifikasi

```bash
ls app/Http/Middleware/         # Authenticate.php, LogRoute.php, RateLimitUploads.php (3 wrapper hilang)
grep -rn "AuthenticateSession" app/ bootstrap/ routes/ config/   # kosong
php artisan route:list          # sukses, route phone-login masih terdaftar
```

> Yang **tetap dipertahankan**: `app/Http/Middleware/Authenticate.php` (custom redirect 401 JSON untuk API + redirect ke `filament.admin.auth.login` untuk web), `LogRoute.php`, `RateLimitUploads.php` — aktif dipakai.

---

## 5. Filament v3 → v4 — migrasi API deprecated

### Yang dilakukan

- **`->reactive()` → `->live()`** di 8 file (35 pemanggilan):
  - `app/Filament/Resources/Regions/RegionResource.php`
  - `app/Filament/Resources/Clusters/ClusterResource.php`
  - `app/Filament/Resources/Outlets/OutletResource.php`
  - `app/Filament/Resources/Roles/RoleResource.php`
  - `app/Filament/Resources/Users/UserResource.php` (juga perbarui komentar stale)
  - `app/Filament/Resources/Registers/RegisterResource.php`
  - `app/Filament/Resources/Users/RelationManagers/OutletsRelationManager.php`
  - `app/Filament/Resources/PlanVisits/PlanVisitResource.php`

  Di Filament v4, `->reactive()` adalah deprecated wrapper yang hanya memanggil `->live()` secara internal. Kode sudah memakai `->live()` di ~20 tempat lain, jadi ini menyamakan konsistensi.

### Verifikasi

```bash
grep -rnF -- "->reactive()" app/Filament/    # harus kosong
grep -rnF -- "->live()" app/Filament/ | wc -l
```

> Penanda Filament **v4** lain pada `php artisan about`: package `schemas` dan `infolists` hadir. Namespace form sekarang `Filament\Schemas\Schema` (bukan `Filament\Forms\Form` v3).

---

## 6. Dependency & cosmetic

### Yang dilakukan

- **`composer remove laravel/boost`** — dev tool optimasi CLI yang tidak ter-wire ke app (tidak ada provider di `bootstrap/providers.php`, tidak ada `config/boost.php`, tidak dipanggil di script deploy).
- **`.gitattributes`** — hapus 3 baris `linguist-vendored` berbasis glob (`*.css`, `*.scss`, `*.js`) yang menandai **source proyek sendiri** sebagai vendored di GitHub language stats. Sisa: `* text=auto` + `CHANGELOG.md export-ignore`.

### Verifikasi

```bash
composer show laravel/boost 2>&1 | head -1   # "Package laravel/boost not found"
grep boost composer.json                     # kosong
cat .gitattributes
```

---

## 7. Verifikasi menyeluruh (end-to-end)

```bash
# 1. Dependency sync
composer install
npm install

# 2. Build frontend
npm run build

# 3. Cache & boot
php artisan optimize:clear
php artisan config:cache        # sukses tanpa error
php artisan route:list          # semua route terdaftar, app boot
php artisan about               # Laravel 12.41.1 + Filament v4 packages

# 4. Test suite
php artisan test
```

### Hasil verifikasi pada commit ini
- `npm run build`: ✅ sukses, CSS `app` + `theme` ter-generate, PWA service worker OK.
- `php artisan about`: ✅ Laravel 12.41.1, packages `filament, forms, notifications, support, tables, actions, infolists, schemas, widgets`.
- `php artisan test`: ✅ **242 passed, 2 skipped, 1 failed**.
- ⚠️ Satu failure `DataOverviewPerformanceTest` (query count 16 vs batas 13) adalah **pre-existing** — diverifikasi gagal identik di clean HEAD sebelum cleanup. Bukan akibat perubahan ini. Kemungkinan regression dari komit "hierarchical organizational grant resolution".

---

## Ringkasan diff

- **10 file dihapus:** `postcss.config.js`, `tailwind.config.js`, `routes/channels.php`, `config/broadcasting.php`, `resources/js/bootstrap.js`, `server.php`, `public/web.config`, `app/Http/Middleware/TrustProxies.php`, `app/Http/Middleware/TrimStrings.php`, `app/Http/Middleware/PreventRequestsDuringMaintenance.php`.
- **16 file dimodifikasi:** `vite.config.js`, `package.json`, `package-lock.json`, `.env.example`, `.gitattributes`, `config/filament.php`, `bootstrap/app.php`, `routes/web.php`, `app/Providers/Filament/AdminPanelProvider.php`, `composer.json`, `composer.lock`, `resources/js/app.js`, + 8 file Filament (migrasi `->reactive()` → `->live()`).

## Yang sengaja TIDAK diubah (tetap dipertahankan)

- `postcss.config.js` & dep PostCSS — *sudah dihapus* karena bermigrasi ke `@tailwindcss/vite`. Sebelum cleanup, pipeline PostCSS adalah yang aktif; jangan pernah menghapusnya tanpa mendaftarkan `@tailwindcss/vite` terlebih dahulu.
- `@tailwindcss/forms` & `@tailwindcss/typography` — dipakai via `@plugin` di tema mekaya.
- `app/Http/Middleware/Authenticate.php` — custom redirect 401 JSON + redirect Filament login.
- `config/cors.php` — dipakai `HandleCors` di global stack.
- `config/app.php` (key/cipher) — masih dibaca framework; bukan legacy.
- Override tema mekaya (`resources/views/vendor/filament-panels/...`) — aktif. Catatan potensi divergence render-hook v4 (bukan penghapusan; re-port hook bila ada plugin topbar yang hilang).
- Semua `composer.json` require/require-dev lain — terverifikasi terpakai (Maatwebsite Excel, Spatie Activitylog, Filament Shield, Scramble, Horizon, Octane, Sanctum, Flysystem S3/SFTP, Debugbar, Pint, Collision, Pest, Tinker, Impersonate).
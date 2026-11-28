# API Dashboard Tiket IT (read-only + realtime)

Untuk layar TV/dashboard luar. Hanya **membaca** tiket yang ditujukan ke divisi
**IT**. Tidak ada endpoint tulis.

Base URL staging: `https://staging-helpdesk.oceanspace.co.id/api/dashboard`

## Autentikasi

Token statis dari admin helpdesk (env `HELPDESK_DASHBOARD_TOKENS`). Kirim lewat:

- header `Authorization: Bearer <TOKEN>` (disarankan), atau
- query `?token=<TOKEN>` (khusus untuk `EventSource` di browser yang tidak bisa
  mengirim header).

Token salah atau kosong akan dibalas `401`.

## Endpoint

| Method | Path | Isi |
|---|---|---|
| GET | `/tickets` | Daftar tiket IT, terbaru dulu, dengan paginasi |
| GET | `/tickets/{id}` | Satu tiket |
| GET | `/tickets/summary` | Jumlah per status, dibuat hari ini, selesai hari ini, lewat SLA |
| GET | `/tickets/stream` | Realtime (Server-Sent Events) |

Filter `/tickets`: `status` (nama atau id, pisah koma, mis. `Open,In Progress`),
`created_from`, `created_to`, `updated_since` (tanggal ISO), `per_page` (1–200,
default 50), `page`.

Contoh satu tiket:

```json
{
  "id": 21,
  "number": "HD-2026-00021",
  "title": "Wifi lantai 2 mati",
  "unit": "IT",
  "category": "Jaringan",
  "priority": { "id": 2, "name": "High" },
  "status": { "id": 1, "name": "Open" },
  "reporter": { "name": "Budi", "unit": "BUSDEV", "business_entity": "PT ..." },
  "responsible": "Andi",
  "created_at": "2026-11-28T09:00:05+07:00",
  "updated_at": "2026-11-28T09:00:05+07:00",
  "approved_at": null,
  "solved_at": null,
  "sla_due_at": null,
  "is_sla_met": null,
  "deleted": false
}
```

Deskripsi, lampiran, email, dan nomor HP pelapor sengaja tidak dikirim karena
data ini tampil di layar umum.

## Realtime (`/tickets/stream`)

Stream SSE dengan event:

- `ready`: koneksi siap, berisi `cursor`
- `ticket.created`: tiket IT baru
- `ticket.updated`: status, PIC, atau data lain berubah
- `ticket.deleted`: tiket dihapus (`deleted: true`)

Data tiap event berisi objek tiket seperti di atas. Perlakukan setiap event
sebagai **upsert berdasarkan `id`**, karena event yang sama bisa terkirim dua
kali setelah koneksi tersambung ulang.

Server menutup koneksi tiap ±5 menit. `EventSource` akan menyambung ulang
otomatis dan melanjutkan dari `Last-Event-ID`, jadi tidak ada event yang
hilang. Bila koneksi dibuka tanpa cursor, stream mulai dari "sekarang". Karena
itu, ambil `/tickets` dulu, lalu buka stream dengan `?last_event_id=<meta.stream_cursor>`.

### Contoh (browser)

```html
<script>
const BASE = 'https://staging-helpdesk.oceanspace.co.id/api/dashboard';
const TOKEN = 'ISI_TOKEN';
const tickets = new Map();

async function start() {
  const res = await fetch(`${BASE}/tickets?per_page=100`, {
    headers: { Authorization: `Bearer ${TOKEN}` },
  });
  const json = await res.json();
  json.data.forEach(t => tickets.set(t.id, t));
  render();

  const es = new EventSource(
    `${BASE}/tickets/stream?token=${TOKEN}&last_event_id=${json.meta.stream_cursor}`
  );
  const upsert = e => {
    const t = JSON.parse(e.data);
    t.deleted ? tickets.delete(t.id) : tickets.set(t.id, t);
    render();
    if (e.type === 'ticket.created') {/* bunyikan notifikasi TV */}
  };
  ['ticket.created', 'ticket.updated', 'ticket.deleted']
    .forEach(type => es.addEventListener(type, upsert));
}

function render() {
  console.table([...tickets.values()].map(t => ({
    no: t.number, judul: t.title, status: t.status?.name, pic: t.responsible,
  })));
}
start();
</script>
```

### Tes cepat dari terminal

```bash
curl -N -H "Authorization: Bearer ISI_TOKEN" https://staging-helpdesk.oceanspace.co.id/api/dashboard/tickets/stream
```

## Konfigurasi server (.env)

```
HELPDESK_DASHBOARD_TOKENS=token-panjang-acak        # bisa beberapa, pisah koma
HELPDESK_DASHBOARD_UNITS=IT                         # nama divisi yang dibaca
HELPDESK_DASHBOARD_STREAM_POLL=3                    # detik antar cek database
HELPDESK_DASHBOARD_STREAM_LIFETIME=300              # detik per koneksi stream
```

Catatan operasional: setiap koneksi stream memegang satu worker PHP-FPM selama
koneksi terbuka. Ini aman untuk beberapa layar TV, tetapi jangan dibuka dari
banyak tab sekaligus. Batas laju: 120 req/menit untuk daftar/ringkasan dan 20
koneksi stream/menit per IP.

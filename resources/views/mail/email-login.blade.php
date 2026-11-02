<x-mail::message>
# Masuk ke {{ config('app.name') }}

Halo {{ $name }},

Klik tombol di bawah untuk masuk tanpa password:

<x-mail::button :url="$loginUrl">
Masuk ke {{ config('app.name') }}
</x-mail::button>

Atau masukkan kode berikut di halaman masuk:

<x-mail::panel>
<div style="font-size: 28px; font-weight: 700; letter-spacing: 8px; text-align: center;">{{ $otp }}</div>
</x-mail::panel>

Link dan kode berlaku {{ $expiresInMinutes }} menit dan hanya bisa dipakai sekali. Jangan bagikan kepada siapa pun.

Jika Anda tidak meminta masuk, abaikan email ini.

Salam,<br>
{{ config('app.name') }}
</x-mail::message>

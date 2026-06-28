<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verifikasi OTP</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gray-100 text-gray-900">
    <main class="flex min-h-screen items-center justify-center px-4">
        <section class="w-full max-w-md rounded-xl border border-gray-200 bg-white p-8 shadow-xl">
            <h1 class="text-2xl font-semibold">Verifikasi OTP</h1>
            <p class="mt-2 text-sm text-gray-600">Masukkan 6 digit kode yang dikirim ke WhatsApp.</p>

            @if (session('status'))
                <div class="mt-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">
                    {{ $errors->first() }}
                </div>
            @endif

            <form class="mt-6 space-y-4" method="POST" action="{{ route('phone-login.verify.submit') }}">
                @csrf
                <input type="hidden" name="phone" value="{{ old('phone', $phone) }}">
                <label class="block">
                    <span class="text-sm font-medium text-gray-700">Kode OTP</span>
                    <input name="otp" autocomplete="one-time-code" inputmode="numeric" required maxlength="6"
                        class="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-center text-xl tracking-widest focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20">
                </label>

                <button type="submit" class="w-full rounded-lg bg-blue-600 px-4 py-2 font-medium text-white hover:bg-blue-700">
                    Login
                </button>
            </form>

            <a class="mt-4 block text-center text-sm text-blue-600" href="{{ route('phone-login') }}">Kirim ulang OTP</a>
        </section>
    </main>
</body>
</html>

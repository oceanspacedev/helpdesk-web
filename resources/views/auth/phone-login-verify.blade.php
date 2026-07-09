@php
    use Filament\Support\Icons\Heroicon;
@endphp

@extends('auth.layout')

@section('content')
    @include('auth.partials.header', [
        'icon' => Heroicon::ChatBubbleLeftRight,
        'title' => 'Verifikasi OTP',
        'description' => 'Masukkan 6 digit kode yang dikirim ke WhatsApp (' . old('phone', $phone) . ')',
        'backUrl' => route('phone-login'),
        'backLabel' => 'Kirim ulang OTP atau ganti nomor',
    ])

    <div class="mt-8 space-y-6">
        @if (session('status'))
            <div class="rounded-lg bg-emerald-50 p-3 text-sm font-medium text-emerald-800 ring-1 ring-emerald-600/20 dark:bg-emerald-950/50 dark:text-emerald-300">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->has('phone'))
            <div data-validation-error class="fi-fo-field-wrp-error-message text-sm text-danger-600 dark:text-danger-400">
                {{ $errors->first('phone') }}
            </div>
        @endif

        <form method="POST" action="{{ route('phone-login.verify.submit') }}" class="fi-sc-form">
            @csrf
            <input type="hidden" name="phone" value="{{ old('phone', $phone) }}">

            <div class="fi-grid fi-sc fi-sc-has-gap" style="--cols-default: repeat(1, minmax(0, 1fr));">
                <div class="fi-grid-col" style="--col-span-default: span 1 / span 1;">
                    <div class="fi-sc-component">
                        <div data-field-wrapper class="fi-fo-field">
                            <div class="fi-fo-field-label-col">
                                <div class="fi-fo-field-label-ctn">
                                    <label for="otp" class="fi-fo-field-label">
                                        <span class="fi-fo-field-label-content">
                                            Kode OTP<sup class="fi-fo-field-label-required-mark">*</sup>
                                        </span>
                                    </label>
                                </div>
                            </div>

                            <div class="fi-fo-field-content-col">
                                <x-filament::input.wrapper :valid="! $errors->has('otp')">
                                    <x-filament::input
                                        id="otp"
                                        name="otp"
                                        type="text"
                                        inputmode="numeric"
                                        autocomplete="one-time-code"
                                        required
                                        maxlength="6"
                                        autofocus
                                        class="text-center text-xl font-mono tracking-widest"
                                    />
                                </x-filament::input.wrapper>

                                @if ($errors->has('otp'))
                                    <div data-validation-error class="fi-fo-field-wrp-error-message mt-2 text-sm text-danger-600 dark:text-danger-400">
                                        {{ $errors->first('otp') }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @include('auth.partials.submit-button', ['label' => 'Verifikasi & Login'])
        </form>
    </div>
@endsection

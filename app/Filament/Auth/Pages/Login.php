<?php

namespace App\Filament\Auth\Pages;

use Apriansyahrs\MekayaTheme\Auth\MekayaLogin;
use Filament\Schemas\Components\Component;

/**
 * Login kata sandi panel. Sama dengan login tema Mekaya, hanya "Ingat saya" dicentang default seperti login WhatsApp
 * dan email, agar pengguna tidak diminta masuk lagi setelah laptop dimatikan (sesi hanya berlaku SESSION_LIFETIME).
 */
class Login extends MekayaLogin
{
    protected function getRememberFormComponent(): Component
    {
        return EmailLogin::rememberFormComponent();
    }
}

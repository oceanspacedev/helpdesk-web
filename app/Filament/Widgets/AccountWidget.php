<?php

namespace App\Filament\Widgets;

use Filament\Widgets\AccountWidget as BaseAccountWidget;

/**
 * Kartu sapaan bawaan Filament, dibuat lebar penuh agar tidak menyisakan
 * setengah baris kosong pada dasbor dua kolom.
 */
class AccountWidget extends BaseAccountWidget
{
    protected int|string|array $columnSpan = 'full';
}

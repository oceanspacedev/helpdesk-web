<?php

namespace App\Filament\Pages;

use Filament\Forms;
use App\Models\UserLevel;
use Jeffgreco13\FilamentBreezy\Pages\MyProfilePage as BaseProfile;

class MyProfile extends BaseProfile
{

    protected static ?int $navigationSort = 500;

    protected function getUpdateProfileFormSchema(): array
    {
        return  [
            Forms\Components\TextInput::make('name')
                ->required()
                ->label(__('filament-breezy::default.fields.name')),
            Forms\Components\TextInput::make('email')
                ->required()
                ->label(__('filament-breezy::default.fields.email'))
                ->disabled(fn ($state) => !is_null($state)),
            Forms\Components\TextInput::make('phone')
                ->required()
                ->tel()
                ->minLength(10)
                ->maxLength(15)
                ->unique()
                ->label(__('Nomer WA'))
                ->beforeStateDehydrated(function ($state, callable $set) {
                    if (substr($state, 0, 1) === '0') {
                        $set('phone', '62' . substr($state, 1)); // Ganti angka 0 di awal dengan 62
                    }
                })
                ->disabled(fn ($state) => !is_null($state)),
        ];
    }
}

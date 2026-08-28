<?php

namespace App\Filament\Livewire;

use Jeffgreco13\FilamentBreezy\Livewire\PersonalInfo as BasePersonalInfo;

class PersonalInfo extends BasePersonalInfo
{
    /**
     * Email and phone are identity attributes and need dedicated verification flows.
     * The self-service profile may only update the display name.
     *
     * @var list<string>
     */
    public array $only = ['name'];

    protected function getProfileFormComponents(): array
    {
        return [
            $this->getNameComponent(),
        ];
    }
}

<?php

namespace App\Filament\Livewire;

use App\Models\User;
use Filament\Facades\Filament;
use Jeffgreco13\FilamentBreezy\Livewire\UpdatePassword as BaseUpdatePassword;

class UpdatePassword extends BaseUpdatePassword
{
    public static function canView(): bool
    {
        $user = Filament::getCurrentOrDefaultPanel()->auth()->user();

        return $user instanceof User
            && $user->canUseVerifiedEmail()
            && filled($user->getAuthPassword());
    }

    public function mount(): void
    {
        parent::mount();

        abort_unless($this->canUpdatePassword(), 403);
    }

    public function submit(): void
    {
        abort_unless($this->canUpdatePassword(), 403);

        parent::submit();
    }

    private function canUpdatePassword(): bool
    {
        return $this->user instanceof User
            && $this->user->canUseVerifiedEmail()
            && filled($this->user->getAuthPassword());
    }
}

<?php

namespace App\Filament\Auth\Concerns;

use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Collection;

trait InteractsWithPhoneNumbers
{
    /**
     * @return Collection<int, User>
     */
    protected function usersByPhone(string $phone): Collection
    {
        $phone = $this->normalizePhone($phone);
        if ($phone === null) {
            return new Collection;
        }

        return User::query()
            ->withTrashed()
            ->where('phone_normalized', $phone)
            ->get();
    }

    protected function normalizePhone(mixed $value): ?string
    {
        return PhoneNumber::canonical($value);
    }
}

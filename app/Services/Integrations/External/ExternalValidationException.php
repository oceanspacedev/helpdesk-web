<?php

namespace App\Services\Integrations\External;

use Illuminate\Validation\ValidationException;

/**
 * Sistem eksternal menolak isian (HTTP 422). Kunci error memakai notasi titik
 * kontrak, mis. "answers.proses.0.judul", sehingga bisa langsung dipetakan ke
 * field form.
 */
class ExternalValidationException extends ExternalSystemException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        string $message,
        private readonly array $errors = [],
    ) {
        parent::__construct($message !== '' ? $message : 'Isian ditolak oleh sistem tujuan.');
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Bentuk ValidationException Laravel agar form bisa menampilkan error
     * pada field terkait. $prefix dipakai bila state form bersarang,
     * mis. "data." untuk Filament.
     */
    public function toValidationException(string $prefix = ''): ValidationException
    {
        $messages = [];
        foreach ($this->errors as $path => $fieldMessages) {
            $messages[$prefix.$path] = $fieldMessages;
        }

        if ($messages === []) {
            $messages[$prefix.'answers'] = [$this->getMessage()];
        }

        return ValidationException::withMessages($messages);
    }
}

<?php

namespace App\Rules;

use App\Support\SafeUploadedFile;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Size limits for uploads that fail validation instead of throwing when a
 * Livewire temporary file has already expired.
 *
 * This is a rule object rather than a closure on purpose: Filament evaluates
 * closure rules through dependency injection and cannot resolve the
 * `$attribute` parameter, which crashed every form that used the closure.
 *
 * Filament's FileUpload runs custom rules once per file (`field.*`), so the
 * total-size and file-count limits read the sibling files from the validator
 * data instead of relying on the single value being validated.
 */
final class SafeUploadSize implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(
        private readonly int $maxTotalBytes,
        private readonly int $maxPerFileBytes,
        private readonly int $maxFiles = 0,
        private readonly string $missingMessage = SafeUploadedFile::MISSING_MESSAGE,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === [] || $value === '') {
            return;
        }

        $files = $this->filesUnderValidation($attribute, $value);

        if ($this->maxFiles > 0 && count($files) > $this->maxFiles) {
            $fail('Lampiran maksimal '.$this->maxFiles.' file.');

            return;
        }

        $totalBytes = 0;

        foreach ($files as $file) {
            // Paths of files stored on an earlier save are not re-measured.
            if (is_string($file)) {
                continue;
            }

            $size = SafeUploadedFile::size($file);

            if ($size === null) {
                $fail($this->missingMessage);

                return;
            }

            if ($this->maxPerFileBytes > 0 && $size > $this->maxPerFileBytes) {
                $fail('Ukuran setiap lampiran maksimal '.self::megabytes($this->maxPerFileBytes).' MB.');

                return;
            }

            $totalBytes += $size;
        }

        if ($this->maxTotalBytes > 0 && $totalBytes > $this->maxTotalBytes) {
            $fail('Total ukuran seluruh lampiran maksimal '.self::megabytes($this->maxTotalBytes).' MB.');
        }
    }

    /**
     * @return array<mixed>
     */
    private function filesUnderValidation(string $attribute, mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! str_contains($attribute, '.')) {
            return [$value];
        }

        $siblings = Arr::get($this->data, Str::beforeLast($attribute, '.'));

        return is_array($siblings) ? $siblings : [$value];
    }

    private static function megabytes(int $bytes): int
    {
        return (int) max(1, round($bytes / (1024 * 1024)));
    }
}

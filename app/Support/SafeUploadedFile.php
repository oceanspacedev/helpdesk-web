<?php

namespace App\Support;

use App\Rules\SafeUploadSize;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToRetrieveMetadata;
use Throwable;

final class SafeUploadedFile
{
    public const MISSING_MESSAGE = 'Lampiran tidak ditemukan atau sudah kedaluwarsa. Unggah ulang file.';

    public static function size(mixed $file): ?int
    {
        if (is_string($file) && $file !== '') {
            return 0;
        }

        if (! is_object($file) || ! method_exists($file, 'getSize')) {
            return null;
        }

        try {
            $size = $file->getSize();
        } catch (Throwable) {
            return null;
        }

        if ($size === false || $size === null) {
            return null;
        }

        return (int) $size;
    }

    public static function isMissingUpload(Throwable $exception): bool
    {
        if ($exception instanceof UnableToRetrieveMetadata) {
            return true;
        }

        $message = $exception->getMessage();

        return str_contains($message, 'livewire-tmp')
            || str_contains($message, 'Unable to retrieve the file_size')
            || str_contains($message, 'stat failed');
    }

    /**
     * Size limits for a Filament FileUpload (or any Laravel validator).
     *
     * Returns a rule object instead of a closure: Filament would otherwise try
     * to inject the closure's `$attribute` parameter and crash the form.
     */
    public static function rule(
        int $maxTotalBytes,
        int $maxPerFileBytes,
        int $maxFiles = 0,
        string $missingMessage = self::MISSING_MESSAGE,
    ): SafeUploadSize {
        return new SafeUploadSize($maxTotalBytes, $maxPerFileBytes, $maxFiles, $missingMessage);
    }

    public static function wrapValidation(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            if (! self::isMissingUpload($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'data.supporting_attachments' => self::MISSING_MESSAGE,
                'data.attachments' => self::MISSING_MESSAGE,
            ]);
        }
    }
}

<?php

namespace App\Support;

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
     * @return \Closure(string, mixed, \Closure): void
     */
    public static function rule(
        int $maxTotalBytes,
        int $maxPerFileBytes,
        int $maxFiles = 0,
        string $missingMessage = self::MISSING_MESSAGE,
    ): \Closure {
        return function (string $attribute, mixed $value, \Closure $fail) use (
            $maxTotalBytes,
            $maxPerFileBytes,
            $maxFiles,
            $missingMessage,
        ): void {
            if ($value === null || $value === [] || $value === '') {
                return;
            }

            $files = is_array($value) ? $value : [$value];
            if ($maxFiles > 0 && count($files) > $maxFiles) {
                $fail('Lampiran maksimal '.$maxFiles.' file.');

                return;
            }

            $totalBytes = 0;

            foreach ($files as $file) {
                if (is_string($file) && $file !== '') {
                    continue;
                }

                $size = self::size($file);
                if ($size === null) {
                    $fail($missingMessage);

                    return;
                }

                if ($maxPerFileBytes > 0 && $size > $maxPerFileBytes) {
                    $fail('Ukuran setiap lampiran maksimal '.self::megabytes($maxPerFileBytes).' MB.');

                    return;
                }

                $totalBytes += $size;
            }

            if ($maxTotalBytes > 0 && $totalBytes > $maxTotalBytes) {
                $fail('Total ukuran seluruh lampiran maksimal '.self::megabytes($maxTotalBytes).' MB.');
            }
        };
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

    private static function megabytes(int $bytes): int
    {
        return (int) max(1, round($bytes / (1024 * 1024)));
    }
}

<?php

namespace Tests\Unit;

use App\Support\SafeUploadedFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToRetrieveMetadata;
use RuntimeException;
use Tests\TestCase;

class SafeUploadedFileTest extends TestCase
{
    public function test_size_returns_null_when_livewire_temp_file_is_missing(): void
    {
        $file = new class
        {
            public function getSize(): int
            {
                throw UnableToRetrieveMetadata::fileSize(
                    'livewire-tmp/PbSiWlftckvMJ4oZ5LwmThL2M00Wdd-meta.xlsx',
                    '',
                );
            }
        };

        $this->assertNull(SafeUploadedFile::size($file));
    }

    public function test_size_returns_null_when_stat_fails(): void
    {
        $file = new class
        {
            public function getSize(): int
            {
                throw new RuntimeException('SplFileInfo::getSize(): stat failed for /tmp/missing');
            }
        };

        $this->assertNull(SafeUploadedFile::size($file));
    }

    public function test_size_reads_a_real_uploaded_file(): void
    {
        $file = UploadedFile::fake()->create('bukti.xlsx', 100);

        $this->assertSame(100 * 1024, SafeUploadedFile::size($file));
    }

    public function test_rule_fails_validation_instead_of_throwing_when_temp_file_is_gone(): void
    {
        $file = new class
        {
            public function getSize(): int
            {
                throw UnableToRetrieveMetadata::fileSize('livewire-tmp/missing.xlsx', '');
            }
        };

        $failed = null;
        $rule = SafeUploadedFile::rule(10 * 1024 * 1024, 10 * 1024 * 1024, 5);
        $rule('supporting_attachments', [$file], function (string $message) use (&$failed): void {
            $failed = $message;
        });

        $this->assertSame(SafeUploadedFile::MISSING_MESSAGE, $failed);
    }

    public function test_rule_rejects_total_size_over_the_limit(): void
    {
        $file = UploadedFile::fake()->create('besar.xlsx', 6000);

        $failed = null;
        $rule = SafeUploadedFile::rule(5 * 1024 * 1024, 10 * 1024 * 1024, 5);
        $rule('supporting_attachments', [$file], function (string $message) use (&$failed): void {
            $failed = $message;
        });

        $this->assertSame('Total ukuran seluruh lampiran maksimal 5 MB.', $failed);
    }

    public function test_wrap_validation_converts_missing_temp_files_to_validation_errors(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(SafeUploadedFile::MISSING_MESSAGE);

        SafeUploadedFile::wrapValidation(function (): void {
            throw UnableToRetrieveMetadata::fileSize('livewire-tmp/missing.xlsx', '');
        });
    }

    public function test_wrap_validation_does_not_hide_unrelated_errors(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('smtp down');

        SafeUploadedFile::wrapValidation(function (): void {
            throw new RuntimeException('smtp down');
        });
    }
}

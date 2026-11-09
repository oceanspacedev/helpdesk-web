<?php

namespace Tests\Unit;

use App\Rules\SafeUploadSize;
use App\Support\SafeUploadedFile;
use Closure;
use Filament\Forms\Components\FileUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
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

    public function test_rule_is_an_object_so_filament_does_not_inject_it_as_a_closure(): void
    {
        $rule = SafeUploadedFile::rule(10 * 1024 * 1024, 10 * 1024 * 1024, 5);

        $this->assertInstanceOf(SafeUploadSize::class, $rule);
        $this->assertNotInstanceOf(Closure::class, $rule);

        // This is the call that crashed in production with
        // "[$attribute] was unresolvable" when the rule was a closure.
        $rules = FileUpload::make('attachments')
            ->rules([$rule])
            ->getValidationRules();

        $this->assertContains('array', $rules);
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
        $rule->validate('supporting_attachments', [$file], function (string $message) use (&$failed): void {
            $failed = $message;
        });

        $this->assertSame(SafeUploadedFile::MISSING_MESSAGE, $failed);
    }

    public function test_rule_rejects_total_size_over_the_limit(): void
    {
        $file = UploadedFile::fake()->create('besar.xlsx', 6000);

        $failed = null;
        $rule = SafeUploadedFile::rule(5 * 1024 * 1024, 10 * 1024 * 1024, 5);
        $rule->validate('supporting_attachments', [$file], function (string $message) use (&$failed): void {
            $failed = $message;
        });

        $this->assertSame('Total ukuran seluruh lampiran maksimal 5 MB.', $failed);
    }

    public function test_rule_accepts_a_file_within_the_limits(): void
    {
        $file = UploadedFile::fake()->create('kecil.pdf', 100);

        $failed = null;
        $rule = SafeUploadedFile::rule(5 * 1024 * 1024, 10 * 1024 * 1024, 5);
        $rule->setData(['supporting_attachments' => [$file]]);
        $rule->validate('supporting_attachments.0', $file, function (string $message) use (&$failed): void {
            $failed = $message;
        });

        $this->assertNull($failed);
    }

    public function test_rule_counts_sibling_files_when_validated_per_file(): void
    {
        $first = UploadedFile::fake()->create('satu.xlsx', 3000);
        $second = UploadedFile::fake()->create('dua.xlsx', 3000);

        $failed = null;
        $rule = SafeUploadedFile::rule(5 * 1024 * 1024, 10 * 1024 * 1024, 5);
        $rule->setData(['supporting_attachments' => [$first, $second]]);
        $rule->validate('supporting_attachments.0', $first, function (string $message) use (&$failed): void {
            $failed = $message;
        });

        $this->assertSame('Total ukuran seluruh lampiran maksimal 5 MB.', $failed);
    }

    public function test_rule_enforces_the_total_when_laravel_applies_it_per_file(): void
    {
        $first = UploadedFile::fake()->create('satu.xlsx', 3000);
        $second = UploadedFile::fake()->create('dua.xlsx', 3000);

        // Mirrors how Filament's FileUpload applies custom rules: `field.*`.
        $validator = Validator::make(
            ['attachments' => [$first, $second]],
            ['attachments.*' => ['file', SafeUploadedFile::rule(5 * 1024 * 1024, 10 * 1024 * 1024)]],
        );

        $this->assertTrue($validator->fails());
        $this->assertSame(
            'Total ukuran seluruh lampiran maksimal 5 MB.',
            $validator->errors()->first('attachments.0'),
        );
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

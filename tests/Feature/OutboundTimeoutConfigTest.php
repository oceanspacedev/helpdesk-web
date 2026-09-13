<?php

namespace Tests\Feature;

use App\Support\SafeUploadedFile;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToRetrieveMetadata;
use Tests\TestCase;

class OutboundTimeoutConfigTest extends TestCase
{
    public function test_mail_and_whatsapp_timeouts_are_configured_below_php_limit(): void
    {
        $this->assertSame(10, config('mail.mailers.smtp.timeout'));
        $this->assertSame(8.0, (float) config('services.whatsapp_gateway.timeout'));
        $this->assertSame(5.0, (float) config('services.whatsapp_gateway.connect_timeout'));
        $this->assertLessThan(30, (float) config('services.whatsapp_gateway.timeout')
            + (float) config('mail.mailers.smtp.timeout'));
    }

    public function test_missing_livewire_temp_file_is_converted_to_a_validation_error(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(SafeUploadedFile::MISSING_MESSAGE);

        app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->render(
            Request::create('/admin/tickets/create', 'POST'),
            UnableToRetrieveMetadata::fileSize(
                'livewire-tmp/PbSiWlftckvMJ4oZ5LwmThL2M00Wdd-metaUGVuZ2FqdWFuIEFrdGlmIEd1ZGFuZyBTYWxlcy54bHN4-.xlsx',
                '',
            ),
        );
    }
}

<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    #[DataProvider('canonicalPhoneProvider')]
    public function test_it_canonicalizes_supported_phone_formats(string $input): void
    {
        $this->assertSame('6281234567890', PhoneNumber::canonical($input));
    }

    public static function canonicalPhoneProvider(): array
    {
        return [
            'local formatted' => ['0812-3456 7890'],
            'international formatted' => ['+62 812-3456-7890'],
            'international dialing prefix' => ['0062 812-3456-7890'],
            'country code with local zero' => ['62081234567890'],
            'without country prefix' => ['81234567890'],
            'whatsapp jid' => ['6281234567890@s.whatsapp.net'],
            'whatsapp legacy jid' => ['6281234567890@c.us'],
        ];
    }

    #[DataProvider('invalidPhoneProvider')]
    public function test_it_rejects_untrusted_or_invalid_phone_identifiers(mixed $input): void
    {
        $this->assertNull(PhoneNumber::canonical($input));
    }

    public static function invalidPhoneProvider(): array
    {
        return [
            'empty' => [''],
            'lid identity' => ['123456789012345@lid'],
            'group jid' => ['6281234567890@g.us'],
            'letters' => ['0812abc345678'],
            'too short' => ['0812345'],
            'array' => [['081234567890']],
        ];
    }
}

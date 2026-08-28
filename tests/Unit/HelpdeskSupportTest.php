<?php

namespace Tests\Unit;

use App\Support\HelpdeskReporterName;
use App\Support\HttpsUrl;
use App\Support\TalentaEmployee;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HelpdeskSupportTest extends TestCase
{
    public function test_reporter_name_is_cleaned_without_accepting_conversation_controls(): void
    {
        $this->assertSame('Budi Santoso', HelpdeskReporterName::normalize('  <b>Budi</b>   Santoso  '));
        $this->assertNull(HelpdeskReporterName::normalize('iya'));
        $this->assertNull(HelpdeskReporterName::normalize('0812-3456-7890'));
        $this->assertNull(HelpdeskReporterName::normalize('12'));
    }

    #[DataProvider('urlProvider')]
    public function test_attachment_url_accepts_only_bounded_https_urls(string $url, bool $expected): void
    {
        $this->assertSame($expected, HttpsUrl::isValid($url));
    }

    public static function urlProvider(): array
    {
        return [
            'https' => ['https://files.example.test/report.png', true],
            'http' => ['http://files.example.test/report.png', false],
            'invalid' => ['not-a-url', false],
            'too long' => ['https://example.test/'.str_repeat('a', 2048), false],
        ];
    }

    public function test_talenta_fingerprint_uses_canonical_sorted_phones(): void
    {
        $employee = [
            'id_employee' => 'EMP-1',
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'email' => 'BUDI@EXAMPLE.TEST',
            'mobile_phone' => '0812-3456-7890',
            'phone' => '0813-0000-0000',
        ];
        $sameIdentity = array_merge($employee, [
            'email' => 'budi@example.test',
            'mobile_phone' => '+62 813 0000 0000',
            'phone' => '6281234567890',
        ]);

        $this->assertSame(
            TalentaEmployee::identityFingerprint($employee),
            TalentaEmployee::identityFingerprint($sameIdentity),
        );
    }
}

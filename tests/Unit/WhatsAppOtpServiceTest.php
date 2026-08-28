<?php

namespace Tests\Unit;

use App\Services\WhatsAppGateway;
use App\Services\WhatsAppOtpService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class WhatsAppOtpServiceTest extends TestCase
{
    private InMemoryWhatsAppGateway $gateway;

    private WhatsAppOtpService $otp;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('services.whatsapp_otp.ttl_minutes', 5);

        $this->gateway = new InMemoryWhatsAppGateway;
        $this->otp = new WhatsAppOtpService($this->gateway);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_issues_and_verifies_an_otp_for_the_normalized_phone(): void
    {
        $issued = $this->otp->issue(
            'helpdesk-intake',
            'intake-123',
            'telegram:user-a',
            '0812-3456-7890',
        );

        $this->assertTrue($issued['ok']);
        $this->assertSame('otp_sent', $issued['status']);
        $this->assertSame('6281234567890', $issued['phone']);
        $this->assertFalse($issued['replayed']);
        $this->assertCount(1, $this->gateway->messages);
        $this->assertSame('6281234567890', $this->gateway->messages[0]['phone']);

        $verified = $this->otp->verify(
            'helpdesk-intake',
            'intake-123',
            'telegram:user-a',
            $issued['challenge_id'],
            $this->sentCode(),
        );

        $this->assertTrue($verified['ok']);
        $this->assertSame('verified', $verified['status']);
        $this->assertSame('6281234567890', $verified['phone']);
    }

    public function test_a_challenge_is_isolated_to_its_subject_and_principal(): void
    {
        $issued = $this->issue();
        $code = $this->sentCode();

        $wrongSubject = $this->otp->verify(
            'helpdesk-intake',
            'intake-other',
            'telegram:user-a',
            $issued['challenge_id'],
            $code,
        );
        $wrongPrincipal = $this->otp->verify(
            'helpdesk-intake',
            'intake-123',
            'telegram:user-b',
            $issued['challenge_id'],
            $code,
        );

        $this->assertFalse($wrongSubject['ok']);
        $this->assertSame('expired', $wrongSubject['status']);
        $this->assertFalse($wrongPrincipal['ok']);
        $this->assertSame('expired', $wrongPrincipal['status']);

        $this->assertTrue($this->otp->verify(
            'helpdesk-intake',
            'intake-123',
            'telegram:user-a',
            $issued['challenge_id'],
            $code,
        )['ok']);
    }

    public function test_a_verified_challenge_is_single_use(): void
    {
        $issued = $this->issue();
        $code = $this->sentCode();

        $first = $this->verify($issued['challenge_id'], $code);
        $second = $this->verify($issued['challenge_id'], $code);

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertSame('expired', $second['status']);
    }

    public function test_retrying_issue_reuses_the_live_challenge_without_resending(): void
    {
        $first = $this->issue();
        $second = $this->issue();

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok']);
        $this->assertSame($first['challenge_id'], $second['challenge_id']);
        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['replayed']);
        $this->assertCount(1, $this->gateway->messages);
    }

    public function test_delivery_failure_does_not_leave_a_replayable_challenge(): void
    {
        $this->gateway->deliver = false;

        $failed = $this->issue();

        $this->assertFalse($failed['ok']);
        $this->assertSame('delivery_failed', $failed['status']);
        $this->assertNull($failed['challenge_id']);
        $this->assertCount(1, $this->gateway->messages);

        $this->gateway->deliver = true;
        $retried = $this->issue();

        $this->assertTrue($retried['ok']);
        $this->assertFalse($retried['replayed']);
        $this->assertCount(2, $this->gateway->messages);
    }

    public function test_invalid_code_is_rejected_without_consuming_the_challenge(): void
    {
        $issued = $this->issue();
        $code = $this->sentCode();
        $invalidCode = $code === '000000' ? '999999' : '000000';

        $invalid = $this->verify($issued['challenge_id'], $invalidCode);

        $this->assertFalse($invalid['ok']);
        $this->assertSame('invalid', $invalid['status']);
        $this->assertTrue($this->verify($issued['challenge_id'], $code)['ok']);
    }

    public function test_expired_and_missing_challenges_cannot_be_verified(): void
    {
        $start = Carbon::parse('2026-08-28 10:00:00');
        Carbon::setTestNow($start);
        config()->set('services.whatsapp_otp.ttl_minutes', 1);

        $issued = $this->issue();
        $code = $this->sentCode();

        Carbon::setTestNow($start->copy()->addMinutes(2));

        $expired = $this->verify($issued['challenge_id'], $code);
        $missing = $this->verify('wotp_'.str_repeat('A', 48), '123456');

        $this->assertFalse($expired['ok']);
        $this->assertSame('expired', $expired['status']);
        $this->assertFalse($missing['ok']);
        $this->assertSame('expired', $missing['status']);
    }

    public function test_fourth_rotated_send_to_one_phone_is_rate_limited(): void
    {
        $results = [];

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $results[] = $this->otp->issue(
                'helpdesk-intake',
                'intake-123',
                'telegram:user-a',
                '0812-3456-7890',
                rotate: true,
            );
        }

        $this->assertTrue($results[0]['ok']);
        $this->assertTrue($results[1]['ok']);
        $this->assertTrue($results[2]['ok']);
        $this->assertFalse($results[3]['ok']);
        $this->assertSame('rate_limited', $results[3]['status']);
        $this->assertGreaterThan(0, $results[3]['retry_after']);
        $this->assertCount(3, $this->gateway->messages);
    }

    public function test_sixth_wrong_verification_attempt_is_rate_limited(): void
    {
        $issued = $this->issue();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $result = $this->verify($issued['challenge_id'], '000000');

            $this->assertFalse($result['ok']);
            $this->assertSame('invalid', $result['status']);
        }

        $sixth = $this->verify($issued['challenge_id'], '000000');

        $this->assertFalse($sixth['ok']);
        $this->assertSame('rate_limited', $sixth['status']);
        $this->assertGreaterThan(0, $sixth['retry_after']);
    }

    public function test_principal_quota_cannot_be_bypassed_with_new_subjects_and_is_isolated_per_user(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $result = $this->otp->issue(
                'helpdesk-intake',
                "intake-user-a-{$attempt}",
                'reporter:user-a',
                $this->phone($attempt),
                tenant: 'tenant-gateway',
            );

            $this->assertTrue($result['ok']);
        }

        $limited = $this->otp->issue(
            'helpdesk-intake',
            'intake-user-a-11',
            'reporter:user-a',
            $this->phone(11),
            tenant: 'tenant-gateway',
        );
        $otherUser = $this->otp->issue(
            'helpdesk-intake',
            'intake-user-b-1',
            'reporter:user-b',
            $this->phone(12),
            tenant: 'tenant-gateway',
        );

        $this->assertFalse($limited['ok']);
        $this->assertSame('rate_limited', $limited['status']);
        $this->assertTrue($otherUser['ok']);
        $this->assertCount(11, $this->gateway->messages);
    }

    public function test_tenant_quota_is_global_across_principals_and_isolated_between_tenants(): void
    {
        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $result = $this->otp->issue(
                'helpdesk-intake',
                "intake-tenant-a-{$attempt}",
                "reporter:user-{$attempt}",
                $this->phone($attempt),
                tenant: 'tenant-gateway',
            );

            $this->assertTrue($result['ok']);
        }

        $limited = $this->otp->issue(
            'helpdesk-intake',
            'intake-tenant-a-31',
            'reporter:user-31',
            $this->phone(31),
            tenant: 'tenant-gateway',
        );
        $otherTenant = $this->otp->issue(
            'helpdesk-intake',
            'intake-tenant-b-1',
            'reporter:user-1',
            $this->phone(32),
            tenant: 'tenant-other',
        );

        $this->assertFalse($limited['ok']);
        $this->assertSame('rate_limited', $limited['status']);
        $this->assertTrue($otherTenant['ok']);
        $this->assertCount(31, $this->gateway->messages);
    }

    public function test_busy_principal_target_and_tenant_locks_fail_closed_without_sending(): void
    {
        $principal = 'reporter:user-a';
        $phone = $this->phone(1);
        $tenant = 'tenant-gateway';
        $cases = [
            [
                'key' => 'phone-otp:lock:principal:'.hash('sha256', $principal),
                'subject' => 'intake-principal-lock',
                'principal' => $principal,
                'phone' => $phone,
                'tenant' => $tenant,
            ],
            [
                'key' => 'phone-otp:lock:target:'.hash('sha256', $phone),
                'subject' => 'intake-target-lock',
                'principal' => 'reporter:user-b',
                'phone' => $phone,
                'tenant' => $tenant,
            ],
            [
                'key' => 'phone-otp:lock:tenant-quota:'.hash('sha256', $tenant),
                'subject' => 'intake-tenant-lock',
                'principal' => 'reporter:user-c',
                'phone' => $this->phone(2),
                'tenant' => $tenant,
            ],
        ];

        foreach ($cases as $case) {
            $lock = Cache::lock($case['key'], 30);
            $this->assertTrue($lock->get());

            try {
                $result = $this->otp->issue(
                    'helpdesk-intake',
                    $case['subject'],
                    $case['principal'],
                    $case['phone'],
                    tenant: $case['tenant'],
                );
            } finally {
                $lock->release();
            }

            $this->assertFalse($result['ok']);
            $this->assertSame('busy', $result['status']);
        }

        $this->assertCount(0, $this->gateway->messages);

        $afterRelease = $this->otp->issue(
            'helpdesk-intake',
            'intake-after-release',
            $principal,
            $phone,
            tenant: $tenant,
        );

        $this->assertTrue($afterRelease['ok']);
        $this->assertCount(1, $this->gateway->messages);
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(): array
    {
        return $this->otp->issue(
            'helpdesk-intake',
            'intake-123',
            'telegram:user-a',
            '0812-3456-7890',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function verify(string $challengeId, string $code): array
    {
        return $this->otp->verify(
            'helpdesk-intake',
            'intake-123',
            'telegram:user-a',
            $challengeId,
            $code,
        );
    }

    private function sentCode(): string
    {
        $message = $this->gateway->messages[0]['message'] ?? '';
        preg_match('/\b([0-9]{6})\b/', $message, $matches);

        $this->assertArrayHasKey(1, $matches, 'The WhatsApp message did not contain a six-digit OTP.');

        return $matches[1];
    }

    private function phone(int $suffix): string
    {
        return '62812000'.str_pad((string) $suffix, 5, '0', STR_PAD_LEFT);
    }
}

class InMemoryWhatsAppGateway extends WhatsAppGateway
{
    /** @var list<array{phone: string, message: string}> */
    public array $messages = [];

    public bool $deliver = true;

    public function send($phoneNumber, string $message): bool
    {
        $this->messages[] = [
            'phone' => (string) $phoneNumber,
            'message' => $message,
        ];

        return $this->deliver;
    }
}

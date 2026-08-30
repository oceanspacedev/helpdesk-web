<?php

namespace Tests\Feature;

use App\Models\HelpdeskReporterBinding;
use App\Models\Ticket;
use App\Models\TicketStatus;
use App\Models\User;
use App\Services\EmployeeService;
use App\Services\Integrations\HelpdeskReporterIdentityService;
use App\Services\Integrations\HelpdeskTicketCreationService;
use App\Services\WhatsAppGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesHelpdeskIntegrationSchema;
use Tests\Concerns\SeedsHelpdeskMasterData;
use Tests\Fakes\RecordingWhatsAppGateway;
use Tests\TestCase;

/**
 * Exercise the public MCP JSON-RPC transport rather than the in-process session.
 */
class HelpdeskMcpHttpFlowTest extends TestCase
{
    use CreatesHelpdeskIntegrationSchema;
    use SeedsHelpdeskMasterData;

    private ?string $mcpSessionId = null;

    private int $rpcId = 0;

    private RecordingWhatsAppGateway $whatsAppGateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestSchema();
        Cache::flush();
        Notification::fake();
        $this->seedHelpdeskMasterData();
        $this->whatsAppGateway = new RecordingWhatsAppGateway;
        $this->app->instance(WhatsAppGateway::class, $this->whatsAppGateway);
        config()->set('services.whatsapp_otp.ttl_minutes', 5);
        $this->withToken((string) config('services.helpdesk_mcp.token'));
        $this->initializeMcp();
    }

    public function test_initialize_advertises_vendor_neutral_stateful_contract(): void
    {
        $this->assertNotEmpty($this->mcpSessionId);

        $init = $this->rpc('initialize', [
            'protocolVersion' => '2025-03-26',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'mcp-client', 'version' => '1'],
        ]);
        $init->assertOk();
        $instructions = (string) $init->json('result.instructions');
        $this->assertStringContainsString('intake_id', $instructions);
        $this->assertStringContainsString('helpdesk_intake', $instructions);
        $this->assertStringContainsString('any MCP client', $instructions);
        $this->assertStringContainsString('WhatsApp', $instructions);
        $this->assertStringContainsString('OTP', $instructions);
        $this->assertStringContainsString('registration_consent', $instructions);
        $this->assertStringContainsString('registration_name', $instructions);
        $this->assertStringContainsString('two paths', $instructions);
        $this->assertStringContainsString('terminal cancelled', $instructions);
        $this->assertStringNotContainsString('registration_required', $instructions);
        $this->assertStringNotContainsString('session_key', $instructions);

        $tools = $this->rpc('tools/list');
        $tools->assertOk();
        $names = collect($tools->json('result.tools'))->pluck('name')->all();
        $this->assertSame(['helpdesk_intake'], $names);
        $this->assertNotContains('begin_ticket', $names);
        $this->assertNotContains('create_ticket', $names);
        $tool = collect($tools->json('result.tools'))->firstWhere('name', 'helpdesk_intake');
        $this->assertArrayHasKey('outputSchema', $tool);
        $this->assertArrayHasKey('intake_id', $tool['inputSchema']['properties']);
        $this->assertArrayHasKey('external_message_id', $tool['inputSchema']['properties']);
        $this->assertArrayHasKey('identity_assertion', $tool['inputSchema']['properties']);
        $this->assertArrayNotHasKey('session_key', $tool['inputSchema']['properties']);
        $this->assertArrayNotHasKey('enum', $tool['inputSchema']['properties']['channel']);

        $prompt = $this->rpc('prompts/get', ['name' => 'create_ticket_flow']);
        $prompt->assertOk();
        $promptText = collect($prompt->json('result.messages'))
            ->map(fn ($message) => $message['content']['text'] ?? '')
            ->implode("\n");
        $this->assertStringContainsString('intake_id', $promptText);
        $this->assertStringContainsString('gateway must', $promptText);
        $this->assertStringContainsString('OTP', $promptText);
        $this->assertStringContainsString('registration_consent', $promptText);
        $this->assertStringContainsString('registration_name', $promptText);
        $this->assertStringContainsString('two business paths', $promptText);
        $this->assertStringContainsString('terminal cancelled', $promptText);
        $this->assertStringNotContainsString('registration_required', $promptText);
    }

    public function test_http_whatsapp_script_creates_open_ticket(): void
    {
        $phone = '6281234567001';
        $owner = User::create([
            'name' => 'Apri Http',
            'email' => 'apri-http@example.test',
            'password' => 'secret',
            'phone' => $phone,
            'is_active' => true,
        ]);

        $idle = $this->intake([
            'phone' => $phone,
            'channel' => 'whatsapp',
            'external_conversation_id' => 'wa-chat-7001',
            'external_user_id' => 'wa-user-7001',
            'message' => 'odoo gabisa invoice urgent',
        ]);
        $this->assertSame('idle', $idle['status']);
        $this->assertStringContainsString('lapor', $idle['user_reply']);
        $this->assertSame(0, Ticket::count());

        $opened = $this->intake([
            'phone' => $phone,
            'channel' => 'whatsapp',
            'external_conversation_id' => 'wa-chat-7001',
            'external_user_id' => 'wa-user-7001',
            'message' => 'lapor',
        ]);
        $this->assertSame('phone_otp', $opened['step']);
        $this->assertStringNotContainsString('Apri Http', $opened['user_reply']);
        $this->assertCount(1, $this->whatsAppGateway->messages);

        $intakeId = $opened['intake_id'];
        $verified = $this->intake([
            'intake_id' => $intakeId,
            'channel' => 'whatsapp',
            'external_user_id' => 'wa-user-7001',
            'message' => $this->otpCode(),
        ]);
        $this->assertSame('issue', $verified['step']);
        $this->assertStringContainsString('Apri Http', $verified['user_reply']);
        $this->assertFalse(Cache::has('helpdesk-intake-session:phone:'.$phone));

        $classified = $this->intake([
            'intake_id' => $intakeId,
            'channel' => 'whatsapp',
            'external_user_id' => 'wa-user-7001',
            'message' => 'lapor odoo tidak bisa login',
        ]);
        $this->assertSame('confirm', $classified['data']['step']);
        $this->assertStringContainsString('Odoo tidak bisa login', $classified['user_reply']);
        $this->assertStringContainsString('Odoo Program', $classified['user_reply']);
        $this->assertStringContainsString('Cabang: belum disebut', $classified['user_reply']);
        $this->assertStringContainsString('Prioritas: Medium (antrian)', $classified['user_reply']);
        $this->assertStringNotContainsString('Dicatat ke', $classified['user_reply']);
        $this->assertStringNotContainsString('• Kendala: lapor', $classified['user_reply']);
        $this->assertStringContainsString('Ketik *ya* untuk kirim ke IT', $classified['user_reply']);
        $this->assertStringContainsString('Staf bisa mengubah', $classified['user_reply']);
        $this->assertStringContainsString('apa adanya', $classified['user_reply']);
        $this->assertStringContainsString('Lampiran opsional', $classified['user_reply']);
        $this->assertStringNotContainsString('Jawab semua sisa pilihan', $classified['user_reply']);

        $this->assertSame('confirm', $this->intake([
            'intake_id' => $intakeId,
            'channel' => 'whatsapp',
            'external_user_id' => 'wa-user-7001',
            'message' => '99',
        ])['data']['step']);
        $this->assertSame(0, Ticket::count());

        foreach (['1', '1', '1', '1'] as $choice) {
            $this->intake([
                'intake_id' => $intakeId,
                'channel' => 'whatsapp',
                'external_user_id' => 'wa-user-7001',
                'message' => $choice,
            ]);
        }

        $confirm = $this->intake([
            'intake_id' => $intakeId,
            'channel' => 'whatsapp',
            'external_user_id' => 'wa-user-7001',
            'message' => 'lewati',
        ]);
        $this->assertSame('confirm', $confirm['data']['step']);
        $this->assertStringContainsString('Rekap', $confirm['user_reply']);

        $done = $this->intake([
            'intake_id' => $intakeId,
            'channel' => 'whatsapp',
            'external_user_id' => 'wa-user-7001',
            'message' => 'ya',
        ]);
        $this->assertSame('ticket_created', $done['status']);
        $this->assertMatchesRegularExpression('/HD-\d+/', $done['user_reply']);
        $this->assertSame(1, User::count());
        $this->assertSame(1, Ticket::count());
        $this->assertDatabaseHas('tickets', [
            'owner_id' => $owner->id,
            'ticket_statuses_id' => TicketStatus::OPEN,
            'title' => 'Odoo tidak bisa login',
        ]);
        $ticket = Ticket::firstOrFail();
        $this->assertNotNull($ticket->business_entities_id);
        $this->assertSame('IT', $ticket->unit?->name);
        $this->assertSame('Odoo Program', $ticket->problemCategory?->name);
        $this->assertSame('Medium', $ticket->priority?->name);
        $this->assertSame('Belum disebutkan', $ticket->businessEntity?->name);
        $this->assertNull($ticket->responsible_id);
        $this->assertStringStartsWith('<p>Odoo tidak bisa login</p>', $ticket->description);
        $this->assertStringContainsString('Dilaporkan via Helpdesk MCP', $ticket->description);
        $this->assertStringContainsString('<strong>Kanal:</strong> WhatsApp', $ticket->description);
        $this->assertStringNotContainsString('Urgensi:', $ticket->description);
        $this->assertStringNotContainsString('Dampak:</strong> -', $ticket->description);
        $this->assertSame(1, HelpdeskReporterBinding::count());
        $this->assertSame(0, User::query()->where('identity', 'like', 'mcp-external:%')->count());
    }

    public function test_confirm_ignores_atlas_menus_and_accepts_spoken_yes(): void
    {
        $phone = '6281234567011';
        User::create([
            'name' => 'Apri Confirm',
            'email' => 'apri-confirm@example.test',
            'password' => 'secret',
            'phone' => $phone,
            'is_active' => true,
        ]);

        $opened = $this->intake([
            'phone' => $phone,
            'channel' => 'whatsapp',
            'external_conversation_id' => 'wa-chat-7011',
            'external_user_id' => 'wa-user-7011',
            'message' => 'lapor',
        ]);
        $this->assertSame('phone_otp', $opened['step']);
        $intakeId = $opened['intake_id'];

        $issue = $this->intake([
            'intake_id' => $intakeId,
            'channel' => 'whatsapp',
            'external_user_id' => 'wa-user-7011',
            'message' => $this->otpCode(),
        ]);
        $this->assertSame('issue', $issue['step']);

        $ignoredChoice = $this->intake([
            'intake_id' => $intakeId,
            'channel' => 'whatsapp',
            'external_user_id' => 'wa-user-7011',
            'message' => '1',
        ]);
        $this->assertSame('issue', $ignoredChoice['step']);
        $this->assertStringContainsString('bukan nomor pilihan', $ignoredChoice['user_reply']);

        $summary = $this->intake([
            'intake_id' => $intakeId,
            'channel' => 'whatsapp',
            'external_user_id' => 'wa-user-7011',
            'message' => 'The user reported that the printer at the cashier cannot print.',
        ]);
        $this->assertSame('issue', $summary['step']);
        $this->assertStringContainsString('apa adanya', $summary['user_reply']);

        $classified = $this->intake([
            'intake_id' => $intakeId,
            'channel' => 'whatsapp',
            'external_user_id' => 'wa-user-7011',
            'message' => 'printer kasir tidak bisa print sejak pagi',
        ]);
        $this->assertSame('confirm', $classified['step']);
        $this->assertStringContainsString('Printer kasir tidak bisa print sejak pagi', $classified['user_reply']);
        $this->assertStringContainsString('Laptop, Komputer, Printer', $classified['user_reply']);
        $this->assertStringContainsString('Cabang: belum disebut', $classified['user_reply']);
        $this->assertStringNotContainsString('CCTV', $classified['user_reply']);

        $quiz = $this->intake([
            'intake_id' => $intakeId,
            'channel' => 'whatsapp',
            'external_user_id' => 'wa-user-7011',
            'message' => "Pilih kategori:\n1. CCTV\n2. Odoo Program",
        ]);
        $this->assertSame('confirm', $quiz['step']);
        $this->assertStringContainsString('Printer kasir tidak bisa print sejak pagi', $quiz['user_reply']);
        $this->assertStringNotContainsString('• Kendala: Pilih kategori', $quiz['user_reply']);

        $echo = $this->intake([
            'intake_id' => $intakeId,
            'channel' => 'whatsapp',
            'external_user_id' => 'wa-user-7011',
            'message' => "Rekap laporan (belum tersimpan):\n• Kendala: Printer kasir tidak bisa print sejak pagi\nKetik *ya* untuk kirim ke IT.",
        ]);
        $this->assertSame('confirm', $echo['step']);
        $this->assertStringContainsString('Printer kasir tidak bisa print sejak pagi', $echo['user_reply']);

        $done = $this->intake([
            'intake_id' => $intakeId,
            'channel' => 'whatsapp',
            'external_user_id' => 'wa-user-7011',
            'message' => 'ya silakan',
        ]);
        $this->assertSame('ticket_created', $done['status']);
        $ticket = Ticket::firstOrFail();
        $this->assertSame('Printer kasir tidak bisa print sejak pagi', $ticket->title);
        $this->assertSame('Laptop, Komputer, Printer', $ticket->problemCategory?->name);
        $this->assertSame('Belum disebutkan', $ticket->businessEntity?->name);
        $this->assertNull($ticket->responsible_id);
        $this->assertNotSame('CCTV', $ticket->problemCategory?->name);
    }

    public function test_http_telegram_links_verified_whatsapp_owner_before_creating_ticket(): void
    {
        $phone = '6281255500001';
        $owner = User::create([
            'name' => 'Budi Telegram',
            'email' => 'budi-telegram@example.test',
            'password' => 'secret',
            'phone' => $phone,
            'is_active' => true,
        ]);

        $start = $this->intake([
            'external_conversation_id' => 'telegram-chat-77',
            'external_user_id' => 'telegram-user-77',
            'channel' => 'telegram',
            'message' => 'lapor',
        ]);
        $this->assertSame('phone', $start['step']);
        $this->assertStringContainsString('nomor WhatsApp', $start['user_reply']);

        $otp = $this->intake([
            'intake_id' => $start['intake_id'],
            'channel' => 'telegram',
            'external_user_id' => 'telegram-user-77',
            'message' => $phone,
        ]);
        $this->assertSame('phone_otp', $otp['step']);
        $this->assertStringNotContainsString('Budi Telegram', $otp['user_reply']);

        $verified = $this->intake([
            'intake_id' => $start['intake_id'],
            'channel' => 'telegram',
            'external_user_id' => 'telegram-user-77',
            'message' => $this->otpCode(),
        ]);
        $this->assertSame('issue', $verified['step']);
        $this->assertStringContainsString('Budi Telegram', $verified['user_reply']);

        $this->intake([
            'intake_id' => $start['intake_id'],
            'channel' => 'telegram',
            'external_user_id' => 'telegram-user-77',
            'message' => 'VPN kantor putus',
        ]);
        foreach (['1', '1', '1', '1', 'lewati'] as $message) {
            $this->intake([
                'intake_id' => $start['intake_id'],
                'channel' => 'telegram',
                'external_user_id' => 'telegram-user-77',
                'message' => $message,
            ]);
        }

        $done = $this->intake([
            'intake_id' => $start['intake_id'],
            'channel' => 'telegram',
            'external_user_id' => 'telegram-user-77',
            'message' => 'ya',
        ]);
        $this->assertSame('ticket_created', $done['status'], $done['user_reply']);
        $ticket = Ticket::firstOrFail();
        $this->assertSame($owner->id, $ticket->owner_id);
        $this->assertSame($phone, $ticket->owner->phone);
        $this->assertStringContainsString('<strong>Kanal:</strong> Telegram', $ticket->description);
        $this->assertSame(TicketStatus::OPEN, $ticket->ticket_statuses_id);
        $this->assertSame(0, User::query()->whereNull('phone')->count());
    }

    public function test_trusted_whatsapp_gateway_assertion_skips_otp_but_plain_channel_label_does_not(): void
    {
        config()->set('services.helpdesk_mcp.identity_assertion_secret', 'trusted-gateway-secret');
        config()->set('services.helpdesk_mcp.identity_assertion_leeway_seconds', 300);
        $phone = '6281234567099';
        User::create([
            'name' => 'Sender WhatsApp Terpercaya',
            'email' => 'trusted-wa@example.test',
            'password' => 'secret',
            'phone' => $phone,
            'is_active' => true,
        ]);
        $externalUserId = 'wa-jid-7099';
        $externalMessageId = 'wa-webhook-event-7099';
        $timestamp = now()->addSeconds(300)->timestamp;
        $clientKey = hash('sha256', (string) config('services.helpdesk_mcp.token'));
        $payload = implode("\n", [
            'v1',
            (string) $timestamp,
            $clientKey,
            'whatsapp',
            $externalUserId,
            $phone,
            $externalMessageId,
        ]);
        $assertion = 'v1.'.$timestamp.'.'.strtoupper(hash_hmac('sha256', $payload, 'trusted-gateway-secret'));

        $started = $this->intake([
            'channel' => 'whatsapp',
            'external_conversation_id' => 'wa-thread-trusted',
            'external_user_id' => $externalUserId,
            'external_message_id' => $externalMessageId,
            'phone' => $phone,
            'identity_assertion' => $assertion,
            'message' => 'VPN kantor mati',
            'start' => true,
        ]);

        $this->assertSame('confirm', $started['step']);
        $this->assertStringContainsString('Sender WhatsApp Terpercaya', $started['user_reply']);
        $this->assertCount(0, $this->whatsAppGateway->messages);

        $this->travel(301)->seconds();
        Cache::flush();
        $replayedIntoAnotherIntake = $this->intake([
            'channel' => 'whatsapp',
            'external_conversation_id' => 'wa-thread-attacker',
            'external_user_id' => $externalUserId,
            'external_message_id' => $externalMessageId,
            'phone' => $phone,
            'identity_assertion' => $assertion,
            'message' => 'VPN kantor mati',
            'start' => true,
        ]);
        $this->assertSame('phone_otp', $replayedIntoAnotherIntake['step']);
        $this->assertCount(1, $this->whatsAppGateway->messages);
        $this->travelBack();
    }

    public function test_v2_whatsapp_assertion_binds_the_inbound_message_digest(): void
    {
        config()->set('services.helpdesk_mcp.identity_assertion_secret', 'trusted-gateway-secret');
        config()->set('services.helpdesk_mcp.identity_assertion_leeway_seconds', 300);
        $phone = '6281234567098';
        User::create([
            'name' => 'Sender WhatsApp V2',
            'email' => 'trusted-wa-v2@example.test',
            'password' => 'secret',
            'phone' => $phone,
            'is_active' => true,
        ]);
        $externalUserId = 'wa-jid-7098';
        $externalMessageId = 'wa-webhook-event-7098';
        $message = 'printer kasir tidak bisa print';
        $timestamp = now()->timestamp;
        $clientKey = hash('sha256', (string) config('services.helpdesk_mcp.token'));
        $payload = implode("\n", [
            'v2',
            (string) $timestamp,
            $clientKey,
            'whatsapp',
            $externalUserId,
            $phone,
            $externalMessageId,
            hash('sha256', $message),
        ]);
        $assertion = 'v2.'.$timestamp.'.'.hash_hmac('sha256', $payload, 'trusted-gateway-secret');

        $started = $this->intake([
            'channel' => 'whatsapp',
            'external_conversation_id' => 'wa-thread-trusted-v2',
            'external_user_id' => $externalUserId,
            'external_message_id' => $externalMessageId,
            'phone' => $phone,
            'identity_assertion' => $assertion,
            'message' => $message,
            'start' => true,
        ]);
        $this->assertSame('confirm', $started['step']);
        $this->assertCount(0, $this->whatsAppGateway->messages);

        $forgedMessageId = $externalMessageId.'-summary';
        $forgedPayload = implode("\n", [
            'v2',
            (string) $timestamp,
            $clientKey,
            'whatsapp',
            $externalUserId,
            $phone,
            $forgedMessageId,
            hash('sha256', $message),
        ]);
        $forgedAssertion = 'v2.'.$timestamp.'.'.hash_hmac('sha256', $forgedPayload, 'trusted-gateway-secret');

        $summarized = $this->intake([
            'channel' => 'whatsapp',
            'external_conversation_id' => 'wa-thread-trusted-v2-summarized',
            'external_user_id' => $externalUserId,
            'external_message_id' => $forgedMessageId,
            'phone' => $phone,
            'identity_assertion' => $forgedAssertion,
            'message' => 'The user reported a printer issue',
            'start' => true,
        ]);
        $this->assertSame('phone_otp', $summarized['step']);
        $this->assertCount(1, $this->whatsAppGateway->messages);
    }

    public function test_direct_client_conversation_id_separates_parallel_intakes(): void
    {
        $first = $this->intake([
            'channel' => 'mcp',
            'conversation_id' => 'direct-conversation-a',
            'message' => 'lapor',
        ]);
        $second = $this->intake([
            'channel' => 'mcp',
            'conversation_id' => 'direct-conversation-b',
            'message' => 'lapor',
        ]);

        $this->assertSame('phone', $first['step']);
        $this->assertSame('phone', $second['step']);
        $this->assertNotSame($first['intake_id'], $second['intake_id']);
    }

    public function test_direct_client_transport_sessions_separate_chats_without_conversation_id(): void
    {
        $firstSession = $this->mcpSessionId;
        $first = $this->intake([
            'channel' => 'mcp',
            'message' => 'lapor',
        ]);

        $this->mcpSessionId = null;
        $this->initializeMcp();
        $this->assertNotSame($firstSession, $this->mcpSessionId);
        $second = $this->intake([
            'channel' => 'mcp',
            'message' => 'lapor',
        ]);

        $this->assertSame('phone', $first['step']);
        $this->assertSame('phone', $second['step']);
        $this->assertNotSame($first['intake_id'], $second['intake_id']);
    }

    public function test_shared_transport_session_does_not_merge_parallel_direct_intakes(): void
    {
        $first = $this->intake([
            'channel' => 'mcp',
            'message' => 'lapor printer kasir A',
            'start' => true,
        ]);
        $second = $this->intake([
            'channel' => 'mcp',
            'message' => 'lapor printer kasir B',
            'start' => true,
        ]);

        $this->assertSame('phone', $first['step']);
        $this->assertSame('phone', $second['step']);
        $this->assertNotSame($first['intake_id'], $second['intake_id']);

        $continueFirst = $this->intake([
            'intake_id' => $first['intake_id'],
            'channel' => 'mcp',
            'message' => '081234567001',
        ]);
        $continueSecond = $this->intake([
            'intake_id' => $second['intake_id'],
            'channel' => 'mcp',
            'message' => '081234567002',
        ]);

        $this->assertSame($first['intake_id'], $continueFirst['intake_id']);
        $this->assertSame($second['intake_id'], $continueSecond['intake_id']);
        $this->assertSame('phone_otp', $continueFirst['step']);
        $this->assertSame('phone_otp', $continueSecond['step']);
        $this->assertCount(2, $this->whatsAppGateway->messages);
    }

    public function test_empty_message_without_attachment_is_rejected(): void
    {
        $invalid = $this->intake([
            'channel' => 'mcp',
            'message' => '',
        ], true);

        $this->assertSame('invalid_input', $invalid['status']);
        $this->assertSame('INVALID_MESSAGE', $invalid['data']['error_code']);
    }

    public function test_talenta_identity_is_hidden_until_otp_then_becomes_ticket_owner(): void
    {
        $phone = '6281234567088';
        $lookupCalls = 0;
        $employees = $this->mock(EmployeeService::class);
        $employees->shouldReceive('findAllByPhone')
            ->twice()
            ->with($phone)
            ->andReturnUsing(function () use (&$lookupCalls): array {
                $lookupCalls++;

                return [[
                    'first_name' => 'Ayu',
                    'last_name' => 'Talenta',
                    'email' => 'ayu-talenta@example.test',
                    'id_employee' => 'EMP-7088',
                ]];
            });

        $started = $this->intake([
            'channel' => 'discord',
            'external_conversation_id' => 'discord-thread-7088',
            'external_user_id' => 'discord-user-7088',
            'phone' => $phone,
            'reporter_name' => 'Nama Palsu',
            'message' => 'printer kasir mati',
            'start' => true,
        ]);
        $this->assertSame('phone_otp', $started['step']);
        $this->assertStringNotContainsString('Ayu Talenta', $started['user_reply']);
        $this->assertSame(0, User::count());
        $this->assertSame(0, $lookupCalls);

        $verified = $this->intake([
            'intake_id' => $started['intake_id'],
            'channel' => 'discord',
            'external_user_id' => 'discord-user-7088',
            'message' => $this->otpCode(),
        ]);
        $this->assertSame('confirm', $verified['step']);
        $this->assertStringContainsString('Ayu Talenta', $verified['user_reply']);
        $this->assertSame(1, $lookupCalls);

        foreach (['1', '1', '1', '1', 'lewati'] as $message) {
            $this->intake([
                'intake_id' => $started['intake_id'],
                'channel' => 'discord',
                'external_user_id' => 'discord-user-7088',
                'message' => $message,
            ]);
        }
        $done = $this->intake([
            'intake_id' => $started['intake_id'],
            'channel' => 'discord',
            'external_user_id' => 'discord-user-7088',
            'message' => 'ya',
        ]);

        $this->assertSame('ticket_created', $done['status']);
        $this->assertSame(2, $lookupCalls);
        $reporter = User::query()->where('phone', $phone)->firstOrFail();
        $this->assertSame('Ayu Talenta', $reporter->name);
        $this->assertSame('EMP-7088', $reporter->identity);
        $this->assertSame($reporter->id, Ticket::firstOrFail()->owner_id);
    }

    public function test_duplicate_talenta_phone_fails_closed_after_otp(): void
    {
        $phone = '6281234567077';
        $this->mock(EmployeeService::class)
            ->shouldReceive('findAllByPhone')
            ->once()
            ->with($phone)
            ->andReturn([
                ['first_name' => 'User', 'last_name' => 'A', 'id_employee' => 'EMP-A'],
                ['first_name' => 'User', 'last_name' => 'B', 'id_employee' => 'EMP-B'],
            ]);

        $started = $this->intake([
            'channel' => 'telegram',
            'external_user_id' => 'duplicate-talenta-user',
            'phone' => $phone,
            'message' => 'VPN kantor mati',
            'start' => true,
        ]);
        $this->assertSame('phone_otp', $started['step']);

        $blocked = $this->intake([
            'intake_id' => $started['intake_id'],
            'channel' => 'telegram',
            'external_user_id' => 'duplicate-talenta-user',
            'message' => $this->otpCode(),
        ]);
        $this->assertSame('phone', $blocked['step']);
        $this->assertSame('TALENTA_PHONE_AMBIGUOUS', $blocked['data']['error_code']);
        $this->assertStringNotContainsString('User A', $blocked['user_reply']);
        $this->assertStringNotContainsString('User B', $blocked['user_reply']);
        $this->assertSame(0, User::count());
        $this->assertSame(0, Ticket::count());
    }

    public function test_active_placeholder_helpdesk_user_wins_before_ambiguous_talenta_data(): void
    {
        $phone = '6281234567076';
        User::create([
            'name' => 'Pelapor 7076',
            'email' => null,
            'password' => null,
            'phone' => '0812-3456-7076',
            'is_active' => true,
        ]);
        $this->mock(EmployeeService::class)
            ->shouldNotReceive('findAllByPhone');

        $started = $this->intake([
            'channel' => 'telegram',
            'external_user_id' => 'placeholder-helpdesk-user',
            'phone' => $phone,
            'message' => 'VPN kantor mati',
            'start' => true,
        ]);
        $this->assertSame('phone_otp', $started['step']);

        $continued = $this->intake([
            'intake_id' => $started['intake_id'],
            'channel' => 'telegram',
            'external_user_id' => 'placeholder-helpdesk-user',
            'message' => $this->otpCode(),
        ]);

        $this->assertSame('confirm', $continued['step']);
        $this->assertStringContainsString('Pelapor 7076', $continued['user_reply']);
        $this->assertSame(1, User::count());
        $this->assertSame(0, Ticket::count());
    }

    public function test_duplicate_normalized_helpdesk_phone_fails_closed_after_otp(): void
    {
        User::create([
            'name' => 'Akun Format Internasional',
            'email' => 'international@example.test',
            'password' => 'secret',
            'phone' => '6281234567066',
            'is_active' => true,
        ]);
        User::create([
            'name' => 'Akun Format Lokal',
            'email' => 'local@example.test',
            'password' => 'secret',
            'phone' => '081234567066',
            'is_active' => true,
        ]);

        $started = $this->intake([
            'channel' => 'web',
            'external_user_id' => 'duplicate-helpdesk-user',
            'phone' => '6281234567066',
            'message' => 'laptop tidak bisa login',
            'start' => true,
        ]);
        $this->assertSame('phone_otp', $started['step']);

        $blocked = $this->intake([
            'intake_id' => $started['intake_id'],
            'channel' => 'web',
            'external_user_id' => 'duplicate-helpdesk-user',
            'message' => $this->otpCode(),
        ]);
        $this->assertSame('phone', $blocked['step']);
        $this->assertSame('HELPDESK_PHONE_AMBIGUOUS', $blocked['data']['error_code']);
        $this->assertSame(0, Ticket::count());
    }

    public function test_inactive_formatted_helpdesk_phone_is_blocked_before_talenta_lookup(): void
    {
        $phone = '6281234567055';
        User::create([
            'name' => 'Akun Nonaktif',
            'email' => 'inactive-mcp@example.test',
            'password' => 'secret',
            'phone' => '0812-3456-7055',
            'is_active' => false,
        ]);
        $this->mock(EmployeeService::class)
            ->shouldNotReceive('findAllByPhone');

        $started = $this->intake([
            'channel' => 'discord',
            'external_user_id' => 'inactive-helpdesk-user',
            'phone' => $phone,
            'message' => 'email kantor terkunci',
            'start' => true,
        ]);
        $this->assertSame('phone_otp', $started['step']);

        $blocked = $this->intake([
            'intake_id' => $started['intake_id'],
            'channel' => 'discord',
            'external_user_id' => 'inactive-helpdesk-user',
            'message' => $this->otpCode(),
        ]);

        $this->assertSame('in_progress', $blocked['status']);
        $this->assertSame('phone', $blocked['step']);
        $this->assertSame('HELPDESK_ACTOR_INACTIVE', $blocked['data']['error_code']);
        $this->assertStringContainsString('tidak aktif', $blocked['user_reply']);
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, HelpdeskReporterBinding::count());
    }

    public function test_ticket_creation_rejects_a_helpdesk_owner_that_changes_after_identity_resolution(): void
    {
        $phone = '6280000098733';
        $originalOwner = User::query()->create([
            'name' => 'Owner Saat Verifikasi',
            'email' => null,
            'password' => null,
            'phone' => $phone,
            'is_active' => true,
        ]);
        $started = $this->intake([
            'channel' => 'web',
            'external_user_id' => 'web-user-owner-drift',
            'external_message_id' => 'web-owner-drift-1',
            'phone' => $phone,
            'message' => 'printer gudang mati',
            'start' => true,
        ]);
        $this->assertSame('phone_otp', $started['step']);

        $intakeId = $started['intake_id'];
        $verified = $this->intake([
            'intake_id' => $intakeId,
            'channel' => 'web',
            'external_user_id' => 'web-user-owner-drift',
            'external_message_id' => 'web-owner-drift-2',
            'message' => $this->otpCode(),
        ]);
        $this->assertSame('confirm', $verified['step']);
        $this->assertStringContainsString('Owner Saat Verifikasi', $verified['user_reply']);

        foreach (['1', '1', '1', '1', 'lewati'] as $index => $message) {
            $this->intake([
                'intake_id' => $intakeId,
                'channel' => 'web',
                'external_user_id' => 'web-user-owner-drift',
                'external_message_id' => 'web-owner-drift-'.($index + 3),
                'message' => $message,
            ]);
        }

        $originalOwner->update(['phone' => '6280000098734']);
        User::query()->create([
            'name' => 'Owner Baru Tidak Boleh Dipakai',
            'email' => null,
            'password' => null,
            'phone' => $phone,
            'is_active' => true,
        ]);

        $rejected = $this->intake([
            'intake_id' => $intakeId,
            'channel' => 'web',
            'external_user_id' => 'web-user-owner-drift',
            'external_message_id' => 'web-owner-drift-8',
            'message' => 'ya',
        ]);

        $this->assertSame('in_progress', $rejected['status']);
        $this->assertSame('phone', $rejected['step']);
        $this->assertSame('HELPDESK_ACTOR_CHANGED', $rejected['data']['error_code']);
        $this->assertStringContainsString('Verifikasi ulang', $rejected['user_reply']);
        $this->assertStringNotContainsString('Owner Baru Tidak Boleh Dipakai', $rejected['user_reply']);
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, HelpdeskReporterBinding::count());
        $this->assertDatabaseCount('mcp_ticket_creation_requests', 0);
    }

    public function test_unknown_reporter_registers_inline_and_continues_the_same_intake_idempotently(): void
    {
        $phone = '6280000098765';
        $started = $this->intake([
            'channel' => 'web',
            'external_conversation_id' => 'web-thread-new-reporter',
            'external_user_id' => 'web-user-new-reporter',
            'external_message_id' => 'web-message-new-reporter-1',
            'phone' => $phone,
            'reporter_name' => 'Nama Hint Tidak Dipercaya',
            'message' => 'email kantor terkunci',
            'start' => true,
        ]);
        $this->assertSame('phone_otp', $started['step']);

        $otpCode = $this->otpCode();
        $intakeId = $started['intake_id'];
        $continue = fn (string $message, string $messageId, bool $expectError = false): array => $this->intake([
            'intake_id' => $intakeId,
            'channel' => 'web',
            'external_conversation_id' => 'web-thread-new-reporter',
            'external_user_id' => 'web-user-new-reporter',
            'external_message_id' => $messageId,
            'message' => $message,
        ], $expectError);

        $offered = $continue($otpCode, 'web-message-new-reporter-2');
        $this->assertSame('in_progress', $offered['status']);
        $this->assertSame('registration_consent', $offered['step']);
        $this->assertSame($intakeId, $offered['intake_id']);
        $this->assertTrue($offered['ok']);
        $this->assertFalse($offered['terminal']);
        $this->assertTrue($offered['requires_input']);
        $this->assertStringContainsString('belum ada di Helpdesk atau Talenta', $offered['user_reply']);
        $this->assertStringContainsString('tanpa email dan password', $offered['user_reply']);
        $this->assertStringNotContainsString('Nama Hint Tidak Dipercaya', $offered['user_reply']);
        $this->assertSame(0, User::count());
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, HelpdeskReporterBinding::count());

        $withoutConsent = $continue('Nama Langsung Tanpa Persetujuan', 'web-message-new-reporter-3');
        $this->assertSame('registration_consent', $withoutConsent['step']);
        $this->assertSame(0, User::count());

        $askName = $continue('ya', 'web-message-new-reporter-4');
        $this->assertSame('registration_name', $askName['step']);
        $this->assertSame($intakeId, $askName['intake_id']);
        $this->assertSame(0, User::count());

        $replayedConsent = $continue('ya', 'web-message-new-reporter-4');
        $this->assertSame('registration_name', $replayedConsent['step']);
        $this->assertTrue($replayedConsent['replayed']);
        $this->assertSame(0, User::count());

        $invalidName = $continue('12345', 'web-message-new-reporter-4-invalid-name');
        $this->assertSame('registration_name', $invalidName['step']);
        $this->assertSame(0, User::count());

        $continued = $continue('Reporter MCP Inline', 'web-message-new-reporter-5');
        $this->assertSame('in_progress', $continued['status']);
        $this->assertSame('confirm', $continued['step']);
        $this->assertSame($intakeId, $continued['intake_id']);
        $this->assertStringContainsString('Reporter MCP Inline', $continued['user_reply']);

        $reporter = User::query()->where('phone_normalized', $phone)->firstOrFail();
        $this->assertSame('Reporter MCP Inline', $reporter->name);
        $this->assertSame($phone, $reporter->phone);
        $this->assertNull($reporter->email);
        $this->assertNull($reporter->password);
        $this->assertNull($reporter->email_verified_at);
        $this->assertNull($reporter->email_verified_via);
        $this->assertTrue($reporter->is_active);
        $this->assertSame(1, HelpdeskReporterBinding::count());
        $this->assertSame(0, Ticket::count());

        $replayedName = $continue('Reporter MCP Inline', 'web-message-new-reporter-5');
        $this->assertSame('confirm', $replayedName['step']);
        $this->assertTrue($replayedName['replayed']);
        $this->assertSame($continued['user_reply'], $replayedName['user_reply']);
        $this->assertSame(1, User::count());

        $messageConflict = $continue('Nama Berbeda', 'web-message-new-reporter-5', true);
        $this->assertSame('message_conflict', $messageConflict['status']);
        $this->assertSame('SOURCE_MESSAGE_ID_REUSED', $messageConflict['data']['error_code']);
        $this->assertSame('Reporter MCP Inline', $reporter->fresh()->name);

        foreach (['1', '1', '1', '1', 'lewati'] as $index => $message) {
            $continue($message, 'web-message-new-reporter-'.($index + 6));
        }
        $done = $continue('ya', 'web-message-new-reporter-11');

        $this->assertSame('ticket_created', $done['status']);
        $this->assertSame($intakeId, $done['intake_id']);
        $this->assertSame(1, User::count());
        $this->assertSame(1, Ticket::count());
        $this->assertSame($reporter->id, Ticket::firstOrFail()->owner_id);
        $this->assertSame('Email kantor terkunci', Ticket::firstOrFail()->title);
        $this->assertSame(1, HelpdeskReporterBinding::count());

        $linkedNextIntake = $this->intake([
            'channel' => 'web',
            'external_conversation_id' => 'web-thread-new-reporter-next',
            'external_user_id' => 'web-user-new-reporter',
            'external_message_id' => 'web-message-new-reporter-next-1',
            'message' => 'printer cabang tidak tersambung',
            'start' => true,
        ]);
        $this->assertSame('confirm', $linkedNextIntake['step']);
        $this->assertStringContainsString('Reporter MCP Inline', $linkedNextIntake['user_reply']);
        $this->assertCount(1, $this->whatsAppGateway->messages);
    }

    public function test_declined_inline_registration_returns_replayable_cancellation_without_writes(): void
    {
        $phone = '6280000098755';
        $started = $this->intake([
            'channel' => 'web',
            'external_conversation_id' => 'web-thread-registration-declined',
            'external_user_id' => 'web-user-registration-declined',
            'external_message_id' => 'web-message-registration-declined-1',
            'phone' => $phone,
            'message' => 'printer kasir tidak bisa dipakai',
            'start' => true,
        ]);
        $this->assertSame('phone_otp', $started['step']);

        $offered = $this->intake([
            'intake_id' => $started['intake_id'],
            'channel' => 'web',
            'external_conversation_id' => 'web-thread-registration-declined',
            'external_user_id' => 'web-user-registration-declined',
            'external_message_id' => 'web-message-registration-declined-2',
            'message' => $this->otpCode(),
        ]);
        $this->assertSame('registration_consent', $offered['step']);

        $declinePayload = [
            'intake_id' => $started['intake_id'],
            'channel' => 'web',
            'external_conversation_id' => 'web-thread-registration-declined',
            'external_user_id' => 'web-user-registration-declined',
            'external_message_id' => 'web-message-registration-declined-3',
            'message' => 'tidak',
        ];
        $declined = $this->intake($declinePayload);

        $this->assertSame('cancelled', $declined['status']);
        $this->assertSame('idle', $declined['step']);
        $this->assertTrue($declined['ok']);
        $this->assertTrue($declined['terminal']);
        $this->assertFalse($declined['requires_input']);
        $this->assertArrayNotHasKey('error_code', $declined['data']);
        $this->assertArrayNotHasKey('registration_url', $declined['data']);
        $this->assertStringContainsString('Tidak ada akun atau tiket yang dibuat', $declined['user_reply']);
        $this->assertStringNotContainsString('/phone-login', $declined['user_reply']);
        $this->assertSame(0, User::count());
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, HelpdeskReporterBinding::count());

        $replayed = $this->intake($declinePayload);
        $this->assertSame('cancelled', $replayed['status']);
        $this->assertTrue($replayed['ok']);
        $this->assertTrue($replayed['terminal']);
        $this->assertArrayNotHasKey('registration_url', $replayed['data']);
        $this->assertTrue($replayed['replayed']);
        $this->assertSame($declined['user_reply'], $replayed['user_reply']);
        $this->assertSame(0, User::count());
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, HelpdeskReporterBinding::count());
    }

    public function test_account_required_at_ticket_write_boundary_preserves_the_completed_draft(): void
    {
        $phone = '6280000098991';
        $owner = User::query()->create([
            'name' => 'Reporter Boundary Lama',
            'email' => null,
            'password' => null,
            'phone' => $phone,
            'is_active' => true,
        ]);
        $this->mock(EmployeeService::class)
            ->shouldReceive('findAllByPhone')
            ->once()
            ->with($phone)
            ->andReturn([]);
        $this->mock(HelpdeskTicketCreationService::class)
            ->shouldReceive('create')
            ->once()
            ->andReturn([
                'ok' => false,
                'status' => 'account_required',
                'message' => 'Akun Helpdesk pelapor belum tersedia.',
                'data' => [],
                'error' => [
                    'code' => 'HELPDESK_REPORTER_ACCOUNT_REQUIRED',
                    'message' => 'Akun Helpdesk pelapor belum tersedia.',
                ],
            ]);

        $started = $this->intake([
            'channel' => 'web',
            'external_conversation_id' => 'web-thread-write-boundary',
            'external_user_id' => 'web-user-write-boundary',
            'external_message_id' => 'web-message-write-boundary-1',
            'phone' => $phone,
            'message' => 'router gudang mati total',
            'start' => true,
        ]);
        $this->assertSame('phone_otp', $started['step']);

        $messageNumber = 2;
        $continue = function (string $message) use ($started, &$messageNumber): array {
            return $this->intake([
                'intake_id' => $started['intake_id'],
                'channel' => 'web',
                'external_conversation_id' => 'web-thread-write-boundary',
                'external_user_id' => 'web-user-write-boundary',
                'external_message_id' => 'web-message-write-boundary-'.$messageNumber++,
                'message' => $message,
            ]);
        };

        $this->assertSame('confirm', $continue($this->otpCode())['step']);
        foreach (['1', '1', '1', '1', 'lewati'] as $message) {
            $draft = $continue($message);
        }
        $this->assertSame('confirm', $draft['step']);

        $accountRequired = $continue('ya');
        $this->assertSame('registration_consent', $accountRequired['step']);
        $this->assertSame($started['intake_id'], $accountRequired['intake_id']);

        $owner->forceFill([
            'phone' => '6280000098992',
            'phone_normalized' => '6280000098992',
        ])->saveQuietly();

        $this->assertSame('registration_name', $continue('ya')['step']);
        $resumedDraft = $continue('Reporter Boundary Baru');

        $this->assertSame('confirm', $resumedDraft['step']);
        $this->assertSame($started['intake_id'], $resumedDraft['intake_id']);
        $this->assertStringContainsString('router gudang mati total', $resumedDraft['user_reply']);
        $this->assertStringContainsString('Reporter Boundary Baru', $resumedDraft['user_reply']);
        $this->assertSame(0, Ticket::count());
        $this->assertSame(1, User::query()->where('phone_normalized', $phone)->count());
    }

    public function test_inline_registration_re_resolves_when_talenta_appears_before_name_is_saved(): void
    {
        $phone = '6280000098766';
        $employee = [
            'id_employee' => 'EMP-MCP-98766',
            'first_name' => 'Reporter',
            'last_name' => 'Talenta Baru',
            'mobile_phone' => $phone,
            'email' => 'reporter-talenta-baru@example.test',
        ];
        $employees = $this->mock(EmployeeService::class);
        $employees->shouldReceive('findAllByPhone')
            ->times(3)
            ->with($phone)
            ->andReturn([], [$employee], [$employee]);

        $started = $this->intake([
            'channel' => 'web',
            'external_user_id' => 'web-user-talenta-race',
            'external_message_id' => 'web-talenta-race-1',
            'phone' => $phone,
            'message' => 'akses ERP terkunci',
            'start' => true,
        ]);
        $this->assertSame('phone_otp', $started['step']);

        $offered = $this->intake([
            'intake_id' => $started['intake_id'],
            'channel' => 'web',
            'external_user_id' => 'web-user-talenta-race',
            'external_message_id' => 'web-talenta-race-2',
            'message' => $this->otpCode(),
        ]);
        $this->assertSame('registration_consent', $offered['step']);

        $askedName = $this->intake([
            'intake_id' => $started['intake_id'],
            'channel' => 'web',
            'external_user_id' => 'web-user-talenta-race',
            'external_message_id' => 'web-talenta-race-3',
            'message' => 'ya',
        ]);
        $this->assertSame('registration_name', $askedName['step']);

        $reResolved = $this->intake([
            'intake_id' => $started['intake_id'],
            'channel' => 'web',
            'external_user_id' => 'web-user-talenta-race',
            'external_message_id' => 'web-talenta-race-4',
            'message' => 'Nama Manual Tidak Boleh Dibuat',
        ]);

        $this->assertSame('confirm', $reResolved['step']);
        $this->assertSame($started['intake_id'], $reResolved['intake_id']);
        $this->assertStringContainsString('Reporter Talenta Baru', $reResolved['user_reply']);
        $this->assertStringNotContainsString('Nama Manual Tidak Boleh Dibuat', $reResolved['user_reply']);
        $this->assertSame(0, User::count());
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, HelpdeskReporterBinding::count());
    }

    public function test_active_helpdesk_account_that_appears_after_consent_wins_without_name_overwrite(): void
    {
        $phone = '6280000098767';
        $started = $this->intake([
            'channel' => 'discord',
            'external_user_id' => 'discord-user-helpdesk-race',
            'external_message_id' => 'discord-helpdesk-race-1',
            'phone' => $phone,
            'message' => 'aplikasi kasir terkunci',
            'start' => true,
        ]);
        $this->assertSame('phone_otp', $started['step']);

        $offered = $this->intake([
            'intake_id' => $started['intake_id'],
            'channel' => 'discord',
            'external_user_id' => 'discord-user-helpdesk-race',
            'external_message_id' => 'discord-helpdesk-race-2',
            'message' => $this->otpCode(),
        ]);
        $this->assertSame('registration_consent', $offered['step']);

        $askedName = $this->intake([
            'intake_id' => $started['intake_id'],
            'channel' => 'discord',
            'external_user_id' => 'discord-user-helpdesk-race',
            'external_message_id' => 'discord-helpdesk-race-3',
            'message' => 'ya',
        ]);
        $this->assertSame('registration_name', $askedName['step']);

        $winner = User::query()->create([
            'name' => 'Nama Autoritatif Helpdesk',
            'email' => null,
            'password' => null,
            'phone' => $phone,
            'is_active' => true,
        ]);

        $continued = $this->intake([
            'intake_id' => $started['intake_id'],
            'channel' => 'discord',
            'external_user_id' => 'discord-user-helpdesk-race',
            'external_message_id' => 'discord-helpdesk-race-4',
            'message' => 'Nama Manual Tidak Boleh Menang',
        ]);

        $this->assertSame('confirm', $continued['step']);
        $this->assertSame($started['intake_id'], $continued['intake_id']);
        $this->assertStringContainsString('Nama Autoritatif Helpdesk', $continued['user_reply']);
        $this->assertStringNotContainsString('Nama Manual Tidak Boleh Menang', $continued['user_reply']);
        $this->assertSame('Nama Autoritatif Helpdesk', $winner->fresh()->name);
        $this->assertSame(1, User::count());
        $this->assertSame(0, Ticket::count());
        $binding = HelpdeskReporterBinding::query()->firstOrFail();
        $this->assertSame($winner->id, $binding->user_id);
    }

    public function test_inactive_helpdesk_account_that_appears_after_consent_blocks_registration(): void
    {
        $phone = '6280000098768';
        $started = $this->intake([
            'channel' => 'telegram',
            'external_user_id' => 'telegram-user-inactive-race',
            'external_message_id' => 'telegram-inactive-race-1',
            'phone' => $phone,
            'message' => 'akses email terkunci',
            'start' => true,
        ]);
        $this->assertSame('phone_otp', $started['step']);

        $offered = $this->intake([
            'intake_id' => $started['intake_id'],
            'channel' => 'telegram',
            'external_user_id' => 'telegram-user-inactive-race',
            'external_message_id' => 'telegram-inactive-race-2',
            'message' => $this->otpCode(),
        ]);
        $this->assertSame('registration_consent', $offered['step']);

        $askedName = $this->intake([
            'intake_id' => $started['intake_id'],
            'channel' => 'telegram',
            'external_user_id' => 'telegram-user-inactive-race',
            'external_message_id' => 'telegram-inactive-race-3',
            'message' => 'ya',
        ]);
        $this->assertSame('registration_name', $askedName['step']);

        User::query()->create([
            'name' => 'Akun Mendadak Nonaktif',
            'email' => null,
            'password' => null,
            'phone' => $phone,
            'is_active' => false,
        ]);
        $blocked = $this->intake([
            'intake_id' => $started['intake_id'],
            'channel' => 'telegram',
            'external_user_id' => 'telegram-user-inactive-race',
            'external_message_id' => 'telegram-inactive-race-4',
            'message' => 'Nama Manual Tidak Boleh Dibuat',
        ]);

        $this->assertSame('phone', $blocked['step']);
        $this->assertSame('HELPDESK_ACTOR_INACTIVE', $blocked['data']['error_code']);
        $this->assertSame(1, User::query()->withTrashed()->count());
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, HelpdeskReporterBinding::count());
    }

    public function test_trusted_whatsapp_assertion_cannot_bypass_unknown_reporter_registration(): void
    {
        config()->set('services.helpdesk_mcp.identity_assertion_secret', 'trusted-gateway-secret');
        config()->set('services.helpdesk_mcp.identity_assertion_leeway_seconds', 300);
        $phone = '6280000098777';
        $externalUserId = 'wa-unknown-98777';
        $externalMessageId = 'wa-unknown-message-98777';
        $timestamp = now()->timestamp;
        $clientKey = hash('sha256', (string) config('services.helpdesk_mcp.token'));
        $payload = implode("\n", [
            'v1',
            (string) $timestamp,
            $clientKey,
            'whatsapp',
            $externalUserId,
            $phone,
            $externalMessageId,
        ]);
        $assertion = 'v1.'.$timestamp.'.'.hash_hmac('sha256', $payload, 'trusted-gateway-secret');

        $offered = $this->intake([
            'channel' => 'whatsapp',
            'external_conversation_id' => 'wa-unknown-thread-98777',
            'external_user_id' => $externalUserId,
            'external_message_id' => $externalMessageId,
            'phone' => $phone,
            'reporter_name' => 'Nama Assertion Palsu',
            'identity_assertion' => $assertion,
            'message' => 'printer kasir mati',
            'start' => true,
        ]);

        $this->assertSame('in_progress', $offered['status']);
        $this->assertSame('registration_consent', $offered['step']);
        $this->assertFalse($offered['terminal']);
        $this->assertTrue($offered['requires_input']);
        $this->assertStringNotContainsString('Nama Assertion Palsu', $offered['user_reply']);
        $this->assertCount(0, $this->whatsAppGateway->messages);
        $this->assertSame(0, User::count());
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, HelpdeskReporterBinding::count());

        $withoutConsent = $this->intake([
            'intake_id' => $offered['intake_id'],
            'channel' => 'whatsapp',
            'external_user_id' => $externalUserId,
            'external_message_id' => 'wa-unknown-message-98777-name-without-consent',
            'message' => 'Nama Dipaksakan',
        ]);
        $this->assertSame('registration_consent', $withoutConsent['step']);
        $this->assertSame(0, User::count());

        $askName = $this->intake([
            'intake_id' => $offered['intake_id'],
            'channel' => 'whatsapp',
            'external_user_id' => $externalUserId,
            'external_message_id' => 'wa-unknown-message-98777-consent',
            'message' => 'ya',
        ]);
        $this->assertSame('registration_name', $askName['step']);
        $this->assertSame(0, User::count());

        $continued = $this->intake([
            'intake_id' => $offered['intake_id'],
            'channel' => 'whatsapp',
            'external_user_id' => $externalUserId,
            'external_message_id' => 'wa-unknown-message-98777-name',
            'message' => 'Reporter Assertion Resmi',
        ]);
        $this->assertSame('confirm', $continued['step']);
        $this->assertSame($offered['intake_id'], $continued['intake_id']);
        $this->assertStringContainsString('Reporter Assertion Resmi', $continued['user_reply']);
        $reporter = User::query()->where('phone_normalized', $phone)->firstOrFail();
        $this->assertSame('Reporter Assertion Resmi', $reporter->name);
        $this->assertSame(1, HelpdeskReporterBinding::count());
        $this->assertSame(0, Ticket::count());
    }

    public function test_http_direct_client_gets_server_minted_intake_and_unknown_tools_stay_rejected(): void
    {
        $unsafeGatewayCall = $this->intake([
            'channel' => 'slack',
            'message' => 'VPN kantor putus',
            'start' => true,
        ], true);
        $this->assertSame('invalid_input', $unsafeGatewayCall['status']);
        $this->assertSame('EXTERNAL_USER_REQUIRED', $unsafeGatewayCall['data']['error_code']);

        $started = $this->intake([
            'channel' => 'mcp',
            'reporter_name' => 'Ayu Direct',
            'message' => 'VPN kantor putus',
            'start' => true,
        ]);
        $this->assertTrue($started['ok']);
        $this->assertSame('phone', $started['step']);
        $this->assertStringContainsString('WhatsApp', $started['user_reply']);
        $this->assertMatchesRegularExpression('/^hdi_[A-Za-z0-9_-]{20,100}$/', $started['intake_id']);
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, User::count());

        $unknown = $this->rpc('tools/call', [
            'name' => 'create_ticket',
            'arguments' => ['message' => 'lapor'],
        ]);
        $unknown->assertOk();
        $this->assertSame(-32602, $unknown->json('error.code'));
        $this->assertSame(0, Ticket::count());
    }

    public function test_direct_mcp_clients_without_external_identity_never_share_a_reusable_reporter_binding(): void
    {
        $phone = '6280000098744';
        $startedA = $this->intake([
            'channel' => 'mcp',
            'conversation_id' => 'direct-synthetic-conversation-a',
            'phone' => $phone,
            'reporter_name' => 'Nama Hint Direct A',
            'message' => 'VPN kantor putus untuk user A',
            'start' => true,
        ]);
        $this->assertSame('phone_otp', $startedA['step']);

        $intakeA = $startedA['intake_id'];
        $continueA = fn (string $message): array => $this->intake([
            'intake_id' => $intakeA,
            'channel' => 'mcp',
            'conversation_id' => 'direct-synthetic-conversation-a',
            'message' => $message,
        ]);

        $this->assertSame('registration_consent', $continueA($this->otpCode())['step']);
        $this->assertSame('registration_name', $continueA('ya')['step']);
        $registered = $continueA('Reporter Direct A');
        $this->assertSame('confirm', $registered['step']);
        $reporterA = User::query()->where('phone_normalized', $phone)->firstOrFail();
        $this->assertSame('Reporter Direct A', $reporterA->name);
        $this->assertSame(0, HelpdeskReporterBinding::count());

        foreach (['1', '1', '1', '1', 'lewati'] as $message) {
            $continueA($message);
        }
        $doneA = $continueA('ya');
        $this->assertSame('ticket_created', $doneA['status']);
        $this->assertSame($reporterA->id, Ticket::firstOrFail()->owner_id);
        $this->assertSame(0, HelpdeskReporterBinding::count());

        $startedB = $this->intake([
            'channel' => 'mcp',
            'conversation_id' => 'direct-synthetic-conversation-b',
            'message' => 'lapor masalah berbeda milik user B',
            'start' => true,
        ]);

        $this->assertSame('phone', $startedB['step']);
        $this->assertNotSame($intakeA, $startedB['intake_id']);
        $this->assertStringNotContainsString('Reporter Direct A', json_encode($startedB));
        $this->assertStringNotContainsString($phone, json_encode($startedB));
        $this->assertSame(0, HelpdeskReporterBinding::count());
        $this->assertSame(1, User::count());
        $this->assertSame(1, Ticket::count());
    }

    public function test_direct_mcp_omission_ignores_binding_but_an_explicit_external_identity_reuses_it(): void
    {
        $phone = '6280000098743';
        $untrustedBoundUser = User::query()->create([
            'name' => 'Identitas Direct Tidak Tepercaya',
            'email' => null,
            'password' => null,
            'phone' => $phone,
            'is_active' => true,
        ]);
        $clientId = 'token:'.hash('sha256', (string) config('services.helpdesk_mcp.token'));
        $this->assertTrue(app(HelpdeskReporterIdentityService::class)->bind(
            $clientId,
            'mcp',
            'direct-client',
            $untrustedBoundUser,
        ));
        $this->assertSame(1, HelpdeskReporterBinding::count());

        $started = $this->intake([
            'channel' => 'mcp',
            'conversation_id' => 'direct-after-untrusted-binding',
            'message' => 'lapor masalah user baru',
            'start' => true,
        ]);

        $this->assertSame('phone', $started['step']);
        $this->assertStringNotContainsString('Identitas Direct Tidak Tepercaya', json_encode($started));
        $this->assertStringNotContainsString($phone, json_encode($started));
        $this->assertSame(0, Ticket::count());

        $explicitIdentity = $this->intake([
            'channel' => 'mcp',
            'conversation_id' => 'direct-with-explicit-binding',
            'external_user_id' => 'direct-client',
            'message' => 'lapor masalah pemilik binding',
            'start' => true,
        ]);

        $this->assertSame('confirm', $explicitIdentity['step']);
        $this->assertStringContainsString('Identitas Direct Tidak Tepercaya', $explicitIdentity['user_reply']);
        $this->assertCount(0, $this->whatsAppGateway->messages);
    }

    public function test_stateless_slack_client_retries_safely_and_recovers_completion_from_database(): void
    {
        $phone = '6281234567042';
        $owner = User::create([
            'name' => 'Ayu Slack Talenta',
            'email' => 'ayu-slack@example.test',
            'password' => 'secret',
            'phone' => $phone,
            'is_active' => true,
        ]);
        $started = $this->intake([
            'channel' => 'slack',
            'external_conversation_id' => 'slack-thread-991',
            'external_user_id' => 'slack-user-42',
            'external_message_id' => 'slack-message-1',
            'reporter_name' => 'Nama Tidak Dipercaya',
            'phone' => $phone,
            'message' => 'VPN kantor putus',
            'start' => true,
        ]);
        $intakeId = $started['intake_id'];
        $this->assertSame('phone_otp', $started['step']);
        $this->assertStringNotContainsString('Ayu Slack Talenta', $started['user_reply']);
        $this->assertCount(1, $this->whatsAppGateway->messages);

        // Prove application state does not depend on the transport session.
        $this->mcpSessionId = null;
        $verified = $this->intake([
            'channel' => 'slack',
            'external_conversation_id' => 'slack-thread-991',
            'external_user_id' => 'slack-user-42',
            'external_message_id' => 'slack-message-2',
            'message' => $this->otpCode(),
        ]);
        $this->assertSame('confirm', $verified['step']);
        $this->assertSame($intakeId, $verified['intake_id']);
        $this->assertFalse($verified['replayed']);
        $this->assertStringContainsString('Ayu Slack Talenta', $verified['user_reply']);

        $retry = $this->intake([
            'intake_id' => $intakeId,
            'channel' => 'slack',
            'external_conversation_id' => 'slack-thread-991',
            'external_user_id' => 'slack-user-42',
            'external_message_id' => 'slack-message-2',
            'message' => $this->otpCode(),
        ]);
        $this->assertSame('confirm', $retry['step']);
        $this->assertTrue($retry['replayed']);
        $this->assertCount(1, $this->whatsAppGateway->messages);

        $crossUserReplay = $this->intake([
            'intake_id' => $intakeId,
            'channel' => 'slack',
            'external_user_id' => 'slack-user-intruder',
            'external_message_id' => 'slack-message-2',
            'message' => $this->otpCode(),
        ], true);
        $this->assertSame('invalid_session', $crossUserReplay['status']);
        $this->assertSame('EXTERNAL_USER_CONTEXT_MISMATCH', $crossUserReplay['data']['error_code']);
        $this->assertArrayNotHasKey('state', $crossUserReplay['data']);
        $this->assertStringNotContainsString('Ayu Slack Talenta', json_encode($crossUserReplay));

        $conflict = $this->intake([
            'intake_id' => $intakeId,
            'channel' => 'slack',
            'external_user_id' => 'slack-user-42',
            'external_message_id' => 'slack-message-2',
            'message' => 'different payload',
        ], true);
        $this->assertSame('message_conflict', $conflict['status']);
        $this->assertSame('SOURCE_MESSAGE_ID_REUSED', $conflict['data']['error_code']);

        foreach ([
            ['slack-message-3', '1'],
            ['slack-message-4', '1'],
            ['slack-message-5', '1'],
            ['slack-message-6', '1'],
            ['slack-message-7', 'lewati'],
        ] as [$messageId, $message]) {
            $this->intake([
                'intake_id' => $intakeId,
                'channel' => 'slack',
                'external_user_id' => 'slack-user-42',
                'external_message_id' => $messageId,
                'message' => $message,
            ]);
        }

        $done = $this->intake([
            'intake_id' => $intakeId,
            'channel' => 'slack',
            'external_user_id' => 'slack-user-42',
            'external_message_id' => 'slack-message-8',
            'message' => 'ya',
        ]);
        $this->assertSame('ticket_created', $done['status']);
        $this->assertSame(1, Ticket::count());
        $this->assertSame($owner->id, Ticket::firstOrFail()->owner_id);
        $ticketId = $done['ticket']['id'];

        Cache::flush();
        $durableRetry = $this->intake([
            'intake_id' => $intakeId,
            'channel' => 'slack',
            'external_user_id' => 'slack-user-42',
            'external_message_id' => 'slack-message-9',
            'message' => 'ya',
        ]);
        $this->assertSame('ticket_created', $durableRetry['status']);
        $this->assertTrue($durableRetry['replayed']);
        $this->assertSame($ticketId, $durableRetry['ticket']['id']);
        $this->assertSame(1, Ticket::count());

        $linkedNextIntake = $this->intake([
            'channel' => 'slack',
            'external_conversation_id' => 'slack-thread-992',
            'external_user_id' => 'slack-user-42',
            'external_message_id' => 'slack-next-message-1',
            'message' => 'printer kasir mati',
            'start' => true,
        ]);
        $this->assertSame('confirm', $linkedNextIntake['step']);
        $this->assertStringContainsString('Ayu Slack Talenta', $linkedNextIntake['user_reply']);
        $this->assertCount(1, $this->whatsAppGateway->messages);
    }

    public function test_users_a_b_c_d_keep_separate_sessions_and_phone_bound_ticket_owners(): void
    {
        $reporters = [
            ['A', 'web', '628177770001'],
            ['B', 'telegram', '628177770002'],
            ['C', 'discord', '628177770003'],
            ['D', 'whatsapp', '628177770004'],
        ];
        $intakes = [];

        foreach ($reporters as [$label, $channel, $phone]) {
            User::create([
                'name' => "User {$label}",
                'email' => strtolower($label).'@example.test',
                'password' => 'secret',
                'phone' => $phone,
                'is_active' => true,
            ]);
            $started = $this->intake([
                'channel' => $channel,
                'external_conversation_id' => 'shared-gateway-thread',
                'external_user_id' => "gateway-user-{$label}",
                'phone' => $phone,
                'message' => "kendala khusus user {$label}",
                'start' => true,
            ]);
            $this->assertSame('phone_otp', $started['step']);
            $intakes[$label] = $started['intake_id'];
        }

        $this->assertCount(4, array_unique($intakes));
        $this->assertSame(
            array_column($reporters, 2),
            array_column($this->whatsAppGateway->messages, 'phone'),
        );

        $missingUserAttempt = $this->intake([
            'intake_id' => $intakes['A'],
            'channel' => 'web',
            'message' => $this->otpCode(0),
        ], true);
        $this->assertSame('invalid_session', $missingUserAttempt['status']);
        $this->assertSame('EXTERNAL_USER_CONTEXT_REQUIRED', $missingUserAttempt['data']['error_code']);
        $this->assertArrayNotHasKey('state', $missingUserAttempt['data']);
        $this->assertStringNotContainsString('628177770001', json_encode($missingUserAttempt));
        $this->assertStringNotContainsString('kendala khusus user A', json_encode($missingUserAttempt));

        $crossUserAttempt = $this->intake([
            'intake_id' => $intakes['A'],
            'channel' => 'web',
            'external_user_id' => 'gateway-user-B',
            'message' => $this->otpCode(1),
        ], true);
        $this->assertSame('invalid_session', $crossUserAttempt['status']);
        $this->assertSame('EXTERNAL_USER_CONTEXT_MISMATCH', $crossUserAttempt['data']['error_code']);
        $this->assertArrayNotHasKey('state', $crossUserAttempt['data']);
        $this->assertStringNotContainsString('628177770001', json_encode($crossUserAttempt));
        $this->assertCount(4, $this->whatsAppGateway->messages);

        $crossChannelAttempt = $this->intake([
            'intake_id' => $intakes['A'],
            'channel' => 'discord',
            'external_user_id' => 'gateway-user-A',
            'message' => $this->otpCode(0),
        ], true);
        $this->assertSame('invalid_session', $crossChannelAttempt['status']);
        $this->assertSame('CHANNEL_CONTEXT_MISMATCH', $crossChannelAttempt['data']['error_code']);
        $this->assertArrayNotHasKey('state', $crossChannelAttempt['data']);
        $this->assertStringNotContainsString('628177770001', json_encode($crossChannelAttempt));

        foreach ($reporters as $index => [$label, $channel, $phone]) {
            $verified = $this->intake([
                'intake_id' => $intakes[$label],
                'channel' => $channel,
                'external_user_id' => "gateway-user-{$label}",
                'message' => $this->otpCode($index),
            ]);
            $this->assertSame('confirm', $verified['step']);
            $this->assertStringContainsString("User {$label}", $verified['user_reply']);

            foreach (['1', '1', '1', '1', 'lewati'] as $message) {
                $this->intake([
                    'intake_id' => $intakes[$label],
                    'channel' => $channel,
                    'external_user_id' => "gateway-user-{$label}",
                    'message' => $message,
                ]);
            }
            $done = $this->intake([
                'intake_id' => $intakes[$label],
                'channel' => $channel,
                'external_user_id' => "gateway-user-{$label}",
                'message' => 'ya',
            ]);
            $this->assertSame('ticket_created', $done['status']);

            $ticket = Ticket::query()->where('title', "kendala khusus user {$label}")->firstOrFail();
            $this->assertSame($phone, $ticket->owner->phone);
            $this->assertSame("User {$label}", $ticket->owner->name);
        }

        $this->assertSame(4, Ticket::count());
        $this->assertSame(4, HelpdeskReporterBinding::count());
        $this->assertSame(0, User::query()->whereNull('phone')->count());

        Cache::flush();
        $crossDurableReplay = $this->intake([
            'intake_id' => $intakes['A'],
            'channel' => 'web',
            'external_user_id' => 'gateway-user-B',
            'message' => 'ya',
        ], true);
        $this->assertSame('invalid_session', $crossDurableReplay['status']);
        $this->assertNull($crossDurableReplay['ticket']);
        $this->assertStringNotContainsString('kendala khusus user A', json_encode($crossDurableReplay));
    }

    public function test_http_without_token_is_401(): void
    {
        $this->flushHeaders();

        $this->postJson('/mcp/helpdesk', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => new \stdClass,
                'clientInfo' => ['name' => 'mcp-client', 'version' => '1'],
            ],
        ])
            ->assertUnauthorized()
            ->assertJsonPath('jsonrpc', '2.0')
            ->assertJsonPath('error.code', -32001);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{ok: bool, status: string, user_reply: string, data: array<string, mixed>}
     */
    private function intake(array $arguments, bool $expectError = false): array
    {
        $response = $this->rpc('tools/call', [
            'name' => 'helpdesk_intake',
            'arguments' => $arguments,
        ]);

        $response->assertOk()->assertJsonPath('jsonrpc', '2.0');

        $structured = $response->json('result.structuredContent');
        $this->assertIsArray($structured, $response->getContent());
        $this->assertArrayHasKey('user_reply', $structured);
        $this->assertSame($structured['user_reply'], $response->json('result.content.0.text'));
        $this->assertSame($expectError, (bool) $response->json('result.isError'));

        return $structured;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function rpc(string $method, array $params = []): TestResponse
    {
        $this->rpcId++;

        $headers = [
            'Authorization' => 'Bearer '.(string) config('services.helpdesk_mcp.token'),
        ];
        if ($this->mcpSessionId) {
            $headers['MCP-Session-Id'] = $this->mcpSessionId;
        }

        $response = $this->postJson('/mcp/helpdesk', [
            'jsonrpc' => '2.0',
            'id' => $this->rpcId,
            'method' => $method,
            'params' => $params === [] ? new \stdClass : $params,
        ], $headers);

        $sessionId = $response->headers->get('MCP-Session-Id');
        if (is_string($sessionId) && $sessionId !== '') {
            $this->mcpSessionId = $sessionId;
        }

        return $response;
    }

    private function initializeMcp(): void
    {
        $response = $this->rpc('initialize', [
            'protocolVersion' => '2025-03-26',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'helpdesk-mcp-test', 'version' => '1'],
        ]);
        $response->assertOk()->assertJsonPath('result.serverInfo.name', 'helpdesk');
    }

    private function otpCode(int $messageIndex = -1): string
    {
        $code = $this->whatsAppGateway->otpCode($messageIndex);
        $this->assertNotNull($code, 'Fake WhatsApp gateway did not receive an OTP.');

        return $code;
    }
}

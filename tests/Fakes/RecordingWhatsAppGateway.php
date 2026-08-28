<?php

namespace Tests\Fakes;

use App\Services\WhatsAppGateway;

class RecordingWhatsAppGateway extends WhatsAppGateway
{
    /** @var list<array{phone: string, message: string}> */
    public array $messages = [];

    public function send($phoneNumber, string $message): bool
    {
        $this->messages[] = [
            'phone' => (string) $phoneNumber,
            'message' => $message,
        ];

        return true;
    }

    public function otpCode(int $messageIndex = -1): ?string
    {
        $index = $messageIndex >= 0 ? $messageIndex : count($this->messages) - 1;
        $message = (string) ($this->messages[$index]['message'] ?? '');
        preg_match('/\b([0-9]{6})\b/', $message, $matches);

        return $matches[1] ?? null;
    }
}

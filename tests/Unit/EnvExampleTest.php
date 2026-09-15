<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class EnvExampleTest extends TestCase
{
    public function test_env_example_documents_laravel_12_and_helpdesk_keys(): void
    {
        $path = dirname(__DIR__, 2).'/.env.example';
        $contents = file_get_contents($path);

        $this->assertIsString($contents);

        $keys = $this->uncommentedKeys($contents);

        foreach ([
            'CACHE_STORE',
            'MAIL_SCHEME',
            'APP_LOCALE',
            'APP_FALLBACK_LOCALE',
            'APP_FAKER_LOCALE',
            'APP_MAINTENANCE_DRIVER',
            'BCRYPT_ROUNDS',
            'LOG_STACK',
            'REDIS_CLIENT',
            'SESSION_ENCRYPT',
            'SESSION_PATH',
            'SESSION_DOMAIN',
            'VITE_APP_NAME',
        ] as $key) {
            $this->assertContains($key, $keys, "{$key} must be an uncommented key in .env.example");
        }

        foreach ([
            'CACHE_DRIVER',
            'MAIL_ENCRYPTION',
            'PUSHER_APP_ID',
            'PUSHER_APP_KEY',
            'PUSHER_APP_SECRET',
            'PUSHER_HOST',
            'PUSHER_PORT',
            'PUSHER_SCHEME',
            'PUSHER_APP_CLUSTER',
            'VITE_PUSHER_APP_KEY',
            'VITE_PUSHER_HOST',
            'VITE_PUSHER_PORT',
            'VITE_PUSHER_SCHEME',
            'VITE_PUSHER_APP_CLUSTER',
        ] as $key) {
            $this->assertStringNotContainsString($key, $contents);
            $this->assertNotContains($key, $keys);
        }

        foreach ([
            'APP_NAME',
            'APP_DESCRIPTION',
            'INERTIA_SSR_ENABLED',
            'GOOGLE_CLIENT_ID',
            'GOOGLE_CLIENT_SECRET',
            'GOOGLE_CLIENT_REDIRECT',
            'WAG_URL',
            'WAG_TOKEN',
            'WA_CONNECT_TIMEOUT',
            'WA_API_TIMEOUT',
            'MAIL_TIMEOUT',
            'PHONE_OTP_LOGIN_TTL_MINUTES',
            'WHATSAPP_OTP_TTL_MINUTES',
            'HELPDESK_MCP_LOCAL_CLIENT_ID',
        ] as $key) {
            $this->assertContains($key, $keys, "{$key} must remain in .env.example");
        }

        $this->assertMatchesRegularExpression('/^\s*#\s*TALENTA_EMPLOYEE_FILE=/m', $contents);
    }

    /**
     * @return list<string>
     */
    private function uncommentedKeys(string $contents): array
    {
        $keys = [];

        foreach (preg_split('/\r\n|\r|\n/', $contents) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $keys[] = explode('=', $line, 2)[0];
        }

        return $keys;
    }
}

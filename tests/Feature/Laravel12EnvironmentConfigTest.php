<?php

namespace Tests\Feature;

use Illuminate\Support\Env;
use Tests\TestCase;

class Laravel12EnvironmentConfigTest extends TestCase
{
    public function test_phpunit_xml_cache_store_and_mail_scheme_drive_config(): void
    {
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertNull(config('mail.mailers.smtp.encryption'));
    }

    public function test_laravel_12_env_names_win_over_legacy_names(): void
    {
        $this->withEnv([
            'CACHE_STORE' => 'redis',
            'CACHE_DRIVER' => 'file',
            'MAIL_SCHEME' => 'smtps',
            'MAIL_ENCRYPTION' => 'tls',
            'APP_LOCALE' => 'en',
            'APP_FALLBACK_LOCALE' => 'id',
            'APP_FAKER_LOCALE' => 'en_US',
            'APP_MAINTENANCE_DRIVER' => 'cache',
            'BCRYPT_ROUNDS' => '7',
            'LOG_STACK' => 'daily,stderr',
            'REDIS_CLIENT' => 'predis',
            'SESSION_ENCRYPT' => 'true',
            'SESSION_PATH' => '/helpdesk',
            'SESSION_DOMAIN' => 'helpdesk.test',
        ], function (): void {
            config([
                'app' => require config_path('app.php'),
                'cache' => require config_path('cache.php'),
                'mail' => require config_path('mail.php'),
                'hashing' => require config_path('hashing.php'),
                'logging' => require config_path('logging.php'),
                'database' => require config_path('database.php'),
                'session' => require config_path('session.php'),
            ]);

            $this->assertSame('redis', config('cache.default'));
            $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
            $this->assertNull(config('mail.mailers.smtp.encryption'));
            $this->assertSame('en', config('app.locale'));
            $this->assertSame('id', config('app.fallback_locale'));
            $this->assertSame('en_US', config('app.faker_locale'));
            $this->assertSame('cache', config('app.maintenance.driver'));
            $this->assertSame(7, (int) config('hashing.bcrypt.rounds'));
            $this->assertSame(['daily', 'stderr'], config('logging.channels.stack.channels'));
            $this->assertSame('predis', config('database.redis.client'));
            $this->assertTrue(config('session.encrypt'));
            $this->assertSame('/helpdesk', config('session.path'));
            $this->assertSame('helpdesk.test', config('session.domain'));
        });
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function withEnv(array $variables, callable $callback): void
    {
        $previous = [];

        foreach ($variables as $key => $value) {
            $previous[$key] = [
                'env' => array_key_exists($key, $_ENV) ? $_ENV[$key] : null,
                'server' => array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null,
                'putenv' => getenv($key),
                'had_env' => array_key_exists($key, $_ENV),
                'had_server' => array_key_exists($key, $_SERVER),
            ];

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }

        Env::enablePutenv();

        try {
            $callback();
        } finally {
            foreach ($previous as $key => $state) {
                if ($state['had_env']) {
                    $_ENV[$key] = $state['env'];
                } else {
                    unset($_ENV[$key]);
                }

                if ($state['had_server']) {
                    $_SERVER[$key] = $state['server'];
                } else {
                    unset($_SERVER[$key]);
                }

                if ($state['putenv'] === false) {
                    putenv($key);
                } else {
                    putenv($key.'='.$state['putenv']);
                }
            }

            Env::enablePutenv();
        }
    }
}

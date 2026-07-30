<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class StagingPreflightTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['DEMO_USER_PASSWORD', 'DB_PASSWORD'] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }

        parent::tearDown();
    }

    public function test_secure_staging_configuration_passes_without_exposing_secrets(): void
    {
        $demoPassword = 'preflight-demo-secret-9257';
        $databasePassword = 'preflight-database-secret-7812';
        $this->configureSecureStaging($demoPassword, $databasePassword);

        $exitCode = Artisan::call('bakery:staging-preflight', [
            '--phase' => 'config',
            '--format' => 'json',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('"status": "pass"', $output);
        $this->assertStringNotContainsString($demoPassword, $output);
        $this->assertStringNotContainsString($databasePassword, $output);
    }

    public function test_insecure_configuration_fails_closed_without_exposing_values(): void
    {
        $this->configureSecureStaging('123456', 'database-secret-not-for-output');
        Config::set('app.debug', true);
        Config::set('app.url', 'http://staging.example.invalid');
        Config::set('session.secure', false);
        Config::set('services.mercadopago.enabled', true);
        Config::set('services.mercadopago.driver', 'fake');
        Config::set('services.mercadopago.access_token', 'token-must-never-appear');

        $exitCode = Artisan::call('bakery:staging-preflight', [
            '--phase' => 'config',
            '--format' => 'json',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('"status": "fail"', $output);
        $this->assertStringContainsString('"id": "app.debug"', $output);
        $this->assertStringContainsString('"id": "features.mercadopago"', $output);
        $this->assertStringNotContainsString('token-must-never-appear', $output);
        $this->assertStringNotContainsString('database-secret-not-for-output', $output);
    }

    public function test_enabled_push_requires_both_vapid_keys(): void
    {
        $this->configureSecureStaging(
            'unique-demo-password-7519',
            'unique-database-password-4826',
        );
        Config::set('services.pwa_push.enabled', true);
        Config::set('services.pwa_push.public_key', 'public-key-not-for-output');
        Config::set('services.pwa_push.private_key', null);

        $exitCode = Artisan::call('bakery:staging-preflight', [
            '--phase' => 'config',
            '--format' => 'json',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode, $output);
        $this->assertStringContainsString('"id": "features.pwa_push"', $output);
        $this->assertStringContainsString('"status": "fail"', $output);
        $this->assertStringNotContainsString('public-key-not-for-output', $output);
    }

    private function configureSecureStaging(string $demoPassword, string $databasePassword): void
    {
        Config::set([
            'app.env' => 'staging',
            'app.debug' => false,
            'app.url' => 'https://staging.example.com',
            'session.secure' => true,
            'session.http_only' => true,
            'session.domain' => 'staging.example.com',
            'database.default' => 'pgsql',
            'database.connections.pgsql.driver' => 'pgsql',
            'database.redis.default.password' => 'redis-secret-not-for-output',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'mailpit',
            'mail.mailers.smtp.port' => 1025,
            'mail.from.address' => 'no-reply@staging.example.com',
            'queue.default' => 'redis',
            'queue.connections.redis.driver' => 'redis',
            'services.arca.enabled' => false,
            'services.arca.driver' => 'fake',
            'services.mercadopago.enabled' => false,
            'services.mercadopago.webhooks_enabled' => false,
            'services.mercadopago.driver' => 'fake',
            'services.pwa_push.enabled' => false,
        ]);

        putenv("DEMO_USER_PASSWORD={$demoPassword}");
        putenv("DB_PASSWORD={$databasePassword}");
        $_ENV['DEMO_USER_PASSWORD'] = $_SERVER['DEMO_USER_PASSWORD'] = $demoPassword;
        $_ENV['DB_PASSWORD'] = $_SERVER['DB_PASSWORD'] = $databasePassword;
    }
}

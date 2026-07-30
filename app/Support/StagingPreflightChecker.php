<?php

namespace App\Support;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class StagingPreflightChecker
{
    private array $checks = [];

    public function __construct(
        private readonly Migrator $migrator,
        private readonly Schedule $schedule,
    ) {}

    public function run(string $phase, bool $allowPendingMigrations = false): array
    {
        $this->checks = [];

        $this->configurationChecks();

        if ($phase !== 'config') {
            $this->runtimeChecks($allowPendingMigrations);
        }

        $failed = count(array_filter(
            $this->checks,
            fn (array $check): bool => $check['status'] === 'fail',
        ));

        return [
            'status' => $failed === 0 ? 'pass' : 'fail',
            'phase' => $phase,
            'summary' => [
                'checks' => count($this->checks),
                'passed' => count($this->checks) - $failed,
                'failed' => $failed,
            ],
            'checks' => $this->checks,
        ];
    }

    private function configurationChecks(): void
    {
        $this->check(
            'app.environment',
            config('app.env') === 'staging',
            'El entorno de aplicación es staging.',
            'APP_ENV debe ser staging.',
        );
        $this->check(
            'app.debug',
            config('app.debug') === false,
            'El modo debug está desactivado.',
            'APP_DEBUG debe estar desactivado.',
        );

        $key = (string) config('app.key', '');
        $decodedKey = str_starts_with($key, 'base64:')
            ? base64_decode(substr($key, 7), true)
            : $key;
        $this->check(
            'app.key',
            is_string($decodedKey)
                && Encrypter::supported($decodedKey, (string) config('app.cipher')),
            'La clave de aplicación tiene un formato válido.',
            'APP_KEY falta o no es válida para el cifrado configurado.',
        );

        $appUrl = (string) config('app.url', '');
        $appHost = parse_url($appUrl, PHP_URL_HOST);
        $this->check(
            'app.https',
            filter_var($appUrl, FILTER_VALIDATE_URL) !== false
                && parse_url($appUrl, PHP_URL_SCHEME) === 'https'
                && is_string($appHost)
                && $appHost !== ''
                && ! str_ends_with($appHost, '.invalid')
                && $appHost !== 'localhost',
            'La URL pública usa HTTPS y un host no reservado.',
            'APP_URL debe ser una URL HTTPS con el host real de staging.',
        );

        $sessionDomain = ltrim((string) config('session.domain', ''), '.');
        $this->check(
            'session.secure',
            config('session.secure') === true && config('session.http_only') === true,
            'Las cookies de sesión requieren HTTPS y son HttpOnly.',
            'Las cookies de sesión deben ser Secure y HttpOnly.',
        );
        $this->check(
            'session.domain',
            is_string($appHost)
                && $sessionDomain !== ''
                && ($sessionDomain === $appHost || str_ends_with($appHost, '.'.$sessionDomain)),
            'El dominio de sesión corresponde al host público.',
            'SESSION_DOMAIN debe corresponder al host de APP_URL.',
        );

        $defaultDatabase = (string) config('database.default', '');
        $this->check(
            'database.postgresql',
            $defaultDatabase === 'pgsql'
                && config("database.connections.{$defaultDatabase}.driver") === 'pgsql',
            'PostgreSQL es la conexión predeterminada.',
            'DB_CONNECTION debe ser pgsql.',
        );
        $this->check(
            'redis.password',
            $this->configured(config('database.redis.default.password')),
            'Redis requiere autenticación.',
            'REDIS_PASSWORD debe estar configurada.',
        );

        $mailer = (string) config('mail.default', '');
        $mailerConfig = config("mail.mailers.{$mailer}");
        $mailerValid = is_array($mailerConfig)
            && ! in_array($mailer, ['array', 'log'], true)
            && $this->configured(config('mail.from.address'))
            && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false;
        if ($mailer === 'smtp') {
            $mailerValid = $mailerValid
                && $this->configured($mailerConfig['host'] ?? null)
                && filter_var($mailerConfig['port'] ?? null, FILTER_VALIDATE_INT) !== false;
        }
        $this->check(
            'mail.transport',
            $mailerValid,
            'El transporte de correo y el remitente están configurados.',
            'El mailer debe ser entregable y tener remitente válido.',
        );

        $this->check(
            'worker.configuration',
            config('queue.default') === 'redis'
                && config('queue.connections.redis.driver') === 'redis',
            'El worker está configurado para Redis.',
            'QUEUE_CONNECTION debe ser redis.',
        );
        $this->check(
            'scheduler.configuration',
            count($this->schedule->events()) > 0,
            'El scheduler tiene tareas registradas.',
            'No hay tareas registradas en el scheduler.',
        );

        $demoPassword = $this->environmentValue('DEMO_USER_PASSWORD');
        $knownPasswords = [
            '123456',
            'password',
            'changeme',
            'local_only_change_me',
            'staging-ci-demo-only',
        ];
        $this->check(
            'credentials.demo_password',
            is_string($demoPassword)
                && strlen($demoPassword) >= 16
                && ! in_array(strtolower($demoPassword), $knownPasswords, true)
                && ! hash_equals((string) $this->environmentValue('DB_PASSWORD'), $demoPassword),
            'La contraseña demo no coincide con valores conocidos ni con la base.',
            'DEMO_USER_PASSWORD debe ser única, no conocida y tener al menos 16 caracteres.',
        );

        $this->integrationChecks();
    }

    private function integrationChecks(): void
    {
        $arcaEnabled = config('services.arca.enabled') === true;
        $arcaValid = $arcaEnabled
            ? config('services.arca.driver') === 'sandbox'
                && $this->httpsEndpoint(config('services.arca.endpoint'))
                && $this->readableFile(config('services.arca.certificate_path'))
            : config('services.arca.driver') === 'fake';
        $this->check(
            'features.arca',
            $arcaValid,
            $arcaEnabled
                ? 'ARCA habilitada con endpoint HTTPS y certificado legible.'
                : 'ARCA permanece deshabilitada con adaptador fake.',
            'ARCA debe quedar deshabilitada/fake o tener sandbox, HTTPS y certificado legible.',
        );

        $mercadoPagoEnabled = config('services.mercadopago.enabled') === true;
        $webhooksEnabled = config('services.mercadopago.webhooks_enabled') === true;
        $mercadoPagoValid = $mercadoPagoEnabled
            ? config('services.mercadopago.driver') === 'sandbox'
                && $this->httpsEndpoint(config('services.mercadopago.endpoint'))
                && $this->configured(config('services.mercadopago.access_token'))
                && (! $webhooksEnabled || $this->configured(config('services.mercadopago.webhook_secret')))
            : config('services.mercadopago.driver') === 'fake' && ! $webhooksEnabled;
        $this->check(
            'features.mercadopago',
            $mercadoPagoValid,
            $mercadoPagoEnabled
                ? 'Mercado Pago habilitado con configuración sandbox completa.'
                : 'Mercado Pago y sus webhooks permanecen deshabilitados con adaptador fake.',
            'Mercado Pago debe quedar deshabilitado/fake o tener sandbox y secretos completos.',
        );

        $pushEnabled = config('services.pwa_push.enabled') === true;
        $pushValid = ! $pushEnabled
            || (
                $this->configured(config('services.pwa_push.public_key'))
                && $this->configured(config('services.pwa_push.private_key'))
            );
        $this->check(
            'features.pwa_push',
            $pushValid,
            $pushEnabled
                ? 'Push PWA habilitado con el par VAPID configurado.'
                : 'Push PWA permanece deshabilitado; VAPID no es obligatorio.',
            'Las claves VAPID pública y privada son obligatorias al habilitar push.',
        );
    }

    private function runtimeChecks(bool $allowPendingMigrations): void
    {
        $this->attempt(
            'database.connectivity',
            fn (): bool => DB::selectOne('SELECT 1 AS ready') !== null,
            'PostgreSQL responde a una consulta.',
            'No se pudo verificar PostgreSQL.',
        );
        $this->attempt(
            'redis.connectivity',
            function (): bool {
                $response = Redis::connection()->ping();

                return $response === true || strtoupper((string) $response) === 'PONG';
            },
            'Redis responde con autenticación.',
            'No se pudo verificar Redis.',
        );
        $this->attempt(
            'storage.writable',
            function (): bool {
                $directory = storage_path('framework');
                if (! is_dir($directory) || ! is_writable($directory)) {
                    return false;
                }

                $path = $directory.'/.preflight-'.bin2hex(random_bytes(8));
                try {
                    return file_put_contents($path, 'ok', LOCK_EX) === 2
                        && is_file($path);
                } finally {
                    if (is_file($path)) {
                        @unlink($path);
                    }
                }
            },
            'El storage admite escritura y limpieza.',
            'El storage persistente no admite una escritura segura.',
        );

        $this->attempt(
            'database.migrations',
            function () use ($allowPendingMigrations): bool {
                if (! $this->migrator->repositoryExists()) {
                    return false;
                }
                $available = array_keys($this->migrator->getMigrationFiles(database_path('migrations')));
                $pending = array_diff($available, $this->migrator->getRepository()->getRan());

                return $pending === [] || $allowPendingMigrations;
            },
            $allowPendingMigrations
                ? 'El repositorio de migraciones existe; se permiten pendientes sólo en esta compuerta previa.'
                : 'No hay migraciones pendientes.',
            $allowPendingMigrations
                ? 'No existe el repositorio de migraciones requerido para desplegar.'
                : 'Hay migraciones pendientes o no existe su repositorio.',
        );
    }

    private function check(string $id, bool $passed, string $passMessage, string $failureMessage): void
    {
        $this->checks[] = [
            'id' => $id,
            'status' => $passed ? 'pass' : 'fail',
            'message' => $passed ? $passMessage : $failureMessage,
        ];
    }

    private function attempt(
        string $id,
        callable $callback,
        string $passMessage,
        string $failureMessage,
    ): void {
        try {
            $passed = $callback() === true;
        } catch (Throwable) {
            $passed = false;
        }

        $this->check($id, $passed, $passMessage, $failureMessage);
    }

    private function configured(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '' && strtolower(trim($value)) !== 'null';
    }

    private function httpsEndpoint(mixed $value): bool
    {
        return $this->configured($value)
            && filter_var($value, FILTER_VALIDATE_URL) !== false
            && parse_url($value, PHP_URL_SCHEME) === 'https';
    }

    private function readableFile(mixed $value): bool
    {
        return $this->configured($value) && is_file($value) && is_readable($value);
    }

    private function environmentValue(string $name): ?string
    {
        $value = getenv($name);

        return $value === false ? null : trim($value);
    }
}

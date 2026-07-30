<?php

declare(strict_types=1);

const ROOT = __DIR__.'/../..';

final class CommandResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {}
}

function options(array $arguments): array
{
    $parsed = [];
    for ($index = 0; $index < count($arguments); $index++) {
        $argument = $arguments[$index];
        if (! str_starts_with($argument, '--')) {
            throw new RuntimeException("Unexpected argument: {$argument}");
        }
        $name = substr($argument, 2);
        if (str_contains($name, '=')) {
            [$name, $value] = explode('=', $name, 2);
            $parsed[$name] = $value;
        } elseif (isset($arguments[$index + 1]) && ! str_starts_with($arguments[$index + 1], '--')) {
            $parsed[$name] = $arguments[++$index];
        } else {
            $parsed[$name] = true;
        }
    }

    return $parsed;
}

function absolutePath(string $path): string
{
    if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|\/)/', $path) === 1) {
        return str_replace('\\', '/', $path);
    }

    return str_replace('\\', '/', ROOT.'/'.$path);
}

function requireFile(string $path, string $label): string
{
    $absolute = absolutePath($path);
    if (! is_file($absolute)) {
        throw new RuntimeException("Missing {$label}: {$path}");
    }

    return realpath($absolute) ?: $absolute;
}

function readEnvironment(string $path): array
{
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);
        if (strlen($value) >= 2 && in_array($value[0], ['"', "'"], true) && $value[-1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        $values[trim($key)] = $value;
    }

    return $values;
}

function sensitiveValues(array $environment): array
{
    $values = [];
    foreach ($environment as $key => $value) {
        if ($value !== '' && preg_match('/(?:PASSWORD|TOKEN|SECRET|KEY|CERTIFICATE|COOKIE)/i', $key) === 1) {
            $values[] = $value;
        }
    }

    usort($values, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

    return array_values(array_unique($values));
}

function redact(string $text, array $secrets): string
{
    return str_replace($secrets, '[REDACTED]', $text);
}

function runCommand(array $command, array $secrets, bool $allowFailure = false): CommandResult
{
    $stdoutFile = tmpfile();
    $stderrFile = tmpfile();
    if ($stdoutFile === false || $stderrFile === false) {
        throw new RuntimeException('Unable to allocate command output files.');
    }

    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => $stdoutFile,
            2 => $stderrFile,
        ],
        $pipes,
        ROOT,
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start subprocess.');
    }
    fclose($pipes[0]);
    $exitCode = proc_close($process);
    rewind($stdoutFile);
    rewind($stderrFile);
    $stdout = (string) stream_get_contents($stdoutFile);
    $stderr = (string) stream_get_contents($stderrFile);
    fclose($stdoutFile);
    fclose($stderrFile);

    $result = new CommandResult(
        $exitCode,
        redact(trim($stdout), $secrets),
        redact(trim($stderr), $secrets),
    );
    if ($exitCode !== 0 && ! $allowFailure) {
        $detail = $result->stderr !== '' ? $result->stderr : $result->stdout;
        throw new RuntimeException("Command failed with exit code {$exitCode}: {$detail}");
    }

    return $result;
}

function composeCommand(array $context, array $arguments): array
{
    $command = [
        'docker', 'compose',
        '--env-file', $context['env_file'],
        '-f', $context['compose_file'],
    ];
    if ($context['project_name'] !== '') {
        array_push($command, '--project-name', $context['project_name']);
    }

    return [...$command, ...$arguments];
}

function compose(array $context, array $arguments, bool $allowFailure = false): CommandResult
{
    return runCommand(composeCommand($context, $arguments), $context['secrets'], $allowFailure);
}

function context(array $options): array
{
    $envFile = requireFile((string) ($options['env-file'] ?? '.env.staging'), 'environment file');
    $composeFile = requireFile((string) ($options['compose-file'] ?? 'compose.staging.yaml'), 'Compose file');
    $environment = readEnvironment($envFile);

    return [
        'env_file' => $envFile,
        'compose_file' => $composeFile,
        'project_name' => (string) ($options['project-name'] ?? ''),
        'environment' => $environment,
        'secrets' => sensitiveValues($environment),
    ];
}

function validateDatabaseName(string $database): string
{
    if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/', $database) !== 1) {
        throw new RuntimeException('Database names must contain only letters, digits and underscores.');
    }

    return $database;
}

function postgresShell(array $context, string $script, bool $allowFailure = false): CommandResult
{
    return compose($context, ['exec', '-T', 'postgres', 'sh', '-lc', $script], $allowFailure);
}

function deployedSha(array $options, array $context): string
{
    if (isset($options['deployed-sha'])) {
        $sha = trim((string) $options['deployed-sha']);
    } elseif (is_file(ROOT.'/.staging-release')) {
        $sha = trim((string) file_get_contents(ROOT.'/.staging-release'));
    } else {
        $sha = trim(runCommand(['git', 'rev-parse', 'HEAD'], $context['secrets'])->stdout);
    }
    if (preg_match('/^[A-Fa-f0-9]{7,64}$/', $sha) !== 1) {
        throw new RuntimeException('The deployed SHA is missing or invalid.');
    }

    return strtolower($sha);
}

function writeJsonExclusive(string $path, array $data): void
{
    $handle = @fopen($path, 'x');
    if ($handle === false) {
        throw new RuntimeException("Refusing to overwrite existing report: {$path}");
    }
    try {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        if (fwrite($handle, $encoded) !== strlen($encoded)) {
            throw new RuntimeException("Unable to write complete JSON report: {$path}");
        }
    } finally {
        fclose($handle);
    }
}

function postgresVersion(array $context): array
{
    $dump = postgresShell($context, 'pg_dump --version')->stdout;
    $server = postgresShell(
        $context,
        'export PGPASSWORD="$POSTGRES_PASSWORD"; psql --tuples-only --no-align --username="$POSTGRES_USER" --dbname="$POSTGRES_DB" --command="SHOW server_version;"',
    )->stdout;

    return ['dump' => trim($dump), 'server' => trim($server)];
}

function backup(array $options): array
{
    $context = context($options);
    $outputDirectory = absolutePath((string) ($options['output-dir'] ?? 'storage/app/backups'));
    if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0700, true) && ! is_dir($outputDirectory)) {
        throw new RuntimeException("Unable to create backup directory: {$outputDirectory}");
    }

    $timestamp = gmdate('Ymd\THis\Z');
    $sha = deployedSha($options, $context);
    $baseName = "la-victoria-staging-{$timestamp}-".substr($sha, 0, 12);
    $backupPath = "{$outputDirectory}/{$baseName}.dump";
    $manifestPath = "{$outputDirectory}/{$baseName}.manifest.json";
    if (file_exists($backupPath) || file_exists($manifestPath)) {
        throw new RuntimeException('Refusing to overwrite an existing backup or manifest.');
    }

    $containerPath = "/tmp/{$baseName}.dump";
    $started = hrtime(true);
    try {
        postgresShell(
            $context,
            'umask 077; export PGPASSWORD="$POSTGRES_PASSWORD"; pg_dump --format=custom --no-owner --no-privileges --username="$POSTGRES_USER" --dbname="$POSTGRES_DB" --file='
                .escapeshellarg($containerPath),
        );
        compose($context, ['cp', "postgres:{$containerPath}", $backupPath]);
    } finally {
        postgresShell($context, 'rm -f '.escapeshellarg($containerPath), true);
    }

    clearstatcache(true, $backupPath);
    $size = is_file($backupPath) ? filesize($backupPath) : false;
    if ($size === false || $size <= 0) {
        @unlink($backupPath);
        throw new RuntimeException('Backup is missing or empty.');
    }
    $checksum = hash_file('sha256', $backupPath);
    if ($checksum === false) {
        @unlink($backupPath);
        throw new RuntimeException('Unable to calculate backup checksum.');
    }
    $versions = postgresVersion($context);
    $duration = round((hrtime(true) - $started) / 1_000_000_000, 3);
    $manifest = [
        'schema_version' => 1,
        'created_at_utc' => gmdate(DATE_ATOM),
        'source_environment' => $context['environment']['APP_ENV'] ?? 'unknown',
        'source_database' => $context['environment']['DB_DATABASE'] ?? 'unknown',
        'deployed_sha' => $sha,
        'format' => 'postgresql-custom',
        'postgres_dump_version' => $versions['dump'],
        'postgres_server_version' => $versions['server'],
        'backup_file' => basename($backupPath),
        'sha256' => $checksum,
        'size_bytes' => $size,
        'duration_seconds' => $duration,
    ];
    try {
        writeJsonExclusive($manifestPath, $manifest);
    } catch (Throwable $exception) {
        @unlink($backupPath);
        throw $exception;
    }

    $result = [
        'status' => 'pass',
        'backup' => $backupPath,
        'manifest' => $manifestPath,
        'size_bytes' => $size,
        'duration_seconds' => $duration,
    ];
    if (! isset($options['quiet'])) {
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    }

    return $result;
}

function readManifest(string $path): array
{
    try {
        $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        throw new RuntimeException('Backup manifest is not valid JSON.', previous: $exception);
    }
    foreach (['backup_file', 'sha256', 'postgres_server_version', 'deployed_sha'] as $field) {
        if (! isset($manifest[$field]) || ! is_string($manifest[$field]) || $manifest[$field] === '') {
            throw new RuntimeException("Backup manifest is missing {$field}.");
        }
    }

    return $manifest;
}

function majorVersion(string $version): int
{
    if (preg_match('/(\d+)(?:\.\d+)?/', $version, $matches) !== 1) {
        throw new RuntimeException("Unable to parse PostgreSQL version: {$version}");
    }

    return (int) $matches[1];
}

function backend(array $context, string $database, string $environment, array $arguments): CommandResult
{
    return compose($context, [
        'exec', '-T',
        '-e', "DB_DATABASE={$database}",
        '-e', "APP_ENV={$environment}",
        'backend',
        ...$arguments,
    ]);
}

function restore(array $options): array
{
    if (! isset($options['manifest'])) {
        throw new RuntimeException('Restore requires --manifest.');
    }
    $context = context($options);
    $manifestPath = requireFile((string) $options['manifest'], 'backup manifest');
    $manifest = readManifest($manifestPath);
    $backupPath = isset($options['backup'])
        ? requireFile((string) $options['backup'], 'backup file')
        : requireFile(dirname($manifestPath).'/'.$manifest['backup_file'], 'backup file');
    $configuredEnvironment = strtolower((string) ($context['environment']['APP_ENV'] ?? 'unknown'));
    $targetEnvironment = strtolower((string) (
        $options['target-environment']
        ?? $configuredEnvironment
        ?? 'staging'
    ));
    if (
        in_array('production', [$configuredEnvironment, $targetEnvironment], true)
        && ! isset($options['allow-production'])
    ) {
        throw new RuntimeException('Production restore is rejected. A human must pass --allow-production explicitly.');
    }

    $sourceDatabase = validateDatabaseName((string) ($context['environment']['DB_DATABASE'] ?? ''));
    $targetDatabase = validateDatabaseName((string) (
        $options['target-database']
        ?? "{$sourceDatabase}_restore_".gmdate('YmdHis')
    ));
    if ($targetDatabase === $sourceDatabase) {
        throw new RuntimeException('Restore must target a new isolated database, never the source database.');
    }

    $checksum = hash_file('sha256', $backupPath);
    if ($checksum === false || ! hash_equals(strtolower($manifest['sha256']), strtolower($checksum))) {
        throw new RuntimeException('Backup checksum verification failed.');
    }
    $targetVersion = postgresVersion($context)['server'];
    if (majorVersion($manifest['postgres_server_version']) !== majorVersion($targetVersion)) {
        throw new RuntimeException(
            "PostgreSQL major version mismatch: backup {$manifest['postgres_server_version']}, target {$targetVersion}.",
        );
    }

    $exists = postgresShell(
        $context,
        'export PGPASSWORD="$POSTGRES_PASSWORD"; psql --tuples-only --no-align --username="$POSTGRES_USER" --dbname=postgres --command='
            .escapeshellarg("SELECT 1 FROM pg_database WHERE datname = '{$targetDatabase}';"),
    )->stdout;
    if (trim($exists) !== '') {
        throw new RuntimeException("Refusing to overwrite existing database: {$targetDatabase}");
    }

    $timestamp = gmdate('Ymd\THis\Z');
    $containerPath = "/tmp/restore-{$timestamp}-".bin2hex(random_bytes(3)).'.dump';
    $reportDirectory = absolutePath((string) ($options['report-dir'] ?? dirname($manifestPath)));
    if (! is_dir($reportDirectory) && ! mkdir($reportDirectory, 0700, true) && ! is_dir($reportDirectory)) {
        throw new RuntimeException("Unable to create report directory: {$reportDirectory}");
    }
    $reportPath = "{$reportDirectory}/restore-{$targetDatabase}-{$timestamp}.json";
    $started = hrtime(true);
    $created = false;
    $report = [
        'schema_version' => 1,
        'started_at_utc' => gmdate(DATE_ATOM),
        'status' => 'fail',
        'source_manifest' => basename($manifestPath),
        'source_sha' => $manifest['deployed_sha'],
        'target_environment' => $targetEnvironment,
        'target_database' => $targetDatabase,
        'checksum_verified' => true,
        'postgres_version_compatible' => true,
        'migrations' => 'not-run',
        'integrity' => 'not-run',
        'smoke' => 'not-run',
    ];

    try {
        compose($context, ['cp', $backupPath, "postgres:{$containerPath}"]);
        postgresShell(
            $context,
            'export PGPASSWORD="$POSTGRES_PASSWORD"; createdb --username="$POSTGRES_USER" '.escapeshellarg($targetDatabase),
        );
        $created = true;
        postgresShell(
            $context,
            'export PGPASSWORD="$POSTGRES_PASSWORD"; pg_restore --exit-on-error --no-owner --no-privileges --username="$POSTGRES_USER" --dbname='
                .escapeshellarg($targetDatabase).' '.escapeshellarg($containerPath),
        );
        backend($context, $targetDatabase, $targetEnvironment, ['php', 'artisan', 'migrate', '--force', '--no-interaction']);
        $report['migrations'] = 'pass';
        backend($context, $targetDatabase, $targetEnvironment, ['php', 'artisan', 'bakery:integrity-check', '--format=json']);
        $report['integrity'] = 'pass';
        backend($context, $targetDatabase, $targetEnvironment, ['php', 'artisan', 'migrate:status', '--no-ansi']);
        backend($context, $targetDatabase, $targetEnvironment, ['php', 'artisan', 'route:list', '--path=api/v1', '--except-vendor', '--no-ansi']);
        compose($context, [
            'run', '--rm', '-T', '--no-deps',
            '-e', "DB_DATABASE={$targetDatabase}",
            '-e', "APP_ENV={$targetEnvironment}",
            'backend', 'sh', '-lc',
            <<<'SH'
set -eu
log=/tmp/restore-http-smoke.log
php artisan serve --host=127.0.0.1 --port=8765 >"$log" 2>&1 &
server_pid=$!
trap 'kill "$server_pid" >/dev/null 2>&1 || true' EXIT
attempt=0
while [ "$attempt" -lt 20 ]; do
    if curl --fail --silent http://127.0.0.1:8765/up >/dev/null 2>&1; then
        curl --fail --silent --header 'Accept: application/json' \
            http://127.0.0.1:8765/api/v1/auth/csrf >/dev/null
        exit 0
    fi
    attempt=$((attempt + 1))
    sleep 1
done
cat "$log" >&2
exit 1
SH,
        ]);
        $report['smoke'] = 'pass';
        $report['status'] = 'pass';
    } catch (Throwable $exception) {
        $report['error'] = redact($exception->getMessage(), $context['secrets']);
        if ($created && ! isset($options['keep-failed-database'])) {
            postgresShell(
                $context,
                'export PGPASSWORD="$POSTGRES_PASSWORD"; dropdb --if-exists --force --username="$POSTGRES_USER" '
                    .escapeshellarg($targetDatabase),
                true,
            );
            $report['failed_database_removed'] = true;
        }
        throw $exception;
    } finally {
        postgresShell($context, 'rm -f '.escapeshellarg($containerPath), true);
        $report['finished_at_utc'] = gmdate(DATE_ATOM);
        $report['duration_seconds'] = round((hrtime(true) - $started) / 1_000_000_000, 3);
        writeJsonExclusive($reportPath, $report);
        $result = [
            'status' => $report['status'],
            'report' => $reportPath,
            'target_database' => $targetDatabase,
        ];
        if (! isset($options['quiet'])) {
            echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        }
    }

    return $result;
}

function databaseMetrics(array $context, string $database): string
{
    $database = validateDatabaseName($database);
    $sql = <<<'SQL'
SELECT concat_ws('|',
    'customers=' || (SELECT COUNT(*) FROM customers),
    'orders=' || (SELECT COUNT(*) FROM orders),
    'lots=' || (SELECT COUNT(*) FROM inventory_lots),
    'inventory_milli=' || (SELECT COALESCE(SUM(ROUND(quantity * 1000)), 0) FROM inventory_lots),
    'customer_balance_cents=' || (SELECT COALESCE(SUM(amount_cents), 0) FROM customer_account_entries),
    'payable_balance_cents=' || (SELECT COALESCE(SUM(total_cents - paid_cents), 0) FROM accounts_payable),
    'cash_expected_cents=' || (SELECT COALESCE(SUM(expected_balance_cents), 0) FROM cash_sessions),
    'receipts=' || (SELECT COUNT(*) FROM purchase_receipts),
    'consumptions=' || (SELECT COUNT(*) FROM production_consumptions),
    'produced_lots=' || (SELECT COUNT(*) FROM inventory_lots WHERE production_batch_id IS NOT NULL),
    'notifications=' || (SELECT COUNT(*) FROM notification_deliveries)
);
SQL;

    return trim(postgresShell(
        $context,
        'export PGPASSWORD="$POSTGRES_PASSWORD"; psql --tuples-only --no-align --username="$POSTGRES_USER" --dbname='
            .escapeshellarg($database).' --command='.escapeshellarg($sql),
    )->stdout);
}

function writeTextExclusive(string $path, string $contents): void
{
    $handle = @fopen($path, 'x');
    if ($handle === false) {
        throw new RuntimeException("Refusing to overwrite existing report: {$path}");
    }
    try {
        if (fwrite($handle, $contents) !== strlen($contents)) {
            throw new RuntimeException("Unable to write complete report: {$path}");
        }
    } finally {
        fclose($handle);
    }
}

function restoreTest(array $options): void
{
    $outputRoot = absolutePath((string) ($options['output-dir'] ?? 'storage/app/restore-tests'));
    $runId = gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(3));
    $runDirectory = "{$outputRoot}/{$runId}";
    if (! mkdir($runDirectory, 0700, true) && ! is_dir($runDirectory)) {
        throw new RuntimeException("Unable to create restore test directory: {$runDirectory}");
    }
    $envPath = "{$runDirectory}/restore-test.env";
    $appKey = base64_encode(random_bytes(32));
    $dbPassword = bin2hex(random_bytes(16));
    $demoPassword = bin2hex(random_bytes(12));
    $environment = <<<ENV
APP_NAME="La Victoria Bakery Restore Test"
APP_ENV=testing
APP_KEY=base64:{$appKey}
APP_DEBUG=false
APP_URL=http://localhost
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=la_victoria_restore_source
DB_USERNAME=la_victoria_restore
DB_PASSWORD={$dbPassword}
DB_SSLMODE=disable
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_HOST=redis
REDIS_PORT=6379
MAIL_MAILER=log
MAIL_FROM_ADDRESS=restore-test@example.invalid
DEMO_USER_PASSWORD={$demoPassword}
ARCA_ENABLED=false
MERCADOPAGO_ENABLED=false
MERCADOPAGO_WEBHOOKS_ENABLED=false
PWA_PUSH_ENABLED=false
FORWARD_FRONTEND_PORT=0
FORWARD_BACKEND_PORT=0
FORWARD_DB_PORT=0
FORWARD_REDIS_PORT=0
FORWARD_MAILPIT_SMTP_PORT=0
FORWARD_MAILPIT_UI_PORT=0
ENV;
    if (file_put_contents($envPath, $environment, LOCK_EX) === false) {
        throw new RuntimeException('Unable to create isolated restore test environment.');
    }
    @chmod($envPath, 0600);

    $context = context([
        'env-file' => $envPath,
        'compose-file' => 'compose.yaml',
        'project-name' => 'la-victoria-restore-test',
    ]);
    $reportPath = "{$runDirectory}/restore-test-report.json";
    $markdownPath = "{$runDirectory}/restore-test-report.md";
    $started = hrtime(true);
    $report = [
        'schema_version' => 1,
        'run_id' => $runId,
        'status' => 'fail',
        'started_at_utc' => gmdate(DATE_ATOM),
        'project_name' => $context['project_name'],
        'source_database' => 'la_victoria_restore_source',
        'target_database' => 'la_victoria_restore_verified',
        'external_features' => [
            'arca' => false,
            'mercadopago' => false,
            'mercadopago_webhooks' => false,
            'push' => false,
        ],
    ];

    try {
        compose($context, ['up', '-d', '--build', '--wait']);
        backend(
            $context,
            'la_victoria_restore_source',
            'testing',
            ['php', 'artisan', 'migrate:fresh', '--seed', '--force', '--no-interaction'],
        );
        compose($context, [
            'exec', '-T', '-e', 'RESTORE_TEST_SCENARIO=true',
            '-e', 'APP_ENV=testing', '-e', 'DB_DATABASE=la_victoria_restore_source',
            'backend', 'php', 'artisan', 'db:seed',
            '--class=Database\\Seeders\\RestoreVerificationSeeder', '--force', '--no-interaction',
        ]);
        backend(
            $context,
            'la_victoria_restore_source',
            'testing',
            ['php', 'artisan', 'bakery:integrity-check', '--format=json'],
        );
        $report['source_integrity'] = 'pass';
        $report['source_metrics'] = databaseMetrics($context, 'la_victoria_restore_source');

        $backup = backup([
            'quiet' => true,
            'env-file' => $envPath,
            'compose-file' => 'compose.yaml',
            'project-name' => $context['project_name'],
            'output-dir' => $runDirectory,
            'deployed-sha' => deployedSha([], $context),
        ]);
        $report['backup_manifest'] = basename($backup['manifest']);
        $report['backup_size_bytes'] = $backup['size_bytes'];
        $report['backup_duration_seconds'] = $backup['duration_seconds'];

        compose($context, ['down', '--volumes', '--remove-orphans']);
        compose($context, ['up', '-d', '--wait']);
        $restored = restore([
            'quiet' => true,
            'manifest' => $backup['manifest'],
            'env-file' => $envPath,
            'compose-file' => 'compose.yaml',
            'project-name' => $context['project_name'],
            'target-environment' => 'testing',
            'target-database' => 'la_victoria_restore_verified',
            'report-dir' => $runDirectory,
        ]);
        $report['restore_report'] = basename($restored['report']);
        $report['target_metrics'] = databaseMetrics($context, 'la_victoria_restore_verified');
        if (! hash_equals($report['source_metrics'], $report['target_metrics'])) {
            throw new RuntimeException('Restored business metrics do not match the controlled source data.');
        }
        $report['metrics_match'] = true;

        $phpunit = runCommand(
            ['php', 'artisan', 'test', '--filter=BakeryIntegrityCheckTest'],
            $context['secrets'],
        );
        $report['focused_phpunit'] = $phpunit->exitCode === 0 ? 'pass' : 'fail';
        $report['status'] = 'pass';
    } catch (Throwable $exception) {
        $report['error'] = redact($exception->getMessage(), $context['secrets']);
        throw $exception;
    } finally {
        $report['finished_at_utc'] = gmdate(DATE_ATOM);
        $report['duration_seconds'] = round((hrtime(true) - $started) / 1_000_000_000, 3);
        writeJsonExclusive($reportPath, $report);
        $markdown = "# Ensayo de backup y restauración\n\n"
            ."- ID: `{$runId}`\n"
            .'- Estado: **'.strtoupper($report['status'])."**\n"
            ."- Inicio UTC: {$report['started_at_utc']}\n"
            ."- Fin UTC: {$report['finished_at_utc']}\n"
            ."- Duración: {$report['duration_seconds']} s\n"
            ."- Base origen: `{$report['source_database']}`\n"
            ."- Base restaurada: `{$report['target_database']}`\n"
            .'- Métricas origen: `'.($report['source_metrics'] ?? 'no disponibles')."`\n"
            .'- Métricas restauradas: `'.($report['target_metrics'] ?? 'no disponibles')."`\n"
            .'- PHPUnit focalizado: '.($report['focused_phpunit'] ?? 'no ejecutado')."\n"
            .'- Error: '.($report['error'] ?? 'ninguno')."\n";
        writeTextExclusive($markdownPath, $markdown);

        if (! isset($options['keep-environment'])) {
            compose($context, ['down', '--volumes', '--remove-orphans'], true);
            @unlink($envPath);
        }
        echo json_encode([
            'status' => $report['status'],
            'report' => $reportPath,
            'markdown' => $markdownPath,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    }
}

function usage(): never
{
    fwrite(STDERR, "Usage: php scripts/lib/staging-database.php <backup|restore|restore-test> [options]\n");
    exit(2);
}

$action = $argv[1] ?? null;
if (! in_array($action, ['backup', 'restore', 'restore-test'], true)) {
    usage();
}

try {
    $parsed = options(array_slice($argv, 2));
    match ($action) {
        'backup' => backup($parsed),
        'restore' => restore($parsed),
        'restore-test' => restoreTest($parsed),
    };
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: '.$exception->getMessage().PHP_EOL);
    exit(1);
}

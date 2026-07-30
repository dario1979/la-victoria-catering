<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class OperationalHealth
{
    private const WORKER_HEARTBEAT = 'operations:heartbeat:worker';

    private const SCHEDULER_HEARTBEAT = 'operations:heartbeat:scheduler';

    private const LAST_JOB_SUCCESS = 'operations:jobs:last-success';

    private const LAST_JOB_FAILURE = 'operations:jobs:last-failure';

    public function __construct(private readonly Migrator $migrator) {}

    public function readiness(): array
    {
        $checks = [
            $this->attempt('postgresql', fn (): bool => DB::selectOne('SELECT 1 AS ready') !== null),
            $this->attempt('redis', function (): bool {
                $response = Redis::connection()->ping();

                return $response === true || strtoupper((string) $response) === 'PONG';
            }),
            $this->attempt('storage', fn (): bool => $this->storageWritable()),
            $this->attempt('migrations', fn (): bool => $this->migrationsCurrent()),
            $this->heartbeatCheck(
                'worker',
                self::WORKER_HEARTBEAT,
                (int) config('operations.worker_heartbeat_max_age_seconds'),
            ),
            $this->heartbeatCheck(
                'scheduler',
                self::SCHEDULER_HEARTBEAT,
                (int) config('operations.scheduler_heartbeat_max_age_seconds'),
            ),
        ];
        $ready = array_all($checks, fn (array $check): bool => $check['status'] === 'pass');

        return [
            'status' => $ready ? 'ready' : 'unavailable',
            'checks' => $checks,
        ];
    }

    public function diagnostics(TenantContext $tenant): array
    {
        $readiness = $this->readiness();

        return [
            'status' => $readiness['status'],
            'system' => $readiness['checks'],
            'platform_signals' => [
                'scope' => 'platform',
                'last_job_success' => $this->cachedSignal(self::LAST_JOB_SUCCESS),
                'last_job_failure' => $this->cachedSignal(self::LAST_JOB_FAILURE),
                'failed_jobs' => $this->safeCount('failed_jobs'),
                'backup' => $this->backupSignal(),
            ],
            'tenant_signals' => [
                'scope' => [
                    'organization_id' => $tenant->organization->id,
                    'branch_id' => $tenant->branch->id,
                ],
                'old_pending_notifications' => $this->tenantCount(
                    'notification_deliveries',
                    $tenant,
                    fn ($query) => $query
                        ->where('status', 'pending')
                        ->where('available_at', '<=', now()->subMinutes(
                            (int) config('operations.pending_notification_max_age_minutes'),
                        )),
                    branchMayBeNull: true,
                ),
                'failed_notifications' => $this->tenantCount(
                    'notification_deliveries',
                    $tenant,
                    fn ($query) => $query->where('status', 'failed'),
                    branchMayBeNull: true,
                ),
                'external_transactions_attention' => $this->tenantCount(
                    'payment_gateway_transactions',
                    $tenant,
                    fn ($query) => $query->whereIn('internal_status', ['retrying', 'manual_review']),
                ),
                'fiscal_documents_pending' => $this->tenantCount(
                    'fiscal_documents',
                    $tenant,
                    fn ($query) => $query->whereIn('internal_status', ['pending', 'processing', 'retrying']),
                ),
            ],
        ];
    }

    public function recordWorkerHeartbeat(): void
    {
        $this->putSignal(self::WORKER_HEARTBEAT, ['at' => now()->toIso8601String()]);
    }

    public function recordSchedulerHeartbeat(): void
    {
        $this->putSignal(self::SCHEDULER_HEARTBEAT, ['at' => now()->toIso8601String()]);
    }

    public function recordJobSuccess(string $jobName): void
    {
        $this->putSignal(self::LAST_JOB_SUCCESS, [
            'at' => now()->toIso8601String(),
            'job' => class_basename($jobName),
        ]);
    }

    public function recordJobFailure(string $jobName): void
    {
        $this->putSignal(self::LAST_JOB_FAILURE, [
            'at' => now()->toIso8601String(),
            'job' => class_basename($jobName),
        ]);
    }

    private function attempt(string $id, callable $callback): array
    {
        try {
            $passed = $callback() === true;
        } catch (Throwable) {
            $passed = false;
        }

        return ['id' => $id, 'status' => $passed ? 'pass' : 'fail'];
    }

    private function heartbeatCheck(string $id, string $key, int $maxAge): array
    {
        try {
            $signal = Cache::get($key);
            $at = is_array($signal) ? ($signal['at'] ?? null) : null;
            $timestamp = is_string($at) ? CarbonImmutable::parse($at) : null;
            $passed = $timestamp !== null
                && $timestamp->lessThanOrEqualTo(now()->addSeconds(30))
                && $timestamp->greaterThanOrEqualTo(now()->subSeconds($maxAge));
        } catch (Throwable) {
            $passed = false;
        }

        return ['id' => "{$id}_heartbeat", 'status' => $passed ? 'pass' : 'fail'];
    }

    private function storageWritable(): bool
    {
        $directory = storage_path('framework');
        if (! is_dir($directory) || ! is_writable($directory)) {
            return false;
        }

        $path = $directory.'/.readiness-'.bin2hex(random_bytes(8));
        try {
            return file_put_contents($path, 'ok', LOCK_EX) === 2 && is_file($path);
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function migrationsCurrent(): bool
    {
        if (! $this->migrator->repositoryExists()) {
            return false;
        }

        $available = array_keys($this->migrator->getMigrationFiles(database_path('migrations')));

        return array_diff($available, $this->migrator->getRepository()->getRan()) === [];
    }

    private function putSignal(string $key, array $signal): void
    {
        try {
            Cache::put($key, $signal, now()->addDay());
        } catch (Throwable) {
            // Health instrumentation must never break the operational job.
        }
    }

    private function cachedSignal(string $key): array
    {
        try {
            $signal = Cache::get($key);

            return is_array($signal) && isset($signal['at'])
                ? ['status' => 'recorded', 'at' => $signal['at'], 'job' => $signal['job'] ?? null]
                : ['status' => 'missing', 'at' => null, 'job' => null];
        } catch (Throwable) {
            return ['status' => 'unavailable', 'at' => null, 'job' => null];
        }
    }

    private function safeCount(string $table): ?int
    {
        try {
            return Schema::hasTable($table) ? DB::table($table)->count() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function tenantCount(
        string $table,
        TenantContext $tenant,
        callable $filter,
        bool $branchMayBeNull = false,
    ): ?int {
        try {
            if (! Schema::hasTable($table)) {
                return null;
            }
            $query = DB::table($table)
                ->where('organization_id', $tenant->organization->id)
                ->when(
                    $branchMayBeNull,
                    fn ($query) => $query->where(fn ($branch) => $branch
                        ->whereNull('branch_id')
                        ->orWhere('branch_id', $tenant->branch->id)),
                    fn ($query) => $query->where('branch_id', $tenant->branch->id),
                );
            $filter($query);

            return $query->count();
        } catch (Throwable) {
            return null;
        }
    }

    private function backupSignal(): array
    {
        $directory = config('operations.backup_manifest_directory');
        if (! is_string($directory) || $directory === '') {
            return ['status' => 'not_configured', 'last_manifest_at' => null];
        }

        try {
            $manifests = glob(rtrim($directory, '/\\').'/*.manifest.json') ?: [];
            $timestamps = array_filter(array_map('filemtime', $manifests), 'is_int');
            if ($timestamps === []) {
                return ['status' => 'missing', 'last_manifest_at' => null];
            }
            $latest = max($timestamps);
            $fresh = $latest >= now()->subHours((int) config('operations.backup_max_age_hours'))->timestamp;

            return [
                'status' => $fresh ? 'fresh' : 'overdue',
                'last_manifest_at' => CarbonImmutable::createFromTimestampUTC($latest)->toIso8601String(),
            ];
        } catch (Throwable) {
            return ['status' => 'unavailable', 'last_manifest_at' => null];
        }
    }
}

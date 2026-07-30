<?php

namespace App\Support;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class OperationalFailureRecorder
{
    public function record(JobFailed $event): void
    {
        try {
            if (! Schema::hasTable('operational_failures')) {
                return;
            }
            $payload = $event->job->payload();
            $uuid = $payload['uuid'] ?? null;
            if (! is_string($uuid) || $uuid === '') {
                return;
            }
            $organizationId = $this->positiveContextId('organization_id');
            $branchId = $this->positiveContextId('branch_id');

            DB::table('operational_failures')->updateOrInsert(
                ['job_uuid' => $uuid],
                [
                    'organization_id' => $organizationId,
                    'branch_id' => $organizationId === null ? null : $branchId,
                    'job_name' => class_basename($event->job->resolveName()),
                    'connection' => mb_substr((string) $event->connectionName, 0, 64),
                    'queue' => mb_substr((string) $event->job->getQueue(), 0, 64),
                    'correlation_id' => $this->correlationId(),
                    'status' => 'failed',
                    'failed_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        } catch (Throwable) {
            // Failure instrumentation must never hide the original queue failure.
        }
    }

    private function positiveContextId(string $key): ?int
    {
        $value = Context::get($key);

        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            ? null
            : (int) $value;
    }

    private function correlationId(): ?string
    {
        $value = Context::get('request_id');

        return is_string($value) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $value) === 1
            ? $value
            : null;
    }
}

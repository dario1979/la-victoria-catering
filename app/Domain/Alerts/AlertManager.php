<?php

namespace App\Domain\Alerts;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class AlertManager
{
    public function raise(
        int $organizationId,
        string $deduplicationKey,
        string $event,
        string $condition,
        string $severity,
        string $recipient,
        string $action,
        ?int $branchId = null,
        ?string $relatedType = null,
        ?int $relatedId = null,
    ): object {
        $lock = 'alerts:'.hash('sha256', "{$organizationId}:{$deduplicationKey}");

        return Cache::lock($lock, 30)->block(10, fn () => DB::transaction(function () use (
            $organizationId, $deduplicationKey, $event, $condition, $severity, $recipient, $action,
            $branchId, $relatedType, $relatedId
        ): object {
            $existing = DB::table('alerts')
                ->where('organization_id', $organizationId)
                ->where('deduplication_key', $deduplicationKey)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();
            if ($existing) {
                DB::table('alerts')->where('id', $existing->id)->update([
                    'occurrences' => $existing->occurrences + 1,
                    'last_seen_at' => now(),
                    'updated_at' => now(),
                ]);

                return DB::table('alerts')->find($existing->id);
            }
            $id = DB::table('alerts')->insertGetId([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'deduplication_key' => $deduplicationKey,
                'event' => $event,
                'condition' => $condition,
                'severity' => $severity,
                'recipient' => $recipient,
                'action' => $action,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'status' => 'open',
                'occurrences' => 1,
                'last_seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('alerts')->find($id);
        }));
    }

    public function acknowledge(int $alertId, int $userId): void
    {
        DB::table('alerts')->where('id', $alertId)->where('status', 'open')->update([
            'read_at' => now(), 'acknowledged_at' => now(), 'acknowledged_by' => $userId,
            'updated_at' => now(),
        ]);
    }

    public function resolve(int $alertId, ?int $userId = null): void
    {
        DB::table('alerts')->where('id', $alertId)->where('status', 'open')->update([
            'status' => 'resolved', 'resolved_at' => now(), 'resolved_by' => $userId, 'updated_at' => now(),
        ]);
    }
}

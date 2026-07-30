<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Models\User;
use App\Support\Imports\ImportExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessImportBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $batchId) {}

    public function handle(ImportExecutor $executor): void
    {
        try {
            DB::transaction(function () use ($executor): void {
                $batch = ImportBatch::query()->lockForUpdate()->findOrFail($this->batchId);
                if ($batch->status === 'completed') {
                    return;
                }
                abort_unless(in_array($batch->status, ['queued', 'processing'], true), 409, 'El lote no está listo para procesarse.');
                $batch->update(['status' => 'processing']);
                $created = $executor->execute($batch);
                $batch->update([
                    'status' => 'completed',
                    'created_records' => $created,
                    'completed_at' => now(),
                    'failure_reason' => null,
                ]);
                DB::table('audit_logs')->insert([
                    'event' => 'import.completed',
                    'subject_type' => ImportBatch::class,
                    'subject_id' => $batch->id,
                    'actor_type' => User::class,
                    'actor_id' => $batch->created_by,
                    'context' => json_encode([
                        'organization_id' => $batch->organization_id,
                        'branch_id' => $batch->branch_id,
                        'type' => $batch->type,
                        'rows' => $batch->valid_rows,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }, 3);
        } catch (Throwable $exception) {
            ImportBatch::query()->whereKey($this->batchId)->update([
                'status' => 'failed',
                'failed_at' => now(),
                'failure_reason' => 'La importación falló y no aplicó cambios. Revise el informe y ejecute un nuevo dry-run.',
            ]);
            throw $exception;
        }
    }
}

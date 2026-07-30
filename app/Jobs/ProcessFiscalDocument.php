<?php

namespace App\Jobs;

use App\Domain\Integrations\FiscalDocumentManager;
use App\Models\FiscalDocument;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessFiscalDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function __construct(public readonly int $fiscalDocumentId) {}

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(FiscalDocumentManager $manager): void
    {
        if (! config('services.arca.enabled')) {
            return;
        }

        $document = FiscalDocument::query()->find($this->fiscalDocumentId);
        if (! $document || $document->internal_status === 'authorized') {
            return;
        }

        $manager->issue(
            $document->safe_request,
            $document->organization_id,
            $document->branch_id,
            $document->idempotency_key,
        );
    }

    public function failed(?Throwable $exception): void
    {
        $document = FiscalDocument::query()->find($this->fiscalDocumentId);
        if (! $document) {
            return;
        }

        $document->update([
            'internal_status' => 'manual_review',
            'last_error' => $exception?->getMessage() ?? 'Fiscal issuance exhausted its retries.',
        ]);
        DB::table('alerts')->updateOrInsert(
            [
                'organization_id' => $document->organization_id,
                'deduplication_key' => "arca:fiscal-document:{$document->id}:manual-review",
                'status' => 'open',
            ],
            [
                'branch_id' => $document->branch_id,
                'event' => 'Comprobante fiscal requiere revisión manual',
                'condition' => "El comprobante #{$document->id} agotó sus reintentos.",
                'severity' => 'critical',
                'recipient' => 'finance',
                'action' => 'Verificar el estado en ARCA antes de intentar una corrección no destructiva.',
                'occurrences' => 1,
                'last_seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        DB::table('audit_logs')->insert([
            'event' => 'integration.arca.dead_lettered',
            'subject_type' => FiscalDocument::class,
            'subject_id' => $document->id,
            'actor_type' => User::class,
            'actor_id' => null,
            'context' => json_encode(['error' => $document->last_error], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

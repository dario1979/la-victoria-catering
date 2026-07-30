<?php

namespace App\Jobs;

use App\Models\FiscalDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPendingFiscalDocuments implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        if (! config('services.arca.enabled')) {
            return;
        }

        FiscalDocument::query()
            ->whereIn('internal_status', ['pending', 'processing', 'retrying'])
            ->orderBy('id')
            ->eachById(fn (FiscalDocument $document) => ProcessFiscalDocument::dispatch($document->id));
    }
}

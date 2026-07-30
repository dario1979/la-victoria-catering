<?php

namespace App\Console\Commands;

use App\Models\ImportBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BakeryImportsPrune extends Command
{
    protected $signature = 'bakery:imports-prune {--hours= : Antigüedad mínima; usa IMPORT_RETENTION_HOURS por defecto}';

    protected $description = 'Elimina archivos temporales vencidos sin borrar trazabilidad de importaciones';

    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?? config('imports.retention_hours'));
        if ($hours < 1) {
            $this->error('La retención debe ser de al menos una hora.');

            return self::INVALID;
        }
        $count = 0;
        ImportBatch::query()
            ->whereNull('file_purged_at')
            ->where('created_at', '<=', now()->subHours($hours))
            ->whereNotIn('status', ['queued', 'processing'])
            ->orderBy('id')
            ->eachById(function (ImportBatch $batch) use (&$count): void {
                Storage::disk(config('imports.disk'))->delete($batch->storage_path);
                $batch->update(['file_purged_at' => now()]);
                $count++;
            }, 100);
        $this->info("Archivos temporales purgados: {$count}.");

        return self::SUCCESS;
    }
}

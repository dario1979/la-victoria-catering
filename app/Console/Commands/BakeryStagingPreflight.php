<?php

namespace App\Console\Commands;

use App\Support\StagingPreflightChecker;
use Illuminate\Console\Command;

final class BakeryStagingPreflight extends Command
{
    protected $signature = 'bakery:staging-preflight
        {--phase=runtime : config, pre-migrate o runtime}
        {--allow-pending-migrations : Permite migraciones pendientes sólo en pre-migrate}
        {--format=human : Formato human o json}';

    protected $description = 'Verifica que staging sea seguro antes de exponerlo';

    public function handle(StagingPreflightChecker $checker): int
    {
        $phase = strtolower((string) $this->option('phase'));
        $format = strtolower((string) $this->option('format'));
        $allowPendingMigrations = (bool) $this->option('allow-pending-migrations');

        if (! in_array($phase, ['config', 'pre-migrate', 'runtime'], true)) {
            $this->error('La fase debe ser config, pre-migrate o runtime.');

            return self::INVALID;
        }
        if (! in_array($format, ['human', 'json'], true)) {
            $this->error('El formato debe ser human o json.');

            return self::INVALID;
        }
        if ($allowPendingMigrations && $phase !== 'pre-migrate') {
            $this->error('--allow-pending-migrations sólo puede usarse con --phase=pre-migrate.');

            return self::INVALID;
        }

        $result = $checker->run($phase, $allowPendingMigrations);
        if ($format === 'json') {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->components->info(sprintf(
                'Preflight staging %s: %d verificaciones, %d fallas.',
                strtoupper($result['status']),
                $result['summary']['checks'],
                $result['summary']['failed'],
            ));
            $this->table(
                ['Verificación', 'Estado', 'Resultado'],
                collect($result['checks'])->map(fn (array $check): array => [
                    $check['id'],
                    strtoupper($check['status']),
                    $check['message'],
                ])->all(),
            );
        }

        return $result['status'] === 'pass' ? self::SUCCESS : self::FAILURE;
    }
}

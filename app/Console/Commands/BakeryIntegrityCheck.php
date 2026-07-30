<?php

namespace App\Console\Commands;

use App\Support\BakeryIntegrityChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class BakeryIntegrityCheck extends Command
{
    protected $signature = 'bakery:integrity-check
        {--organization= : Limita la verificación a una organización}
        {--branch= : Limita la verificación a una sucursal}
        {--format=human : Formato human o json}';

    protected $description = 'Verifica invariantes operativas sin modificar datos';

    public function handle(BakeryIntegrityChecker $checker): int
    {
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['human', 'json'], true)) {
            $this->error('El formato debe ser human o json.');

            return self::INVALID;
        }
        $organizationId = $this->positiveIntegerOption('organization');
        $branchId = $this->positiveIntegerOption('branch');
        if ($organizationId === false || $branchId === false) {
            return self::INVALID;
        }
        if ($organizationId !== null && ! DB::table('organizations')->where('id', $organizationId)->exists()) {
            $this->error('La organización indicada no existe.');

            return self::INVALID;
        }
        if ($branchId !== null) {
            $branch = DB::table('branches')->where('id', $branchId)->first(['organization_id']);
            if ($branch === null || ($organizationId !== null && (int) $branch->organization_id !== $organizationId)) {
                $this->error('La sucursal indicada no existe dentro del alcance solicitado.');

                return self::INVALID;
            }
            $organizationId ??= (int) $branch->organization_id;
        }

        $result = $checker->run($organizationId, $branchId);
        if ($format === 'json') {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->components->info(sprintf(
                'Integridad %s: %d verificaciones, %d violaciones.',
                strtoupper($result['status']),
                $result['summary']['checks'],
                $result['summary']['violations'],
            ));
            $this->table(
                ['Verificación', 'Estado', 'Violaciones', 'IDs de muestra'],
                collect($result['checks'])->map(fn (array $check): array => [
                    $check['id'],
                    strtoupper($check['status']),
                    $check['violations'],
                    implode(', ', $check['sample_ids']),
                ])->all(),
            );
        }

        return $result['status'] === 'pass' ? self::SUCCESS : self::FAILURE;
    }

    private function positiveIntegerOption(string $name): int|false|null
    {
        $value = $this->option($name);
        if ($value === null) {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->error("La opción --{$name} debe ser un entero positivo.");

            return false;
        }

        return (int) $value;
    }
}

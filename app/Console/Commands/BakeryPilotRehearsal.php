<?php

namespace App\Console\Commands;

use App\Models\PilotScenario;
use App\Support\Pilot\PilotRehearsalRunner;
use App\Support\Pilot\PilotReportWriter;
use App\Support\Pilot\PilotScenarioProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class BakeryPilotRehearsal extends Command
{
    protected $signature = 'bakery:pilot-rehearsal
        {--scenario=pilot-technical : Identificador estable del escenario}
        {--confirm : Confirma el ensayo o la limpieza aislada}
        {--cleanup : Elimina únicamente el escenario indicado y sus informes}
        {--format=human : Formato human o json}';

    protected $description = 'Ejecuta por API el ensayo técnico reproducible del piloto';

    public function handle(
        PilotScenarioProvisioner $provisioner,
        PilotRehearsalRunner $runner,
        PilotReportWriter $reports,
    ): int {
        if (! app()->environment(['local', 'testing', 'staging'])) {
            $this->error('El ensayo piloto está bloqueado fuera de local, testing o staging.');

            return self::FAILURE;
        }
        if (! $this->option('confirm')) {
            $this->error('Se requiere --confirm para ejecutar o limpiar el escenario piloto.');

            return self::INVALID;
        }
        $identifier = strtolower(trim((string) $this->option('scenario')));
        if (preg_match('/^[a-z0-9][a-z0-9_-]{2,48}$/', $identifier) !== 1) {
            $this->error('Identificador de escenario inválido.');

            return self::INVALID;
        }
        if ($this->option('cleanup')) {
            return $this->cleanup($identifier);
        }

        try {
            $scenario = $provisioner->provision($identifier);
            $result = $runner->run($scenario);
            $paths = $reports->write($scenario->fresh(), $result);
            $payload = $result + ['reports' => $paths];
            if ($this->option('format') === 'json') {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $this->components->info("Ensayo {$identifier} PASS en {$result['duration_ms']} ms.");
                $this->line("JSON: {$paths['json']}");
                $this->line("Markdown: {$paths['markdown']}");
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error("Ensayo {$identifier} FAIL: {$exception->getMessage()}");

            return self::FAILURE;
        }
    }

    private function cleanup(string $identifier): int
    {
        $scenario = PilotScenario::query()->where('identifier', $identifier)->first();
        if (! $scenario) {
            $this->components->info("El escenario {$identifier} ya no existe.");

            return self::SUCCESS;
        }
        $organization = DB::table('organizations')->find($scenario->organization_id);
        if (! $organization || ! str_starts_with((string) $organization->name, "[PILOT {$identifier}]")) {
            throw new RuntimeException('La organización no coincide con el marcador aislado del escenario; limpieza cancelada.');
        }
        $paths = array_values($scenario->report_paths ?? []);
        DB::transaction(function () use ($scenario): void {
            DB::table('branches')->where('id', $scenario->branch_id)->update([
                'active' => false,
                'updated_at' => now(),
            ]);
            $scenario->update([
                'status' => 'cleaned',
                'report_paths' => [],
            ]);
        });
        Storage::disk('local')->delete($paths);
        $this->components->info("Sucursal del escenario {$identifier} desactivada; la evidencia y sus destinatarios se conservaron.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Support\Pilot\PilotScenarioProvisioner;
use Illuminate\Console\Command;

final class BakerySeedPilotScenario extends Command
{
    protected $signature = 'bakery:seed-pilot-scenario
        {--scenario=pilot-technical : Identificador estable del escenario}
        {--confirm : Confirma la creación de datos ficticios aislados}
        {--format=human : Formato human o json}';

    protected $description = 'Crea idempotentemente los participantes y datos ficticios del piloto';

    public function handle(PilotScenarioProvisioner $provisioner): int
    {
        if (! app()->environment(['local', 'testing', 'staging'])) {
            $this->error('El escenario piloto sólo puede crearse en local, testing o staging.');

            return self::FAILURE;
        }
        if (! $this->option('confirm')) {
            $this->error('Se requiere --confirm para crear datos ficticios del piloto.');

            return self::INVALID;
        }
        $identifier = strtolower(trim((string) $this->option('scenario')));
        if (preg_match('/^[a-z0-9][a-z0-9_-]{2,48}$/', $identifier) !== 1) {
            $this->error('El identificador debe usar 3–49 caracteres: minúsculas, números, guion o guion bajo.');

            return self::INVALID;
        }
        $scenario = $provisioner->provision($identifier);
        $payload = [
            'status' => $scenario->status,
            'scenario' => $scenario->identifier,
            'organization_id' => $scenario->organization_id,
            'branch_id' => $scenario->branch_id,
            'participants' => $scenario->participants,
            'fixtures' => $scenario->fixtures,
        ];
        if ($this->option('format') === 'json') {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->components->info("Escenario {$scenario->identifier} listo (estado: {$scenario->status}).");
            $this->line("Organización {$scenario->organization_id} · sucursal {$scenario->branch_id}");
        }

        return self::SUCCESS;
    }
}

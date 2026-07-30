<?php

namespace App\Support\Pilot;

use App\Models\PilotScenario;
use Illuminate\Support\Facades\Storage;

final class PilotReportWriter
{
    public function write(PilotScenario $scenario, array $result): array
    {
        $stamp = now()->utc()->format('Ymd-His');
        $base = "pilot-rehearsals/{$scenario->identifier}-{$stamp}";
        $jsonPath = "{$base}.json";
        $markdownPath = "{$base}.md";
        Storage::disk('local')->put(
            $jsonPath,
            json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
        $lines = [
            "# Ensayo técnico del piloto — {$scenario->identifier}",
            '',
            "- Estado: **{$result['status']}**",
            "- Entorno: `{$result['environment']}`",
            "- Duración: {$result['duration_ms']} ms",
            '- Repetición idempotente: '.($result['replayed'] ? 'sí' : 'no'),
            "- Integridad: {$result['assertions']['integrity_checks']} controles, {$result['assertions']['integrity_violations']} violaciones",
            '',
            '## Evidencia',
            '',
            '| Indicador | Resultado |',
            '|---|---:|',
            "| Cuenta corriente | {$result['assertions']['customer_balance_cents']} centavos |",
            "| Cuentas por pagar | {$result['assertions']['accounts_payable']} |",
            "| Notificaciones | {$result['assertions']['notifications']} |",
            "| Permiso negativo | HTTP {$result['assertions']['negative_permission_status']} |",
            '',
            '> Este ensayo técnico no sustituye la validación humana del piloto.',
        ];
        Storage::disk('local')->put($markdownPath, implode(PHP_EOL, $lines).PHP_EOL);
        $paths = ['json' => $jsonPath, 'markdown' => $markdownPath];
        $scenario->update(['report_paths' => $paths]);

        return $paths;
    }
}

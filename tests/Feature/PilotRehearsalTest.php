<?php

namespace Tests\Feature;

use App\Models\PilotScenario;
use App\Support\Pilot\PilotScenarioProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PilotRehearsalTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_and_rehearsal_are_confirmed_idempotent_and_reported(): void
    {
        Storage::fake('local');
        Mail::fake();
        config([
            'services.arca.enabled' => false,
            'services.mercadopago.enabled' => false,
            'services.pwa_push.enabled' => false,
        ]);

        $this->artisan('bakery:seed-pilot-scenario', [
            '--scenario' => 'automated-pilot',
            '--confirm' => true,
        ])->assertSuccessful();
        $first = PilotScenario::query()->where('identifier', 'automated-pilot')->firstOrFail();

        $this->artisan('bakery:seed-pilot-scenario', [
            '--scenario' => 'automated-pilot',
            '--confirm' => true,
        ])->assertSuccessful();
        $this->assertSame(1, PilotScenario::query()->where('identifier', 'automated-pilot')->count());

        $this->artisan('bakery:pilot-rehearsal', [
            '--scenario' => 'automated-pilot',
            '--confirm' => true,
        ])->assertSuccessful();
        $scenario = $first->fresh();
        $this->assertSame('passed', $scenario->status);
        $this->assertSame('passed', $scenario->result['status']);
        $this->assertSame(0, $scenario->result['assertions']['integrity_violations']);
        $this->assertSame(403, $scenario->result['assertions']['negative_permission_status']);
        Storage::disk('local')->assertExists($scenario->report_paths['json']);
        Storage::disk('local')->assertExists($scenario->report_paths['markdown']);

        $orderCount = count($scenario->result['ids']);
        $this->artisan('bakery:pilot-rehearsal', [
            '--scenario' => 'automated-pilot',
            '--confirm' => true,
        ])->assertSuccessful();
        $this->assertSame($orderCount, count($scenario->fresh()->result['ids']));

        $this->artisan('bakery:pilot-rehearsal', [
            '--scenario' => 'automated-pilot',
            '--confirm' => true,
            '--cleanup' => true,
        ])->assertSuccessful();
        $this->assertDatabaseHas('pilot_scenarios', [
            'identifier' => 'automated-pilot',
            'status' => 'cleaned',
        ]);
        $this->assertDatabaseHas('branches', ['id' => $scenario->branch_id, 'active' => false]);
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $scenario->organization_id,
            'user_id' => $scenario->participants['admin_user_id'],
        ]);
    }

    public function test_commands_fail_closed_without_confirmation(): void
    {
        $this->artisan('bakery:seed-pilot-scenario')->assertExitCode(2);
        $this->artisan('bakery:pilot-rehearsal')->assertExitCode(2);
        $this->assertDatabaseCount('pilot_scenarios', 0);
    }

    public function test_in_process_api_client_honors_csrf_outside_testing_environment(): void
    {
        app()->detectEnvironment(fn (): string => 'local');
        try {
            $scenario = app(PilotScenarioProvisioner::class)->provision('csrf-local');
            $this->assertSame('seeded', $scenario->status);
            $this->assertNotEmpty($scenario->fixtures['fefo_lot_id']);
        } finally {
            app()->detectEnvironment(fn (): string => 'testing');
        }
    }
}

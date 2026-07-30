<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BakeryIntegrityCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_database_passes_and_check_is_read_only(): void
    {
        $this->seed(DatabaseSeeder::class);
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|alter|drop|create|truncate)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $exitCode = Artisan::call('bakery:integrity-check', ['--format' => 'json']);
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(0, $exitCode);
        self::assertSame('pass', $result['status']);
        self::assertSame(0, $result['summary']['violations']);
        self::assertGreaterThanOrEqual(20, $result['summary']['checks']);
        self::assertSame([], $writes);
    }

    public function test_violation_returns_failure_and_branch_filter_isolated_scope(): void
    {
        $this->seed(DatabaseSeeder::class);
        $organizationId = (int) DB::table('organizations')->value('id');
        $branches = DB::table('branches')->orderBy('id')->pluck('id');
        $lotId = (int) DB::table('inventory_lots')->value('id');
        DB::table('inventory_lots')->where('id', $lotId)->update(['quantity' => '-1.000']);

        $failed = Artisan::call('bakery:integrity-check', [
            '--organization' => $organizationId,
            '--branch' => (int) $branches[0],
            '--format' => 'json',
        ]);
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $negative = collect($result['checks'])->firstWhere('id', 'inventory.non_negative');

        self::assertSame(1, $failed);
        self::assertSame('fail', $result['status']);
        self::assertSame(1, $negative['violations']);
        self::assertSame([$lotId], $negative['sample_ids']);

        $isolated = Artisan::call('bakery:integrity-check', [
            '--branch' => (int) $branches[1],
            '--format' => 'json',
        ]);
        self::assertSame(0, $isolated);
        self::assertSame('pass', json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['status']);
    }

    public function test_rejects_branch_outside_requested_organization(): void
    {
        $this->seed(DatabaseSeeder::class);
        $organizationId = (int) DB::table('organizations')->value('id');
        $otherOrganization = DB::table('organizations')->insertGetId([
            'name' => 'Otra organización', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherBranch = DB::table('branches')->insertGetId([
            'organization_id' => $otherOrganization, 'name' => 'Otra sucursal',
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('bakery:integrity-check', [
            '--organization' => $organizationId,
            '--branch' => $otherBranch,
            '--format' => 'json',
        ]);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('no existe dentro del alcance', Artisan::output());
    }
}

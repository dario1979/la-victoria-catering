<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RestoreVerificationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RestoreVerificationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_controlled_fixture_is_idempotent_and_satisfies_all_invariants(): void
    {
        $this->seed(DatabaseSeeder::class);
        putenv('RESTORE_TEST_SCENARIO=true');
        try {
            $this->seed(RestoreVerificationSeeder::class);
            $this->seed(RestoreVerificationSeeder::class);
        } finally {
            putenv('RESTORE_TEST_SCENARIO');
        }

        $exitCode = Artisan::call('bakery:integrity-check', ['--format' => 'json']);
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(0, $exitCode);
        self::assertSame('pass', $result['status']);
        self::assertSame(0, $result['summary']['violations']);
        self::assertSame(1, DB::table('orders')->where('customer_name', 'Restore Verification')->count());
        self::assertSame(6000, DB::table('customer_account_entries')->sum('amount_cents'));
        self::assertSame(
            140000,
            DB::table('accounts_payable')->selectRaw('SUM(total_cents - paid_cents) AS balance')->value('balance')
        );
        self::assertSame(1, DB::table('production_consumptions')->count());
    }

    public function test_fixture_is_fail_closed_without_explicit_test_flag(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->expectExceptionMessage('RESTORE_TEST_SCENARIO=true');
        $this->seed(RestoreVerificationSeeder::class);
    }
}

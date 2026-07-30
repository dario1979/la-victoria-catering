<?php

namespace Tests\Feature;

use App\Domain\Procurement\ReceivePurchaseOrder;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

class ProcurementConcurrencyTest extends TestCase
{
    public function test_postgresql_serializes_competing_receipts_for_the_same_pending_quantity(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires PostgreSQL and pcntl to exercise a real row-lock race.');
        }
        Artisan::call('migrate:fresh', ['--force' => true]);
        [$order, $orderItem, $location, $user] = $this->scenario();
        $directory = storage_path('framework/cache/procurement-race-'.str()->uuid());
        mkdir($directory, 0777, true);
        $start = "{$directory}/start";
        $children = [];

        for ($worker = 1; $worker <= 2; $worker++) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid, 'Could not fork concurrency worker.');
            if ($pid === 0) {
                while (! file_exists($start)) {
                    usleep(5_000);
                }
                DB::purge();
                try {
                    app(ReceivePurchaseOrder::class)->execute(
                        PurchaseOrder::findOrFail($order),
                        [
                            'received_at' => now()->subMinute()->toISOString(),
                            'items' => [[
                                'purchase_order_item_id' => $orderItem,
                                'location_id' => $location,
                                'received_quantity' => '1.000',
                                'accepted_quantity' => '1.000',
                                'rejected_quantity' => '0.000',
                                'lot_code' => "RACE-{$worker}",
                                'expires_at' => today()->addMonth()->toDateString(),
                            ]],
                        ],
                        1,
                        1,
                        $user,
                    );
                    file_put_contents("{$directory}/result-{$worker}", 'success');
                } catch (Throwable $exception) {
                    file_put_contents("{$directory}/result-{$worker}", 'rejected:'.$exception::class);
                }
                exit(0);
            }
            $children[] = $pid;
        }
        touch($start);
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
        }
        DB::purge();
        $results = [
            file_get_contents("{$directory}/result-1"),
            file_get_contents("{$directory}/result-2"),
        ];

        self::assertCount(1, array_filter($results, fn ($result) => $result === 'success'));
        self::assertCount(1, array_filter($results, fn ($result) => str_starts_with($result, 'rejected:')));
        self::assertSame(1, DB::table('purchase_receipts')->count());
        self::assertSame(1, DB::table('stock_movements')->count());
        self::assertSame('1.000', number_format(
            (float) DB::table('purchase_order_items')->where('id', $orderItem)->value('received_quantity'),
            3,
            '.',
            '',
        ));
        self::assertSame('received', DB::table('purchase_orders')->where('id', $order)->value('status'));
    }

    private function scenario(): array
    {
        $organization = DB::table('organizations')->insertGetId([
            'name' => 'Concurrency Org', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branch = DB::table('branches')->insertGetId([
            'organization_id' => $organization, 'name' => 'Concurrency Branch', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        self::assertSame(1, $organization);
        self::assertSame(1, $branch);
        $user = User::factory()->create();
        $product = DB::table('products')->insertGetId([
            'organization_id' => $organization, 'name' => 'Race Product',
            'type' => 'raw_material', 'unit' => 'unit', 'minimum_stock' => '0.000',
            'price' => '0.00', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $location = DB::table('locations')->insertGetId([
            'organization_id' => $organization, 'branch_id' => $branch,
            'name' => 'Race Location', 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $supplier = DB::table('suppliers')->insertGetId([
            'organization_id' => $organization, 'trade_name' => 'Race Supplier',
            'lead_time_days' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $catalog = DB::table('supplier_products')->insertGetId([
            'organization_id' => $organization, 'supplier_id' => $supplier, 'product_id' => $product,
            'purchase_unit' => 'unit', 'conversion_factor' => '1.000000',
            'minimum_quantity' => '0.000', 'lead_time_days' => 0,
            'preferred' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $order = DB::table('purchase_orders')->insertGetId([
            'organization_id' => $organization, 'branch_id' => $branch, 'supplier_id' => $supplier,
            'number' => 'OC-RACE', 'status' => 'sent', 'ordered_at' => today(),
            'currency' => 'ARS', 'subtotal' => '1.00', 'tax_total' => '0.00', 'total' => '1.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $item = DB::table('purchase_order_items')->insertGetId([
            'purchase_order_id' => $order, 'supplier_product_id' => $catalog, 'product_id' => $product,
            'product_name' => 'Race Product', 'purchase_unit' => 'unit', 'base_unit' => 'unit',
            'conversion_factor' => '1.000000', 'quantity' => '1.000', 'received_quantity' => '0.000',
            'unit_price' => '1.00', 'tax_amount' => '0.00', 'subtotal' => '1.00', 'total' => '1.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$order, $item, $location, $user->id];
    }
}

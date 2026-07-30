<?php

namespace App\Jobs;

use App\Domain\Alerts\AlertManager;
use App\Models\InventoryLot;
use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DetectInventoryRisks implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function handle(AlertManager $alerts): void
    {
        Cache::lock('jobs:detect-inventory-risks', 300)->get(function () use ($alerts): void {
            DB::table('inventory_lots')->join('locations', 'locations.id', '=', 'inventory_lots.location_id')
                ->join('products', 'products.id', '=', 'inventory_lots.product_id')
                ->whereNotNull('expires_at')->whereDate('expires_at', '<', today())
                ->select('inventory_lots.*', 'locations.organization_id', 'locations.branch_id')
                ->orderBy('inventory_lots.id')->each(function (object $lot) use ($alerts): void {
                    DB::table('inventory_lots')->where('id', $lot->id)->update([
                        'status' => 'expired', 'updated_at' => now(),
                    ]);
                    $alerts->raise(
                        $lot->organization_id, "lot:{$lot->id}:expired", 'LotExpired',
                        'expires_at < today', 'high', 'inventory', 'block and replace lot',
                        $lot->branch_id, InventoryLot::class, $lot->id
                    );
                });
            DB::table('inventory_lots')->join('locations', 'locations.id', '=', 'inventory_lots.location_id')
                ->whereBetween('expires_at', [today(), today()->addDays(3)])
                ->select('inventory_lots.*', 'locations.organization_id', 'locations.branch_id')
                ->orderBy('inventory_lots.id')->each(fn (object $lot) => $alerts->raise(
                    $lot->organization_id, "lot:{$lot->id}:expiring", 'LotExpiringSoon',
                    'expires within 3 days', 'warning', 'inventory', 'consume or relocate lot',
                    $lot->branch_id, InventoryLot::class, $lot->id
                ));
            DB::table('branches')->where('active', true)->orderBy('id')
                ->each(function (object $branch) use ($alerts): void {
                    Product::query()->where('organization_id', $branch->organization_id)
                        ->where('active', true)->where('minimum_stock', '>', 0)
                        ->orderBy('id')->each(function (Product $product) use ($alerts, $branch): void {
                            $available = DB::table('inventory_lots')
                                ->join('locations', 'locations.id', '=', 'inventory_lots.location_id')
                                ->where('inventory_lots.product_id', $product->id)
                                ->where('locations.organization_id', $branch->organization_id)
                                ->where('locations.branch_id', $branch->id)
                                ->where('locations.active', true)
                                ->where('inventory_lots.status', 'available')
                                ->where(fn ($query) => $query
                                    ->whereNull('inventory_lots.expires_at')
                                    ->orWhereDate('inventory_lots.expires_at', '>=', today()))
                                ->selectRaw('COALESCE(SUM(inventory_lots.quantity - inventory_lots.reserved_quantity), 0) AS available')
                                ->value('available');
                            $key = "stock-low:{$branch->id}:{$product->id}";
                            if ((float) $available >= (float) $product->minimum_stock) {
                                $alerts->resolveByKey($branch->organization_id, $key);

                                return;
                            }
                            $preferred = DB::table('supplier_products')
                                ->join('suppliers', 'suppliers.id', '=', 'supplier_products.supplier_id')
                                ->where('supplier_products.organization_id', $branch->organization_id)
                                ->where('supplier_products.product_id', $product->id)
                                ->where('supplier_products.active', true)
                                ->where('suppliers.active', true)
                                ->orderByDesc('supplier_products.preferred')
                                ->orderBy('suppliers.trade_name')
                                ->select('suppliers.trade_name')->first();
                            $openOrder = DB::table('purchase_orders')
                                ->join('purchase_order_items', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')
                                ->where('purchase_orders.organization_id', $branch->organization_id)
                                ->where('purchase_orders.branch_id', $branch->id)
                                ->where('purchase_order_items.product_id', $product->id)
                                ->whereNotIn('purchase_orders.status', ['received', 'cancelled'])
                                ->select('purchase_orders.number')->first();
                            $action = $openOrder
                                ? "Review pending purchase order {$openOrder->number}."
                                : ($preferred
                                    ? "Create a purchase order for preferred supplier {$preferred->trade_name}."
                                    : 'Register a supplier product and create a purchase order.');
                            $alerts->raise(
                                $branch->organization_id, $key, 'StockBelowMinimum',
                                "available={$available}; minimum={$product->minimum_stock}; unit={$product->unit}",
                                $preferred ? 'high' : 'critical', 'purchasing', $action,
                                $branch->id, Product::class, $product->id
                            );
                        });
                });
        });
    }
}

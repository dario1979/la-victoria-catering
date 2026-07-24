<?php

namespace App\Jobs;

use App\Domain\Alerts\AlertManager;
use App\Models\InventoryLot;
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
        });
    }
}

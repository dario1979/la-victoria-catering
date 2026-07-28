<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

final class DashboardController extends Controller
{
    public function summary(TenantContext $tenant): JsonResponse
    {
        $orders = Order::query()
            ->where('organization_id', $tenant->organization->id)
            ->where('branch_id', $tenant->branch->id);
        $alerts = Alert::query()
            ->where('organization_id', $tenant->organization->id)
            ->where(fn (Builder $query) => $query
                ->whereNull('branch_id')
                ->orWhere('branch_id', $tenant->branch->id));

        $outstanding = (clone $orders)
            ->selectRaw('COALESCE(SUM(total - paid_total), 0) AS aggregate')
            ->value('aggregate');

        return response()->json([
            'data' => [
                'metrics' => [
                    'orders_total' => (clone $orders)->count(),
                    'orders_today' => (clone $orders)->whereDate('created_at', today())->count(),
                    'overdue_orders' => (clone $orders)
                        ->whereNotNull('required_at')
                        ->where('required_at', '<', now())
                        ->whereNotIn('status', ['delivered', 'cancelled'])
                        ->count(),
                    'pending_production' => ProductionBatch::query()
                        ->where('organization_id', $tenant->organization->id)
                        ->where('branch_id', $tenant->branch->id)
                        ->whereIn('status', ['planned', 'in_progress'])
                        ->count(),
                    'outstanding_balance' => $this->money($outstanding),
                    'open_alerts' => (clone $alerts)->where('status', '!=', 'resolved')->count(),
                    'critical_stock' => $this->criticalStock($tenant),
                ],
                'recent_orders' => (clone $orders)->latest('id')->limit(5)->get(),
                'open_alerts' => (clone $alerts)
                    ->where('status', '!=', 'resolved')
                    ->latest('last_seen_at')
                    ->limit(5)
                    ->get(),
            ],
        ]);
    }

    private function criticalStock(TenantContext $tenant): int
    {
        return Product::query()
            ->where('organization_id', $tenant->organization->id)
            ->where('active', true)
            ->whereRaw(
                'COALESCE((
                    SELECT SUM(inventory_lots.quantity - inventory_lots.reserved_quantity)
                    FROM inventory_lots
                    INNER JOIN locations ON locations.id = inventory_lots.location_id
                    WHERE inventory_lots.product_id = products.id
                      AND inventory_lots.status = ?
                      AND locations.organization_id = ?
                      AND locations.branch_id = ?
                ), 0) <= products.minimum_stock',
                ['available', $tenant->organization->id, $tenant->branch->id]
            )
            ->count();
    }

    private function money(int|float|string|null $value): string
    {
        $raw = (string) ($value ?? '0');
        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');

        return $whole.'.'.str_pad(substr($fraction, 0, 2), 2, '0');
    }
}

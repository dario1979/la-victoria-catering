<?php

namespace App\Jobs;

use App\Domain\Alerts\AlertManager;
use App\Models\PurchaseOrder;
use App\Models\SupplierProduct;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DetectPurchaseRisks implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function handle(AlertManager $alerts): void
    {
        Cache::lock('jobs:detect-purchase-risks', 300)->get(function () use ($alerts): void {
            PurchaseOrder::query()->with('items')->whereNotIn('status', ['received', 'cancelled'])
                ->orderBy('id')->each(function (PurchaseOrder $order) use ($alerts): void {
                    $approvedKey = "purchase-order:{$order->id}:approved-not-sent";
                    if ($order->status === 'approved' && $order->approved_at?->lte(now()->subHours(2))) {
                        $alerts->raise(
                            $order->organization_id, $approvedKey, 'PurchaseOrderApprovedNotSent',
                            'Purchase order remained approved for more than two hours.',
                            'warning', 'purchasing', 'Send the frozen purchase order to the supplier.',
                            $order->branch_id, PurchaseOrder::class, $order->id
                        );
                    } elseif ($order->status !== 'approved') {
                        $alerts->resolveByKey($order->organization_id, $approvedKey);
                    }

                    $overdueKey = "purchase-order:{$order->id}:overdue";
                    if (in_array($order->status, ['sent', 'partially_received'], true)
                        && $order->expected_at?->lt(today())) {
                        $pending = $order->items->sum(
                            fn ($item) => max(0, (float) $item->quantity - (float) $item->received_quantity)
                        );
                        $alerts->raise(
                            $order->organization_id, $overdueKey, 'SupplierDeliveryDelayed',
                            "expected_at={$order->expected_at->toDateString()}; pending={$pending}",
                            'high', 'purchasing', 'Contact the supplier and update the expected receipt.',
                            $order->branch_id, PurchaseOrder::class, $order->id
                        );
                    } else {
                        $alerts->resolveByKey($order->organization_id, $overdueKey);
                    }

                    $inactiveKey = "purchase-order:{$order->id}:inactive";
                    if ($order->status === 'draft' && $order->updated_at->lte(now()->subDays(7))) {
                        $alerts->raise(
                            $order->organization_id, $inactiveKey, 'PurchaseOrderInactive',
                            'Draft purchase order had no activity for seven days.',
                            'medium', 'purchasing', 'Complete, approve or cancel the draft.',
                            $order->branch_id, PurchaseOrder::class, $order->id
                        );
                    } else {
                        $alerts->resolveByKey($order->organization_id, $inactiveKey);
                    }
                });

            SupplierProduct::query()->where('active', true)->orderBy('id')
                ->each(function (SupplierProduct $catalog) use ($alerts): void {
                    $key = "supplier-product:{$catalog->id}:without-price";
                    $hasPrice = DB::table('supplier_product_prices')
                        ->where('supplier_product_id', $catalog->id)
                        ->whereDate('valid_from', '<=', today())
                        ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
                        ->exists();
                    if ($hasPrice) {
                        $alerts->resolveByKey($catalog->organization_id, $key);

                        return;
                    }
                    $alerts->raise(
                        $catalog->organization_id, $key, 'SupplierProductWithoutValidPrice',
                        'Active supplier product has no price valid today.',
                        'warning', 'purchasing', 'Register a valid price before creating the purchase order.',
                        null, SupplierProduct::class, $catalog->id
                    );
                });
        });
    }
}

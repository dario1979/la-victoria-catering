<?php

namespace App\Jobs;

use App\Domain\Alerts\AlertManager;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class DetectOrderDelays implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function handle(AlertManager $alerts): void
    {
        Cache::lock('jobs:detect-order-delays', 300)->get(function () use ($alerts): void {
            Order::query()->whereIn('status', ['confirmed', 'in_production', 'ready'])
                ->where('updated_at', '<=', now()->subHours(2))->orderBy('id')
                ->each(function (Order $order) use ($alerts): void {
                    $event = $order->status === 'ready' ? 'ReadyOrderNotDelivered' : 'OrderStalled';
                    $alerts->raise(
                        $order->organization_id, "order:{$order->id}:{$event}", $event,
                        'status unchanged for 2 hours', 'warning', 'operations', 'review order',
                        $order->branch_id, Order::class, $order->id
                    );
                });
        });
    }
}

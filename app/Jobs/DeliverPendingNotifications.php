<?php

namespace App\Jobs;

use App\Models\NotificationDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class DeliverPendingNotifications implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function handle(): void
    {
        NotificationDelivery::query()->where('status', 'pending')
            ->where('available_at', '<=', now())->with('user')->limit(100)
            ->get()->each(fn (NotificationDelivery $delivery) => $this->deliver($delivery));
    }

    private function deliver(NotificationDelivery $delivery): void
    {
        try {
            $delivery->increment('attempts');
            if ($delivery->channel === 'internal') {
                $delivery->update(['status' => 'delivered', 'sent_at' => now(), 'delivered_at' => now()]);

                return;
            }
            if ($delivery->channel === 'email') {
                Mail::raw(
                    "{$delivery->message}\n\nAcción esperada: {$delivery->action}",
                    fn ($mail) => $mail->to($delivery->user->email)->subject($delivery->subject)
                );
                $delivery->update(['status' => 'sent', 'sent_at' => now()]);

                return;
            }
            throw new RuntimeException('PWA push transport is not configured.');
        } catch (Throwable $exception) {
            $delivery->update([
                'status' => $delivery->attempts >= $this->tries ? 'failed' : 'pending',
                'failed_at' => $delivery->attempts >= $this->tries ? now() : null,
                'available_at' => now()->addMinutes(5 * max(1, $delivery->attempts)),
                'last_error' => $exception->getMessage(),
            ]);
        }
    }
}

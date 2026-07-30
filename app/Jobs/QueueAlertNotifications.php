<?php

namespace App\Jobs;

use App\Models\NotificationDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class QueueAlertNotifications implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        DB::table('alerts')->where('status', 'open')->orderBy('id')->each(function (object $alert): void {
            $roles = $this->roles((string) $alert->recipient);
            $users = DB::table('organization_user')->join('users', 'users.id', '=', 'organization_user.user_id')
                ->where('organization_user.organization_id', $alert->organization_id)
                ->whereIn('organization_user.role', $roles)
                ->when($alert->branch_id, fn ($query) => $query
                    ->join('branch_user', 'branch_user.user_id', '=', 'users.id')
                    ->where('branch_user.branch_id', $alert->branch_id))
                ->select('users.id', 'users.email')->distinct()->get();
            foreach ($users as $user) {
                foreach (['internal', 'email', 'pwa_push'] as $channel) {
                    $preference = DB::table('notification_preferences')->where([
                        'user_id' => $user->id, 'channel' => $channel,
                    ])->first();
                    if (($preference && ! $preference->enabled) || ($channel === 'pwa_push' && ! config('services.pwa_push.enabled'))) {
                        continue;
                    }
                    NotificationDelivery::query()->firstOrCreate(
                        [
                            'user_id' => $user->id, 'channel' => $channel,
                            'deduplication_key' => "alert:{$alert->id}:occurrence:{$alert->occurrences}",
                        ],
                        [
                            'organization_id' => $alert->organization_id, 'branch_id' => $alert->branch_id,
                            'alert_id' => $alert->id, 'status' => 'pending',
                            'subject' => $alert->event, 'message' => $alert->condition,
                            'action' => $alert->action, 'available_at' => $this->availableAt($preference),
                        ],
                    );
                }
            }
        });
    }

    private function roles(string $recipient): array
    {
        return match ($recipient) {
            'purchasing' => ['owner', 'admin', 'purchasing'],
            'finance', 'finance-manager' => ['owner', 'admin', 'finance'],
            'inventory', 'inventory-manager' => ['owner', 'admin', 'inventory'],
            'production' => ['owner', 'admin', 'production'],
            default => ['owner', 'admin'],
        };
    }

    private function availableAt(?object $preference): CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC');
        if (! $preference?->quiet_hours_start || ! $preference?->quiet_hours_end) {
            return $now;
        }

        $localNow = $now->setTimezone((string) $preference->timezone);
        $start = $localNow->setTimeFromTimeString((string) $preference->quiet_hours_start);
        $end = $localNow->setTimeFromTimeString((string) $preference->quiet_hours_end);

        if ($start->equalTo($end)) {
            return $now;
        }
        if ($start->lessThan($end) && $localNow->betweenIncluded($start, $end)) {
            return $end->utc();
        }
        if ($start->greaterThan($end)) {
            if ($localNow->greaterThanOrEqualTo($start)) {
                return $end->addDay()->utc();
            }
            if ($localNow->lessThan($end)) {
                return $end->utc();
            }
        }

        return $now;
    }
}

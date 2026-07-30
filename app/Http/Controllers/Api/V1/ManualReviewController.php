<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverPendingNotifications;
use App\Models\ExternalWebhook;
use App\Models\NotificationDelivery;
use App\Models\OperationalFailure;
use App\Models\User;
use App\Support\IdempotentAction;
use App\Support\ServerDataTable;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class ManualReviewController extends Controller
{
    public function failures(
        Request $request,
        TenantContext $tenant,
        ServerDataTable $table,
    ): Response {
        $this->authorize($tenant);

        return $table->respond(
            OperationalFailure::query()
                ->where('organization_id', $tenant->organization->id)
                ->where('branch_id', $tenant->branch->id)
                ->select([
                    'id', 'job_uuid', 'job_name', 'connection', 'queue', 'correlation_id',
                    'status', 'failed_at', 'resolved_at', 'resolution',
                ]),
            $request,
            ['job_uuid', 'job_name', 'correlation_id'],
            [
                'id' => 'id',
                'job_name' => 'job_name',
                'status' => 'status',
                'failed_at' => 'failed_at',
                'resolved_at' => 'resolved_at',
            ],
            ['status' => 'status', 'queue' => 'queue', 'job_name' => 'job_name'],
            [
                'ID' => 'id',
                'UUID' => 'job_uuid',
                'Job' => 'job_name',
                'Conexión' => 'connection',
                'Cola' => 'queue',
                'Correlación' => 'correlation_id',
                'Estado' => 'status',
                'Falló' => 'failed_at',
                'Resuelto' => 'resolved_at',
                'Resolución' => 'resolution',
            ],
            'fallos-operativos',
            'failed_at',
        );
    }

    public function notifications(
        Request $request,
        TenantContext $tenant,
        ServerDataTable $table,
    ): Response {
        $this->authorize($tenant);

        return $table->respond(
            NotificationDelivery::query()
                ->where('organization_id', $tenant->organization->id)
                ->where(fn ($query) => $query
                    ->whereNull('branch_id')
                    ->orWhere('branch_id', $tenant->branch->id))
                ->select([
                    'id', 'branch_id', 'user_id', 'alert_id', 'channel', 'status',
                    'deduplication_key', 'subject', 'attempts', 'available_at',
                    'sent_at', 'delivered_at', 'failed_at', 'created_at',
                ]),
            $request,
            ['deduplication_key', 'subject'],
            [
                'id' => 'id',
                'status' => 'status',
                'channel' => 'channel',
                'attempts' => 'attempts',
                'available_at' => 'available_at',
                'failed_at' => 'failed_at',
                'created_at' => 'created_at',
            ],
            ['status' => 'status', 'channel' => 'channel', 'user_id' => 'user_id'],
            [
                'ID' => 'id',
                'Sucursal' => 'branch_id',
                'Usuario' => 'user_id',
                'Canal' => 'channel',
                'Estado' => 'status',
                'Clave' => 'deduplication_key',
                'Asunto' => 'subject',
                'Intentos' => 'attempts',
                'Disponible' => 'available_at',
                'Enviado' => 'sent_at',
                'Entregado' => 'delivered_at',
                'Falló' => 'failed_at',
            ],
            'entregas-notificaciones',
            'created_at',
        );
    }

    public function resolveFailure(
        OperationalFailure $operationalFailure,
        Request $request,
        TenantContext $tenant,
        IdempotentAction $idempotency,
    ): JsonResponse {
        $this->authorize($tenant);
        abort_unless(
            $operationalFailure->organization_id === $tenant->organization->id
                && $operationalFailure->branch_id === $tenant->branch->id,
            404,
        );
        $data = $request->validate([
            'resolution' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        [$body, $status, $replayed] = $idempotency->run(
            "organizations.{$tenant->organization->id}.operational-failures.{$operationalFailure->id}.resolve",
            $this->idempotencyKey($request),
            $data,
            function () use ($operationalFailure, $request, $tenant, $data): array {
                abort_unless($operationalFailure->status === 'failed', 409, 'Only an unresolved failure can be resolved.');
                $operationalFailure->update([
                    'status' => 'resolved',
                    'resolved_at' => now(),
                    'resolved_by' => $request->user()->id,
                    'resolution' => $data['resolution'],
                ]);
                $this->audit(
                    'operations.job_failure.resolved',
                    OperationalFailure::class,
                    $operationalFailure->id,
                    $request,
                    $tenant,
                );

                return [['data' => $operationalFailure->fresh()], 200];
            },
        );

        return response()->json($body, $status)
            ->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    public function retryNotification(
        NotificationDelivery $notificationDelivery,
        Request $request,
        TenantContext $tenant,
        IdempotentAction $idempotency,
    ): JsonResponse {
        $this->authorize($tenant);
        $this->assertNotificationTenant($notificationDelivery, $tenant);

        [$body, $status, $replayed] = $idempotency->run(
            "organizations.{$tenant->organization->id}.notification-deliveries.{$notificationDelivery->id}.retry",
            $this->idempotencyKey($request),
            [],
            function () use ($notificationDelivery, $request, $tenant): array {
                abort_unless($notificationDelivery->status === 'failed', 409, 'Only a failed delivery can be retried.');
                abort_unless($this->notificationStillValid($notificationDelivery, $tenant), 409, 'The delivery is no longer actionable.');
                abort_if(
                    $notificationDelivery->channel === 'pwa_push' && ! config('services.pwa_push.enabled'),
                    409,
                    'PWA push is disabled.',
                );
                $notificationDelivery->update([
                    'status' => 'pending',
                    'attempts' => 0,
                    'available_at' => now(),
                    'failed_at' => null,
                    'last_error' => null,
                ]);
                $this->audit(
                    'operations.notification.retry_requested',
                    NotificationDelivery::class,
                    $notificationDelivery->id,
                    $request,
                    $tenant,
                );
                DeliverPendingNotifications::dispatch();

                return [['data' => $notificationDelivery->fresh()], 200];
            },
        );

        return response()->json($body, $status)
            ->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    public function webhooks(
        Request $request,
        TenantContext $tenant,
        ServerDataTable $table,
    ): Response {
        $this->authorize($tenant);

        return $table->respond(
            ExternalWebhook::query()
                ->where('organization_id', $tenant->organization->id)
                ->where('branch_id', $tenant->branch->id)
                ->select([
                    'id', 'provider', 'external_id', 'signature_valid', 'payload_hash',
                    'status', 'processed_at', 'reviewed_at', 'resolution', 'created_at',
                ]),
            $request,
            ['provider', 'external_id', 'payload_hash'],
            [
                'id' => 'id',
                'provider' => 'provider',
                'status' => 'status',
                'signature_valid' => 'signature_valid',
                'created_at' => 'created_at',
                'reviewed_at' => 'reviewed_at',
            ],
            [
                'provider' => 'provider',
                'status' => 'status',
                'signature_valid' => 'signature_valid',
            ],
            [
                'ID' => 'id',
                'Proveedor' => 'provider',
                'Referencia externa' => 'external_id',
                'Firma válida' => 'signature_valid',
                'Hash payload' => 'payload_hash',
                'Estado' => 'status',
                'Procesado' => 'processed_at',
                'Revisado' => 'reviewed_at',
                'Resolución' => 'resolution',
                'Recibido' => 'created_at',
            ],
            'webhooks-operativos',
            'created_at',
        );
    }

    public function resolveWebhook(
        ExternalWebhook $externalWebhook,
        Request $request,
        TenantContext $tenant,
        IdempotentAction $idempotency,
    ): JsonResponse {
        $this->authorize($tenant);
        abort_unless(
            $externalWebhook->organization_id === $tenant->organization->id
                && $externalWebhook->branch_id === $tenant->branch->id,
            404,
        );
        $data = $request->validate([
            'status' => ['required', Rule::in(['manual_review', 'resolved'])],
            'resolution' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        [$body, $status, $replayed] = $idempotency->run(
            "organizations.{$tenant->organization->id}.external-webhooks.{$externalWebhook->id}.resolve",
            $this->idempotencyKey($request),
            $data,
            function () use ($externalWebhook, $request, $tenant, $data): array {
                abort_unless(
                    in_array($externalWebhook->status, ['rejected', 'failed', 'manual_review'], true),
                    409,
                    'Only a rejected or manually reviewed webhook can be resolved.',
                );
                $externalWebhook->update([
                    'status' => $data['status'],
                    'reviewed_at' => now(),
                    'reviewed_by' => $request->user()->id,
                    'resolution' => $data['resolution'],
                ]);
                $this->audit(
                    'operations.webhook.reviewed',
                    ExternalWebhook::class,
                    $externalWebhook->id,
                    $request,
                    $tenant,
                    ['status' => $data['status']],
                );

                return [['data' => $externalWebhook->fresh()], 200];
            },
        );

        return response()->json($body, $status)
            ->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    private function authorize(TenantContext $tenant): void
    {
        abort_unless($tenant->can('owner', 'admin'), 403);
    }

    private function assertNotificationTenant(
        NotificationDelivery $delivery,
        TenantContext $tenant,
    ): void {
        abort_unless(
            $delivery->organization_id === $tenant->organization->id
                && ($delivery->branch_id === null || $delivery->branch_id === $tenant->branch->id),
            404,
        );
    }

    private function notificationStillValid(
        NotificationDelivery $delivery,
        TenantContext $tenant,
    ): bool {
        $membership = DB::table('organization_user')->where([
            'organization_id' => $tenant->organization->id,
            'user_id' => $delivery->user_id,
        ])->exists();
        if (! $membership) {
            return false;
        }
        if ($delivery->branch_id !== null) {
            $branchAccess = DB::table('branch_user')
                ->join('branches', 'branches.id', '=', 'branch_user.branch_id')
                ->where('branch_user.user_id', $delivery->user_id)
                ->where('branches.id', $tenant->branch->id)
                ->where('branches.organization_id', $tenant->organization->id)
                ->where('branches.active', true)
                ->exists();
            if (! $branchAccess) {
                return false;
            }
        }

        return $delivery->alert_id === null
            || DB::table('alerts')
                ->where('id', $delivery->alert_id)
                ->where('organization_id', $tenant->organization->id)
                ->where('status', 'open')
                ->exists();
    }

    private function idempotencyKey(Request $request): string
    {
        abort_unless($request->hasHeader('Idempotency-Key'), 400, 'Idempotency-Key header is required.');

        return (string) $request->header('Idempotency-Key');
    }

    private function audit(
        string $event,
        string $subjectType,
        int $subjectId,
        Request $request,
        TenantContext $tenant,
        array $extra = [],
    ): void {
        DB::table('audit_logs')->insert([
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'actor_type' => User::class,
            'actor_id' => $request->user()->id,
            'context' => json_encode([
                'organization_id' => $tenant->organization->id,
                'branch_id' => $tenant->branch->id,
                'request_id' => $request->attributes->get('request_id'),
                ...$extra,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

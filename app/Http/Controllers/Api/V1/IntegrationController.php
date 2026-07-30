<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Finance\FinanceAccess;
use App\Domain\Integrations\Contracts\PaymentGateway;
use App\Domain\Integrations\FiscalDocumentManager;
use App\Domain\Integrations\PaymentIntegrationManager;
use App\Http\Controllers\Controller;
use App\Models\FiscalDocument;
use App\Models\PaymentGatewayTransaction;
use App\Models\User;
use App\Support\IdempotentAction;
use App\Support\ServerDataTable;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class IntegrationController extends Controller
{
    public function payments(Request $request, TenantContext $tenant, FinanceAccess $access, ServerDataTable $table): Response
    {
        $access->authorize($tenant, 'view');

        return $table->respond(
            PaymentGatewayTransaction::query()->where('organization_id', $tenant->organization->id)
                ->where('branch_id', $tenant->branch->id),
            $request, ['external_id', 'last_error'],
            ['id' => 'id', 'internal_status' => 'internal_status', 'amount_cents' => 'amount_cents', 'created_at' => 'created_at'],
            ['internal_status' => 'internal_status', 'provider' => 'provider'],
            ['ID' => 'id', 'Pago' => 'payment_id', 'Proveedor' => 'provider', 'Estado interno' => 'internal_status',
                'Estado externo' => 'external_status', 'Referencia' => 'external_id', 'Importe' => 'amount_cents'],
            'transacciones-pago',
        );
    }

    public function syncPayment(
        PaymentGatewayTransaction $paymentGatewayTransaction,
        TenantContext $tenant,
        FinanceAccess $access,
        PaymentIntegrationManager $manager,
        Request $request,
        IdempotentAction $idempotency,
    ): JsonResponse {
        $access->authorize($tenant, 'reconcile');
        abort_unless(
            $paymentGatewayTransaction->organization_id === $tenant->organization->id
            && $paymentGatewayTransaction->branch_id === $tenant->branch->id,
            404
        );
        [$body, $status, $replayed] = $idempotency->run(
            "organizations.{$tenant->organization->id}.gateway-transactions.{$paymentGatewayTransaction->id}.sync",
            $this->key($request), [],
            fn () => [['data' => $manager->synchronize($paymentGatewayTransaction)], 200],
        );
        if (! $replayed) {
            DB::table('audit_logs')->insert([
                'event' => 'integration.mercadopago.manual_sync',
                'subject_type' => PaymentGatewayTransaction::class,
                'subject_id' => $paymentGatewayTransaction->id,
                'actor_type' => User::class,
                'actor_id' => $request->user()->id,
                'context' => json_encode([
                    'organization_id' => $tenant->organization->id,
                    'branch_id' => $tenant->branch->id,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    public function fiscalDocuments(Request $request, TenantContext $tenant, FinanceAccess $access, ServerDataTable $table): Response
    {
        $access->authorize($tenant, 'view');

        return $table->respond(
            FiscalDocument::query()->where('organization_id', $tenant->organization->id)
                ->where('branch_id', $tenant->branch->id),
            $request, ['external_id', 'cae'],
            ['id' => 'id', 'internal_status' => 'internal_status', 'total_cents' => 'total_cents', 'created_at' => 'created_at'],
            ['internal_status' => 'internal_status', 'document_type' => 'document_type'],
            ['ID' => 'id', 'Pedido' => 'order_id', 'Tipo' => 'document_type', 'Punto de venta' => 'point_of_sale',
                'Total' => 'total_cents', 'Estado' => 'internal_status', 'CAE' => 'cae'],
            'documentos-fiscales',
        );
    }

    public function issueFiscal(
        Request $request,
        TenantContext $tenant,
        FinanceAccess $access,
        FiscalDocumentManager $manager,
        IdempotentAction $idempotency,
    ): JsonResponse {
        $access->authorize($tenant, 'manage-ledger');
        abort_unless(config('services.arca.enabled'), 409, 'ARCA integration is disabled.');
        $data = $request->validate([
            'order_id' => ['nullable', Rule::exists('orders', 'id')->where(
                fn ($query) => $query->where('organization_id', $tenant->organization->id)
                    ->where('branch_id', $tenant->branch->id)
            )],
            'document_type' => ['required', 'string', 'max:16'],
            'point_of_sale' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'customer_tax_id' => ['required', 'string', 'max:32'],
            'customer_tax_condition' => ['required', 'string', 'max:64'],
            'net_amount' => ['required', 'decimal:0,2', 'gte:0'],
            'tax_amount' => ['required', 'decimal:0,2', 'gte:0'],
            'total_amount' => ['required', 'decimal:0,2', 'gt:0'],
        ]);
        $key = $this->key($request);
        [$body, $status, $replayed] = $idempotency->run(
            "organizations.{$tenant->organization->id}.fiscal-documents.issue",
            $key, $data,
            fn () => [['data' => $manager->issue(
                $data, $tenant->organization->id, $tenant->branch->id, $key
            )], 201],
        );

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    public function reprocessFiscal(
        FiscalDocument $fiscalDocument,
        Request $request,
        TenantContext $tenant,
        FinanceAccess $access,
        FiscalDocumentManager $manager,
        IdempotentAction $idempotency,
    ): JsonResponse {
        $access->authorize($tenant, 'manage-ledger');
        abort_unless(config('services.arca.enabled'), 409, 'ARCA integration is disabled.');
        abort_unless(
            $fiscalDocument->organization_id === $tenant->organization->id
            && $fiscalDocument->branch_id === $tenant->branch->id,
            404,
        );
        abort_unless(
            in_array($fiscalDocument->internal_status, ['pending', 'retrying', 'rejected', 'manual_review'], true),
            409,
            'Only a non-authorized fiscal document can be reprocessed.',
        );

        [$body, $status, $replayed] = $idempotency->run(
            "organizations.{$tenant->organization->id}.fiscal-documents.{$fiscalDocument->id}.reprocess",
            $this->key($request),
            [],
            fn () => [[
                'data' => $manager->issue(
                    $fiscalDocument->safe_request,
                    $fiscalDocument->organization_id,
                    $fiscalDocument->branch_id,
                    $fiscalDocument->idempotency_key,
                ),
            ], 200],
        );
        if (! $replayed) {
            DB::table('audit_logs')->insert([
                'event' => 'integration.arca.manual_reprocess',
                'subject_type' => FiscalDocument::class,
                'subject_id' => $fiscalDocument->id,
                'actor_type' => User::class,
                'actor_id' => $request->user()->id,
                'context' => json_encode([
                    'organization_id' => $tenant->organization->id,
                    'branch_id' => $tenant->branch->id,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json($body, $status)->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    public function mercadoPagoWebhook(Request $request, PaymentGateway $gateway): JsonResponse
    {
        abort_unless(config('services.mercadopago.webhooks_enabled'), 404);
        $result = $gateway->parseWebhook($request->all(), [
            'x-signature' => (string) $request->header('x-signature'),
            'x-request-id' => (string) $request->header('x-request-id'),
        ]);

        return response()->json(['data' => $result], $result['duplicate'] ? 200 : 202);
    }

    private function key(Request $request): string
    {
        abort_unless($request->hasHeader('Idempotency-Key'), 400, 'Idempotency-Key header is required.');

        return (string) $request->header('Idempotency-Key');
    }
}

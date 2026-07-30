<?php

namespace App\Domain\Integrations;

use App\Domain\Integrations\Contracts\FiscalIssuer;
use App\Models\FiscalDocument;
use App\Support\Decimal;
use Illuminate\Validation\ValidationException;
use Throwable;

final class FiscalDocumentManager
{
    public function __construct(private readonly FiscalIssuer $issuer) {}

    public function issue(array $data, int $organizationId, int $branchId, string $idempotencyKey): FiscalDocument
    {
        $net = Decimal::toScaledInt($data['net_amount'], 2);
        $tax = Decimal::toScaledInt($data['tax_amount'], 2);
        $total = Decimal::toScaledInt($data['total_amount'], 2);
        if ($net + $tax !== $total) {
            throw ValidationException::withMessages(['total_amount' => ['Net plus tax must equal total.']]);
        }
        $safe = [
            'order_id' => $data['order_id'] ?? null, 'document_type' => $data['document_type'],
            'point_of_sale' => $data['point_of_sale'], 'currency' => $data['currency'],
            'customer_tax_id' => $data['customer_tax_id'], 'customer_tax_condition' => $data['customer_tax_condition'],
            'net_amount' => $data['net_amount'], 'tax_amount' => $data['tax_amount'],
            'total_amount' => $data['total_amount'],
        ];
        $document = FiscalDocument::query()->firstOrCreate(
            ['organization_id' => $organizationId, 'idempotency_key' => $idempotencyKey],
            [
                'branch_id' => $branchId, 'order_id' => $data['order_id'] ?? null,
                'internal_status' => 'processing', 'document_type' => $data['document_type'],
                'point_of_sale' => $data['point_of_sale'], 'currency' => $data['currency'],
                'net_cents' => $net, 'tax_cents' => $tax, 'total_cents' => $total,
                'safe_request' => $safe,
            ],
        );
        if ($document->cae) {
            return $document;
        }
        try {
            $result = $this->issuer->issue($safe, $idempotencyKey);
        } catch (Throwable $exception) {
            $document->update([
                'internal_status' => 'retrying',
                'last_error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
        $document->update([
            'internal_status' => $result->successful ? 'authorized' : 'manual_review',
            'external_id' => $result->externalReference, 'cae' => $result->payload['cae'] ?? null,
            'safe_response' => $result->payload, 'last_error' => null,
        ]);

        return $document->fresh();
    }
}

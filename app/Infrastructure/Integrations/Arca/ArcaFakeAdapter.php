<?php

namespace App\Infrastructure\Integrations\Arca;

use App\Domain\Integrations\Contracts\FiscalIssuer;
use App\Domain\Integrations\Data\DeliveryResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ArcaFakeAdapter implements FiscalIssuer
{
    public function issue(array $invoice, string $idempotencyKey): DeliveryResult
    {
        return Cache::lock('arca-fake:'.hash('sha256', $idempotencyKey), 30)
            ->block(10, fn () => DB::transaction(function () use ($invoice, $idempotencyKey): DeliveryResult {
                $delivery = DB::table('integration_deliveries')->where([
                    'integration' => 'arca_fake', 'operation' => 'issue',
                    'idempotency_key' => $idempotencyKey,
                ])->lockForUpdate()->first();
                if ($delivery) {
                    if (! hash_equals(
                        hash('sha256', $delivery->request_payload),
                        hash('sha256', json_encode($invoice, JSON_THROW_ON_ERROR))
                    )) {
                        throw ValidationException::withMessages(['Idempotency-Key' => ['Key was reused with another invoice.']]);
                    }
                    $payload = json_decode($delivery->response_payload ?? '{}', true);

                    return new DeliveryResult($delivery->status === 'authorized', $payload['cae'] ?? null, $payload);
                }
                $response = ['cae' => 'FAKE-'.strtoupper(substr(hash('sha256', $idempotencyKey), 0, 12))];
                DB::table('integration_deliveries')->insert([
                    'integration' => 'arca_fake', 'operation' => 'issue',
                    'idempotency_key' => $idempotencyKey, 'status' => 'authorized', 'attempts' => 1,
                    'request_payload' => json_encode($invoice, JSON_THROW_ON_ERROR),
                    'response_payload' => json_encode($response, JSON_THROW_ON_ERROR),
                    'processed_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return new DeliveryResult(true, $response['cae'], $response);
            }));
    }

    public function status(string $externalId): array
    {
        return ['external_id' => $externalId, 'status' => 'authorized'];
    }
}

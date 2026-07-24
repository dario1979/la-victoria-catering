<?php

namespace Tests\Feature;

use App\Infrastructure\Integrations\Arca\ArcaFakeAdapter;
use App\Infrastructure\Integrations\MercadoPago\MercadoPagoFakeAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FakeIntegrationAdaptersTest extends TestCase
{
    use RefreshDatabase;

    public function test_arca_fake_is_idempotent(): void
    {
        $adapter = new ArcaFakeAdapter;
        $first = $adapter->issue(['invoice' => 1], 'invoice-1');
        $second = $adapter->issue(['invoice' => 1], 'invoice-1');

        $this->assertTrue($first->successful);
        $this->assertSame($first->externalReference, $second->externalReference);
        $this->assertDatabaseCount('integration_deliveries', 1);
    }

    public function test_mercadopago_fake_rejects_key_reuse_with_different_payload(): void
    {
        $adapter = new MercadoPagoFakeAdapter;
        $adapter->createPayment(['amount' => '100.00'], 'payment-1');

        $this->expectException(ValidationException::class);
        $adapter->createPayment(['amount' => '200.00'], 'payment-1');
    }

    public function test_mercadopago_webhook_is_idempotent(): void
    {
        $adapter = new MercadoPagoFakeAdapter;
        $first = $adapter->parseWebhook(['id' => 'event-1'], ['x-signature' => 'fake']);
        $second = $adapter->parseWebhook(['id' => 'event-1'], ['x-signature' => 'fake']);

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertDatabaseCount('external_webhooks', 1);
    }
}

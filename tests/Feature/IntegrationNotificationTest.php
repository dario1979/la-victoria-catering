<?php

namespace Tests\Feature;

use App\Jobs\DeliverPendingNotifications;
use App\Jobs\QueueAlertNotifications;
use App\Models\FiscalDocument;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Tests\TestCase;

class IntegrationNotificationTest extends TestCase
{
    use RefreshDatabase;

    private int $organization;

    private int $branch;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'services.mercadopago.enabled' => false,
            'services.mercadopago.webhooks_enabled' => false,
            'services.mercadopago.driver' => 'fake',
            'services.arca.enabled' => false,
            'services.arca.driver' => 'fake',
            'services.pwa_push.enabled' => false,
        ]);
        $this->organization = DB::table('organizations')->insertGetId([
            'name' => 'Integrations Org', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branch = DB::table('branches')->insertGetId([
            'organization_id' => $this->organization,
            'name' => 'Main',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->user = User::factory()->create();
        DB::table('organization_user')->insert([
            'organization_id' => $this->organization,
            'user_id' => $this->user->id,
            'role' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('branch_user')->insert([
            'branch_id' => $this->branch,
            'user_id' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->user)->withHeaders([
            'X-Organization-ID' => (string) $this->organization,
            'X-Branch-ID' => (string) $this->branch,
        ]);
    }

    public function test_external_integrations_are_disabled_by_default(): void
    {
        $order = $this->order('100.00');

        $this->withHeader('Idempotency-Key', 'mp-disabled')
            ->postJson('/api/v1/payments', [
                'order_id' => $order,
                'amount' => '10.00',
                'method' => 'mercadopago',
            ])->assertStatus(409);
        $this->withHeader('Idempotency-Key', 'arca-disabled')
            ->postJson('/api/v1/fiscal-documents', [])
            ->assertStatus(409);
        $this->postJson('/api/v1/webhooks/mercadopago', ['id' => 'disabled'])
            ->assertNotFound();

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('fiscal_documents', 0);
    }

    public function test_fake_mercado_pago_persists_separate_states_and_safe_idempotency(): void
    {
        config()->set('services.mercadopago.enabled', true);
        $order = $this->order('100.00');
        $payload = ['order_id' => $order, 'amount' => '25.50', 'method' => 'mercadopago'];

        $this->withHeader('Idempotency-Key', 'mp-payment-1')
            ->postJson('/api/v1/payments', $payload)
            ->assertCreated();
        $this->withHeader('Idempotency-Key', 'mp-payment-1')
            ->postJson('/api/v1/payments', $payload)
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payment_gateway_transactions', [
            'organization_id' => $this->organization,
            'branch_id' => $this->branch,
            'provider' => 'mercadopago',
            'internal_status' => 'pending',
            'external_status' => 'pending',
            'amount_cents' => 2550,
        ]);
        $this->assertDatabaseCount('integration_deliveries', 1);
    }

    public function test_fake_webhook_verifies_signature_deduplicates_and_redacts_payload(): void
    {
        config()->set('services.mercadopago.webhooks_enabled', true);
        $payload = [
            'id' => 'event-100',
            'type' => 'payment',
            'data' => ['id' => 'MP-100', 'card_number' => '5031755734530604'],
            'card_number' => '4509953566233704',
            'access_token' => 'secret',
        ];

        $this->withHeaders(['x-signature' => 'fake', 'x-request-id' => 'req-1'])
            ->postJson('/api/v1/webhooks/mercadopago', $payload)
            ->assertStatus(202)
            ->assertJsonPath('data.duplicate', false);
        $this->withHeaders(['x-signature' => 'fake', 'x-request-id' => 'req-1'])
            ->postJson('/api/v1/webhooks/mercadopago', $payload)
            ->assertOk()
            ->assertJsonPath('data.duplicate', true);
        $this->withHeader('x-signature', 'invalid')
            ->postJson('/api/v1/webhooks/mercadopago', ['id' => 'event-101'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('signature');

        $stored = (string) DB::table('external_webhooks')->value('payload');
        self::assertStringNotContainsString('4509953566233704', $stored);
        self::assertStringNotContainsString('5031755734530604', $stored);
        self::assertStringNotContainsString('secret', $stored);
        $this->assertDatabaseCount('external_webhooks', 1);
    }

    public function test_fake_fiscal_issuance_requires_explicit_data_and_is_immutable(): void
    {
        config()->set('services.arca.enabled', true);
        $payload = [
            'document_type' => 'explicit-test',
            'point_of_sale' => 1,
            'currency' => 'ARS',
            'customer_tax_id' => 'explicit-tax-id',
            'customer_tax_condition' => 'explicit-condition',
            'net_amount' => '100.00',
            'tax_amount' => '21.00',
            'total_amount' => '121.00',
        ];

        $document = $this->withHeader('Idempotency-Key', 'fiscal-1')
            ->postJson('/api/v1/fiscal-documents', $payload)
            ->assertCreated()
            ->assertJsonPath('data.internal_status', 'authorized')
            ->json('data');
        $this->withHeader('Idempotency-Key', 'fiscal-1')
            ->postJson('/api/v1/fiscal-documents', $payload)
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true');
        $this->withHeader('Idempotency-Key', 'fiscal-incomplete')
            ->postJson('/api/v1/fiscal-documents', ['total_amount' => '121.00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'document_type',
                'point_of_sale',
                'customer_tax_id',
                'customer_tax_condition',
                'net_amount',
                'tax_amount',
            ]);

        $this->expectException(LogicException::class);
        FiscalDocument::query()->findOrFail($document['id'])->delete();
    }

    public function test_notification_delivery_honors_roles_deduplication_and_quiet_hours(): void
    {
        Mail::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-30 23:00:00', 'UTC'));
        DB::table('notification_preferences')->insert([
            'user_id' => $this->user->id,
            'channel' => 'internal',
            'enabled' => true,
            'quiet_hours_start' => '19:00',
            'quiet_hours_end' => '07:00',
            'timezone' => 'America/Argentina/Buenos_Aires',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('alerts')->insert([
            'organization_id' => $this->organization,
            'branch_id' => $this->branch,
            'deduplication_key' => 'test-alert',
            'event' => 'Stock crítico',
            'condition' => 'Quedan menos de dos unidades.',
            'severity' => 'critical',
            'recipient' => 'inventory',
            'action' => 'Revisar reposición.',
            'status' => 'open',
            'occurrences' => 1,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new QueueAlertNotifications)->handle();
        (new QueueAlertNotifications)->handle();

        $this->assertDatabaseCount('notification_deliveries', 2);
        $internal = DB::table('notification_deliveries')->where('channel', 'internal')->first();
        self::assertNotNull($internal);
        self::assertSame(
            '2026-07-31 10:00:00',
            CarbonImmutable::parse($internal->available_at, 'UTC')->format('Y-m-d H:i:s'),
        );
        (new DeliverPendingNotifications)->handle();
        $this->assertDatabaseHas('notification_deliveries', ['channel' => 'email', 'status' => 'sent']);
        $this->assertDatabaseHas('notification_deliveries', ['channel' => 'internal', 'status' => 'pending']);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_branch_alerts_and_manual_reprocessing_cannot_cross_tenant_boundaries(): void
    {
        config()->set('services.arca.enabled', true);
        $otherOrganization = DB::table('organizations')->insertGetId([
            'name' => 'Other Org', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherBranch = DB::table('branches')->insertGetId([
            'organization_id' => $otherOrganization,
            'name' => 'Other Branch',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherDocument = FiscalDocument::query()->create([
            'organization_id' => $otherOrganization,
            'branch_id' => $otherBranch,
            'internal_status' => 'manual_review',
            'document_type' => 'explicit-test',
            'point_of_sale' => 1,
            'currency' => 'ARS',
            'net_cents' => 10000,
            'tax_cents' => 2100,
            'total_cents' => 12100,
            'safe_request' => [
                'document_type' => 'explicit-test',
                'point_of_sale' => 1,
                'currency' => 'ARS',
                'customer_tax_id' => 'explicit-tax-id',
                'customer_tax_condition' => 'explicit-condition',
                'net_amount' => '100.00',
                'tax_amount' => '21.00',
                'total_amount' => '121.00',
            ],
            'idempotency_key' => 'other-fiscal',
        ]);

        $this->withHeader('Idempotency-Key', 'cross-tenant-reprocess')
            ->postJson("/api/v1/fiscal-documents/{$otherDocument->id}/reprocess")
            ->assertNotFound();

        $otherUser = User::factory()->create();
        DB::table('organization_user')->insert([
            'organization_id' => $this->organization,
            'user_id' => $otherUser->id,
            'role' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('alerts')->insert([
            'organization_id' => $this->organization,
            'branch_id' => $this->branch,
            'deduplication_key' => 'branch-only',
            'event' => 'Alerta de sucursal',
            'condition' => 'Sólo usuarios asignados.',
            'severity' => 'high',
            'recipient' => 'admin',
            'action' => 'Revisar.',
            'status' => 'open',
            'occurrences' => 1,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new QueueAlertNotifications)->handle();

        $this->assertDatabaseMissing('notification_deliveries', ['user_id' => $otherUser->id]);
        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $this->user->id,
            'branch_id' => $this->branch,
        ]);
    }

    private function order(string $total): int
    {
        return DB::table('orders')->insertGetId([
            'organization_id' => $this->organization,
            'branch_id' => $this->branch,
            'customer_name' => 'Cliente integración',
            'status' => 'confirmed',
            'total' => $total,
            'paid_total' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

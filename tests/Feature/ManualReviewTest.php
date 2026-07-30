<?php

namespace Tests\Feature;

use App\Jobs\DeliverPendingNotifications;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use App\Support\OperationalFailureRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class ManualReviewTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private User $admin;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::create(['name' => 'Manual Review A']);
        $this->branch = Branch::create([
            'organization_id' => $this->organization->id,
            'name' => 'Branch A',
            'active' => true,
        ]);
        $this->admin = User::factory()->create();
        $this->admin->organizations()->attach($this->organization, ['role' => 'admin']);
        $this->admin->branches()->attach($this->branch);
        $this->headers = [
            'X-Organization-ID' => (string) $this->organization->id,
            'X-Branch-ID' => (string) $this->branch->id,
        ];
        $this->actingAs($this->admin)->withHeaders($this->headers);
    }

    public function test_operational_lists_are_paginated_filterable_searchable_and_tenant_scoped(): void
    {
        $ownFailure = $this->failure($this->organization->id, $this->branch->id, 'ProcessInvoice');
        [$otherOrganization, $otherBranch, $otherUser] = $this->tenant('B');
        $this->failure($otherOrganization->id, $otherBranch->id, 'ForeignSecretJob');
        $this->notification($this->organization->id, $this->branch->id, $this->admin->id, 'own-delivery');
        $this->notification($otherOrganization->id, $otherBranch->id, $otherUser->id, 'foreign-delivery');
        $this->webhook($this->organization->id, $this->branch->id, 'own-webhook', 'own-payload-secret');
        $this->webhook($otherOrganization->id, $otherBranch->id, 'foreign-webhook', 'foreign-payload-secret');

        $this->getJson('/api/v1/operations/failures?search=ProcessInvoice&filters[status]=failed&sort=failed_at&direction=asc')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $ownFailure)
            ->assertJsonMissing(['ForeignSecretJob']);
        $this->getJson('/api/v1/operations/notification-deliveries?filters[status]=failed')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.deduplication_key', 'own-delivery')
            ->assertJsonMissing(['foreign-delivery']);
        $this->getJson('/api/v1/operations/webhooks?search=own-webhook&filters[status]=rejected')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.external_id', 'own-webhook')
            ->assertJsonMissingPath('data.0.payload')
            ->assertJsonMissing(['own-payload-secret', 'foreign-payload-secret']);

        $export = $this->get('/api/v1/operations/webhooks?export=xlsx');
        $export->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $worksheet = $this->worksheet($export);
        $this->assertStringContainsString('own-webhook', $worksheet);
        $this->assertStringNotContainsString('own-payload-secret', $worksheet);
        $this->assertStringNotContainsString('foreign-webhook', $worksheet);
    }

    public function test_failed_notification_retry_is_validated_idempotent_and_audited(): void
    {
        Queue::fake();
        $delivery = $this->notification(
            $this->organization->id,
            $this->branch->id,
            $this->admin->id,
            'retry-delivery',
        );
        $headers = [...$this->headers, 'Idempotency-Key' => 'retry-delivery-once'];

        $this->withHeaders($headers)
            ->postJson("/api/v1/operations/notification-deliveries/{$delivery}/retry")
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'false')
            ->assertJsonPath('data.status', 'pending');
        $this->withHeaders($headers)
            ->postJson("/api/v1/operations/notification-deliveries/{$delivery}/retry")
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');

        Queue::assertPushed(DeliverPendingNotifications::class, 1);
        $this->assertDatabaseHas('notification_deliveries', [
            'id' => $delivery,
            'status' => 'pending',
            'attempts' => 0,
            'last_error' => null,
        ]);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_notification_is_not_retried_when_its_alert_is_no_longer_actionable(): void
    {
        $alert = DB::table('alerts')->insertGetId([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'deduplication_key' => 'resolved-alert',
            'event' => 'Resolved',
            'condition' => 'Already resolved',
            'severity' => 'warning',
            'recipient' => 'admin',
            'action' => 'None',
            'status' => 'resolved',
            'occurrences' => 1,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $delivery = $this->notification(
            $this->organization->id,
            $this->branch->id,
            $this->admin->id,
            'resolved-delivery',
            $alert,
        );

        $this->withHeader('Idempotency-Key', 'invalid-retry')
            ->postJson("/api/v1/operations/notification-deliveries/{$delivery}/retry")
            ->assertStatus(409);
        $this->assertDatabaseHas('notification_deliveries', ['id' => $delivery, 'status' => 'failed']);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_webhook_resolution_preserves_original_evidence_and_is_idempotent(): void
    {
        $webhook = $this->webhook(
            $this->organization->id,
            $this->branch->id,
            'review-webhook',
            'immutable-payload',
        );
        $headers = [...$this->headers, 'Idempotency-Key' => 'resolve-webhook-once'];
        $body = ['status' => 'resolved', 'resolution' => 'Firma inválida confirmada; no reprocesar.'];

        $this->withHeaders($headers)
            ->postJson("/api/v1/operations/webhooks/{$webhook}/resolution", $body)
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'false')
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonMissingPath('data.payload');
        $this->withHeaders($headers)
            ->postJson("/api/v1/operations/webhooks/{$webhook}/resolution", $body)
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');

        $stored = DB::table('external_webhooks')->where('id', $webhook)->first();
        $this->assertSame('{"source":"immutable-payload"}', $stored->payload);
        $this->assertSame('signature-original', $stored->signature);
        $this->assertSame('resolved', $stored->status);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_job_failure_resolution_and_cross_tenant_access_fail_closed(): void
    {
        $failure = $this->failure($this->organization->id, $this->branch->id, 'SafeJob');
        [$otherOrganization, $otherBranch] = $this->tenant('B');
        $foreignFailure = $this->failure($otherOrganization->id, $otherBranch->id, 'ForeignJob');

        $this->withHeader('Idempotency-Key', 'resolve-job-failure')
            ->postJson("/api/v1/operations/failures/{$failure}/resolution", [
                'resolution' => 'Datos verificados; no corresponde reintento genérico.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');
        $this->withHeader('Idempotency-Key', 'foreign-job-failure')
            ->postJson("/api/v1/operations/failures/{$foreignFailure}/resolution", [
                'resolution' => 'Must not be visible.',
            ])
            ->assertNotFound();
        $this->assertDatabaseHas('operational_failures', ['id' => $foreignFailure, 'status' => 'failed']);
    }

    public function test_failure_recorder_stores_only_scoped_metadata_without_exception_or_payload(): void
    {
        Context::add([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'request_id' => 'queue-correlation-17',
        ]);
        $job = Mockery::mock();
        $job->shouldReceive('payload')->andReturn([
            'uuid' => '7a1c2d35-76ec-4cff-9e7d-2df80681641b',
            'data' => ['command' => 'payload-secret-must-not-be-stored'],
        ]);
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\SafeOperationalJob');
        $job->shouldReceive('getQueue')->andReturn('default');

        app(OperationalFailureRecorder::class)->record(new JobFailed(
            'redis',
            $job,
            new RuntimeException('exception-secret-must-not-be-stored'),
        ));

        $record = (array) DB::table('operational_failures')->first();
        $encoded = json_encode($record, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('payload-secret-must-not-be-stored', $encoded);
        $this->assertStringNotContainsString('exception-secret-must-not-be-stored', $encoded);
        $this->assertSame($this->organization->id, $record['organization_id']);
        $this->assertSame($this->branch->id, $record['branch_id']);
        $this->assertSame('queue-correlation-17', $record['correlation_id']);
    }

    private function failure(int $organizationId, int $branchId, string $job): int
    {
        return DB::table('operational_failures')->insertGetId([
            'job_uuid' => fake()->uuid(),
            'organization_id' => $organizationId,
            'branch_id' => $branchId,
            'job_name' => $job,
            'connection' => 'redis',
            'queue' => 'default',
            'correlation_id' => fake()->uuid(),
            'status' => 'failed',
            'failed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function notification(
        int $organizationId,
        int $branchId,
        int $userId,
        string $key,
        ?int $alertId = null,
    ): int {
        return DB::table('notification_deliveries')->insertGetId([
            'organization_id' => $organizationId,
            'branch_id' => $branchId,
            'alert_id' => $alertId,
            'user_id' => $userId,
            'channel' => 'internal',
            'status' => 'failed',
            'deduplication_key' => $key,
            'subject' => 'Manual review',
            'message' => 'Operational message',
            'action' => 'Review',
            'attempts' => 3,
            'available_at' => now()->subHour(),
            'failed_at' => now(),
            'last_error' => 'transport failed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function webhook(
        int $organizationId,
        int $branchId,
        string $externalId,
        string $payloadMarker,
    ): int {
        return DB::table('external_webhooks')->insertGetId([
            'organization_id' => $organizationId,
            'branch_id' => $branchId,
            'provider' => 'mercadopago',
            'external_id' => $externalId,
            'signature' => 'signature-original',
            'signature_valid' => false,
            'headers' => '{}',
            'payload' => json_encode(['source' => $payloadMarker], JSON_THROW_ON_ERROR),
            'payload_hash' => hash('sha256', $payloadMarker),
            'status' => 'rejected',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{Organization, Branch, User} */
    private function tenant(string $suffix): array
    {
        $organization = Organization::create(['name' => "Manual Review {$suffix}"]);
        $branch = Branch::create([
            'organization_id' => $organization->id,
            'name' => "Branch {$suffix}",
            'active' => true,
        ]);
        $user = User::factory()->create();
        $user->organizations()->attach($organization, ['role' => 'admin']);
        $user->branches()->attach($branch);

        return [$organization, $branch, $user];
    }

    private function worksheet($response): string
    {
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $content = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertIsString($content);

        return $content;
    }
}

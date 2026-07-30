<?php

namespace Tests\Feature;

use App\Logging\RedactSensitiveData;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use App\Support\OperationalHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger as LaravelLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use RuntimeException;
use Tests\TestCase;

final class ObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_id_is_bounded_returned_and_present_on_error_responses(): void
    {
        $this->getJson('/health/live', ['X-Request-ID' => 'pilot-request_42'])
            ->assertOk()
            ->assertHeader('X-Request-ID', 'pilot-request_42')
            ->assertJsonPath('request_id', 'pilot-request_42');

        $generated = $this->getJson('/health/live', [
            'X-Request-ID' => str_repeat('untrusted', 20),
        ])->assertOk();
        $this->assertMatchesRegularExpression(
            '/\A[0-9a-f-]{36}\z/',
            (string) $generated->headers->get('X-Request-ID'),
        );

        $this->getJson('/api/v1/orders', ['X-Request-ID' => 'visible-error-7'])
            ->assertUnauthorized()
            ->assertHeader('X-Request-ID', 'visible-error-7');
    }

    public function test_readiness_requires_database_redis_storage_migrations_and_fresh_heartbeats(): void
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('ping')->twice()->andReturn(true);
        Redis::shouldReceive('connection')->twice()->andReturn($redis);

        $health = app(OperationalHealth::class);
        $health->recordWorkerHeartbeat();
        $health->recordSchedulerHeartbeat();

        $this->getJson('/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonMissingPath('checks');

        config()->set('operations.worker_heartbeat_max_age_seconds', 0);
        $this->travel(2)->seconds();

        $this->getJson('/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('status', 'unavailable')
            ->assertJsonMissingPath('checks');
    }

    public function test_operational_diagnostics_are_admin_only_and_tenant_scoped(): void
    {
        [$organizationA, $branchA] = $this->tenant('A');
        [$organizationB, $branchB] = $this->tenant('B');
        $admin = User::factory()->create();
        $admin->organizations()->attach($organizationA, ['role' => 'admin']);
        $admin->branches()->attach($branchA);
        $viewer = User::factory()->create();
        $viewer->organizations()->attach($organizationA, ['role' => 'viewer']);
        $viewer->branches()->attach($branchA);

        $this->pendingNotification($organizationA->id, $branchA->id, $admin->id, 'tenant-a');
        $foreignUser = User::factory()->create();
        $foreignUser->organizations()->attach($organizationB, ['role' => 'admin']);
        $foreignUser->branches()->attach($branchB);
        $this->pendingNotification($organizationB->id, $branchB->id, $foreignUser->id, 'tenant-b');

        $headersA = [
            'X-Organization-ID' => $organizationA->id,
            'X-Branch-ID' => $branchA->id,
        ];
        $this->actingAs($admin)->withHeaders($headersA)
            ->getJson('/api/v1/operations/status')
            ->assertOk()
            ->assertJsonPath('data.tenant_signals.scope.organization_id', $organizationA->id)
            ->assertJsonPath('data.tenant_signals.scope.branch_id', $branchA->id)
            ->assertJsonPath('data.tenant_signals.old_pending_notifications', 1)
            ->assertJsonMissing(['tenant-b']);

        $this->actingAs($viewer)->withHeaders($headersA)
            ->getJson('/api/v1/operations/status')
            ->assertForbidden();

        $this->actingAs($admin)->withHeaders([
            'X-Organization-ID' => $organizationB->id,
            'X-Branch-ID' => $branchB->id,
        ])->getJson('/api/v1/operations/status')->assertForbidden();
    }

    public function test_log_processor_redacts_credentials_cards_and_exception_messages(): void
    {
        $handler = new TestHandler;
        $monolog = new MonologLogger('redaction-test');
        $monolog->pushHandler($handler);
        $logger = new LaravelLogger($monolog);
        app(RedactSensitiveData::class)($logger);

        $logger->error(
            'Authorization: Bearer visible-bearer password=visible-password card 4111 1111 1111 1111',
            [
                'access_token' => 'visible-token',
                'cookies' => ['session' => 'visible-cookie'],
                'fiscal_payload' => ['customer_tax_id' => 'visible-tax-id'],
                'exception' => new RuntimeException('webhook_secret=visible-webhook-secret'),
                'safe' => 'operational-value',
            ],
        );

        $record = json_encode($handler->getRecords()[0]->toArray(), JSON_THROW_ON_ERROR);
        foreach ([
            'visible-bearer',
            'visible-password',
            '4111 1111 1111 1111',
            'visible-token',
            'visible-cookie',
            'visible-tax-id',
            'visible-webhook-secret',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $record);
        }
        $this->assertStringContainsString('[REDACTED]', $record);
        $this->assertStringContainsString('operational-value', $record);
    }

    /** @return array{Organization, Branch} */
    private function tenant(string $suffix): array
    {
        $organization = Organization::create(['name' => "Operations {$suffix}"]);
        $branch = Branch::create([
            'organization_id' => $organization->id,
            'name' => "Branch {$suffix}",
            'active' => true,
        ]);

        return [$organization, $branch];
    }

    private function pendingNotification(
        int $organizationId,
        int $branchId,
        int $userId,
        string $key,
    ): void {
        DB::table('notification_deliveries')->insert([
            'organization_id' => $organizationId,
            'branch_id' => $branchId,
            'user_id' => $userId,
            'channel' => 'internal',
            'status' => 'pending',
            'deduplication_key' => $key,
            'subject' => 'Operational test',
            'message' => 'No sensitive data',
            'action' => 'Review',
            'attempts' => 0,
            'available_at' => now()->subHour(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
    }
}

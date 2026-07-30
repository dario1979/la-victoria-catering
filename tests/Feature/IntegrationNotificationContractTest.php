<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Tests\TestCase;

class IntegrationNotificationContractTest extends TestCase
{
    public function test_integration_and_notification_routes_do_not_drift_from_openapi(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'))
            ->flatMap(fn (Route $route) => collect($route->methods())
                ->reject(fn (string $method) => $method === 'HEAD')
                ->map(fn (string $method) => "{$method} /".str($route->uri())->after('api/v1/')))
            ->all();
        $expected = [
            'integration/payment-transactions' => ['GET'],
            'integration/payment-transactions/{paymentGatewayTransaction}/sync' => ['POST'],
            'webhooks/mercadopago' => ['POST'],
            'fiscal-documents' => ['GET', 'POST'],
            'fiscal-documents/{fiscalDocument}/reprocess' => ['POST'],
            'notifications' => ['GET'],
            'notification-preferences' => ['GET', 'PUT'],
            'push-subscriptions' => ['POST'],
        ];
        $openapi = file_get_contents(base_path('docs/contracts/openapi.yaml'));
        self::assertIsString($openapi);

        foreach ($expected as $uri => $methods) {
            foreach ($methods as $method) {
                self::assertContains("{$method} /{$uri}", $routes, "Missing route {$method} /{$uri}");
            }
            self::assertStringContainsString("  /{$uri}:", $openapi);
        }
    }
}

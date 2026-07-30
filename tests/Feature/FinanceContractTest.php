<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Tests\TestCase;

class FinanceContractTest extends TestCase
{
    public function test_finance_routes_and_openapi_paths_do_not_drift(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'))
            ->flatMap(fn (Route $route) => collect($route->methods())
                ->reject(fn (string $method) => $method === 'HEAD')
                ->map(fn (string $method) => "{$method} /".str($route->uri())->after('api/v1/')))
            ->all();
        $expected = [
            'cash-registers' => ['GET', 'POST'],
            'cash-registers/{cashRegister}/open' => ['POST'],
            'cash-sessions' => ['GET'],
            'cash-sessions/{cashSession}' => ['GET'],
            'cash-sessions/{cashSession}/movements' => ['POST'],
            'cash-movements/{cashMovement}/reverse' => ['POST'],
            'cash-sessions/{cashSession}/close' => ['POST'],
            'cash-sessions/{cashSession}/approve-difference' => ['POST'],
            'customer-accounts' => ['GET'],
            'customer-accounts/{customer}' => ['GET'],
            'customer-accounts/{customer}/entries' => ['GET', 'POST'],
            'customer-account-entries/{customerAccountEntry}/reverse' => ['POST'],
            'accounts-payable' => ['GET'],
            'accounts-payable/{accountPayable}' => ['GET'],
            'accounts-payable/{accountPayable}/payments' => ['POST'],
            'accounts-payable-payments/{accountPayablePayment}/reverse' => ['POST'],
            'reconciliations' => ['GET', 'POST'],
            'reconciliations/{reconciliation}/status' => ['POST'],
            'payments/{payment}/reverse' => ['POST'],
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

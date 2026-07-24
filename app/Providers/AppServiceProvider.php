<?php

namespace App\Providers;

use App\Domain\Integrations\Contracts\FiscalIssuer;
use App\Domain\Integrations\Contracts\PaymentGateway;
use App\Infrastructure\Integrations\Arca\ArcaFakeAdapter;
use App\Infrastructure\Integrations\Arca\ArcaSandboxAdapter;
use App\Infrastructure\Integrations\MercadoPago\MercadoPagoFakeAdapter;
use App\Infrastructure\Integrations\MercadoPago\MercadoPagoSandboxAdapter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(FiscalIssuer::class, fn () => match (config('services.arca.driver', 'fake')) {
            'sandbox' => new ArcaSandboxAdapter,
            default => new ArcaFakeAdapter,
        });
        $this->app->bind(PaymentGateway::class, fn () => match (config('services.mercadopago.driver', 'fake')) {
            'sandbox' => new MercadoPagoSandboxAdapter,
            default => new MercadoPagoFakeAdapter,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

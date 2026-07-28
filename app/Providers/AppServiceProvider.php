<?php

namespace App\Providers;

use App\Domain\Integrations\Contracts\FiscalIssuer;
use App\Domain\Integrations\Contracts\PaymentGateway;
use App\Infrastructure\Integrations\Arca\ArcaFakeAdapter;
use App\Infrastructure\Integrations\Arca\ArcaSandboxAdapter;
use App\Infrastructure\Integrations\MercadoPago\MercadoPagoFakeAdapter;
use App\Infrastructure\Integrations\MercadoPago\MercadoPagoSandboxAdapter;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
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
        ResetPassword::toMailUsing(function (object $notifiable, string $token): MailMessage {
            $url = route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);

            return (new MailMessage)
                ->subject('Restablecé tu contraseña de La Victoria Bakery')
                ->greeting('Hola.')
                ->line('Recibimos una solicitud para restablecer la contraseña de tu cuenta.')
                ->action('Elegir una nueva contraseña', $url)
                ->line('Este enlace vence en 60 minutos y sólo puede usarse una vez.')
                ->line('Si no pediste este cambio, podés ignorar este correo.')
                ->salutation('La Victoria Bakery');
        });
    }
}

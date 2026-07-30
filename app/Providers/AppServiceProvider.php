<?php

namespace App\Providers;

use App\Domain\Integrations\Contracts\FiscalIssuer;
use App\Domain\Integrations\Contracts\PaymentGateway;
use App\Infrastructure\Integrations\Arca\ArcaFakeAdapter;
use App\Infrastructure\Integrations\Arca\ArcaSandboxAdapter;
use App\Infrastructure\Integrations\MercadoPago\MercadoPagoFakeAdapter;
use App\Infrastructure\Integrations\MercadoPago\MercadoPagoSandboxAdapter;
use App\Support\CorrelationId;
use App\Support\OperationalHealth;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
    public function boot(OperationalHealth $health): void
    {
        Queue::before(function () use ($health): void {
            if (Context::missing('request_id')) {
                Context::add('request_id', CorrelationId::resolve(null));
            }
            $health->recordWorkerHeartbeat();
        });
        Queue::after(fn (JobProcessed $event) => $health->recordJobSuccess($event->job->resolveName()));
        Queue::failing(fn (JobFailed $event) => $health->recordJobFailure($event->job->resolveName()));

        RateLimiter::for('auth.login', function (Request $request): Limit {
            $email = Str::lower(trim((string) $request->input('email')));

            return Limit::perMinute(5)->by("login|{$email}|{$request->ip()}");
        });
        RateLimiter::for(
            'auth.forgot-password',
            fn (Request $request): Limit => Limit::perMinute(5)->by("forgot-password|{$request->ip()}"),
        );
        RateLimiter::for(
            'auth.reset-password',
            fn (Request $request): Limit => Limit::perMinute(10)->by("reset-password|{$request->ip()}"),
        );
        RateLimiter::for(
            'integration.webhooks',
            fn (Request $request): Limit => Limit::perMinute(120)->by("integration-webhook|{$request->ip()}"),
        );

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

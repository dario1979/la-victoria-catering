<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_known_email_receives_a_reset_link_without_exposing_account_existence(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'persona@lavictoria.test']);

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'PERSONA@LAVICTORIA.TEST',
        ])->assertOk()->assertJsonPath(
            'data.message',
            'Si el correo está registrado, vas a recibir un enlace para restablecer la contraseña.',
        );

        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            fn (ResetPassword $notification): bool => str_contains(
                $notification->toMail($user)->actionUrl,
                '/reset-password/',
            ),
        );
    }

    public function test_unknown_email_receives_the_same_generic_response(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'desconocido@lavictoria.test',
        ])->assertOk()->assertJsonPath(
            'data.message',
            'Si el correo está registrado, vas a recibir un enlace para restablecer la contraseña.',
        );

        Notification::assertNothingSent();
    }

    public function test_password_can_be_reset_once_with_a_valid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'persona@lavictoria.test',
            'password' => 'old-password',
        ]);
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertOk()->assertJsonPath(
            'data.message',
            'La contraseña se actualizó. Ya podés ingresar.',
        );

        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'another-password',
            'password_confirmation' => 'another-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
    }

    public function test_reset_requires_a_confirmed_password_of_at_least_eight_characters(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => '123456',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_recovery_requests_do_not_consume_the_login_budget(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'email' => 'rate-limit@lavictoria.test',
            'password' => 'correct-password',
        ]);
        $client = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $client->postJson('/api/v1/auth/forgot-password', [
                'email' => $user->email,
            ])->assertOk();
        }

        $client->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_login_still_rejects_attempts_beyond_its_own_budget(): void
    {
        $user = User::factory()->create([
            'email' => 'throttled-login@lavictoria.test',
            'password' => 'correct-password',
        ]);
        $client = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $client->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $client->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertTooManyRequests();
    }
}

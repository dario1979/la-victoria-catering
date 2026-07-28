<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AuthController extends Controller
{
    private const RESET_LINK_MESSAGE = 'Si el correo está registrado, vas a recibir un enlace para restablecer la contraseña.';

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, false)) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }

        $request->session()->regenerate();

        return $this->user($request);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->load([
            'organizations:id,name',
            'branches:id,organization_id,name,active',
        ])]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:254'],
        ], [
            'email.required' => 'Ingresá tu correo electrónico.',
            'email.email' => 'Ingresá un correo electrónico válido.',
            'email.max' => 'El correo electrónico es demasiado largo.',
        ]);

        Password::sendResetLink([
            'email' => Str::lower(trim($credentials['email'])),
        ]);

        return response()->json(['data' => ['message' => self::RESET_LINK_MESSAGE]]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'token.required' => 'El enlace no contiene un token válido. Solicitá uno nuevo.',
            'email.required' => 'Ingresá tu correo electrónico.',
            'email.email' => 'Ingresá un correo electrónico válido.',
            'email.max' => 'El correo electrónico es demasiado largo.',
            'password.required' => 'Ingresá una nueva contraseña.',
            'password.min' => 'La contraseña debe tener al menos ocho caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ]);
        $credentials['email'] = Str::lower(trim($credentials['email']));

        $status = Password::reset(
            $credentials,
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['El enlace no es válido o ya venció. Solicitá uno nuevo.'],
            ]);
        }

        return response()->json([
            'data' => ['message' => 'La contraseña se actualizó. Ya podés ingresar.'],
        ]);
    }
}

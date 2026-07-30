<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationDelivery;
use App\Support\ServerDataTable;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class NotificationController extends Controller
{
    public function index(Request $request, TenantContext $tenant, ServerDataTable $table): Response
    {
        return $table->respond(
            NotificationDelivery::query()->where('organization_id', $tenant->organization->id)
                ->where('user_id', $request->user()->id)
                ->where(fn ($query) => $query->whereNull('branch_id')
                    ->orWhere('branch_id', $tenant->branch->id)),
            $request, ['subject', 'message', 'action'],
            ['id' => 'id', 'status' => 'status', 'channel' => 'channel', 'created_at' => 'created_at'],
            ['status' => 'status', 'channel' => 'channel'],
            ['ID' => 'id', 'Canal' => 'channel', 'Estado' => 'status', 'Asunto' => 'subject',
                'Mensaje' => 'message', 'Acción' => 'action', 'Fecha' => 'created_at'],
            'notificaciones',
        );
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json(['data' => DB::table('notification_preferences')
            ->where('user_id', $request->user()->id)->get()]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preferences' => ['required', 'array', 'size:3'],
            'preferences.*.channel' => ['required', 'distinct', Rule::in(['internal', 'email', 'pwa_push'])],
            'preferences.*.enabled' => ['required', 'boolean'],
            'preferences.*.quiet_hours_start' => ['nullable', 'date_format:H:i'],
            'preferences.*.quiet_hours_end' => ['nullable', 'date_format:H:i'],
            'preferences.*.timezone' => ['required', 'timezone'],
        ]);
        foreach ($data['preferences'] as $preference) {
            DB::table('notification_preferences')->updateOrInsert(
                ['user_id' => $request->user()->id, 'channel' => $preference['channel']],
                [...$preference, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        return $this->preferences($request);
    }

    public function subscribe(Request $request): JsonResponse
    {
        abort_unless(config('services.pwa_push.enabled'), 409, 'PWA push is disabled.');
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:4000'],
            'public_key' => ['required', 'string', 'max:2000'],
            'auth_token' => ['required', 'string', 'max:2000'],
        ]);
        $hash = hash('sha256', $data['endpoint']);
        DB::table('push_subscriptions')->updateOrInsert(
            ['user_id' => $request->user()->id, 'endpoint_hash' => $hash],
            [
                'endpoint' => Crypt::encryptString($data['endpoint']),
                'public_key' => Crypt::encryptString($data['public_key']),
                'auth_token' => Crypt::encryptString($data['auth_token']),
                'active' => true, 'updated_at' => now(), 'created_at' => now(),
            ],
        );

        return response()->json(['data' => ['subscribed' => true]], 201);
    }
}

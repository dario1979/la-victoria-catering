<?php

use App\Support\OperationalHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health/live', fn (Request $request): JsonResponse => response()->json([
    'status' => 'live',
    'request_id' => $request->attributes->get('request_id'),
]));
Route::get('/health/ready', function (Request $request, OperationalHealth $health): JsonResponse {
    $readiness = $health->readiness();

    return response()->json([
        'status' => $readiness['status'],
        'request_id' => $request->attributes->get('request_id'),
    ], $readiness['status'] === 'ready' ? 200 : 503);
});

Route::get('/', function () {
    return view('welcome');
});

Route::view('/forgot-password', 'welcome');
Route::view('/reset-password/{token}', 'welcome')->name('password.reset');

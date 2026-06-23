<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Auth — public
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Auth — protected
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/register', [AuthController::class, 'register']);
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::put('/auth/me', [AuthController::class, 'updateMe']);
        Route::put('/auth/me/password', [AuthController::class, 'changePassword']);
    });

    // Projects — Fase 2
    // Route::middleware('auth:sanctum')->prefix('projects')->group(function () { ... });

    // Emission Factors — Fase 2
    // Route::middleware('auth:sanctum')->group(function () { ... });

    // Carbon Entries — Fase 3
    // Dashboard — Fase 4
    // Reports — Fase 5
});

<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CarbonEntryController;
use App\Http\Controllers\Api\V1\EmissionCategoryController;
use App\Http\Controllers\Api\V1\EmissionFactorController;
use App\Http\Controllers\Api\V1\ProjectController;
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

        // Emission Categories (read-only, semua user)
        Route::get('/emission-categories', [EmissionCategoryController::class, 'index']);

        // Emission Factors
        Route::get('/emission-factors', [EmissionFactorController::class, 'index']);
        Route::get('/emission-factors/{id}', [EmissionFactorController::class, 'show']);
        Route::post('/emission-factors', [EmissionFactorController::class, 'store']);
        Route::put('/emission-factors/{id}', [EmissionFactorController::class, 'update']);
        Route::delete('/emission-factors/{id}', [EmissionFactorController::class, 'destroy']);

        // Projects
        Route::get('/projects', [ProjectController::class, 'index']);
        Route::post('/projects', [ProjectController::class, 'store']);
        Route::get('/projects/{id}', [ProjectController::class, 'show']);
        Route::put('/projects/{id}', [ProjectController::class, 'update']);
        Route::delete('/projects/{id}', [ProjectController::class, 'destroy']);
        Route::get('/projects/{id}/summary', [ProjectController::class, 'summary']);

        // Project Members
        Route::get('/projects/{id}/members', [ProjectController::class, 'members']);
        Route::post('/projects/{id}/members', [ProjectController::class, 'addMember']);
        Route::put('/projects/{id}/members/{userId}', [ProjectController::class, 'updateMember']);
        Route::delete('/projects/{id}/members/{userId}', [ProjectController::class, 'removeMember']);

        // Carbon Entries
        Route::prefix('projects/{projectId}/entries')->group(function () {
            Route::get('/', [CarbonEntryController::class, 'index']);
            Route::post('/', [CarbonEntryController::class, 'store']);
            Route::post('/bulk', [CarbonEntryController::class, 'bulk']);
            Route::get('/{id}', [CarbonEntryController::class, 'show']);
            Route::put('/{id}', [CarbonEntryController::class, 'update']);
            Route::delete('/{id}', [CarbonEntryController::class, 'destroy']);
            Route::post('/{id}/submit', [CarbonEntryController::class, 'submit']);
            Route::post('/{id}/approve', [CarbonEntryController::class, 'approve']);
        });

        // Carbon Targets — Fase 4
        // Dashboard — Fase 4
        // Reports — Fase 5
    });
});

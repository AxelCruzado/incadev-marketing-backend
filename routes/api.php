<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\CourseVersionController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\AlumnoController;

Route::apiResource('campaigns', Api\CampaignController::class);
Route::apiResource('proposals', Api\ProposalController::class);
Route::apiResource('posts', Api\PostController::class);
// Publish an existing post to social media (calls socialmediaapi then updates local post)
Route::post('posts/{id}/publish', [Api\PostController::class, 'publish']);
// Generation endpoint - create a draft using external microservices
Route::post('posts/generate-draft', [App\Http\Controllers\PostGeneratorController::class, 'generate']);
Route::apiResource('metrics', Api\MetricController::class);

Route::get('campaigns/{id}/metrics', [CampaignController::class, 'metrics']);
Route::get('campaigns/{id}/posts', [PostController::class, 'byCampaign']);
Route::get('posts/{id}/metrics', [PostController::class, 'metrics']);

Route::get('/courses', [CourseController::class, 'index']);
Route::get('/courses/{id}', [CourseController::class, 'show']);
Route::get('/versions', [CourseVersionController::class, 'index']);
Route::get('/versions/{id}', [CourseVersionController::class, 'show']);
Route::get('/versions/{id}/campaigns', [CourseVersionController::class, 'campaigns']);


Route::get('/courses/{id}/versions', [CourseController::class, 'versions']);
Route::get('/courses/{id}/campaigns', [CourseController::class, 'campaigns']);

// Alumnos - Estadísticas para dashboard de marketing
Route::get('/alumnos/stats', [AlumnoController::class, 'stats']);
Route::get('/alumnos/resumen', [AlumnoController::class, 'resumen']);

Route::get('/health', function () {
    return response()->json([
        'status' => 'OK',
        'timestamp' => now()->toISOString(),
        'service' => 'Marketing Backend API'
    ]);
});
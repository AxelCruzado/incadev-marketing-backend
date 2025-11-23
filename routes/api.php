<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api;

Route::apiResource('campaigns', Api\CampaignController::class);
Route::apiResource('proposals', Api\ProposalController::class);
Route::apiResource('posts', Api\PostController::class);
Route::apiResource('metrics', Api\MetricController::class);

Route::get('/health', function () {
    return response()->json([
        'status' => 'OK',
        'timestamp' => now()->toISOString(),
        'service' => 'Marketing Backend API'
    ]);
});
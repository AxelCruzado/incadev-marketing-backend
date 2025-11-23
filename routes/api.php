<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\PostController;

Route::apiResource('campaigns', Api\CampaignController::class);
Route::apiResource('proposals', Api\ProposalController::class);
Route::apiResource('posts', Api\PostController::class);
Route::apiResource('metrics', Api\MetricController::class);
Route::get('campaigns/{id}/metrics', [CampaignController::class, 'metrics']);
Route::get('/campaigns/{id}/posts', [PostController::class, 'byCampaign']);


Route::get('/health', function () {
    return response()->json([
        'status' => 'OK',
        'timestamp' => now()->toISOString(),
        'service' => 'Marketing Backend API'
    ]);
});
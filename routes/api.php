<?php

use App\Http\Controllers\AutopilotController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\MetaController;
use App\Http\Controllers\MusicController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RenderController;
use App\Http\Controllers\TikTokController;
use Illuminate\Support\Facades\Route;

// New Studio API (auth required)
Route::middleware(['auth', 'approved'])->group(function () {
    // Each of these starts yt-dlp / AI work: cap how often a user can fire them
    Route::get('/meta', [MetaController::class, 'show'])->middleware('throttle:20,1');

    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/projects/{projectId}/status', [ProjectController::class, 'status']);
    Route::delete('/projects', [ProjectController::class, 'destroyAll']);
    Route::delete('/projects/{projectId}', [ProjectController::class, 'destroy']);
    Route::post('/projects/{projectId}/cancel', [ProjectController::class, 'cancel']);
    Route::post('/projects/{projectId}/retry', [ProjectController::class, 'retry'])->middleware('throttle:10,1');

    Route::post('/clips/{clipId}/render', [RenderController::class, 'render'])->middleware('throttle:20,1');
    Route::post('/clips/{clipId}/cancel', [RenderController::class, 'cancel']);
    Route::get('/clips/{clipId}/render-status', [RenderController::class, 'renderStatus']);
    Route::get('/clips/{clipId}/caption', [RenderController::class, 'caption']);
    Route::get('/clips/{clipId}/download', [DownloadController::class, 'download']);

    Route::get('/tiktok/accounts', [TikTokController::class, 'accounts']);
    Route::delete('/tiktok/accounts/{accountId}', [TikTokController::class, 'disconnect']);
    Route::get('/tiktok/accounts/{accountId}/creator-info', [TikTokController::class, 'creatorInfo']);
    Route::post('/clips/{clipId}/tiktok', [TikTokController::class, 'upload']);
    Route::get('/clips/{clipId}/tiktok-status', [TikTokController::class, 'status']);

    Route::get('/music', [MusicController::class, 'index']);
    Route::post('/music', [MusicController::class, 'store']);
    Route::patch('/music', [MusicController::class, 'update']);
    Route::delete('/music', [MusicController::class, 'destroy']);
    Route::get('/music/file', [MusicController::class, 'file']);

    Route::get('/autopilot/sources', [AutopilotController::class, 'sources']);
    Route::get('/autopilot/defaults', [AutopilotController::class, 'defaults']);
    Route::post('/autopilot/sources', [AutopilotController::class, 'storeSource']);
    Route::patch('/autopilot/sources/{sourceId}', [AutopilotController::class, 'updateSource']);
    Route::delete('/autopilot/sources/{sourceId}', [AutopilotController::class, 'destroySource']);
    Route::post('/autopilot/sources/{sourceId}/run', [AutopilotController::class, 'runSource'])->middleware('throttle:10,1');
    Route::get('/autopilot/review', [AutopilotController::class, 'review']);
    Route::post('/autopilot/review/clean-approved', [AutopilotController::class, 'cleanApproved']);
    Route::post('/autopilot/review/{clipId}/approve', [AutopilotController::class, 'approve']);
    Route::post('/autopilot/review/{clipId}/reject', [AutopilotController::class, 'reject']);
});

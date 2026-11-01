<?php

use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\AutopilotController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\GameplayClipController;
use App\Http\Controllers\HookTestController;
use App\Http\Controllers\MetaController;
use App\Http\Controllers\MusicController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RenderController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\TikTokController;
use App\Http\Controllers\YouTubeController;
use Illuminate\Support\Facades\Route;

// New Studio API (auth required)
Route::middleware(['auth', 'approved'])->group(function () {
    // Each of these starts yt-dlp / AI work: cap how often a user can fire them
    Route::get('/meta', [MetaController::class, 'show'])->middleware('throttle:20,1');

    Route::get('/tokens', [ApiTokenController::class, 'index']);
    Route::post('/tokens', [ApiTokenController::class, 'store'])->middleware('throttle:10,1');
    Route::delete('/tokens/{tokenId}', [ApiTokenController::class, 'destroy']);

    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/projects/{projectId}/status', [ProjectController::class, 'status']);
    Route::get('/projects/{projectId}/download', [DownloadController::class, 'project']);
    Route::delete('/projects', [ProjectController::class, 'destroyAll']);
    Route::delete('/projects/{projectId}', [ProjectController::class, 'destroy']);
    Route::post('/projects/{projectId}/cancel', [ProjectController::class, 'cancel']);
    Route::post('/projects/{projectId}/retry', [ProjectController::class, 'retry'])->middleware('throttle:10,1');

    Route::post('/clips/{clipId}/render', [RenderController::class, 'render'])->middleware('throttle:20,1');
    Route::post('/clips/{clipId}/cancel', [RenderController::class, 'cancel']);
    Route::get('/clips/{clipId}/render-status', [RenderController::class, 'renderStatus']);
    Route::get('/clips/{clipId}/caption', [RenderController::class, 'caption']);
    Route::get('/clips/{clipId}/transcript', [RenderController::class, 'transcript']);
    Route::patch('/clips/{clipId}/bounds', [RenderController::class, 'bounds']);
    Route::get('/clips/{clipId}/download', [DownloadController::class, 'download']);

    // Hook A/B test: 2-3 hook variants of a clip, posted staggered, winner picked from TikTok stats
    Route::get('/clips/{clipId}/hook-test', [HookTestController::class, 'show']);
    Route::post('/clips/{clipId}/hook-test', [HookTestController::class, 'store'])->middleware('throttle:10,1');
    Route::post('/clips/{clipId}/hook-test/publish', [HookTestController::class, 'publish'])->middleware('throttle:10,1');
    Route::delete('/clips/{clipId}/hook-test', [HookTestController::class, 'destroy']);

    Route::get('/tiktok/accounts', [TikTokController::class, 'accounts']);
    Route::delete('/tiktok/accounts/{accountId}', [TikTokController::class, 'disconnect']);
    Route::get('/tiktok/accounts/{accountId}/creator-info', [TikTokController::class, 'creatorInfo']);
    Route::post('/clips/{clipId}/tiktok', [TikTokController::class, 'upload']);
    Route::get('/clips/{clipId}/tiktok-status', [TikTokController::class, 'status']);

    Route::get('/youtube/accounts', [YouTubeController::class, 'accounts']);
    Route::delete('/youtube/accounts/{accountId}', [YouTubeController::class, 'disconnect']);
    Route::post('/clips/{clipId}/youtube', [YouTubeController::class, 'upload'])->middleware('throttle:10,1');
    Route::get('/clips/{clipId}/youtube-status', [YouTubeController::class, 'status']);

    Route::get('/schedule', [ScheduleController::class, 'index']);
    Route::get('/schedule/clips', [ScheduleController::class, 'clips']);
    Route::post('/schedule', [ScheduleController::class, 'store'])->middleware('throttle:30,1');
    Route::patch('/schedule/{postId}', [ScheduleController::class, 'update'])->middleware('throttle:30,1');
    Route::delete('/schedule/{postId}', [ScheduleController::class, 'destroy']);

    Route::get('/gameplay', [GameplayClipController::class, 'index']);
    Route::post('/gameplay', [GameplayClipController::class, 'store'])->middleware('throttle:20,1');
    Route::delete('/gameplay/{clipId}', [GameplayClipController::class, 'destroy'])->whereNumber('clipId');

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
    Route::post('/autopilot/review/clean-pending', [AutopilotController::class, 'cleanPending']);
    Route::post('/autopilot/review/{clipId}/approve', [AutopilotController::class, 'approve']);
    Route::post('/autopilot/review/{clipId}/reject', [AutopilotController::class, 'reject']);
    Route::post('/autopilot/review/{clipId}/unapprove', [AutopilotController::class, 'unapprove']);
    Route::get('/autopilot/youtube', [AutopilotController::class, 'youtubeSettings']);
    Route::put('/autopilot/youtube', [AutopilotController::class, 'updateYoutubeSettings']);
});

<?php

use App\Http\Controllers\AgentClipController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\ManualClipController;
use App\Http\Controllers\McpController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RenderController;
use App\Http\Controllers\StylePresetController;
use App\Http\Controllers\TikTokController;
use App\Http\Controllers\YouTubeController;
use App\Http\Middleware\ApplyAgentDefaults;
use Illuminate\Support\Facades\Route;

// Bearer-token API for agents and scripts: /api/v1, no session or CSRF.
Route::middleware('agent')->prefix('api/v1')->group(function () {
    // read: list, poll, download
    Route::middleware('token.can:read')->group(function () {
        Route::get('/projects', [ProjectController::class, 'index']);
        Route::get('/projects/{projectId}/status', [ProjectController::class, 'status']);
        Route::get('/projects/{projectId}/clips', [AgentClipController::class, 'index']);
        Route::get('/projects/{projectId}/transcript', [ManualClipController::class, 'transcript']);
        Route::get('/projects/{projectId}/download', [DownloadController::class, 'project']);
        Route::get('/clips/{clipId}/render-status', [RenderController::class, 'renderStatus']);
        Route::get('/clips/{clipId}/download', [DownloadController::class, 'download']);
        Route::get('/publish/accounts', [AgentClipController::class, 'accounts']);
        Route::get('/presets', [StylePresetController::class, 'index']);
        Route::get('/clips/{clipId}/youtube-status', [YouTubeController::class, 'status']);
        Route::get('/clips/{clipId}/tiktok-status', [TikTokController::class, 'status']);
    });

    // render: everything that spends analysis or render quota, plus cancelling it
    Route::middleware('token.can:render')->group(function () {
        Route::post('/projects', [ProjectController::class, 'store'])->middleware(['throttle:10,1', ApplyAgentDefaults::class]);
        Route::post('/projects/{projectId}/cancel', [ProjectController::class, 'cancel']);
        Route::post('/projects/{projectId}/retry', [ProjectController::class, 'retry'])->middleware('throttle:10,1');
        Route::post('/projects/{projectId}/clips', [ManualClipController::class, 'store'])->middleware('throttle:20,1');
        Route::post('/clips/{clipId}/render', [RenderController::class, 'render'])->middleware('throttle:20,1');
        Route::post('/clips/{clipId}/cancel', [RenderController::class, 'cancel']);
        Route::get('/clips/{clipId}/caption', [RenderController::class, 'caption']); // calls the AI when not cached
    });

    // publish: upload to a connected YouTube / TikTok account
    Route::middleware('token.can:publish')->group(function () {
        Route::post('/clips/{clipId}/youtube', [YouTubeController::class, 'upload'])->middleware('throttle:10,1');
        Route::post('/clips/{clipId}/tiktok', [TikTokController::class, 'upload']);
    });

    // Any valid token; each tool re-enters the routes above, which enforce the abilities
    Route::post('/mcp', [McpController::class, 'handle']);
    Route::get('/mcp', fn () => response()->json(['message' => 'Gunakan POST (MCP Streamable HTTP).'], 405, ['Allow' => 'POST']));
});

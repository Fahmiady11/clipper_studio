<?php

use App\Http\Controllers\AgentClipController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\McpController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RenderController;
use App\Http\Controllers\TikTokController;
use App\Http\Controllers\YouTubeController;
use App\Http\Middleware\ApplyAgentDefaults;
use Illuminate\Support\Facades\Route;

// Bearer-token API for agents and scripts: /api/v1, no session or CSRF.
Route::middleware('agent')->prefix('api/v1')->group(function () {
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store'])->middleware(['throttle:10,1', ApplyAgentDefaults::class]);
    Route::get('/projects/{projectId}/status', [ProjectController::class, 'status']);
    Route::get('/projects/{projectId}/clips', [AgentClipController::class, 'index']);
    Route::get('/projects/{projectId}/download', [DownloadController::class, 'project']);
    Route::post('/projects/{projectId}/cancel', [ProjectController::class, 'cancel']);
    Route::post('/projects/{projectId}/retry', [ProjectController::class, 'retry'])->middleware('throttle:10,1');

    Route::post('/clips/{clipId}/render', [RenderController::class, 'render'])->middleware('throttle:20,1');
    Route::post('/clips/{clipId}/cancel', [RenderController::class, 'cancel']);
    Route::get('/clips/{clipId}/render-status', [RenderController::class, 'renderStatus']);
    Route::get('/clips/{clipId}/caption', [RenderController::class, 'caption']);
    Route::get('/clips/{clipId}/download', [DownloadController::class, 'download']);

    Route::get('/publish/accounts', [AgentClipController::class, 'accounts']);
    Route::post('/clips/{clipId}/youtube', [YouTubeController::class, 'upload'])->middleware('throttle:10,1');
    Route::get('/clips/{clipId}/youtube-status', [YouTubeController::class, 'status']);
    Route::post('/clips/{clipId}/tiktok', [TikTokController::class, 'upload']);
    Route::get('/clips/{clipId}/tiktok-status', [TikTokController::class, 'status']);

    Route::post('/mcp', [McpController::class, 'handle']);
    Route::get('/mcp', fn () => response()->json(['message' => 'Gunakan POST (MCP Streamable HTTP).'], 405, ['Allow' => 'POST']));
});

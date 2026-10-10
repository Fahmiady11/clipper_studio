<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        // Tried in order; each has its own (free-tier) quota, so a longer list survives a spent one
        'models' => explode(',', env('GEMINI_MODELS',
            'gemini-2.5-flash,gemini-3.5-flash,gemini-2.5-flash-lite,gemini-3.5-flash-lite,gemini-3.1-flash-lite,gemini-flash-lite-latest')),
    ],

    // Optional OpenAI-compatible gateway (e.g. OmniRoute) tried before the Gemini API key.
    // LLM_GATEWAY_URL is the base that ends in /v1; leave empty to use Gemini directly.
    'llm_gateway' => [
        'url' => env('LLM_GATEWAY_URL'),
        'key' => env('LLM_GATEWAY_KEY'),
        'model' => env('LLM_GATEWAY_MODEL'),
    ],

    'ytdlp' => [
        'path' => env('YTDLP_PATH', 'yt-dlp'),
        'cookies_file' => env('YTDLP_COOKIES', ''),         // path to Netscape cookies.txt
        'cookies_from_browser' => env('YTDLP_COOKIES_BROWSER', ''), // browser name (chrome/safari/firefox)
    ],

    'ffmpeg' => [
        'path' => env('FFMPEG_PATH', 'ffmpeg'),
    ],

    'tiktok' => [
        'client_key' => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        // Must match a Redirect URI registered on the TikTok app (HTTPS, e.g. an ngrok URL + /tiktok/callback)
        'redirect_uri' => env('TIKTOK_REDIRECT_URI'),
        'scopes' => env('TIKTOK_SCOPES', 'user.info.basic,video.upload'),
    ],

    'clipper' => [
        // Downloaded source videos (temp/, video_cache/) older than this are deleted daily
        'temp_retention_hours' => (int) env('CLIPPER_TEMP_RETENTION_HOURS', 72),
    ],

    'music' => [
        // Royalty-free tracks, one folder per mood: <path>/<mood>/*.mp3
        'path' => env('MUSIC_LIBRARY_PATH', storage_path('app/music')),
    ],

    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
    ],

];

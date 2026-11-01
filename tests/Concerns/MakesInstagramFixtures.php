<?php

namespace Tests\Concerns;

use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

trait MakesInstagramFixtures
{
    protected function configureInstagram(): void
    {
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'services.instagram.app_id' => 'ig-app',
            'services.instagram.app_secret' => 'ig-secret',
            'services.instagram.redirect_uri' => 'https://example.test/instagram/callback',
        ]);
    }

    protected function igAccount(User $user, array $attrs = []): SocialAccount
    {
        return SocialAccount::create($attrs + [
            'user_id' => $user->id,
            'platform' => 'instagram',
            'external_id' => '1784',
            'display_name' => '@brandku',
            'access_token' => 'ig-token',
            'access_expires_at' => now()->addDays(50),
        ]);
    }

    protected function doneClip(User $user, array $attrs = []): GeneratedClip
    {
        Storage::fake('local');
        Storage::put('clips/test.mp4', str_repeat('v', 2048));
        $project = ClipProject::create([
            'user_id' => $user->id, 'youtube_url' => 'https://youtu.be/abcdefg',
            'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'done',
        ]);

        return GeneratedClip::create($attrs + [
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 30,
            'topic' => 't', 'reason' => 'r', 'status' => 'done', 'output_path' => 'clips/test.mp4',
        ]);
    }
}

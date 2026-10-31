<?php

namespace Tests\Feature;

use App\Models\ClipProject;
use App\Models\ClipStat;
use App\Models\GeneratedClip;
use App\Models\TiktokAccount;
use App\Models\User;
use App\Services\TikTokStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClipStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    }

    public function test_collects_latest_metrics_for_published_tiktok_clips(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user, ['scope' => 'user.info.basic,video.upload,video.list']);
        $clip = $this->clip($user, ['tiktok_status' => 'published', 'tiktok_publish_id' => 'video-1', 'tiktok_account_id' => $account->id]);
        $this->clip($user, ['tiktok_status' => 'processing']);

        Http::fake([
            'open.tiktokapis.com/v2/video/query/*' => Http::response([
                'data' => ['videos' => [[
                    'id' => 'video-1',
                    'view_count' => 1200,
                    'like_count' => 80,
                    'comment_count' => 12,
                    'share_count' => 7,
                ]]],
                'error' => ['code' => 'ok', 'message' => ''],
            ]),
        ]);

        $collected = app(TikTokStatsService::class)->collectPublishedStats();

        $this->assertSame(1, $collected);
        $stat = ClipStat::where('generated_clip_id', $clip->id)->sole();
        $this->assertSame('tiktok', $stat->platform);
        $this->assertSame(1200, $stat->views);
        $this->assertSame(80, $stat->likes);
        $this->assertSame(12, $stat->comments);
        $this->assertSame(7, $stat->shares);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'fields=')
            && str_contains(urldecode($request->url()), 'id,like_count')
            && $request['filters']['video_ids'] === ['video-1']);
    }

    public function test_missing_stats_scope_degrades_without_calling_tiktok(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user, ['scope' => 'user.info.basic,video.upload']);
        $this->clip($user, ['tiktok_status' => 'published', 'tiktok_publish_id' => 'video-2', 'tiktok_account_id' => $account->id]);

        Http::fake();
        $collected = app(TikTokStatsService::class)->collectPublishedStats();

        $this->assertSame(0, $collected);
        $this->assertSame(0, ClipStat::count());
        Http::assertNothingSent();
    }

    public function test_tiktok_stats_failure_degrades_without_throwing(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user, ['scope' => 'video.list']);
        $this->clip($user, ['tiktok_status' => 'published', 'tiktok_publish_id' => 'video-3', 'tiktok_account_id' => $account->id]);

        Http::fake([
            'open.tiktokapis.com/v2/video/query/*' => Http::response([
                'error' => ['code' => 'access_token_invalid', 'message' => 'The access token is invalid'],
            ], 400),
        ]);

        $this->assertSame(0, app(TikTokStatsService::class)->collectPublishedStats());
        $this->assertSame(0, ClipStat::count());
    }

    public function test_autopilot_review_includes_clip_and_best_performance(): void
    {
        $user = User::factory()->create();
        $best = $this->clip($user, [
            'topic' => 'Momen terbaik',
            'review_status' => 'approved',
            'tiktok_status' => 'published',
        ]);
        $other = $this->clip($user, [
            'topic' => 'Momen lain',
            'review_status' => 'approved',
            'tiktok_status' => 'published',
        ]);

        ClipStat::create([
            'generated_clip_id' => $best->id,
            'platform' => 'tiktok',
            'views' => 1000, 'likes' => 100, 'comments' => 10, 'shares' => 5,
            'collected_at' => now()->subHour(),
        ]);
        ClipStat::create([
            'generated_clip_id' => $best->id,
            'platform' => 'tiktok',
            'views' => 1800, 'likes' => 160, 'comments' => 20, 'shares' => 9,
            'collected_at' => now(),
        ]);
        ClipStat::create([
            'generated_clip_id' => $other->id,
            'platform' => 'tiktok',
            'views' => 400, 'likes' => 30, 'comments' => 3, 'shares' => 2,
            'collected_at' => now(),
        ]);

        $this->actingAs($user)->getJson('/api/autopilot/review')
            ->assertOk()
            ->assertJsonPath('performance.best_clip.id', $best->id)
            ->assertJsonPath('performance.total_views', 2200)
            ->assertJsonPath('performance.total_likes', 190);

        $rows = collect($this->getJson('/api/autopilot/review')->json('data'))->keyBy('id');
        $this->assertSame(1800, $rows[$best->id]['views']);
        $this->assertSame(160, $rows[$best->id]['likes']);
    }

    private function account(User $user, array $attrs = []): TiktokAccount
    {
        return TiktokAccount::create($attrs + [
            'user_id' => $user->id,
            'open_id' => uniqid('open-'),
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'access_expires_at' => now()->addDay(),
            'refresh_expires_at' => now()->addDays(300),
        ]);
    }

    private function clip(User $user, array $attrs = []): GeneratedClip
    {
        $project = ClipProject::create([
            'user_id' => $user->id,
            'youtube_url' => 'https://youtu.be/abcdefg',
            'layout_type' => 'reframe',
            'clip_count' => 1,
            'duration_mode' => 'auto',
            'status' => 'done',
        ]);

        return GeneratedClip::create($attrs + [
            'clip_project_id' => $project->id,
            'ranking' => 1,
            'start_seconds' => 0,
            'end_seconds' => 20,
            'topic' => 'Test clip',
            'reason' => 'A useful moment',
            'viral_score' => 8,
            'status' => 'done',
            'review_status' => 'approved',
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\RenderClipJob;
use App\Models\ClipProject;
use App\Models\ClipStat;
use App\Models\GeneratedClip;
use App\Models\HookSetting;
use App\Models\HookTest;
use App\Models\RenderUsage;
use App\Models\ScheduledPost;
use App\Models\TiktokAccount;
use App\Models\User;
use App\Services\ClipPerformanceSummary;
use App\Services\GeminiService;
use App\Services\HookTestService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HookTestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'services.gemini.key' => 'k',
            'services.gemini.models' => ['model-a'],
            'services.gemini.retry_pauses' => [0, 0],
            'services.llm_gateway' => ['url' => null, 'key' => null, 'model' => null],
        ]);
    }

    private function clip(User $user, bool $hookEnabled = true, array $attrs = []): GeneratedClip
    {
        $project = ClipProject::create([
            'user_id' => $user->id, 'youtube_url' => 'https://youtu.be/abcdefg', 'video_title' => 'Podcast',
            'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
        HookSetting::create(['clip_project_id' => $project->id, 'enabled' => $hookEnabled, 'is_ai_generated' => true, 'duration_seconds' => 3]);

        return GeneratedClip::create($attrs + [
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 60,
            'topic' => 'Gaji dan kebiasaan', 'reason' => 'r', 'viral_score' => 8, 'hook_text' => 'Hook asli', 'status' => 'pending',
            'caption' => 'Caption asli', 'hashtags_json' => ['#a'], 'broll_json' => [['time' => 5, 'query' => 'money']],
            'subtitle_json' => [['start' => 0, 'end' => 5, 'text' => 'halo semua']], 'music_mood' => 'chill',
        ]);
    }

    private function fakeHooks(?array $hooks = null): void
    {
        $hooks ??= [
            ['angle' => 'curiosity_gap', 'text' => 'Rahasia yang *disembunyikan*'],
            ['angle' => 'question', 'text' => 'Kenapa kamu *susah kaya*?'],
            ['angle' => 'bold_claim', 'text' => 'Kerja keras itu *overrated*'],
        ];
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['hooks' => $hooks])]]]]]])]);
    }

    private function account(User $user, string $scope = 'video.publish,video.list'): TiktokAccount
    {
        return TiktokAccount::create([
            'user_id' => $user->id, 'open_id' => uniqid('open-'), 'display_name' => 'Kreator', 'scope' => $scope,
            'access_token' => 'at', 'refresh_token' => 'rt', 'access_expires_at' => now()->addDay(), 'refresh_expires_at' => now()->addDays(300),
        ]);
    }

    /** A test whose variants are rendered, so it can be published. */
    private function readyTest(User $user, int $count = 3): HookTest
    {
        Queue::fake();
        $this->fakeHooks();
        $clip = $this->clip($user);
        $this->actingAs($user)->postJson("/api/clips/{$clip->id}/hook-test", ['count' => $count])->assertCreated();
        Storage::put('clips/variant.mp4', 'v');
        GeneratedClip::where('variant_of_id', $clip->id)->update(['status' => 'done', 'output_path' => 'clips/variant.mp4']);

        return $clip->hookTest;
    }

    public function test_creates_distinct_variants_that_share_everything_but_the_hook(): void
    {
        Queue::fake();
        $this->fakeHooks();
        $user = User::factory()->create();
        $clip = $this->clip($user);

        $res = $this->actingAs($user)->postJson("/api/clips/{$clip->id}/hook-test", ['count' => 3])
            ->assertCreated()->assertJsonPath('test.status', 'draft')->assertJsonCount(3, 'test.variants');

        $variants = $clip->variants;
        $this->assertCount(3, $variants);
        $this->assertEqualsCanonicalizing(['curiosity_gap', 'question', 'bold_claim'], $variants->pluck('hook_angle')->all());
        $this->assertCount(3, $variants->pluck('hook_text_override')->unique());
        foreach ($variants as $v) {
            $this->assertSame($clip->id, $v->variant_of_id);
            $this->assertSame('processing', $v->status);
            $this->assertSame('Caption asli', $v->caption);
            $this->assertSame($clip->broll_json, $v->broll_json); // no new AI call at render time
            $this->assertSame($clip->subtitle_json, $v->subtitle_json);
            $this->assertSame('chill', $v->music_mood);
            $this->assertNull($v->viral_score); // keeps the viral-score buckets clean
        }
        Queue::assertPushed(RenderClipJob::class, 3);
        // Every variant is its own reservation: 3 renders x 60 s
        $this->assertSame(3, RenderUsage::where('status', 'reserved')->count());
        $this->assertSame(180, (int) RenderUsage::where('user_id', $user->id)->sum('seconds'));
        // The studio never sees variants as clips to pick
        $this->assertCount(1, ClipProject::with('generatedClips')->first()->generatedClips);
        $this->assertSame('Hook asli', $clip->fresh()->hook_text);
        $this->assertNotNull($res->json('test.id'));
    }

    public function test_two_variants_use_the_two_best_angles_so_far(): void
    {
        Queue::fake();
        $this->fakeHooks([['angle' => 'bold_claim', 'text' => 'Klaim *berani*'], ['angle' => 'question', 'text' => 'Kenapa *begitu*?']]);
        $user = User::factory()->create();
        foreach (['bold_claim', 'bold_claim', 'question'] as $angle) {
            HookTest::create(['user_id' => $user->id, 'generated_clip_id' => $this->clip($user)->id, 'variant_count' => 2, 'status' => 'decided', 'winner_angle' => $angle]);
        }
        $clip = $this->clip($user);

        $this->actingAs($user)->postJson("/api/clips/{$clip->id}/hook-test", ['count' => 2])->assertCreated();

        Http::assertSent(function ($request) {
            $prompt = $request['contents'][0]['parts'][0]['text'] ?? '';

            return str_contains($prompt, '- bold_claim:') && str_contains($prompt, '- question:') && ! str_contains($prompt, '- curiosity_gap:')
                && str_contains($prompt, 'klaim berani (2x)');
        });
    }

    public function test_validation_ownership_and_prerequisites(): void
    {
        Queue::fake();
        $this->fakeHooks();
        $user = User::factory()->create();
        $clip = $this->clip($user);

        $this->actingAs($user)->postJson("/api/clips/{$clip->id}/hook-test", ['count' => 1])->assertStatus(422);
        $this->actingAs($user)->postJson("/api/clips/{$clip->id}/hook-test", ['count' => 4])->assertStatus(422);
        $this->actingAs(User::factory()->create())->postJson("/api/clips/{$clip->id}/hook-test", ['count' => 2])->assertForbidden();

        $noHook = $this->clip($user, hookEnabled: false);
        $this->actingAs($user)->postJson("/api/clips/{$noHook->id}/hook-test", ['count' => 2])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Hook'));

        $this->actingAs($user)->postJson("/api/clips/{$clip->id}/hook-test", ['count' => 2])->assertCreated();
        $this->actingAs($user)->postJson("/api/clips/{$clip->id}/hook-test", ['count' => 2])->assertStatus(409);
        $variant = $clip->variants()->first();
        $this->actingAs($user)->postJson("/api/clips/{$variant->id}/hook-test", ['count' => 2])->assertStatus(422);
        $this->assertSame(0, $noHook->variants()->count());
    }

    public function test_too_few_distinct_hooks_creates_nothing(): void
    {
        Queue::fake();
        $this->fakeHooks([['angle' => 'question', 'text' => 'Hook asli']]); // one usable at most, and it repeats the current hook
        $user = User::factory()->create();
        $clip = $this->clip($user);

        $this->actingAs($user)->postJson("/api/clips/{$clip->id}/hook-test", ['count' => 3])->assertStatus(422);

        $this->assertSame(0, HookTest::count());
        $this->assertSame(1, GeneratedClip::count());
        Queue::assertNothingPushed();
    }

    public function test_each_variant_is_charged_and_a_short_quota_creates_nothing(): void
    {
        Queue::fake();
        $this->fakeHooks();
        $user = User::factory()->create();
        // Free plan = 30 min; leave room for two 60 s renders but not three
        RenderUsage::create(['user_id' => $user->id, 'generated_clip_id' => 'old', 'period' => now()->format('Y-m'), 'status' => 'charged', 'seconds' => 30 * 60 - 150]);
        $clip = $this->clip($user);

        $this->actingAs($user)->postJson("/api/clips/{$clip->id}/hook-test", ['count' => 3])
            ->assertStatus(402)->assertJsonPath('message', fn ($m) => str_contains($m, 'Kuota render'));

        $this->assertSame(0, HookTest::count());
        $this->assertSame(1, GeneratedClip::count());
        $this->assertSame(1, RenderUsage::count()); // reservations rolled back
        Queue::assertNothingPushed();

        $this->actingAs($user)->postJson("/api/clips/{$clip->id}/hook-test", ['count' => 2])->assertCreated();
        $this->assertSame(2, RenderUsage::where('status', 'reserved')->count());
    }

    public function test_publish_staggers_variants_on_one_account_in_shuffled_order(): void
    {
        $user = User::factory()->create();
        $test = $this->readyTest($user);
        $account = $this->account($user);
        $start = now()->addHour()->startOfMinute();

        $this->actingAs($user)->postJson("/api/clips/{$test->generated_clip_id}/hook-test/publish", [
            'account_id' => $account->id, 'start_at' => $start->toIso8601String(), 'gap_hours' => 24,
        ])->assertOk()->assertJsonPath('test.status', 'testing');

        $posts = ScheduledPost::orderBy('scheduled_at')->get();
        $this->assertCount(3, $posts);
        $this->assertSame($posts->pluck('generated_clip_id')->unique()->count(), 3);
        foreach ($posts as $i => $post) {
            $this->assertSame('tiktok', $post->platform);
            $this->assertSame($account->id, $post->account_id);
            $this->assertSame('direct', $post->options['mode']);
            $this->assertTrue($start->copy()->addDays($i)->equalTo($post->scheduled_at)); // same time of day, a day apart
            $this->assertSame('Caption asli #a', $post->options['title']);
        }
        $test->refresh();
        $this->assertSame('testing', $test->status);
        $this->assertTrue($posts->last()->scheduled_at->copy()->addHours(48 + 24)->equalTo($test->ends_by));

        $this->actingAs($user)->postJson("/api/clips/{$test->generated_clip_id}/hook-test/publish", ['account_id' => $account->id])->assertStatus(422);
        $this->actingAs($user)->deleteJson("/api/clips/{$test->generated_clip_id}/hook-test")->assertStatus(409);
    }

    public function test_publish_needs_rendered_variants_stats_scope_and_own_account(): void
    {
        $user = User::factory()->create();
        $test = $this->readyTest($user, 2);
        $url = "/api/clips/{$test->generated_clip_id}/hook-test/publish";

        $noList = $this->account($user, 'video.publish');
        $this->actingAs($user)->postJson($url, ['account_id' => $noList->id])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'video.list'));

        $stranger = $this->account(User::factory()->create());
        $this->actingAs($user)->postJson($url, ['account_id' => $stranger->id])->assertNotFound();

        $ok = $this->account($user);
        $this->actingAs($user)->postJson($url, ['account_id' => $ok->id, 'start_at' => now()->addSecond()->toIso8601String()])->assertStatus(422);

        $test->clip->variants()->first()->update(['status' => 'processing']);
        $this->actingAs($user)->postJson($url, ['account_id' => $ok->id])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'dirender'));
        $this->assertSame(0, ScheduledPost::count());
    }

    private function publishedTest(User $user, array $statsByAngle): HookTest
    {
        $test = $this->readyTest($user);
        $this->actingAs($user)->postJson("/api/clips/{$test->generated_clip_id}/hook-test/publish", [
            'account_id' => $this->account($user)->id, 'gap_hours' => 24,
        ])->assertOk();
        // Posts went out on schedule; then the 48 h window passed for all of them
        ScheduledPost::query()->update(['status' => 'queued']);
        foreach ($test->clip->variants as $v) {
            $post = ScheduledPost::where('generated_clip_id', $v->id)->first();
            $post->update(['handed_off_at' => $post->scheduled_at]);
            [$views, $likes] = $statsByAngle[$v->hook_angle];
            ClipStat::create(['generated_clip_id' => $v->id, 'platform' => 'tiktok', 'views' => $views, 'likes' => $likes, 'comments' => 0, 'shares' => 0,
                'collected_at' => $post->scheduled_at->copy()->addHours(47)]);
            // A later snapshot is a different age and must not count
            ClipStat::create(['generated_clip_id' => $v->id, 'platform' => 'tiktok', 'views' => 999999, 'likes' => 0, 'comments' => 0, 'shares' => 0,
                'collected_at' => $post->scheduled_at->copy()->addHours(60)]);
        }
        $this->travelTo(ScheduledPost::max('scheduled_at') ? Carbon::parse(ScheduledPost::max('scheduled_at'))->addHours(49) : now());

        return $test->refresh();
    }

    public function test_most_views_at_the_same_age_wins_and_the_winning_angle_is_recorded(): void
    {
        $user = User::factory()->create();
        $test = $this->publishedTest($user, ['curiosity_gap' => [400, 20], 'question' => [1500, 30], 'bold_claim' => [900, 10]]);

        $this->assertSame(1, app(HookTestService::class)->judgeDue());

        $test->refresh();
        $this->assertSame('decided', $test->status);
        $this->assertSame('question', $test->winner_angle);
        $this->assertSame('question', GeneratedClip::find($test->winner_clip_id)->hook_angle);
        $this->assertSame([1500, 900, 400], collect($test->results)->pluck('views')->sortDesc()->values()->all());

        $this->travelBack();
        $this->actingAs($user)->getJson("/api/clips/{$test->generated_clip_id}/hook-test")
            ->assertOk()->assertJsonPath('test.winner_angle', 'question')
            ->assertJsonPath('test.variants.0.views', fn ($v) => $v !== null);
    }

    public function test_close_views_fall_back_to_engagement_then_to_no_winner(): void
    {
        $user = User::factory()->create();
        $test = $this->publishedTest($user, ['curiosity_gap' => [1000, 20], 'question' => [1050, 150], 'bold_claim' => [200, 100]]);
        app(HookTestService::class)->judge($test);
        $this->assertSame('question', $test->refresh()->winner_angle); // 1050 vs 1000 is a tie on views; 150 likes vs 20 settles it

        $user2 = User::factory()->create();
        $tie = $this->publishedTest($user2, ['curiosity_gap' => [1000, 100], 'question' => [1050, 105], 'bold_claim' => [200, 100]]);
        app(HookTestService::class)->judge($tie);
        $tie->refresh();
        $this->assertSame('inconclusive', $tie->status);
        $this->assertNull($tie->winner_clip_id);
        $this->assertStringContainsString('tipis', $tie->note);
    }

    public function test_waits_until_every_variant_reached_the_window_unless_time_ran_out(): void
    {
        $user = User::factory()->create();
        $test = $this->publishedTest($user, ['curiosity_gap' => [400, 0], 'question' => [1500, 0], 'bold_claim' => [900, 0]]);
        $this->travelTo(ScheduledPost::min('scheduled_at') ? Carbon::parse(ScheduledPost::min('scheduled_at'))->addHours(10) : now());

        $this->assertFalse(app(HookTestService::class)->judge($test));
        $this->assertSame('testing', $test->refresh()->status);

        // Past ends_by the verdict comes from whatever exists, even when too thin
        ClipStat::query()->delete();
        $this->travelTo($test->ends_by->copy()->addMinute());
        $this->assertTrue(app(HookTestService::class)->judge($test));
        $test->refresh();
        $this->assertSame('inconclusive', $test->status);
        $this->assertStringContainsString('video.list', $test->note);
    }

    public function test_too_few_views_is_inconclusive(): void
    {
        $user = User::factory()->create();
        $test = $this->publishedTest($user, ['curiosity_gap' => [3, 0], 'question' => [5, 1], 'bold_claim' => [0, 0]]); // private posts barely show views
        app(HookTestService::class)->judge($test);
        $this->assertSame('inconclusive', $test->refresh()->status);
        $this->assertStringContainsString('terlalu sedikit', $test->note);
    }

    public function test_insight_needs_two_decided_tests_and_feeds_the_analysis_prompt(): void
    {
        $user = User::factory()->create();
        $summary = app(ClipPerformanceSummary::class);
        $this->assertNull($summary->hookInsight($user->id));
        $this->assertSame(['curiosity_gap', 'question', 'bold_claim'], $summary->rankedHookAngles($user->id));

        foreach (['question', 'question', 'bold_claim'] as $angle) {
            HookTest::create(['user_id' => $user->id, 'generated_clip_id' => $this->clip($user)->id, 'variant_count' => 2, 'status' => 'decided', 'winner_angle' => $angle]);
        }
        HookTest::create(['user_id' => $user->id, 'generated_clip_id' => $this->clip($user)->id, 'variant_count' => 2, 'status' => 'inconclusive']);

        $insight = $summary->hookInsight($user->id);
        $this->assertStringContainsString('3 tes', $insight);
        $this->assertStringContainsString('pertanyaan tajam (2x), klaim berani (1x)', $insight);
        $this->assertSame(['question', 'bold_claim', 'curiosity_gap'], $summary->rankedHookAngles($user->id));
        $this->assertNull($summary->hookInsight(User::factory()->create()->id)); // per user

        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => '[{"ranking":1,"start_seconds":1,"end_seconds":30}]']]]]]])]);
        app(GeminiService::class)->analyzeWithTranscript(['language' => 'id', 'segments' => [['start' => 0, 'end' => 5, 'text' => 'halo']]], 1, 'reframe', 'auto', 15, 60, 'Data performa X', $insight);
        Http::assertSent(fn ($r) => str_contains($r['contents'][0]['parts'][0]['text'], 'pertanyaan tajam (2x)')
            && str_contains($r['contents'][0]['parts'][0]['text'], 'Data performa X'));
    }

    public function test_variants_stay_out_of_the_calendar_picker_trim_and_discard_cleans_up(): void
    {
        $user = User::factory()->create();
        $test = $this->readyTest($user, 2);
        $clip = $test->clip;

        $this->actingAs($user)->getJson('/api/schedule/clips')->assertOk()->assertJsonCount(0, 'clips'); // original is not rendered, variants hidden

        $this->actingAs($user)->patchJson("/api/clips/{$clip->id}/bounds", ['start_seconds' => 1, 'end_seconds' => 30])->assertStatus(409);

        $this->actingAs($user)->deleteJson("/api/clips/{$clip->id}/hook-test")->assertOk();
        $this->assertSame(0, HookTest::count());
        $this->assertSame(1, GeneratedClip::count());
        Storage::assertMissing('clips/variant.mp4');
        $this->actingAs($user)->getJson("/api/clips/{$clip->id}/hook-test")->assertOk()->assertJsonPath('test', null);
    }
}

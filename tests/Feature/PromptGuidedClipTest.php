<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeVideoJob;
use App\Jobs\DescribeClipJob;
use App\Jobs\RenderClipJob;
use App\Models\ApiToken;
use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\Plan;
use App\Models\RenderUsage;
use App\Models\Transcript;
use App\Models\User;
use App\Models\VideoSource;
use App\Services\DiscoveryService;
use App\Services\GeminiService;
use App\Services\TranscriptService;
use App\Services\YtDlpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class PromptGuidedClipTest extends TestCase
{
    use RefreshDatabase;

    private function body(array $extra = []): array
    {
        return $extra + ['youtube_url' => 'https://youtu.be/abcdefghijk', 'layout_type' => 'reframe', 'clip_count' => 3, 'duration_mode' => 'auto'];
    }

    /** A finished studio project: three sentences with word timing, then a line without. */
    private function project(User $user, array $attrs = []): ClipProject
    {
        $project = ClipProject::create($attrs + [
            'user_id' => $user->id, 'youtube_url' => 'https://youtu.be/abcdefghijk', 'duration_seconds' => 600,
            'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'done',
            'instruction' => 'bagian tentang modal usaha',
        ]);
        $words = fn (float $at, array $list) => array_map(fn ($w, $i) => ['start' => $at + $i, 'end' => $at + $i + 0.8, 'text' => $w], $list, array_keys($list));
        Transcript::create(['clip_project_id' => $project->id, 'language' => 'id', 'content' => [
            ['start' => 10.0, 'end' => 14.8, 'text' => 'Modal usaha itu [tertawa] penting.', 'words' => $words(10.0, ['Modal', 'usaha', 'itu', '[tertawa]', 'penting.'])],
            ['start' => 16.0, 'end' => 20.8, 'text' => 'Jangan pinjam ke teman dulu.', 'words' => $words(16.0, ['Jangan', 'pinjam', 'ke', 'teman', 'dulu.'])],
            ['start' => 22.0, 'end' => 26.8, 'text' => 'Mulai dari yang kecil saja.', 'words' => $words(22.0, ['Mulai', 'dari', 'yang', 'kecil', 'saja.'])],
            ['start' => 40.0, 'end' => 44.0, 'text' => 'baris tanpa timing kata'],
        ]]);

        return $project;
    }

    // ── instruction ───────────────────────────────────────────────────────

    public function test_project_keeps_the_instruction_trimmed_and_empty_means_none(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/projects', $this->body(['instruction' => "  ambil bagian\n tentang   modal usaha "]))->assertCreated();
        $this->assertSame('ambil bagian tentang modal usaha', ClipProject::latest('created_at')->first()->instruction);

        ClipProject::query()->delete();
        $this->actingAs($user)->postJson('/api/projects', $this->body(['instruction' => '   ']))->assertCreated();
        $this->assertNull(ClipProject::first()->instruction);

        $this->actingAs($user)->postJson('/api/projects', $this->body(['instruction' => str_repeat('a', 301)]))
            ->assertUnprocessable()->assertJsonValidationErrors('instruction');
    }

    public function test_analysis_hands_the_instruction_to_the_ai_and_status_returns_it(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = ClipProject::create([
            'user_id' => $user->id, 'youtube_url' => 'https://youtu.be/abcdefg', 'layout_type' => 'reframe',
            'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'processing', 'instruction' => 'yang lucu-lucu',
        ]);
        $this->mock(YtDlpService::class)->shouldReceive('download')->andReturnUsing(function ($url, $path) {
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, 'video');
        });
        $this->mock(TranscriptService::class)->shouldReceive('fetch')
            ->andReturn(['language' => 'id', 'segments' => [['start' => 0, 'end' => 40, 'text' => 'halo']]]);
        $this->mock(GeminiService::class)->shouldReceive('analyzeWithTranscript')
            ->withArgs(fn (...$args) => ($args[8] ?? null) === 'yang lucu-lucu')
            ->once()
            ->andReturn([['ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 30, 'topic' => 'T', 'reason' => 'R']]);

        $this->app->call([new AnalyzeVideoJob($project->id), 'handle']);

        $this->actingAs($user)->getJson("/api/projects/{$project->id}/status")
            ->assertOk()->assertJsonPath('instruction', 'yang lucu-lucu')->assertJsonPath('clips.0.manual', false);
        exec('rm -rf '.escapeshellarg(storage_path('app/temp/'.$project->id)));
    }

    public function test_prompt_puts_the_instruction_first_only_when_given(): void
    {
        config([
            'services.gemini.key' => 'k', 'services.gemini.models' => ['model-a'], 'services.gemini.retry_pauses' => [0, 0],
            'services.llm_gateway' => ['url' => null, 'key' => null, 'model' => null],
        ]);
        $prompts = [];
        Http::fake(function ($request) use (&$prompts) {
            $prompts[] = $request['contents'][0]['parts'][0]['text'];

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => '[{"ranking":1,"start_seconds":1,"end_seconds":30}]']]]]]]);
        });
        $transcript = ['language' => 'id', 'segments' => [['start' => 0, 'end' => 5, 'text' => 'halo']]];

        app(GeminiService::class)->analyzeWithTranscript($transcript, 3, 'reframe', 'auto', 15, 60, null, null, 'maks 30 detik, "lucu"');
        app(GeminiService::class)->analyzeWithTranscript($transcript, 3, 'reframe');

        $this->assertStringContainsString('INSTRUKSI PENGGUNA', $prompts[0]);
        $this->assertStringContainsString("Pengguna minta: \"maks 30 detik, 'lucu'\"", $prompts[0]); // quotes can't close the brief early
        $this->assertStringNotContainsString('INSTRUKSI PENGGUNA', $prompts[1]);
    }

    public function test_agent_api_and_autopilot_sources_carry_the_instruction(): void
    {
        Queue::fake();
        $user = User::factory()->create(['approved_at' => now()]);
        [, $token] = ApiToken::issue($user, 'test');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/projects', ['youtube_url' => 'https://youtu.be/abcdefghijk', 'instruction' => 'tips praktis'])->assertCreated();
        $this->assertSame('tips praktis', ClipProject::firstOrFail()->instruction);

        $this->actingAs($user)->postJson('/api/autopilot/sources', ['type' => 'keyword', 'value' => 'podcast', 'settings' => ['instruction' => 'yang lucu']])->assertCreated();
        $source = VideoSource::firstOrFail();
        $this->assertSame('yang lucu', $source->settings['instruction']);

        $this->mock(YtDlpService::class)->shouldReceive('listVideos')->andReturn([['id' => 'abcdefghijk', 'title' => 'V', 'duration' => 900]]);
        $project = app(DiscoveryService::class)->run($source)[0];
        $this->assertSame('yang lucu', $project->instruction);

        // Cleared again from the editor
        $this->actingAs($user)->patchJson("/api/autopilot/sources/{$source->id}", ['settings' => ['instruction' => null]])->assertOk();
        $this->assertNull($source->fresh()->settings['instruction']);
    }

    // ── a clip cut from the transcript ────────────────────────────────────

    public function test_transcript_lists_clean_lines_for_the_owner_only(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $res = $this->actingAs($user)->getJson("/api/projects/{$project->id}/transcript")->assertOk();
        $this->assertSame('Modal usaha itu penting.', $res->json('lines.0.text'));
        $this->assertCount(4, $res->json('lines'));

        $this->actingAs(User::factory()->create())->getJson("/api/projects/{$project->id}/transcript")->assertNotFound();
    }

    public function test_picked_lines_become_a_snapped_manual_clip_that_renders_after_its_title(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $project = $this->project($user);
        GeneratedClip::create(['clip_project_id' => $project->id, 'ranking' => 4, 'start_seconds' => 100, 'end_seconds' => 130, 'topic' => 'AI', 'reason' => 'r', 'status' => 'done']);

        // Rough picks a little off the sentence edges
        $res = $this->actingAs($user)->postJson("/api/projects/{$project->id}/clips", ['start_seconds' => 10.4, 'end_seconds' => 21.5])
            ->assertCreated()->assertJsonPath('clip.manual', true)->assertJsonPath('clip.status', 'processing')->assertJsonPath('clip.ranking', 5);

        $clip = GeneratedClip::findOrFail($res->json('clip.id'));
        $this->assertTrue($clip->manual);
        $this->assertEqualsWithDelta(9.85, $clip->start_seconds, 0.01);  // a breath before "Modal"
        $this->assertEqualsWithDelta(21.15, $clip->end_seconds, 0.01);   // just after "dulu."
        $this->assertSame('Modal usaha itu penting. Jangan pinjam ke teman…', $clip->topic); // placeholder until the AI answers
        $this->assertSame('reserved', RenderUsage::where('generated_clip_id', $clip->id)->value('status'));
        Bus::assertChained([
            fn (DescribeClipJob $job) => $job->clipId === $clip->id,
            fn (RenderClipJob $job) => $job->clipId === $clip->id,
        ]);
    }

    public function test_manual_clip_can_skip_the_render(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $project = $this->project($user);

        $id = $this->actingAs($user)->postJson("/api/projects/{$project->id}/clips", ['start_seconds' => 10, 'end_seconds' => 26.8, 'render' => false])
            ->assertCreated()->assertJsonPath('clip.status', 'pending')->json('clip.id');

        Bus::assertDispatched(DescribeClipJob::class, fn ($job) => $job->clipId === $id);
        Bus::assertNotDispatched(RenderClipJob::class);
        $this->assertSame(0, RenderUsage::count());
    }

    public function test_manual_clip_respects_quota_length_and_project_state(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        $project = $this->project($user);
        $post = fn (array $body, ?ClipProject $p = null) => $this->actingAs($user)->postJson('/api/projects/'.($p ?? $project)->id.'/clips', $body);

        $post(['start_seconds' => 16, 'end_seconds' => 17.5])->assertUnprocessable();          // under 3 s
        $post(['start_seconds' => 10, 'end_seconds' => 400])->assertUnprocessable();           // over 5 min
        $post(['start_seconds' => 50, 'end_seconds' => 60])->assertUnprocessable();            // nothing said there
        $post(['start_seconds' => 20, 'end_seconds' => 10])->assertJsonValidationErrors('end_seconds');

        $running = $this->project($user, ['status' => 'processing']);
        $post(['start_seconds' => 10, 'end_seconds' => 26], $running)->assertStatus(409);

        $autopilot = $this->project($user, ['video_source_id' => VideoSource::create(['user_id' => $user->id, 'type' => 'keyword', 'value' => 'x', 'settings' => []])->id]);
        $post(['start_seconds' => 10, 'end_seconds' => 26], $autopilot)->assertNotFound();

        $this->actingAs(User::factory()->create())->postJson("/api/projects/{$project->id}/clips", ['start_seconds' => 10, 'end_seconds' => 26])->assertNotFound();

        // No render minutes left: refused, and no half-made clip is left behind
        $user->forceFill(['plan_id' => Plan::create(['name' => 'Habis', 'slug' => 'habis', 'price_usd_cents' => 0, 'render_minutes' => 0])->id])->save();
        $post(['start_seconds' => 10, 'end_seconds' => 26])->assertStatus(402);
        $this->assertSame(0, GeneratedClip::where('clip_project_id', $project->id)->count());
        Bus::assertNothingDispatched();
    }

    public function test_describe_job_fills_title_and_hook_from_the_ai(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $clip = GeneratedClip::create(['clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 9.85, 'end_seconds' => 21.15,
            'topic' => 'placeholder', 'reason' => '', 'status' => 'processing', 'manual' => true]);

        $this->mock(GeminiService::class)->shouldReceive('describeClip')
            ->withArgs(fn ($text, $lang, $instruction) => $text === 'Modal usaha itu penting. Jangan pinjam ke teman dulu.' && $lang === 'id' && $instruction === 'bagian tentang modal usaha')
            ->andReturn(['topic' => 'Modal usaha pertama', 'reason' => 'Tips jelas', 'viral_score' => '8', 'viral_reasons' => ['value', 'nonsense'],
                'viral_potential' => 'TINGGI', 'hook_text' => '"Jangan pinjam *teman*!" 🔥', 'music_mood' => 'chill']);

        $this->app->call([new DescribeClipJob($clip->id), 'handle']);

        $clip->refresh();
        $this->assertSame('Modal usaha pertama', $clip->topic);
        $this->assertSame(8, $clip->viral_score);
        $this->assertSame(['value'], $clip->viral_reasons);
        $this->assertSame('tinggi', $clip->viral_potential);
        $this->assertSame('Jangan pinjam *teman*!', $clip->hook_text);
        $this->assertSame('chill', $clip->music_mood);
        $this->assertSame('processing', $clip->status); // the chained render takes it from here
    }

    public function test_describe_job_keeps_the_placeholder_when_no_ai_answers(): void
    {
        $user = User::factory()->create();
        $clip = GeneratedClip::create(['clip_project_id' => $this->project($user)->id, 'ranking' => 1, 'start_seconds' => 10, 'end_seconds' => 21,
            'topic' => 'placeholder', 'reason' => '', 'status' => 'processing', 'manual' => true]);
        $this->mock(GeminiService::class)->shouldReceive('describeClip')->andThrow(new RuntimeException('down'));

        $this->app->call([new DescribeClipJob($clip->id), 'handle']); // must not throw: that would stop the chained render

        $this->assertSame('placeholder', $clip->fresh()->topic);
    }

    public function test_agent_api_and_mcp_can_cut_a_clip(): void
    {
        Bus::fake();
        $user = User::factory()->create(['approved_at' => now()]);
        [, $token] = ApiToken::issue($user, 'test');
        $project = $this->project($user);
        $api = $this->withHeader('Authorization', 'Bearer '.$token);

        $api->getJson("/api/v1/projects/{$project->id}/transcript")->assertOk()->assertJsonCount(4, 'lines');
        $api->postJson("/api/v1/projects/{$project->id}/clips", ['start_seconds' => 22, 'end_seconds' => 26.8])->assertCreated();
        $api->getJson("/api/v1/projects/{$project->id}/clips")->assertOk()
            ->assertJsonPath('instruction', 'bagian tentang modal usaha')->assertJsonPath('clips.0.manual', true);

        $tools = collect($api->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->json('result.tools'))->keyBy('name');
        $this->assertArrayHasKey('instruction', $tools['create_project']['inputSchema']['properties']);
        $this->assertArrayHasKey('preset_id', $tools['create_project']['inputSchema']['properties']);
        $this->assertTrue($tools->has('create_clip') && $tools->has('get_transcript') && $tools->has('list_presets'));

        $reply = $api->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
            'name' => 'create_clip', 'arguments' => ['project_id' => $project->id, 'start_seconds' => 16, 'end_seconds' => 20.8],
        ]])->json('result');
        $this->assertFalse($reply['isError']);
        $this->assertSame(2, GeneratedClip::where('clip_project_id', $project->id)->where('manual', true)->count());

        // A read-only token sees the transcript but cannot cut
        [, $read] = ApiToken::issue($user, 'read', ['read']);
        $this->withHeader('Authorization', 'Bearer '.$read)->postJson("/api/v1/projects/{$project->id}/clips", ['start_seconds' => 22, 'end_seconds' => 26.8])->assertForbidden();
    }
}

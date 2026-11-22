<?php

namespace Tests\Feature;

use App\Jobs\GenerateClipCaptionJob;
use App\Jobs\RenderClipJob;
use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\PlatformSetting;
use App\Models\TiktokAccount;
use App\Models\User;
use App\Services\ClipCaption;
use App\Services\FFmpegService;
use App\Services\GeminiService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class GenerateClipCaptionJobTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ClipProject $project;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        Storage::fake();
        $this->user = User::factory()->create();
        $this->project = ClipProject::create([
            'user_id' => $this->user->id, 'youtube_url' => 'https://youtu.be/abcdefg',
            'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
    }

    private function clip(array $attrs = []): GeneratedClip
    {
        return GeneratedClip::create($attrs + [
            'clip_project_id' => $this->project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 30,
            'topic' => 't', 'reason' => 'r', 'status' => 'done', 'review_status' => 'pending',
        ]);
    }

    private function gemini(): MockInterface
    {
        return $this->mock(GeminiService::class);
    }

    private function run_(GeneratedClip $clip): void
    {
        app()->call([new GenerateClipCaptionJob($clip->id), 'handle']);
    }

    /** Render a one-second fake clip through RenderClipJob with the encoder mocked */
    private function render(GeneratedClip $clip): void
    {
        @mkdir(storage_path('app/temp/'.$this->project->id), 0755, true);
        file_put_contents(storage_path('app/temp/'.$this->project->id.'/video.mp4'), 'src');
        $this->mock(FFmpegService::class, function (MockInterface $ffmpeg) {
            $ffmpeg->shouldReceive('applyLayout')->andReturnUsing(fn ($in, $layout, $out) => file_put_contents($out, 'laid out'));
            $ffmpeg->shouldReceive('normalizeVoice')->andReturnUsing(function ($in, $out) {
                file_put_contents($out, 'levelled');

                return true;
            });
        });

        app()->call([new RenderClipJob($clip->id), 'handle']);
    }

    public function test_a_finished_autopilot_render_queues_the_caption_on_the_default_queue(): void
    {
        Queue::fake();
        $clip = $this->clip(['status' => 'pending']);

        $this->render($clip);

        $this->assertSame('done', $clip->fresh()->status);
        Queue::assertPushedOn('default', GenerateClipCaptionJob::class, fn ($job) => $job->clipId === $clip->id);
    }

    public function test_a_studio_render_keeps_asking_for_the_caption_lazily(): void
    {
        Queue::fake();
        $clip = $this->clip(['status' => 'pending', 'review_status' => null]);

        $this->render($clip);

        $this->assertSame('done', $clip->fresh()->status);
        Queue::assertNotPushed(GenerateClipCaptionJob::class);
    }

    public function test_a_failing_caption_queue_does_not_fail_the_render(): void
    {
        $clip = $this->clip(['status' => 'pending']);
        $this->mock(Dispatcher::class)->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue down'));

        $this->render($clip);

        $this->assertSame('done', $clip->fresh()->status);
    }

    public function test_it_stores_the_caption_and_a_second_run_does_not_ask_again(): void
    {
        $clip = $this->clip();
        $this->gemini()->shouldReceive('generateCaption')->once()->andReturn(['caption' => 'Halo dunia', 'hashtags' => ['#a', '#b']]);

        $this->run_($clip);
        $this->run_($clip);

        $this->assertSame(['Halo dunia', ['#a', '#b']], [$clip->fresh()->caption, $clip->fresh()->hashtags_json]);
    }

    public function test_it_skips_clips_nobody_will_post(): void
    {
        $this->gemini()->shouldNotReceive('generateCaption');

        $this->run_($this->clip(['review_status' => 'rejected']));
        $this->run_($this->clip(['cancelled_at' => now()]));
        $this->run_($this->clip(['status' => 'processing']));
        $this->run_($this->clip(['review_status' => null]));

        $gone = $this->clip();
        $gone->delete();
        $this->run_($gone);

        $this->assertSame(0, GeneratedClip::whereNotNull('caption')->count());
    }

    public function test_an_empty_answer_is_retried_with_backoff_and_the_last_try_gives_up_quietly(): void
    {
        $clip = $this->clip();
        $this->gemini()->shouldReceive('generateCaption')->times(2)->andReturn(['caption' => '', 'hashtags' => []]);

        $job = new GenerateClipCaptionJob($clip->id);
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300], $job->backoff());

        try {
            app()->call([$job, 'handle']);
            $this->fail('the first try should throw so the queue retries');
        } catch (RuntimeException) {
        }

        $job->setJob(\Mockery::mock(Job::class, ['attempts' => 3]));
        app()->call([$job, 'handle']); // no exception: the render stays untouched, the lazy path takes over

        $this->assertNull($clip->fresh()->caption);
        $this->assertSame('done', $clip->fresh()->status);
    }

    public function test_the_lock_is_held_while_gemini_answers(): void
    {
        $clip = $this->clip();
        $this->gemini()->shouldReceive('generateCaption')->once()->andReturnUsing(function () use ($clip) {
            $this->assertFalse(Cache::lock('clip-caption:'.$clip->id, 5)->get(), 'a second caller must not start its own Gemini call');

            return ['caption' => 'Satu panggilan', 'hashtags' => []];
        });

        $this->assertSame('Satu panggilan', app(ClipCaption::class)->ensure($clip)['caption']);
    }

    public function test_a_caller_that_waited_for_the_lock_uses_the_answer_instead_of_asking_again(): void
    {
        $stale = $this->clip();
        // The job had the lock and finished writing while this caller still held a copy without the caption
        GeneratedClip::whereKey($stale->id)->update(['caption' => 'Dari job', 'hashtags_json' => json_encode(['#x'])]);
        Cache::lock('clip-caption:'.$stale->id, 1)->get();
        $this->gemini()->shouldNotReceive('generateCaption');

        $result = app(ClipCaption::class)->ensure($stale);

        $this->assertSame(['caption' => 'Dari job', 'hashtags' => ['#x']], $result);
    }

    public function test_approve_posts_the_stored_caption_without_calling_gemini(): void
    {
        $this->gemini()->shouldNotReceive('generateCaption');
        Storage::put('clips/a.mp4', 'a');
        $clip = $this->clip(['output_path' => 'clips/a.mp4', 'caption' => 'Sudah siap', 'hashtags_json' => ['#siap']]);
        $account = TiktokAccount::create([
            'user_id' => $this->user->id, 'open_id' => 'o', 'access_token' => 'x', 'refresh_token' => 'y',
            'access_expires_at' => now()->addDay(), 'scope' => 'user.info.basic,video.upload',
        ]);
        PlatformSetting::for($this->user->id, 'tiktok')->forceFill(['enabled' => true, 'account_id' => $account->id])->save();

        $this->actingAs($this->user)->postJson("/api/autopilot/review/{$clip->id}/approve", ['account_id' => $account->id])->assertOk();

        $post = $clip->scheduledPosts()->sole();
        $this->assertStringContainsString('Sudah siap', $post->options['title']);
        $this->assertStringContainsString('#siap', $post->options['title']);
    }
}

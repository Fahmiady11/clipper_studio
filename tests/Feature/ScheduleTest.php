<?php

namespace Tests\Feature;

use App\Jobs\UploadToTikTokJob;
use App\Jobs\UploadToYouTubeJob;
use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\ScheduledPost;
use App\Models\TiktokAccount;
use App\Models\User;
use App\Models\YoutubeAccount;
use App\Services\ScheduledPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    }

    private function youtube(User $user, string $channel = 'UC1'): YoutubeAccount
    {
        return YoutubeAccount::firstOrCreate(['user_id' => $user->id, 'channel_id' => $channel], [
            'title' => 'Channel', 'access_token' => 'at', 'refresh_token' => 'rt', 'access_expires_at' => now()->addHour(),
        ]);
    }

    private function tiktok(User $user, array $attrs = []): TiktokAccount
    {
        return TiktokAccount::create($attrs + [
            'user_id' => $user->id, 'open_id' => 'open-'.$user->id, 'display_name' => 'Creator',
            'access_token' => 'at', 'refresh_token' => 'rt',
            'access_expires_at' => now()->addDay(), 'refresh_expires_at' => now()->addDays(300),
        ]);
    }

    private function doneClip(User $user, float $seconds = 30): GeneratedClip
    {
        $project = ClipProject::create([
            'user_id' => $user->id, 'youtube_url' => 'https://youtu.be/abcdefg', 'video_title' => 'Podcast satu',
            'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
        Storage::put('clips/sched.mp4', str_repeat('v', 2048)); // not a real video: the length check falls back to end - start

        return GeneratedClip::create([
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => $seconds,
            'topic' => 'Momen lucu', 'reason' => 'r', 'status' => 'done', 'output_path' => 'clips/sched.mp4',
        ]);
    }

    private function scheduled(User $user, GeneratedClip $clip, array $attrs = []): ScheduledPost
    {
        return ScheduledPost::create($attrs + [
            'user_id' => $user->id, 'generated_clip_id' => $clip->id, 'platform' => 'youtube',
            'account_id' => $this->youtube($user)->id, 'scheduled_at' => now()->addHour(), 'status' => 'scheduled',
            'options' => ['title' => 'Judul', 'description' => 'Desk', 'privacy' => 'private', 'tags' => ['a']],
        ]);
    }

    public function test_schedules_a_youtube_post_and_lists_it_in_range(): void
    {
        $user = User::factory()->create();
        $account = $this->youtube($user);
        $clip = $this->doneClip($user);
        $when = now()->addDay()->startOfMinute();

        $res = $this->actingAs($user)->postJson('/api/schedule', [
            'clip_id' => $clip->id, 'platform' => 'youtube', 'account_id' => $account->id,
            'scheduled_at' => $when->toIso8601String(), 'title' => 'Judul', 'description' => 'Desk', 'privacy' => 'unlisted', 'tags' => ['podcast'],
        ])->assertCreated()->assertJsonPath('post.status', 'scheduled')->assertJsonPath('post.platform', 'youtube');

        $post = ScheduledPost::sole();
        $this->assertTrue($post->scheduled_at->equalTo($when));
        $this->assertSame(['title' => 'Judul', 'description' => 'Desk', 'privacy' => 'unlisted', 'tags' => ['podcast']], $post->options);
        $this->assertSame($post->id, $res->json('post.id'));

        $list = $this->actingAs($user)->getJson('/api/schedule?'.http_build_query([
            'from' => $when->copy()->subDay()->toIso8601String(), 'to' => $when->copy()->addDay()->toIso8601String(),
        ]))->assertOk();
        $this->assertSame(['Momen lucu'], array_column($list->json('posts'), 'clip_topic'));

        $outside = $this->actingAs($user)->getJson('/api/schedule?'.http_build_query([
            'from' => $when->copy()->addDays(2)->toIso8601String(), 'to' => $when->copy()->addDays(9)->toIso8601String(),
        ]))->assertOk();
        $this->assertSame([], $outside->json('posts'));
    }

    public function test_schedules_a_tiktok_draft_and_a_direct_post(): void
    {
        $user = User::factory()->create();
        $account = $this->tiktok($user);
        $clip = $this->doneClip($user);

        $this->actingAs($user)->postJson('/api/schedule', [
            'clip_id' => $clip->id, 'platform' => 'tiktok', 'account_id' => $account->id,
            'scheduled_at' => now()->addHours(3)->toIso8601String(), 'mode' => 'direct', 'title' => 'Caption', 'allow_comment' => true,
            'privacy_level' => 'PUBLIC_TO_EVERYONE', 'allow_stitch' => true, 'brand_organic_toggle' => true,
        ])->assertCreated();

        $this->assertSame([
            'mode' => 'direct', 'title' => 'Caption', 'allow_comment' => true, 'allow_duet' => false, 'allow_stitch' => true,
            'privacy_level' => 'PUBLIC_TO_EVERYONE', 'brand_organic_toggle' => true, 'brand_content_toggle' => false,
        ], ScheduledPost::sole()->options);
    }

    public function test_a_tiktok_draft_stores_no_direct_post_choices(): void
    {
        $user = User::factory()->create();
        $account = $this->tiktok($user);
        $clip = $this->doneClip($user);

        $this->actingAs($user)->postJson('/api/schedule', [
            'clip_id' => $clip->id, 'platform' => 'tiktok', 'account_id' => $account->id,
            'scheduled_at' => now()->addHours(3)->toIso8601String(), 'mode' => 'inbox', 'privacy_level' => 'PUBLIC_TO_EVERYONE',
        ])->assertCreated();

        $this->assertSame(['mode' => 'inbox', 'title' => '', 'allow_comment' => false, 'allow_duet' => false, 'allow_stitch' => false], ScheduledPost::sole()->options);
    }

    public function test_direct_tiktok_schedule_validates_privacy_and_branded_content(): void
    {
        $user = User::factory()->create();
        $account = $this->tiktok($user);
        $clip = $this->doneClip($user);
        $base = ['clip_id' => $clip->id, 'platform' => 'tiktok', 'account_id' => $account->id, 'scheduled_at' => now()->addHours(3)->toIso8601String(), 'mode' => 'direct'];

        // TikTok wants the audience picked by the user: no privacy, no post
        $this->actingAs($user)->postJson('/api/schedule', $base)->assertUnprocessable()->assertJsonValidationErrors('privacy_level');
        $this->actingAs($user)->postJson('/api/schedule', $base + ['privacy_level' => 'EVERYONE'])->assertJsonValidationErrors('privacy_level');
        $this->actingAs($user)->postJson('/api/schedule', $base + ['privacy_level' => 'SELF_ONLY', 'brand_content_toggle' => true])
            ->assertJsonValidationErrors(['privacy_level' => 'Hanya saya']);
        $this->actingAs($user)->postJson('/api/schedule', $base + ['privacy_level' => 'PUBLIC_TO_EVERYONE', 'allow_duet' => 'maybe'])
            ->assertJsonValidationErrors('allow_duet');
        $this->assertSame(0, ScheduledPost::count());

        // Someone else's account is never usable
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->postJson('/api/schedule', ['account_id' => $this->tiktok($stranger)->id, 'privacy_level' => 'PUBLIC_TO_EVERYONE'] + $base)->assertForbidden();

        // Direct posting needs video.publish
        $uploadOnly = $this->tiktok($user, ['open_id' => 'open-upload', 'scope' => 'user.info.basic,video.upload']);
        $this->actingAs($user)->postJson('/api/schedule', ['account_id' => $uploadOnly->id, 'privacy_level' => 'PUBLIC_TO_EVERYONE'] + $base)
            ->assertUnprocessable()->assertJsonPath('message', TiktokAccount::scopeMessage('video.publish'));

        $this->actingAs($user)->postJson('/api/schedule', $base + ['privacy_level' => 'FOLLOWER_OF_CREATOR', 'brand_content_toggle' => true])->assertCreated();
        $this->assertSame('FOLLOWER_OF_CREATOR', ScheduledPost::sole()->options['privacy_level']);
    }

    public function test_refuses_bad_times_other_users_and_double_booking(): void
    {
        $user = User::factory()->create();
        $account = $this->youtube($user);
        $clip = $this->doneClip($user);
        $base = ['clip_id' => $clip->id, 'platform' => 'youtube', 'account_id' => $account->id, 'title' => 'x', 'privacy' => 'public'];

        $this->actingAs($user)->postJson('/api/schedule', $base + ['scheduled_at' => now()->subHour()->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');
        $this->actingAs($user)->postJson('/api/schedule', $base + ['scheduled_at' => now()->addSeconds(30)->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');
        $this->actingAs($user)->postJson('/api/schedule', $base + ['scheduled_at' => now()->addYears(2)->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors('scheduled_at');
        $this->actingAs($user)->postJson('/api/schedule', ['title' => 'a <b>', 'scheduled_at' => now()->addDay()->toIso8601String()] + $base)
            ->assertUnprocessable()->assertJsonValidationErrors('title');

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->postJson('/api/schedule', ['account_id' => $this->youtube($stranger, 'UC9')->id, 'scheduled_at' => now()->addDay()->toIso8601String()] + $base)
            ->assertForbidden();
        $this->actingAs($user)->postJson('/api/schedule', ['account_id' => $this->youtube($stranger, 'UC8')->id, 'scheduled_at' => now()->addDay()->toIso8601String()] + $base)
            ->assertNotFound();

        $ok = $base + ['scheduled_at' => now()->addDay()->toIso8601String()];
        $this->actingAs($user)->postJson('/api/schedule', $ok)->assertCreated();
        $this->actingAs($user)->postJson('/api/schedule', $ok)->assertUnprocessable()->assertJsonValidationErrors('clip_id');
        $this->assertSame(1, ScheduledPost::count());
    }

    public function test_refuses_youtube_clips_over_three_minutes(): void
    {
        $user = User::factory()->create();
        $clip = $this->doneClip($user, 200);

        $this->actingAs($user)->postJson('/api/schedule', [
            'clip_id' => $clip->id, 'platform' => 'youtube', 'account_id' => $this->youtube($user)->id,
            'scheduled_at' => now()->addDay()->toIso8601String(), 'title' => 'x',
        ])->assertUnprocessable()->assertJsonFragment(['message' => 'YouTube Shorts maksimal 3 menit, clip ini 200 detik. Pendekkan clip dulu.']);
    }

    public function test_reschedules_cancels_and_revives_failed_posts_but_not_handed_off_ones(): void
    {
        $user = User::factory()->create();
        $clip = $this->doneClip($user);
        $waiting = $this->scheduled($user, $clip);
        $new = now()->addDays(2)->startOfMinute();

        $this->actingAs($user)->patchJson("/api/schedule/{$waiting->id}", ['scheduled_at' => $new->toIso8601String()])->assertOk();
        $this->assertTrue($waiting->fresh()->scheduled_at->equalTo($new));

        $failed = $this->scheduled($user, $clip, ['status' => 'failed', 'error' => 'boom', 'platform' => 'tiktok']);
        $this->actingAs($user)->patchJson("/api/schedule/{$failed->id}", ['scheduled_at' => $new->toIso8601String()])->assertOk();
        $this->assertSame(['scheduled', null], [$failed->fresh()->status, $failed->fresh()->error]);

        $sent = $this->scheduled($user, $clip, ['status' => 'queued']);
        $this->actingAs($user)->patchJson("/api/schedule/{$sent->id}", ['scheduled_at' => $new->toIso8601String()])->assertUnprocessable();
        $this->actingAs($user)->deleteJson("/api/schedule/{$sent->id}")->assertUnprocessable();

        $this->actingAs($user)->deleteJson("/api/schedule/{$waiting->id}")->assertOk();
        $this->assertNull(ScheduledPost::find($waiting->id));

        $this->actingAs(User::factory()->create())->deleteJson("/api/schedule/{$failed->id}")->assertNotFound();
    }

    public function test_clip_list_has_my_rendered_clips_only(): void
    {
        $me = User::factory()->create();
        $mine = $this->doneClip($me);
        $this->doneClip(User::factory()->create());
        $rejected = $this->doneClip($me);
        $rejected->update(['review_status' => 'rejected']);

        $res = $this->actingAs($me)->getJson('/api/schedule/clips')->assertOk();
        $this->assertSame([$mine->id], array_column($res->json('clips'), 'id'));
    }

    public function test_due_youtube_post_is_handed_to_the_upload_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $clip = $this->doneClip($user);
        $due = $this->scheduled($user, $clip, ['scheduled_at' => now()->subMinute()]);

        $this->assertSame(1, app(ScheduledPublisher::class)->publishDue());

        Queue::assertPushed(UploadToYouTubeJob::class, fn ($job) => $job->clipId === $clip->id
            && $job->accountId === $due->account_id
            && $job->meta === ['title' => 'Judul', 'description' => 'Desk', 'privacy' => 'private', 'tags' => ['a']]);
        $this->assertSame('queued', $clip->fresh()->youtube_status);
        $this->assertSame('queued', $due->fresh()->status);
        $this->assertNotNull($due->fresh()->handed_off_at);

        // A second tick must not post it again
        $this->assertSame(0, app(ScheduledPublisher::class)->publishDue());
        Queue::assertPushed(UploadToYouTubeJob::class, 1);
    }

    public function test_due_direct_post_goes_out_with_the_chosen_post_info(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $account = $this->tiktok($user, ['scope' => 'user.info.basic,video.upload,video.publish']);
        $clip = $this->doneClip($user);
        $this->scheduled($user, $clip, [
            'platform' => 'tiktok', 'account_id' => $account->id, 'scheduled_at' => now()->subMinute(),
            'options' => [
                'mode' => 'direct', 'title' => 'Cap #fyp', 'privacy_level' => 'MUTUAL_FOLLOW_FRIENDS',
                'allow_comment' => true, 'allow_duet' => false, 'allow_stitch' => true,
                'brand_organic_toggle' => false, 'brand_content_toggle' => true,
            ],
        ]);

        $this->assertSame(1, app(ScheduledPublisher::class)->publishDue());

        Queue::assertPushed(UploadToTikTokJob::class, fn ($job) => $job->clipId === $clip->id && $job->accountId === $account->id
            && $job->mode === 'direct' && $job->postInfo === [
                'title' => 'Cap #fyp', 'privacy_level' => 'MUTUAL_FOLLOW_FRIENDS', 'disable_comment' => false, 'disable_duet' => true,
                'disable_stitch' => false, 'brand_organic_toggle' => false, 'brand_content_toggle' => true,
            ]);
    }

    public function test_due_tiktok_post_booked_without_privacy_stays_private(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $account = $this->tiktok($user);
        $clip = $this->doneClip($user);
        $this->scheduled($user, $clip, [
            'platform' => 'tiktok', 'account_id' => $account->id, 'scheduled_at' => now()->subMinute(),
            'options' => ['mode' => 'direct', 'title' => 'Cap', 'allow_comment' => true],
        ]);

        app(ScheduledPublisher::class)->publishDue();

        Queue::assertPushed(UploadToTikTokJob::class, fn ($job) => $job->mode === 'direct'
            && $job->postInfo['privacy_level'] === 'SELF_ONLY'
            && $job->postInfo['title'] === 'Cap'
            && $job->postInfo['disable_comment'] === false
            && $job->postInfo['disable_duet'] === true);
        $this->assertSame('queued', $clip->fresh()->tiktok_status);
    }

    public function test_posts_not_yet_due_or_of_unapproved_users_are_left_alone(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $clip = $this->doneClip($user);
        $future = $this->scheduled($user, $clip, ['scheduled_at' => now()->addMinutes(10)]);

        $revoked = User::factory()->pending()->create();
        $revokedPost = $this->scheduled($revoked, $this->doneClip($revoked), ['scheduled_at' => now()->subMinute()]);

        $this->assertSame(0, app(ScheduledPublisher::class)->publishDue());
        Queue::assertNothingPushed();
        $this->assertSame(['scheduled', 'scheduled'], [$future->fresh()->status, $revokedPost->fresh()->status]);
    }

    public function test_hand_off_failures_are_recorded_for_the_user(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $gone = $this->doneClip($user);
        $gone->update(['output_path' => null]);
        $noFile = $this->scheduled($user, $gone, ['scheduled_at' => now()->subMinute()]);

        $busy = $this->doneClip($user);
        $busy->update(['youtube_status' => 'uploading']);
        $busyPost = $this->scheduled($user, $busy, ['scheduled_at' => now()->subMinute()]);

        $noAccount = $this->scheduled($user, $this->doneClip($user), ['scheduled_at' => now()->subMinute(), 'account_id' => 9999]);

        app(ScheduledPublisher::class)->publishDue();

        Queue::assertNothingPushed();
        foreach ([$noFile, $busyPost, $noAccount] as $p) {
            $this->assertSame('failed', $p->fresh()->status);
            $this->assertNotEmpty($p->fresh()->error);
        }
        $this->assertStringContainsString('sedang diupload', $busyPost->fresh()->error);
    }

    public function test_project_history_flags_waiting_posts(): void
    {
        $user = User::factory()->create();
        $clip = $this->doneClip($user);
        $soon = now()->addHours(2)->startOfSecond();
        $this->scheduled($user, $clip, ['scheduled_at' => $soon->copy()->addDay()]);
        $this->scheduled($user, $clip, ['scheduled_at' => $soon, 'platform' => 'tiktok']);
        $this->scheduled($user, $clip, ['status' => 'queued']); // already sent: not "waiting"

        $row = $this->actingAs($user)->getJson('/api/projects')->assertOk()->json('0');

        $this->assertSame(2, $row['scheduled_count']);
        $this->assertSame($soon->toISOString(), $row['next_scheduled_at']);
    }

    public function test_calendar_page_and_api_need_a_signed_in_approved_user(): void
    {
        $this->get('/calendar')->assertRedirect('/login');
        $this->getJson('/api/schedule?from=2026-11-01&to=2026-11-30')->assertUnauthorized();

        $this->actingAs(User::factory()->create())->get('/calendar')->assertOk()->assertSee('Jadwal publish');
    }
}

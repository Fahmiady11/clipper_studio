<?php

namespace Tests\Feature;

use App\Jobs\UploadToInstagramJob;
use App\Models\User;
use App\Services\Social\InstagramPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\MakesInstagramFixtures;
use Tests\TestCase;
use Throwable;

class InstagramUploadJobTest extends TestCase
{
    use MakesInstagramFixtures, RefreshDatabase;

    private const MEDIA = 'graph.instagram.com/v21.0/1784/media';

    private const PUBLISH = 'graph.instagram.com/v21.0/1784/media_publish';

    private const CONTAINER = 'graph.instagram.com/v21.0/17900*';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureInstagram();
        Sleep::fake();
    }

    /** Run the job the way a worker would: handle, and on an exception call failed(). */
    private function work(UploadToInstagramJob $job): ?Throwable
    {
        try {
            $job->handle(app(InstagramPublisher::class));

            return null;
        } catch (Throwable $e) {
            $job->failed($e);

            return $e;
        }
    }

    private function job(User $user, array $clipAttrs = [], array $accountAttrs = []): array
    {
        $account = $this->igAccount($user, $accountAttrs);
        $clip = $this->doneClip($user, $clipAttrs);

        return [new UploadToInstagramJob($clip->id, $account->id, ['caption' => 'Halo #klip', 'share_to_feed' => true]), $clip, $account];
    }

    /**
     * Instagram answering every step of a successful upload; the container reports the given
     * states one look at a time. $onLook runs at each look (to observe the clip mid-upload).
     * $overrides replace single steps: Http::fake uses the first stub that matches, so they go first.
     */
    private function fakeHappyPath(array $containerStates = ['FINISHED'], ?callable $onLook = null, array $overrides = []): void
    {
        $looks = 0;
        Http::fake($overrides + [
            'graph.instagram.com/v21.0/1784/content_publishing_limit*' => Http::response(['data' => [['quota_usage' => 3, 'config' => ['quota_total' => 100]]]]),
            self::MEDIA => Http::response(['id' => '17900', 'uri' => 'https://rupload.facebook.com/ig-api-upload/v21.0/17900']),
            'rupload.facebook.com/*' => Http::response(['success' => true]),
            self::CONTAINER => function () use ($containerStates, $onLook, &$looks) {
                $onLook && $onLook();
                $code = $containerStates[min($looks++, count($containerStates) - 1)];

                return Http::response(['id' => '17900', 'status_code' => $code, 'status' => $code === 'ERROR' ? 'Error: video codec not supported' : '']);
            },
            self::PUBLISH => Http::response(['id' => '18123']),
            'graph.instagram.com/v21.0/18123*' => Http::response(['permalink' => 'https://www.instagram.com/reel/C1abc/', 'id' => '18123']),
        ]);
    }

    public function test_a_clip_is_uploaded_processed_and_published_as_a_reel(): void
    {
        [$job, $clip] = $this->job(User::factory()->create());
        $this->fakeHappyPath(['IN_PROGRESS', 'IN_PROGRESS', 'FINISHED']);

        $this->assertNull($this->work($job));

        $clip->refresh();
        $this->assertSame('published', $clip->instagram_status);
        $this->assertSame('18123', $clip->instagram_media_id);
        $this->assertSame('https://www.instagram.com/reel/C1abc/', $clip->instagram_url);
        $this->assertNull($clip->instagram_error);

        // The container is a resumable REELS one carrying the caption
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/1784/media')
            && $r['media_type'] === 'REELS' && $r['upload_type'] === 'resumable'
            && $r['caption'] === 'Halo #klip' && $r['share_to_feed'] === 'true'
            && $r->hasHeader('Authorization', 'Bearer ig-token'));
        // The file goes to the upload URL with the size and the OAuth header Instagram expects
        Http::assertSent(fn (Request $r) => $r->url() === 'https://rupload.facebook.com/ig-api-upload/v21.0/17900'
            && $r->hasHeader('Authorization', 'OAuth ig-token') && $r->hasHeader('offset', '0')
            && $r->hasHeader('file_size', '2048') && strlen($r->body()) === 2048);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/1784/media_publish') && $r['creation_id'] === '17900');
    }

    public function test_it_waits_between_looks_at_the_container_with_growing_pauses(): void
    {
        [$job] = $this->job(User::factory()->create());
        $this->fakeHappyPath(['IN_PROGRESS', 'IN_PROGRESS', 'FINISHED']);

        $this->work($job);

        Sleep::assertSequence([Sleep::for(3)->seconds(), Sleep::for(5)->seconds(), Sleep::for(8)->seconds()]);
    }

    public function test_status_is_processing_while_instagram_works_and_published_at_the_end(): void
    {
        [$job, $clip] = $this->job(User::factory()->create());
        $seen = [];
        $this->fakeHappyPath(['FINISHED'], function () use ($clip, &$seen) {
            $seen[] = $clip->fresh()->instagram_status;
        });

        $this->work($job);

        $this->assertSame(['processing'], $seen);
        $this->assertSame('published', $clip->fresh()->instagram_status);
    }

    public function test_an_error_from_instagram_fails_the_clip_with_its_reason_and_never_publishes(): void
    {
        [$job, $clip] = $this->job(User::factory()->create());
        $this->fakeHappyPath(['IN_PROGRESS', 'ERROR']);

        $e = $this->work($job);

        $this->assertStringContainsString('Instagram menolak video ini: Error: video codec not supported', $e->getMessage());
        $clip->refresh();
        $this->assertSame('failed', $clip->instagram_status);
        $this->assertStringContainsString('video codec not supported', $clip->instagram_error);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/media_publish'));
    }

    public function test_a_container_that_never_finishes_fails_after_the_last_look(): void
    {
        [$job, $clip] = $this->job(User::factory()->create());
        Http::fake([
            'graph.instagram.com/v21.0/1784/content_publishing_limit*' => Http::response(['data' => []]),
            self::MEDIA => Http::response(['id' => '17900', 'uri' => 'https://rupload.facebook.com/ig-api-upload/v21.0/17900']),
            'rupload.facebook.com/*' => Http::response(['success' => true]),
            self::CONTAINER => Http::response(['status_code' => 'IN_PROGRESS']),
        ]);

        $e = $this->work($job);

        $this->assertStringContainsString('belum selesai memproses', $e->getMessage());
        $this->assertSame('failed', $clip->fresh()->instagram_status);
        Sleep::assertSleptTimes(count(UploadToInstagramJob::POLL_PAUSES));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/media_publish'));
    }

    public function test_an_account_that_used_up_its_24_hour_allowance_is_stopped_before_anything_is_uploaded(): void
    {
        [$job, $clip] = $this->job(User::factory()->create());
        Http::fake([
            'graph.instagram.com/v21.0/1784/content_publishing_limit*' => Http::response(['data' => [['quota_usage' => 100, 'config' => ['quota_total' => 100]]]]),
        ]);

        $e = $this->work($job);

        $this->assertStringContainsString('Batas posting Instagram per 24 jam', $e->getMessage());
        $this->assertSame('failed', $clip->fresh()->instagram_status);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/media'));
    }

    public function test_an_unreadable_allowance_does_not_block_the_upload(): void
    {
        [$job, $clip] = $this->job(User::factory()->create());
        $this->fakeHappyPath(overrides: ['graph.instagram.com/v21.0/1784/content_publishing_limit*' => Http::response(['error' => ['message' => 'nope']], 400)]);

        $this->assertNull($this->work($job));
        $this->assertSame('published', $clip->fresh()->instagram_status);
    }

    public function test_a_busy_instagram_is_retried_with_growing_pauses_before_giving_up_on_a_call(): void
    {
        [$job, $clip] = $this->job(User::factory()->create());
        $this->fakeHappyPath(overrides: [
            self::MEDIA => Http::sequence()
                ->push(['error' => ['message' => 'Service unavailable']], 503)
                ->push(['error' => ['message' => 'Rate limited']], 429)
                ->push(['id' => '17900', 'uri' => 'https://rupload.facebook.com/ig-api-upload/v21.0/17900']),
        ]);

        $this->assertNull($this->work($job));

        $this->assertSame('published', $clip->fresh()->instagram_status);
        Http::assertSentCount(8); // limit, media x3, upload, container, publish, permalink
        // Two retry pauses from RETRY_PAUSES_MS (1 s, 3 s), then the job's first look at the container
        Sleep::assertSequence([Sleep::usleep(1_000_000), Sleep::usleep(3_000_000), Sleep::for(3)->seconds()]);
    }

    public function test_a_call_that_keeps_failing_ends_the_upload_with_instagrams_message(): void
    {
        [$job, $clip] = $this->job(User::factory()->create());
        $this->fakeHappyPath(overrides: [self::MEDIA => Http::response(['error' => ['message' => 'Service unavailable']], 503)]);

        $e = $this->work($job);

        $this->assertStringContainsString('Service unavailable', $e->getMessage());
        $this->assertSame('failed', $clip->fresh()->instagram_status);
        Http::assertSentCount(1 + 4); // the limit check, then the first try and 3 retries of the container
    }

    public function test_an_unreachable_instagram_is_reported_without_curl_noise(): void
    {
        [$job, $clip] = $this->job(User::factory()->create());
        Http::fake(fn () => throw new ConnectionException('cURL error 56: CONNECT tunnel failed, response 403'));

        $this->work($job);

        $this->assertSame('Tidak bisa terhubung ke Instagram. Coba lagi beberapa menit lagi.', $clip->fresh()->instagram_error);
    }

    public function test_a_client_error_is_not_retried(): void
    {
        [$job] = $this->job(User::factory()->create());
        $this->fakeHappyPath(overrides: [self::MEDIA => Http::response(['error' => ['message' => 'Invalid parameter', 'error_user_msg' => 'Video terlalu panjang']], 400)]);

        $e = $this->work($job);

        $this->assertStringContainsString('Video terlalu panjang', $e->getMessage());
        Http::assertSentCount(2); // the limit check and a single container call
    }

    public function test_publishing_is_never_retried_because_the_reel_may_already_be_live(): void
    {
        [$job, $clip] = $this->job(User::factory()->create());
        $this->fakeHappyPath(overrides: [self::PUBLISH => Http::response(['error' => ['message' => 'Internal error']], 500)]);

        $e = $this->work($job);

        $this->assertStringContainsString('Internal error', $e->getMessage());
        $this->assertSame('failed', $clip->fresh()->instagram_status);
        $publishCalls = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/media_publish'));
        $this->assertCount(1, $publishCalls);
    }

    public function test_a_token_in_its_last_week_is_renewed_before_use(): void
    {
        [$job, $clip, $account] = $this->job(User::factory()->create(), accountAttrs: ['access_expires_at' => now()->addDays(3)]);
        $this->fakeHappyPath(overrides: ['graph.instagram.com/refresh_access_token*' => Http::response(['access_token' => 'renewed', 'expires_in' => 5183944])]);

        $this->assertNull($this->work($job));

        $account->refresh();
        $this->assertSame('renewed', $account->access_token);
        $this->assertTrue($account->access_expires_at->isAfter(now()->addDays(50)));
        Http::assertSent(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/media') && $r->hasHeader('Authorization', 'Bearer renewed'));
    }

    public function test_an_expired_token_asks_the_user_to_reconnect(): void
    {
        [$job, $clip] = $this->job(User::factory()->create(), accountAttrs: ['access_expires_at' => now()->subDay()]);
        Http::fake();

        $e = $this->work($job);

        $this->assertStringContainsString('Hubungkan ulang akun Instagram', $e->getMessage());
        $this->assertStringContainsString('Hubungkan ulang', $clip->fresh()->instagram_error);
        Http::assertNothingSent();
    }

    public function test_an_account_can_only_post_its_owners_clips(): void
    {
        $owner = User::factory()->create();
        $clip = $this->doneClip($owner);
        $intruder = $this->igAccount(User::factory()->create());
        Http::fake();

        $e = $this->work(new UploadToInstagramJob($clip->id, $intruder->id, ['caption' => '', 'share_to_feed' => true]));

        $this->assertSame('Akun Instagram bukan milik pemilik clip.', $e->getMessage());
        Http::assertNothingSent();
    }

    public function test_a_clip_that_was_rejected_or_re_rendered_since_dispatch_is_not_uploaded(): void
    {
        [$job, $clip] = $this->job(User::factory()->create(), ['status' => 'processing']);
        Http::fake();

        $e = $this->work($job);

        $this->assertStringContainsString('File clip tidak tersedia', $e->getMessage());
        Http::assertNothingSent();
    }

    public function test_a_job_is_never_retried_by_the_queue(): void
    {
        $this->assertSame(1, (new UploadToInstagramJob('c', 1, []))->tries);
    }
}

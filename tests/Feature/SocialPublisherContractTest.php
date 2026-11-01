<?php

namespace Tests\Feature;

use App\Jobs\UploadToInstagramJob;
use App\Jobs\UploadToTikTokJob;
use App\Jobs\UploadToYouTubeJob;
use App\Models\TiktokAccount;
use App\Models\User;
use App\Models\YoutubeAccount;
use App\Services\Social\InstagramPublisher;
use App\Services\Social\PublishRefused;
use App\Services\Social\SocialPublisher;
use App\Services\Social\SocialPublishers;
use App\Services\Social\TikTokPublisher;
use App\Services\Social\YouTubePublisher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\MakesInstagramFixtures;
use Tests\TestCase;

/** The same expectations for every platform that clips can be published to. */
class SocialPublisherContractTest extends TestCase
{
    use MakesInstagramFixtures, RefreshDatabase;

    private const CREDENTIALS = [
        'tiktok' => ['services.tiktok.client_key' => 'k', 'services.tiktok.client_secret' => 's', 'services.tiktok.redirect_uri' => 'https://example.test/tiktok/callback'],
        'youtube' => ['services.youtube.client_id' => 'k', 'services.youtube.client_secret' => 's', 'services.youtube.redirect_uri' => 'https://example.test/youtube/callback'],
        'instagram' => ['services.instagram.app_id' => 'k', 'services.instagram.app_secret' => 's', 'services.instagram.redirect_uri' => 'https://example.test/instagram/callback'],
    ];

    private const CONSENT_HOST = [
        'tiktok' => 'www.tiktok.com',
        'youtube' => 'accounts.google.com',
        'instagram' => 'www.instagram.com',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    }

    public static function platforms(): array
    {
        return [['tiktok'], ['youtube'], ['instagram']];
    }

    private function publisher(string $platform): SocialPublisher
    {
        return app(SocialPublishers::class)->get($platform);
    }

    private function connect(string $platform, User $user, string $name): int
    {
        return match ($platform) {
            'tiktok' => TiktokAccount::create(['user_id' => $user->id, 'open_id' => 'o'.$user->id, 'display_name' => $name, 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addDay()])->id,
            'youtube' => YoutubeAccount::create(['user_id' => $user->id, 'channel_id' => 'UC'.$user->id, 'title' => $name, 'access_token' => 'a', 'refresh_token' => 'r', 'access_expires_at' => now()->addHour()])->id,
            'instagram' => $this->igAccount($user, ['display_name' => $name, 'external_id' => 'i'.$user->id])->id,
        };
    }

    public function test_the_registry_knows_all_three_platforms(): void
    {
        $all = app(SocialPublishers::class)->all();

        $this->assertSame(['tiktok', 'instagram', 'youtube'], $all->keys()->all());
        $this->assertContainsOnlyInstancesOf(SocialPublisher::class, $all);
        $this->assertInstanceOf(TikTokPublisher::class, $all['tiktok']);
        $this->assertInstanceOf(InstagramPublisher::class, $all['instagram']);
        $this->assertInstanceOf(YouTubePublisher::class, $all['youtube']);
    }

    public function test_an_unknown_platform_is_a_404(): void
    {
        $this->expectException(HttpException::class);

        app(SocialPublishers::class)->get('myspace');
    }

    #[DataProvider('platforms')]
    public function test_a_platform_is_configured_only_when_its_credentials_are_set(string $platform): void
    {
        config(array_map(fn () => null, self::CREDENTIALS[$platform]));
        $this->assertFalse($this->publisher($platform)->isConfigured());

        config(self::CREDENTIALS[$platform]);
        $this->assertTrue($this->publisher($platform)->isConfigured());
    }

    #[DataProvider('platforms')]
    public function test_the_consent_url_points_at_the_provider_and_carries_the_state(string $platform): void
    {
        config(self::CREDENTIALS[$platform]);

        $url = $this->publisher($platform)->authorizeUrl('state-123');

        $this->assertSame(self::CONSENT_HOST[$platform], parse_url($url, PHP_URL_HOST));
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('state-123', $query['state']);
        $this->assertSame(self::CREDENTIALS[$platform][array_key_last(self::CREDENTIALS[$platform])], $query['redirect_uri']);
    }

    #[DataProvider('platforms')]
    public function test_connect_url_is_a_route_in_this_app_and_label_is_readable(string $platform): void
    {
        $publisher = $this->publisher($platform);

        $this->assertStringStartsWith(url('/'), $publisher->connectUrl());
        $this->assertStringEndsWith("/{$platform}/connect", $publisher->connectUrl());
        $this->assertNotSame('', $publisher->label());
    }

    #[DataProvider('platforms')]
    public function test_accounts_are_listed_by_name_and_only_for_their_owner(string $platform): void
    {
        $mine = User::factory()->create();
        $other = User::factory()->create();
        $id = $this->connect($platform, $mine, 'Akun Saya');
        $this->connect($platform, $other, 'Akun Orang');

        $accounts = $this->publisher($platform)->accounts($mine->id);

        $this->assertCount(1, $accounts);
        $this->assertSame($id, $accounts[0]->id);
        $this->assertSame('Akun Saya', $accounts[0]->name);
        $this->assertSame($platform, $accounts[0]->platform);
        $this->assertSame([], $this->publisher($platform)->accounts(User::factory()->create()->id)->all());
    }

    #[DataProvider('platforms')]
    public function test_disconnect_removes_the_owners_account_and_refuses_anyone_elses(string $platform): void
    {
        $mine = User::factory()->create();
        $other = User::factory()->create();
        $id = $this->connect($platform, $mine, 'Akun Saya');

        try {
            $this->publisher($platform)->disconnect($other->id, $id);
            $this->fail('another user disconnected the account');
        } catch (ModelNotFoundException) {
            $this->assertCount(1, $this->publisher($platform)->accounts($mine->id));
        }

        $this->publisher($platform)->disconnect($mine->id, $id);
        $this->assertCount(0, $this->publisher($platform)->accounts($mine->id));
    }

    #[DataProvider('platforms')]
    public function test_a_clip_that_was_never_sent_has_no_status(string $platform): void
    {
        $clip = $this->doneClip(User::factory()->create());

        $status = $this->publisher($platform)->status($clip);

        $this->assertNull($status->status);
        $this->assertNull($status->error);
        $this->assertFalse($status->isBusy());
    }

    public function test_publishing_marks_the_clip_queued_and_queues_the_platforms_own_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $clip = $this->doneClip($user);

        $tiktok = $this->connect('tiktok', $user, 'tt');
        $this->publisher('tiktok')->publish($clip, $tiktok, 'inbox');
        Queue::assertPushed(UploadToTikTokJob::class, fn ($j) => $j->accountId === $tiktok && $j->mode === 'inbox');
        $this->assertSame('queued', $this->publisher('tiktok')->status($clip->fresh())->status);

        $youtube = $this->connect('youtube', $user, 'yt');
        $this->publisher('youtube')->publish($clip, $youtube, 'default', ['title' => 'T', 'description' => '', 'privacy' => 'public']);
        Queue::assertPushed(UploadToYouTubeJob::class, fn ($j) => $j->accountId === $youtube && $j->meta['title'] === 'T');
        $this->assertSame('queued', $this->publisher('youtube')->status($clip->fresh())->status);

        $instagram = $this->connect('instagram', $user, 'ig');
        $this->publisher('instagram')->publish($clip, $instagram, 'reels', ['caption' => 'c']);
        Queue::assertPushed(UploadToInstagramJob::class, fn ($j) => $j->accountId === $instagram);
        $this->assertSame('queued', $this->publisher('instagram')->status($clip->fresh())->status);
    }

    public function test_each_platform_keeps_its_own_status_for_the_same_clip(): void
    {
        $clip = $this->doneClip(User::factory()->create(), [
            'tiktok_status' => 'inbox',
            'youtube_status' => 'processing', 'youtube_video_id' => 'vid1',
            'instagram_status' => 'failed', 'instagram_error' => 'Instagram menolak video ini: format.',
        ]);

        $tiktok = $this->publisher('tiktok')->status($clip);
        $youtube = $this->publisher('youtube')->status($clip);
        $instagram = $this->publisher('instagram')->status($clip);

        $this->assertSame('inbox', $tiktok->status);
        $this->assertFalse($tiktok->isBusy());
        $this->assertSame('processing', $youtube->status);
        $this->assertTrue($youtube->isBusy());
        $this->assertSame('https://youtube.com/shorts/vid1', $youtube->url);
        $this->assertSame('failed', $instagram->status);
        $this->assertSame('Instagram menolak video ini: format.', $instagram->error);
    }

    public function test_instagram_refuses_a_clip_that_is_not_rendered_yet(): void
    {
        $user = User::factory()->create();
        $account = $this->connect('instagram', $user, 'ig');
        $clip = $this->doneClip($user, ['status' => 'processing']);

        $this->expectException(PublishRefused::class);

        $this->publisher('instagram')->publish($clip, $account);
    }
}

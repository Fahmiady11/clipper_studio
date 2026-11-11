<?php

namespace Tests\Feature;

use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\MusicTrack;
use App\Models\PlatformSetting;
use App\Models\ScheduledPost;
use App\Models\TiktokAccount;
use App\Models\User;
use App\Models\VideoSource;
use App\Models\YoutubeAccount;
use App\Services\Autopost;
use App\Support\CaptionCredit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SourceCreditTest extends TestCase
{
    use RefreshDatabase;

    private const CREDIT = "Sumber: Podcast Bisnis\nhttps://youtu.be/abcdefg";

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        Storage::fake();
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', 'Asia/Jakarta'));
    }

    private function clip(User $user, ?string $channel = 'Podcast Bisnis', array $attrs = []): GeneratedClip
    {
        $source = VideoSource::create([
            'user_id' => $user->id, 'type' => 'channel', 'value' => '@chan',
            'max_per_run' => 1, 'min_video_seconds' => 300, 'max_video_seconds' => 7200, 'settings' => [],
        ]);
        $project = ClipProject::create([
            'user_id' => $user->id, 'video_source_id' => $source->id, 'youtube_url' => 'https://youtu.be/abcdefg',
            'video_title' => 'Podcast', 'source_channel' => $channel, 'layout_type' => 'reframe',
            'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
        $path = 'clips/'.uniqid().'.mp4';
        Storage::put($path, 'video');

        return GeneratedClip::create($attrs + [
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 40,
            'topic' => 'Rahasia bisnis kecil', 'reason' => 'r', 'status' => 'done', 'review_status' => 'pending',
            'output_path' => $path, 'caption' => 'Dengar ini sampai habis.', 'hashtags_json' => ['#bisnis', '#umkm'],
        ]);
    }

    private function youtubeOn(User $user): void
    {
        $account = YoutubeAccount::create([
            'user_id' => $user->id, 'channel_id' => 'UC'.$user->id, 'title' => 'Channel Saya',
            'access_token' => 'at', 'refresh_token' => 'rt', 'access_expires_at' => now()->addHour(),
        ]);
        PlatformSetting::create([
            'user_id' => $user->id, 'platform' => 'youtube', 'account_id' => $account->id, 'enabled' => true,
            'max_per_day' => 3, 'slot_times' => ['15:00'], 'timezone' => 'Asia/Jakarta',
        ]);
    }

    public function test_source_credit_names_the_channel_and_links_the_video(): void
    {
        $user = User::factory()->create();

        $this->assertSame(self::CREDIT, CaptionCredit::source($this->clip($user)->clipProject));
        $this->assertSame(
            "Sumber: Satu Dua\nhttps://youtu.be/abcdefg",
            CaptionCredit::source($this->clip($user, "  Satu \n Dua ")->clipProject), // one line, no stray whitespace
        );
        $this->assertSame(100 + 8, mb_strlen(explode("\n", CaptionCredit::source($this->clip($user, str_repeat('x', 150))->clipProject))[0]));
    }

    public function test_no_credit_when_the_channel_is_unknown(): void
    {
        $user = User::factory()->create();

        foreach ([null, '', '   '] as $channel) {
            $this->assertNull(CaptionCredit::source($this->clip($user, $channel)->clipProject));
        }
        $manual = ClipProject::create([
            'user_id' => $user->id, 'youtube_url' => 'https://youtu.be/abcdefg', 'layout_type' => 'reframe',
            'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
        $this->assertNull(CaptionCredit::source($manual));
        $this->assertNull(CaptionCredit::block(null, $manual));
        $this->assertSame('Caption', CaptionCredit::append('Caption', CaptionCredit::block(null, $manual)));
    }

    public function test_review_card_caption_carries_music_then_source_credit(): void
    {
        $user = User::factory()->create();
        MusicTrack::create(['path' => 'chill/a.mp3', 'name' => 'a', 'mood' => 'chill', 'credit' => 'Song by X (CC BY 4.0)']);
        $clip = $this->clip($user, 'Podcast Bisnis', ['music_track' => 'chill/a.mp3']);
        $unknown = $this->clip($user, null, ['ranking' => 2]);

        $data = $this->actingAs($user)->getJson('/api/autopilot/review')->assertOk()->json('data');
        $byId = collect($data)->keyBy('id');

        $this->assertSame("Dengar ini sampai habis.\n\n🎵 Song by X (CC BY 4.0)\n".self::CREDIT, $byId[$clip->id]['caption']);
        $this->assertSame('Dengar ini sampai habis.', $byId[$unknown->id]['caption']);
    }

    public function test_approve_puts_the_credit_in_the_booked_post(): void
    {
        $user = User::factory()->create();
        $this->youtubeOn($user);
        $clip = $this->clip($user);

        $this->actingAs($user)->postJson("/api/autopilot/review/{$clip->id}/approve")->assertOk();

        $this->assertSame(
            "Dengar ini sampai habis.\n\n".self::CREDIT."\n\n#bisnis #umkm",
            ScheduledPost::sole()->options['description'],
        );
    }

    public function test_approve_without_a_known_channel_keeps_the_old_text(): void
    {
        $user = User::factory()->create();
        $this->youtubeOn($user);
        $clip = $this->clip($user, null);

        $this->actingAs($user)->postJson("/api/autopilot/review/{$clip->id}/approve")->assertOk();

        $this->assertSame("Dengar ini sampai habis.\n\n#bisnis #umkm", ScheduledPost::sole()->options['description']);
    }

    public function test_tiktok_post_gets_the_credit_too(): void
    {
        $user = User::factory()->create();
        $tiktok = TiktokAccount::create([
            'user_id' => $user->id, 'open_id' => 'o', 'access_token' => 'x', 'refresh_token' => 'y',
            'access_expires_at' => now()->addDay(), 'scope' => 'user.info.basic,video.upload',
        ]);
        PlatformSetting::create([
            'user_id' => $user->id, 'platform' => 'tiktok', 'account_id' => $tiktok->id, 'enabled' => true,
            'max_per_day' => 3, 'slot_times' => ['15:00'], 'timezone' => 'Asia/Jakarta',
        ]);
        $clip = $this->clip($user);

        $this->actingAs($user)->postJson("/api/autopilot/review/{$clip->id}/approve")->assertOk();

        $this->assertSame("Dengar ini sampai habis.\n\n".self::CREDIT."\n\n#bisnis #umkm", ScheduledPost::sole()->options['title']);
    }

    /** @return array<string, array{0: string, 1: string, 2: int, 3: bool}> platform, option key, limit, limit counted in bytes */
    public static function limits(): array
    {
        return [
            'tiktok' => ['tiktok', 'title', 2200, false],
            'youtube' => ['youtube', 'description', 5000, false],
            'facebook' => ['facebook', 'description', 5000, false],
            'instagram' => ['instagram', 'caption', 2200, false],
            'threads' => ['threads', 'text', 500, true],
        ];
    }

    #[DataProvider('limits')]
    public function test_a_long_caption_is_cut_but_the_credit_stays_whole(string $platform, string $key, int $limit, bool $bytes): void
    {
        $clip = $this->clip(User::factory()->create());
        $body = str_repeat('Kalimat panjang tentang bisnis kecil. ', 400);
        $tags = ['#bisnis', '#umkm', '#cuan'];

        $text = app(Autopost::class)->platform($platform)->options($clip, $body, $tags, self::CREDIT)[$key];

        $this->assertLessThanOrEqual($limit, $bytes ? strlen($text) : mb_strlen($text), $platform);
        $this->assertStringContainsString(self::CREDIT, $text, $platform);
        $this->assertStringContainsString('…', $text, $platform); // the body gave way
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
    }

    #[DataProvider('limits')]
    public function test_a_short_caption_is_not_touched_by_the_limit(string $platform, string $key, int $limit, bool $bytes): void
    {
        $clip = $this->clip(User::factory()->create());

        $text = app(Autopost::class)->platform($platform)->options($clip, 'Dengar ini.', ['#bisnis', '#umkm'], self::CREDIT)[$key];

        $this->assertStringContainsString("Dengar ini.\n\n".self::CREDIT, $text, $platform);
        $this->assertStringNotContainsString('…', $text, $platform);
    }

    public function test_hashtags_go_before_the_credit_when_the_body_would_be_too_short(): void
    {
        $text = CaptionCredit::compose('Dengar ini sampai habis dan jangan lewatkan.', self::CREDIT, str_repeat('#tag ', 40), 100);

        $this->assertLessThanOrEqual(100, mb_strlen($text));
        $this->assertStringEndsWith(self::CREDIT, $text);
        $this->assertStringNotContainsString('#tag', $text);
    }

    public function test_threads_bytes_are_counted_for_emoji_in_the_body(): void
    {
        $text = CaptionCredit::compose(str_repeat('kata 😀 ', 200), "🎵 Lagu\n".self::CREDIT, '#bisnis', 500, bytes: true);

        $this->assertLessThanOrEqual(500, strlen($text));
        $this->assertStringEndsWith("🎵 Lagu\n".self::CREDIT."\n\n#bisnis", $text);
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
    }
}

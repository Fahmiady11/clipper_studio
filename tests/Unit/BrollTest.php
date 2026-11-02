<?php

namespace Tests\Unit;

use App\Services\FFmpegService;
use App\Services\StockFootage;
use App\Support\BrollCues;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrollTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.pexels.key' => 'px',
            'services.pixabay.key' => null,
            'services.broll_cache' => sys_get_temp_dir().'/clipper-broll-cache-'.getmypid(),
            'services.gemini.key' => 'k',
            'services.gemini.models' => ['model-a'],
            'services.gemini.retry_pauses' => [0, 0],
            'services.llm_gateway' => ['url' => null, 'key' => null, 'model' => null],
        ]);
        $this->dir = config('services.broll_cache');
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function video(int $id, array $files, int $duration = 10, string $slug = 'cars-in-city-traffic'): array
    {
        return ['id' => $id, 'duration' => $duration, 'url' => "https://www.pexels.com/video/{$slug}-{$id}/", 'video_files' => array_map(fn ($f) => [
            'file_type' => 'video/mp4', 'width' => $f[0], 'height' => $f[1], 'link' => "https://cdn.test/{$id}-{$f[0]}.mp4",
        ], $files)];
    }

    public function test_pexels_picks_a_portrait_file_downloads_once_and_skips_used_videos(): void
    {
        Http::fake([
            'api.pexels.com/*' => Http::response(['videos' => [
                $this->video(1, [[1920, 1080], [720, 1280]], 2),           // too short
                $this->video(2, [[1080, 1920], [2160, 3840], [720, 1280]]), // portrait: 1080 is the pick
                $this->video(3, [[720, 1280]]),
            ]]),
            'cdn.test/*' => Http::response('MP4DATA'),
        ]);
        $pexels = new StockFootage;

        $first = $pexels->clip('city traffic');
        $this->assertSame('px2', $first['id']);
        $this->assertSame(1.0, $first['offset']); // 10s long: skip its first second
        $this->assertSame('MP4DATA', file_get_contents($first['path']));
        Http::assertSent(fn ($r) => $r->url() === 'https://cdn.test/2-1080.mp4');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'orientation=portrait') && $r->hasHeader('Authorization', 'px'));

        $this->assertSame('px3', $pexels->clip('City Traffic ', ['px2'])['id']); // same search (cached), next video
        Http::assertSentCount(3); // one search, two downloads
    }

    public function test_pixabay_is_used_when_only_its_key_is_set_portrait_first(): void
    {
        config(['services.pexels.key' => '', 'services.pixabay.key' => 'pb']);
        $rendition = fn ($id, $w, $h) => ['url' => "https://cdn.pixabay.test/{$id}-{$w}.mp4", 'width' => $w, 'height' => $h, 'size' => 1];
        Http::fake([
            'pixabay.com/api/*' => Http::response(['hits' => [
                ['id' => 10, 'type' => 'film', 'tags' => 'money, cash', 'duration' => 12, 'videos' => ['large' => $rendition(10, 3840, 2160), 'medium' => $rendition(10, 1920, 1080), 'small' => $rendition(10, 1280, 720)]],
                ['id' => 11, 'type' => 'film', 'tags' => 'money, dollar', 'duration' => 9, 'videos' => ['large' => $rendition(11, 1080, 1920), 'small' => $rendition(11, 720, 1280)]],
                ['id' => 12, 'type' => 'film', 'tags' => 'money', 'duration' => 9, 'videos' => ['medium' => $rendition(12, 1280, 720)]], // too small for a 1920 frame
                ['id' => 13, 'type' => 'animation', 'tags' => 'money, 3d', 'duration' => 9, 'videos' => ['large' => $rendition(13, 1080, 1920)]], // a render, not footage
            ]]),
            'cdn.pixabay.test/*' => Http::response('MP4'),
        ]);
        $stock = new StockFootage;

        $this->assertTrue($stock->configured());
        $this->assertSame('pb11', $stock->clip('money')['id']);              // portrait first
        $this->assertSame('pb10', $stock->clip('money', ['pb11'])['id']);    // then landscape, cropped by the render
        Http::assertSent(fn ($r) => $r->url() === 'https://cdn.pixabay.test/10-1920.mp4'); // smallest that fits
        $this->assertNull($stock->clip('money', ['pb11', 'pb10'])); // the animation is never used
        Http::assertSent(fn ($r) => str_contains($r->url(), 'video_type=film'));
    }

    public function test_results_are_ranked_by_shared_words_and_unrelated_ones_are_never_used(): void
    {
        Http::fake([
            'api.pexels.com/*' => Http::response(['videos' => [
                $this->video(1, [[1080, 1920]], 10, 'water-running-over-hands'),
                $this->video(2, [[1080, 1920]], 10, 'couple-holding-hands-on-a-street'),
                $this->video(3, [[1080, 1920]], 4, 'woman-drinking-coffee'),
            ]]),
            'cdn.test/*' => Http::response('MP4'),
        ]);
        $stock = new StockFootage;

        // "hands" alone is not enough for a 4-word query: the couple wins over the tap
        $this->assertSame('px2', $stock->clip('couple holding hands street')['id']);
        $this->assertNull($stock->clip('couple holding hands street', ['px2']));
        // the fallback query is tried when the first finds nothing related
        $pick = $stock->clip('boxer punching heavy bag', [], 'coffee');
        $this->assertSame('px3', $pick['id']);
        $this->assertSame(0.0, $pick['offset']); // too short to skip into
    }

    public function test_a_result_without_the_subject_or_on_green_screen_is_never_used(): void
    {
        config(['services.pexels.key' => '', 'services.pixabay.key' => 'pb']);
        $hit = fn ($id, $tags) => ['id' => $id, 'type' => 'film', 'tags' => $tags, 'duration' => 9,
            'videos' => ['large' => ['url' => "https://cdn.pixabay.test/{$id}.mp4", 'width' => 1080, 'height' => 1920]]];
        Http::fake([
            'pixabay.com/api/*' => Http::response(['hits' => [
                $hit(1, 'work, office, people, pad, team'),          // two query words, no boxing
                $hit(2, 'punch, boxing, green screen, chroma key'),
                $hit(3, 'boxing, sports, fitness'),
            ]]),
            'cdn.pixabay.test/*' => Http::response('MP4'),
        ]);
        $stock = new StockFootage;

        $this->assertSame('pb3', $stock->clip('two people boxing pad work', [], '', ['boxing'])['id']);
        $this->assertNull($stock->clip('two people boxing pad work', ['pb3'], '', ['boxing']));
    }

    public function test_query_terms_drop_filler_and_share_a_stem(): void
    {
        $this->assertSame(['boxer' => 'box', 'boxing' => 'box', 'bus' => 'bus', 'hands' => 'hand'], [
            'boxer' => StockFootage::terms('boxer')[0], 'boxing' => StockFootage::terms('boxing')[0],
            'bus' => StockFootage::terms('bus')[0], 'hands' => StockFootage::terms('hands')[0],
        ]);
        $this->assertSame(['couple', 'walk', 'beach'], StockFootage::terms('A couple walking on the beach'));
        $this->assertSame(['box', 'pad'], StockFootage::terms('two people boxing pad work'));
    }

    public function test_pexels_off_without_key_or_on_errors(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        $this->assertNull((new StockFootage)->clip('money'));
        config(['services.pexels.key' => '', 'services.pixabay.key' => '']);
        $this->assertFalse((new StockFootage)->configured());
        $this->assertNull((new StockFootage)->clip('money'));
    }

    public function test_cues_are_sorted_spaced_sized_and_kept_off_the_hook_and_punchlines(): void
    {
        $cues = [
            ['time' => 30.0, 'duration' => 3.0, 'query' => 'boxing gym', 'alt' => 'gym', 'must' => ['boxing']], // longer than allowed
            ['time' => 2.0, 'query' => 'too early'],                // under the hook
            ['time' => 12.0, 'duration' => 0.4, 'query' => 'counting money'], // too short: stretched
            ['time' => 15.0, 'query' => 'too close'],               // 3s after the previous
            ['time' => 44.0, 'query' => 'over the punchline'],
            ['time' => 59.0, 'query' => 'too late'],                // no room left
            ['time' => 40.0, 'query' => ''],
        ];

        $this->assertSame([
            ['time' => 12.0, 'duration' => BrollCues::MIN_DURATION, 'query' => 'counting money', 'alt' => '', 'must' => []],
            ['time' => 30.0, 'duration' => BrollCues::MAX_DURATION, 'query' => 'boxing gym', 'alt' => 'gym', 'must' => ['boxing']],
        ], BrollCues::filter($cues, 60.0, 4.0, [[45.0, 47.0]]));
        $this->assertSame(3, BrollCues::max(60.0));
        $this->assertSame(1, BrollCues::max(30.0));
    }

    public function test_broll_is_cut_in_full_frame_for_its_moment(): void
    {
        $dir = sys_get_temp_dir().'/clipper-broll-'.getmypid();
        @mkdir($dir);
        $bin = escapeshellarg(config('services.ffmpeg.path', 'ffmpeg'));
        exec(sprintf('%s -v error -y -f lavfi -i color=c=black:s=640x360:r=25:d=4 -pix_fmt yuv420p %s 2>&1', $bin, escapeshellarg($dir.'/src.mp4')), $o, $code);
        exec(sprintf('%s -v error -y -f lavfi -i color=c=white:s=720x1280:r=30:d=3 -pix_fmt yuv420p %s 2>&1', $bin, escapeshellarg($dir.'/stock.mp4')));
        if ($code !== 0) {
            $this->markTestSkipped('ffmpeg not available');
        }

        $out = $dir.'/out.mp4';
        (new FFmpegService)->applyLayout($dir.'/src.mp4', 'reframe', $out, 0.0, 4.0, null, null, [],
            ['broll' => [['path' => $dir.'/stock.mp4', 'start' => 1.5, 'duration' => 1.5]]]);

        $lum = fn ($t) => array_sum(unpack('C*', (string) shell_exec(sprintf(
            'ffmpeg -v error -ss %s -i %s -frames:v 1 -vf scale=10:10 -f rawvideo -pix_fmt gray -', $t, escapeshellarg($out))))) / 100;
        $this->assertLessThan(40, $lum('0.8'));   // before: the source
        $this->assertGreaterThan(200, $lum('2.2')); // during: the stock clip fills the frame
        $this->assertLessThan(40, $lum('3.6'));   // after: back to the source
        exec('rm -rf '.escapeshellarg($dir));
    }

    public function test_analysis_moments_follow_the_cuts_and_trim(): void
    {
        // clip 100..160 in the source; 4s cut out at 120..124 (clip time 20..24)
        $pieces = [[0.0, 20.0], [24.0, 60.0]];
        $broll = [
            ['time' => 102.0, 'query' => 'under the hook'],
            ['time' => 110.0, 'query' => 'counting money'],
            ['time' => 130.0, 'query' => 'city traffic'],   // 30s in the clip -> 26s after the cut
            ['time' => 90.0, 'query' => 'before the clip'],
            ['time' => 170.0, 'query' => 'after the clip'],
        ];

        $this->assertSame(
            [['time' => 10.0, 'duration' => 1.8, 'query' => 'counting money', 'alt' => '', 'must' => []], ['time' => 26.0, 'duration' => 1.8, 'query' => 'city traffic', 'alt' => '', 'must' => []]],
            BrollCues::fromAnalysis($broll, $pieces, 100.0, 56.0, 4.0)
        );
    }
}

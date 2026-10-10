<?php

namespace Tests\Unit;

use App\Services\FFmpegService;
use App\Services\MusicService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MusicTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/music_test_'.uniqid();
        mkdir($this->dir.'/chill', 0755, true);
        mkdir($this->dir.'/energetic', 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_picks_track_from_mood_folder_and_is_stable(): void
    {
        touch($this->dir.'/chill/a.mp3');
        touch($this->dir.'/chill/b.mp3');
        touch($this->dir.'/energetic/c.mp3');
        touch($this->dir.'/chill/notes.txt');

        $svc = new MusicService($this->dir);
        $pick = $svc->pickTrack('chill', 'clip-1');

        $this->assertContains(basename($pick), ['a.mp3', 'b.mp3']);
        $this->assertSame($pick, $svc->pickTrack('chill', 'clip-1'));
    }

    public function test_falls_back_to_any_track_when_mood_is_empty(): void
    {
        touch($this->dir.'/energetic/c.mp3');

        $this->assertSame('c.mp3', basename((new MusicService($this->dir))->pickTrack('sad', 'x')));
        $this->assertSame('c.mp3', basename((new MusicService($this->dir))->pickTrack(null, 'x')));
    }

    public function test_empty_library_returns_null(): void
    {
        $this->assertNull((new MusicService($this->dir))->pickTrack('chill', 'x'));
    }

    public function test_normalize_mood_rejects_unknown_values(): void
    {
        $this->assertSame('chill', MusicService::normalizeMood(' Chill '));
        $this->assertNull(MusicService::normalizeMood('happy-go-lucky'));
        $this->assertNull(MusicService::normalizeMood(null));
    }

    public function test_migration_adds_music_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('clip_projects', ['music_enabled', 'music_mood', 'music_volume']));
        $this->assertTrue(Schema::hasColumns('generated_clips', ['music_mood', 'music_track']));
    }

    public function test_mix_music_keeps_duration_and_video_stream(): void
    {
        exec('command -v ffmpeg && command -v ffprobe', $o, $code);
        if ($code !== 0) {
            $this->markTestSkipped('ffmpeg/ffprobe not installed');
        }

        $video = $this->dir.'/in.mp4';
        $music = $this->dir.'/chill/short.mp3'; // shorter than the clip: must loop
        $out = $this->dir.'/out.mp4';
        exec('ffmpeg -loglevel error -y -f lavfi -i color=c=black:s=320x240:d=6 -f lavfi -i sine=f=300:d=6 -c:v libx264 -c:a aac -shortest '.escapeshellarg($video));
        exec('ffmpeg -loglevel error -y -f lavfi -i sine=f=800:d=2 '.escapeshellarg($music));

        (new FFmpegService)->mixMusic($video, $music, 20, $out);

        $probe = fn ($args) => trim(shell_exec("ffprobe -v error {$args} -of csv=p=0 ".escapeshellarg($out)));
        $this->assertEqualsWithDelta(6.0, (float) $probe('-show_entries format=duration'), 0.15);
        $this->assertSame('h264', $probe('-select_streams v -show_entries stream=codec_name'));
        $this->assertSame('aac', $probe('-select_streams a -show_entries stream=codec_name'));
    }
}

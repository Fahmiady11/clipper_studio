<?php

namespace Tests\Feature;

use App\Models\ClipProject;
use App\Models\GeneratedClip;
use App\Models\MusicTrack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MusicLibraryTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private static ?string $mp3 = null;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->root = sys_get_temp_dir().'/music-test-'.uniqid();
        mkdir($this->root);
        config(['services.music.path' => $this->root]);
        $this->user = User::factory()->create();

        exec('command -v ffmpeg && command -v ffprobe', $o, $code);
        if ($code !== 0) {
            $this->markTestSkipped('ffmpeg/ffprobe not installed');
        }

        // A real (1 s, silent) mp3 so the ffprobe audio check runs for real
        if (! self::$mp3) {
            self::$mp3 = sys_get_temp_dir().'/music-test-src.mp3';
            exec(sprintf('%s -v error -y -f lavfi -i anullsrc=r=44100:cl=mono -t 1 -q:a 9 %s',
                config('services.ffmpeg.path', 'ffmpeg'), escapeshellarg(self::$mp3)));
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function mp3(string $name = 'Calm Morning - Artist.mp3'): UploadedFile
    {
        $copy = sys_get_temp_dir().'/'.uniqid().'.mp3';
        copy(self::$mp3, $copy);

        return new UploadedFile($copy, $name, 'audio/mpeg', null, true);
    }

    public function test_upload_stores_file_in_mood_folder_with_credit(): void
    {
        $this->actingAs($this->user)->post('/api/music', [
            'file' => $this->mp3(), 'mood' => 'chill', 'license' => 'cc-by', 'credit' => 'Calm Morning by Artist (CC BY 4.0)',
        ], ['Accept' => 'application/json'])->assertCreated()->assertJson(['path' => 'chill/calm-morning-artist.mp3']);

        $this->assertFileExists("{$this->root}/chill/calm-morning-artist.mp3");
        $this->assertSame('Calm Morning by Artist (CC BY 4.0)', MusicTrack::first()->credit);

        // Same name again never overwrites
        $this->actingAs($this->user)->post('/api/music', [
            'file' => $this->mp3(), 'mood' => 'chill', 'license' => 'free',
        ], ['Accept' => 'application/json'])->assertCreated()->assertJson(['path' => 'chill/calm-morning-artist-2.mp3']);

        $this->actingAs($this->user)->getJson('/api/music')
            ->assertJsonPath('total', 2)->assertJsonPath('counts.chill', 2);
    }

    public function test_cc_by_needs_a_credit_and_files_must_be_audio(): void
    {
        $this->actingAs($this->user)->post('/api/music', [
            'file' => $this->mp3(), 'mood' => 'chill', 'license' => 'cc-by',
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('credit');

        $fake = UploadedFile::fake()->createWithContent('not-music.mp3', 'just text');
        $this->actingAs($this->user)->post('/api/music', [
            'file' => $fake, 'mood' => 'chill', 'license' => 'free',
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertSame([], glob("{$this->root}/chill/*") ?: []);
    }

    public function test_paths_outside_the_library_are_refused(): void
    {
        file_put_contents(dirname($this->root).'/secret.mp3', 'x');

        $this->actingAs($this->user)->get('/api/music/file?path='.urlencode('../secret.mp3'))->assertNotFound();
        $this->actingAs($this->user)->deleteJson('/api/music', ['path' => '../secret.mp3'])->assertNotFound();
        $this->assertFileExists(dirname($this->root).'/secret.mp3');
        @unlink(dirname($this->root).'/secret.mp3');
    }

    public function test_files_are_served_as_audio_even_when_the_content_looks_like_html(): void
    {
        // ffprobe still finds the mp3 frames after a large HTML prefix, while content
        // sniffing (finfo) only reads the start and would call it text/html (stored XSS)
        mkdir($this->root.'/chill');
        $html = '<html><script>alert(document.domain)</script><!--'.str_repeat('A', 1100 * 1024).'-->';
        file_put_contents($this->root.'/chill/poly.mp3', $html.file_get_contents(self::$mp3));

        $response = $this->actingAs($this->user)->get('/api/music/file?path='.urlencode('chill/poly.mp3'));

        $response->assertOk();
        $this->assertSame('audio/mpeg', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_move_mood_and_delete_keep_credit_rows_in_sync(): void
    {
        mkdir("{$this->root}/chill");
        copy(self::$mp3, "{$this->root}/chill/song.mp3");
        MusicTrack::create(['path' => 'chill/song.mp3', 'license' => 'cc-by', 'credit' => 'Song by X']);

        $this->actingAs($this->user)->patchJson('/api/music', ['path' => 'chill/song.mp3', 'mood' => 'sad'])
            ->assertOk()->assertJson(['path' => 'sad/song.mp3']);
        $this->assertFileExists("{$this->root}/sad/song.mp3");
        $this->assertSame('sad/song.mp3', MusicTrack::first()->path);

        $this->actingAs($this->user)->deleteJson('/api/music', ['path' => 'sad/song.mp3'])->assertOk();
        $this->assertFileDoesNotExist("{$this->root}/sad/song.mp3");
        $this->assertSame(0, MusicTrack::count());
    }

    public function test_caption_carries_the_music_credit(): void
    {
        MusicTrack::create(['path' => 'chill/song.mp3', 'license' => 'cc-by', 'credit' => 'Song by X (CC BY 4.0)']);
        $project = ClipProject::create([
            'user_id' => $this->user->id, 'youtube_url' => 'https://youtu.be/abcdefg',
            'layout_type' => 'reframe', 'clip_count' => 1, 'duration_mode' => 'auto', 'status' => 'done',
        ]);
        $clip = GeneratedClip::create([
            'clip_project_id' => $project->id, 'ranking' => 1, 'start_seconds' => 0, 'end_seconds' => 30,
            'topic' => 'T', 'reason' => 'R', 'viral_potential' => 'tinggi', 'status' => 'done',
            'caption' => 'Caption keren', 'hashtags_json' => ['#a'], 'music_track' => 'chill/song.mp3',
        ]);

        $this->actingAs($this->user)->getJson("/api/clips/{$clip->id}/caption")
            ->assertOk()
            ->assertJson(['caption' => "Caption keren\n\n🎵 Song by X (CC BY 4.0)", 'music_credit' => 'Song by X (CC BY 4.0)']);
    }
}

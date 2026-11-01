<?php

namespace Tests\Feature;

use App\Models\GameplayClip;
use App\Models\User;
use App\Services\GameplayFootage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GameplayUploadTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->dir = sys_get_temp_dir().'/clipper-upload-'.getmypid().'-'.bin2hex(random_bytes(3));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function needFfmpeg(): void
    {
        exec(escapeshellarg(config('services.ffmpeg.path', 'ffmpeg')).' -version 2>&1', $o, $code);
        if ($code !== 0) {
            $this->markTestSkipped('ffmpeg not available');
        }
    }

    private function video(string $name = 'run.mp4', int $seconds = 5): UploadedFile
    {
        $path = $this->dir.'/'.uniqid().'-'.$name;
        exec(sprintf('%s -v error -y -f lavfi -i color=c=red:s=160x90:r=30:d=%d -c:v libx264 -pix_fmt yuv420p %s 2>&1',
            escapeshellarg(config('services.ffmpeg.path', 'ffmpeg')), $seconds, escapeshellarg($path)));

        return new UploadedFile($path, $name, null, null, true);
    }

    private function clipFor(User $user, string $name = 'a'): GameplayClip
    {
        Storage::disk('local')->put("gameplay/{$user->id}/{$name}.mp4", 'x');
        Storage::disk('local')->put("gameplay/{$user->id}/{$name}.jpg", 'jpg');

        return GameplayClip::create([
            'user_id' => $user->id, 'name' => "{$name}.mp4", 'path' => "gameplay/{$user->id}/{$name}.mp4", 'thumb_path' => "gameplay/{$user->id}/{$name}.jpg",
            'size_bytes' => 2_500_000, 'duration' => 42.4, 'width' => 1080, 'height' => 1920,
        ]);
    }

    public function test_guests_cannot_reach_any_endpoint(): void
    {
        $this->get('/settings/gameplay')->assertRedirect('/login');
        $this->getJson('/api/gameplay')->assertUnauthorized();
        $this->postJson('/api/gameplay')->assertUnauthorized();
        $this->deleteJson('/api/gameplay/1')->assertUnauthorized();
        $this->get('/settings/gameplay/1/thumb')->assertRedirect('/login');
    }

    public function test_unapproved_users_are_kept_out(): void
    {
        $user = User::factory()->create(['approved_at' => null]);

        $this->actingAs($user)->getJson('/api/gameplay')->assertForbidden();
    }

    public function test_page_renders_with_the_limits(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/settings/gameplay')->assertOk()
            ->assertSee('Footage gameplay')->assertSee('maks 200 MB', false);
    }

    public function test_the_account_menu_links_to_the_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/music')->assertSee(route('gameplay.page'), false);
    }

    public function test_list_shows_only_my_clips_with_name_duration_size_and_thumbnail(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $mine = $this->clipFor($me, 'mine');
        $this->clipFor($other, 'theirs');

        $res = $this->actingAs($me)->getJson('/api/gameplay')->assertOk();

        $res->assertJsonCount(1, 'clips')
            ->assertJsonPath('clips.0.name', 'mine.mp4')
            ->assertJsonPath('clips.0.duration', 42.4)
            ->assertJsonPath('clips.0.size', 2_500_000)
            ->assertJsonPath('clips.0.thumb', route('gameplay.thumb', $mine->id, absolute: false))
            ->assertJsonPath('max', 10)
            ->assertJsonPath('remaining', 9);
    }

    public function test_upload_stores_a_real_video_for_me(): void
    {
        $this->needFfmpeg();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/gameplay', ['file' => $this->video('parkour.mp4')])
            ->assertCreated()->assertJsonPath('name', 'parkour.mp4');

        $clip = GameplayClip::firstOrFail();
        $this->assertSame($user->id, $clip->user_id);
        Storage::disk('local')->assertExists($clip->path);
        $this->assertStringStartsWith("gameplay/{$user->id}/", $clip->path);
    }

    public function test_upload_rejects_audio_only_and_garbage_with_a_clear_message(): void
    {
        $this->needFfmpeg();
        $user = User::factory()->create();
        $garbage = $this->dir.'/garbage.mp4';
        file_put_contents($garbage, random_bytes(2048));
        $audio = $this->dir.'/song.mp4';
        exec(sprintf('%s -v error -y -f lavfi -i sine=f=440:d=5 -c:a aac -f mp4 %s 2>&1', escapeshellarg(config('services.ffmpeg.path', 'ffmpeg')), escapeshellarg($audio)));

        foreach ([$garbage, $audio] as $path) {
            $this->actingAs($user)->postJson('/api/gameplay', ['file' => new UploadedFile($path, 'x.mp4', null, null, true)])
                ->assertUnprocessable()
                ->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'bukan video'));
        }
        $this->assertSame(0, GameplayClip::count());
    }

    public function test_upload_enforces_extension_and_the_200_mb_cap(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/gameplay', ['file' => UploadedFile::fake()->create('clip.avi', 100)])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->actingAs($user)->postJson('/api/gameplay', ['file' => UploadedFile::fake()->create('big.mp4', GameplayFootage::MAX_KB + 1)])
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'Ukuran file maksimal 200 MB.');
        $this->actingAs($user)->postJson('/api/gameplay', [])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_the_eleventh_upload_is_refused_but_another_user_is_not_affected(): void
    {
        $this->needFfmpeg();
        $user = User::factory()->create();
        $other = User::factory()->create();
        foreach (range(1, 10) as $i) {
            $this->clipFor($user, "c{$i}");
        }

        $this->actingAs($user)->postJson('/api/gameplay', ['file' => $this->video()])
            ->assertUnprocessable()->assertJsonPath('errors.file.0', fn ($m) => str_contains($m, 'Batas 10'));
        $this->assertSame(10, GameplayClip::where('user_id', $user->id)->count());

        $this->actingAs($other)->postJson('/api/gameplay', ['file' => $this->video()])->assertCreated();
    }

    public function test_delete_removes_my_clip_and_its_files(): void
    {
        $user = User::factory()->create();
        $clip = $this->clipFor($user);

        $this->actingAs($user)->deleteJson('/api/gameplay/'.$clip->id)->assertOk();

        $this->assertNull(GameplayClip::find($clip->id));
        Storage::disk('local')->assertMissing($clip->path);
        Storage::disk('local')->assertMissing($clip->thumb_path);
    }

    public function test_nobody_can_delete_or_see_someone_elses_clip(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $clip = $this->clipFor($owner);

        $this->actingAs($intruder)->deleteJson('/api/gameplay/'.$clip->id)->assertNotFound();
        $this->actingAs($intruder)->get(route('gameplay.thumb', $clip->id))->assertNotFound();

        $this->assertNotNull(GameplayClip::find($clip->id));
        Storage::disk('local')->assertExists($clip->path);
        $this->actingAs($owner)->get(route('gameplay.thumb', $clip->id))->assertOk();
    }

    public function test_deleting_the_last_clip_turns_the_layout_off_so_a_waiting_render_falls_back(): void
    {
        $user = User::factory()->create();
        $clip = $this->clipFor($user);
        $this->assertNotNull(GameplayFootage::for($user->id)->pick(10.0));

        $this->actingAs($user)->deleteJson('/api/gameplay/'.$clip->id)->assertOk();

        // RenderClipJob picks at render start: nothing to pick means a plain reframe, not a failure
        $this->assertNull(GameplayFootage::for($user->id)->pick(10.0));
    }

    public function test_a_clip_deleted_while_another_remains_leaves_the_render_a_valid_pick(): void
    {
        $user = User::factory()->create();
        $first = $this->clipFor($user, 'one');
        $second = $this->clipFor($user, 'two');

        $this->actingAs($user)->deleteJson('/api/gameplay/'.$first->id)->assertOk();

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame($second->absolutePath(), GameplayFootage::for($user->id)->pick(5.0)['path']);
        }
    }

    public function test_deploy_limits_fit_a_200_mb_upload(): void
    {
        $ini = (string) file_get_contents(base_path('deploy/php-clipper.ini'));
        preg_match('/upload_max_filesize\s*=\s*(\d+)M/', $ini, $upload);
        preg_match('/post_max_size\s*=\s*(\d+)M/', $ini, $post);
        preg_match('/client_max_body_size\s+(\d+)M/', (string) file_get_contents(base_path('deploy/nginx.conf')), $nginx);

        $this->assertGreaterThanOrEqual(200, (int) $upload[1]);
        $this->assertGreaterThan((int) $upload[1], (int) $post[1]);
        $this->assertGreaterThanOrEqual((int) $post[1], (int) $nginx[1]);
    }
}

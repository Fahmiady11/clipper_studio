<?php

namespace Tests\Feature;

use App\Models\BrandKit;
use App\Models\ClipProject;
use App\Models\User;
use App\Services\ProjectCreator;
use App\Support\ClipSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandKitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        $this->user = User::factory()->create();
    }

    public function test_guest_cannot_open_brand_settings(): void
    {
        $this->get('/settings/brand')->assertRedirect('/login');
    }

    public function test_settings_page_renders_for_user_without_kit(): void
    {
        $this->actingAs($this->user)->get('/settings/brand')
            ->assertOk()
            ->assertSee('Brand Kit')
            ->assertSee('Default (Montserrat)');
    }

    public function test_user_saves_colors_font_and_hook_style(): void
    {
        $this->actingAs($this->user)->put('/settings/brand', [
            'primary_color' => '#22C55E',
            'secondary_color' => '#111111',
            'font_family' => 'Oswald',
            'hook_style' => 'full',
        ])->assertRedirect();

        $this->assertDatabaseHas('brand_kits', [
            'user_id' => $this->user->id,
            'primary_color' => '#22C55E',
            'secondary_color' => '#111111',
            'font_family' => 'Oswald',
            'hook_style' => 'full',
        ]);
    }

    public function test_invalid_values_are_rejected(): void
    {
        $this->actingAs($this->user)->put('/settings/brand', [
            'primary_color' => 'yellow',
            'font_family' => 'Comic Sans',
            'hook_style' => 'neon',
        ])->assertSessionHasErrors(['primary_color', 'font_family', 'hook_style']);

        $this->assertDatabaseCount('brand_kits', 0);
    }

    public function test_logo_upload_is_stored_per_user_and_replaced(): void
    {
        $this->actingAs($this->user);

        $this->put('/settings/brand', [
            'logo' => UploadedFile::fake()->image('a.png', 200, 200),
            'hook_style' => 'semi',
        ])->assertRedirect();
        $first = BrandKit::where('user_id', $this->user->id)->value('logo_path');
        $this->assertStringStartsWith('brand/'.$this->user->id.'/', $first);
        Storage::disk('local')->assertExists($first);

        $this->put('/settings/brand', [
            'logo' => UploadedFile::fake()->image('b.png', 200, 200),
            'hook_style' => 'semi',
        ])->assertRedirect();
        $second = BrandKit::where('user_id', $this->user->id)->value('logo_path');
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);

        $this->get('/settings/brand/logo')->assertOk();
    }

    public function test_logo_upload_rejects_non_image(): void
    {
        $this->actingAs($this->user)->put('/settings/brand', [
            'logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
            'hook_style' => 'semi',
        ])->assertSessionHasErrors('logo');
    }

    public function test_remove_logo_clears_path_and_file(): void
    {
        $this->actingAs($this->user);
        $this->put('/settings/brand', ['logo' => UploadedFile::fake()->image('a.png'), 'hook_style' => 'semi']);
        $path = BrandKit::where('user_id', $this->user->id)->value('logo_path');

        $this->delete('/settings/brand/logo')->assertRedirect();

        $this->assertNull(BrandKit::where('user_id', $this->user->id)->value('logo_path'));
        Storage::disk('local')->assertMissing($path);
        $this->get('/settings/brand/logo')->assertNotFound();
    }

    public function test_studio_starts_from_brand_kit_values(): void
    {
        BrandKit::create([
            'user_id' => $this->user->id,
            'primary_color' => '#22c55e',
            'secondary_color' => '#eeeeee',
            'font_family' => 'Oswald',
            'hook_style' => 'none',
        ]);

        $this->actingAs($this->user)->get('/')
            ->assertOk()
            ->assertSee('"font_family":"Oswald"', false)
            ->assertSee('"highlight_color":"#22c55e"', false)
            ->assertSee('"background_style":"none"', false);
    }

    public function test_new_project_takes_brand_defaults_when_request_leaves_them_out(): void
    {
        Storage::disk('local')->put('brand/x/logo.png', 'png');
        BrandKit::create([
            'user_id' => $this->user->id,
            'logo_path' => 'brand/x/logo.png',
            'primary_color' => '#22c55e',
            'secondary_color' => '#eeeeee',
            'font_family' => 'Bebas Neue',
            'hook_style' => 'full',
        ]);

        $project = app(ProjectCreator::class)->create($this->user->id, 'https://youtu.be/dQw4w9WgXcQ', []);

        $project->refresh()->load(['subtitleSetting', 'hookSetting']);
        $this->assertTrue($project->watermark);
        $this->assertSame('Bebas Neue', $project->subtitleSetting->font_family);
        $this->assertSame('#22c55e', $project->subtitleSetting->highlight_color);
        $this->assertSame('#eeeeee', $project->subtitleSetting->text_color);
        $this->assertSame('#eeeeee', $project->hookSetting->text_color);
        $this->assertSame('full', $project->hookSetting->background_style);
    }

    public function test_explicit_project_settings_override_brand_kit(): void
    {
        BrandKit::create([
            'user_id' => $this->user->id,
            'primary_color' => '#22c55e',
            'font_family' => 'Bebas Neue',
            'hook_style' => 'full',
        ]);

        $settings = ClipSettings::normalize([
            'watermark' => false,
            'subtitle' => ['font_family' => 'Roboto', 'highlight_color' => '#f97316'],
            'hook' => ['background_style' => 'none'],
        ]);
        $project = app(ProjectCreator::class)->create($this->user->id, 'https://youtu.be/dQw4w9WgXcQ', $settings);

        $project->load(['subtitleSetting', 'hookSetting']);
        $this->assertFalse($project->watermark);
        $this->assertSame('Roboto', $project->subtitleSetting->font_family);
        $this->assertSame('#f97316', $project->subtitleSetting->highlight_color);
        $this->assertSame('none', $project->hookSetting->background_style);
    }

    public function test_project_without_brand_kit_uses_built_in_defaults(): void
    {
        $project = app(ProjectCreator::class)->create($this->user->id, 'https://youtu.be/dQw4w9WgXcQ', []);

        $project->load(['subtitleSetting', 'hookSetting']);
        $this->assertFalse($project->watermark);
        $this->assertSame('Montserrat', $project->subtitleSetting->font_family);
        $this->assertSame('#facc15', $project->subtitleSetting->highlight_color);
        $this->assertSame('semi', $project->hookSetting->background_style);
        $this->assertInstanceOf(ClipProject::class, $project);
    }
}

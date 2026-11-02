<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A named clip look. user_id null = built in, offered to everyone and read-only.
        Schema::create('style_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('slug', 40)->nullable()->unique(); // built-ins only
            $table->string('name', 60);
            $table->json('settings');
            $table->timestamps();
        });

        // Plain columns, nulled by hand when a preset is deleted (SQLite can't add a foreign key to an existing table)
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('default_style_preset_id')->nullable();
        });
        Schema::table('video_sources', function (Blueprint $table) {
            // Set: every new project of this source takes the preset's current look
            $table->unsignedBigInteger('style_preset_id')->nullable()->index();
        });

        $edit = fn (bool $zoom, bool $progress, bool $title, bool $broll, bool $sfx) => ['jump_cut' => true, 'zoom' => $zoom, 'progress' => $progress, 'title' => $title, 'broll' => $broll, 'sfx' => $sfx];
        $sub = fn (string $font, int $size, string $hl, string $pos, string $bg, string $style, bool $emoji) => [
            'enabled' => true, 'font_family' => $font, 'font_size' => $size, 'text_color' => '#ffffff', 'highlight_color' => $hl,
            'position' => $pos, 'position_y' => null, 'background_style' => $bg, 'style' => $style, 'emoji' => $emoji,
        ];
        $hook = fn (bool $on, float $secs, string $pos, string $bg) => ['enabled' => $on, 'duration_seconds' => $secs, 'position' => $pos, 'text_color' => '#ffffff', 'background_style' => $bg];
        $builtins = [
            // Calm and readable: no zoom, b-roll or sound effects, topic held at the top
            'podcast-bersih' => ['Podcast bersih', [
                'layout_type' => 'reframe', 'edit' => $edit(false, true, true, false, false),
                'subtitle' => $sub('Montserrat', 52, '#facc15', 'bottom', 'semi', 'karaoke', false),
                'hook' => $hook(true, 3.0, 'top', 'semi'),
                'music' => ['enabled' => false, 'mood' => null, 'volume' => 20], 'watermark' => false, 'brand' => false,
            ]],
            // Everything moving: zoom, b-roll, sound effects, pop subtitles, upbeat music
            'energik' => ['Energik', [
                'layout_type' => 'reframe', 'edit' => $edit(true, true, false, true, true),
                'subtitle' => $sub('Bebas Neue', 68, '#22c55e', 'center', 'none', 'pop', true),
                'hook' => $hook(true, 2.5, 'center', 'full'),
                'music' => ['enabled' => true, 'mood' => 'energetic', 'volume' => 25], 'watermark' => false, 'brand' => false,
            ]],
            // Only the talking: one word at a time, no hook, no extras
            'minimalis' => ['Minimalis', [
                'layout_type' => 'reframe', 'edit' => $edit(false, false, false, false, false),
                'subtitle' => $sub('Arial', 48, '#ffffff', 'bottom', 'none', 'word', false),
                'hook' => $hook(false, 3.0, 'center', 'semi'),
                'music' => ['enabled' => false, 'mood' => null, 'volume' => 20], 'watermark' => false, 'brand' => false,
            ]],
            // The user's brand kit: its font, colours and logo over a standard look
            'brand-kit' => ['Brand kit', [
                'layout_type' => 'reframe', 'edit' => $edit(true, true, false, true, false),
                'subtitle' => $sub('Montserrat', 56, '#facc15', 'bottom', 'semi', 'karaoke', true),
                'hook' => $hook(true, 3.0, 'center', 'semi'),
                'music' => ['enabled' => false, 'mood' => null, 'volume' => 25], 'watermark' => true, 'brand' => true,
            ]],
        ];
        foreach ($builtins as $slug => [$name, $settings]) {
            DB::table('style_presets')->insert([
                'slug' => $slug, 'name' => $name, 'settings' => json_encode($settings),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('video_sources', function (Blueprint $table) {
            $table->dropIndex(['style_preset_id']);
            $table->dropColumn('style_preset_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('default_style_preset_id');
        });
        Schema::dropIfExists('style_presets');
    }
};

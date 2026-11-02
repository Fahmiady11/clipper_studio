<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clip_projects', function (Blueprint $table) {
            // subtle | energetic (SfxCues::STYLES); only used when sfx is on
            $table->string('sfx_style', 12)->default('subtle')->after('sfx');
        });

        $this->setBuiltin('energetic');
    }

    public function down(): void
    {
        $this->setBuiltin(null);

        Schema::table('clip_projects', function (Blueprint $table) {
            $table->dropColumn('sfx_style');
        });
    }

    /** The built-in Energik preset sounds energetic; the others keep the default. */
    private function setBuiltin(?string $style): void
    {
        $row = DB::table('style_presets')->where('slug', 'energik')->first();
        if (! $row) {
            return;
        }
        $settings = json_decode($row->settings, true);
        if ($style === null) {
            unset($settings['edit']['sfx_style']);
        } else {
            $settings['edit']['sfx_style'] = $style;
        }
        DB::table('style_presets')->where('id', $row->id)->update(['settings' => json_encode($settings)]);
    }
};

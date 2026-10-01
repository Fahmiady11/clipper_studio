<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Credit/licence for files in the music library (the files themselves live
        // in one folder per mood; a track copied in by hand simply has no row yet)
        Schema::create('music_tracks', function (Blueprint $table) {
            $table->id();
            $table->string('path')->unique(); // relative to the library root, e.g. "chill/song.mp3"
            $table->string('license', 20)->default('cc-by'); // cc-by (credit required) | free (no attribution)
            $table->text('credit')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('music_tracks');
    }
};

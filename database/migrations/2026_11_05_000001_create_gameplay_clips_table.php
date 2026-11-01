<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gameplay_clips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);              // original file name, shown in settings
            $table->string('path');                   // local disk: gameplay/<user>/<uuid>.<ext>
            $table->string('thumb_path')->nullable(); // local disk: gameplay/<user>/<uuid>.jpg
            $table->unsignedBigInteger('size_bytes');
            $table->float('duration');                // seconds
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gameplay_clips');
    }
};

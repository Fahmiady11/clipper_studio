<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Autopilot: approved clips go to the next free daily YouTube slot
        Schema::create('youtube_autopost_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('youtube_account_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('max_per_day')->default(3);
            $table->json('slot_times'); // ["09:00", "15:00", "20:00"], wall clock in `timezone`
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('youtube_autopost_settings');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per user and platform: approved clips go to the next free daily slot there
        Schema::create('autopost_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 16); // youtube | facebook
            // Id in the platform's account table (youtube_accounts, meta_targets); may point at a disconnected one
            $table->unsignedBigInteger('account_id')->nullable();
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('max_per_day')->default(3);
            $table->json('slot_times'); // ["09:00", "15:00", "20:00"], wall clock in `timezone`
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->timestamps();

            $table->unique(['user_id', 'platform']);
        });

        DB::table('youtube_autopost_settings')->orderBy('id')->each(function ($row) {
            DB::table('autopost_settings')->insert([
                'user_id' => $row->user_id,
                'platform' => 'youtube',
                'account_id' => $row->youtube_account_id,
                'enabled' => $row->enabled,
                'max_per_day' => $row->max_per_day,
                'slot_times' => $row->slot_times,
                'timezone' => $row->timezone,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        });

        Schema::dropIfExists('youtube_autopost_settings');
    }

    public function down(): void
    {
        Schema::create('youtube_autopost_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('youtube_account_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('max_per_day')->default(3);
            $table->json('slot_times');
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->timestamps();
        });

        DB::table('autopost_settings')->where('platform', 'youtube')->orderBy('id')->each(function ($row) {
            DB::table('youtube_autopost_settings')->insert([
                'user_id' => $row->user_id,
                'youtube_account_id' => DB::table('youtube_accounts')->where('id', $row->account_id)->exists() ? $row->account_id : null,
                'enabled' => $row->enabled,
                'max_per_day' => $row->max_per_day,
                'slot_times' => $row->slot_times,
                'timezone' => $row->timezone,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        });

        Schema::dropIfExists('autopost_settings');
    }
};

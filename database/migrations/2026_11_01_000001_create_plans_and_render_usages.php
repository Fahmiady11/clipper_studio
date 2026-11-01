<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 24)->unique();
            $table->string('name', 48);
            $table->unsignedInteger('price_usd_cents')->default(0); // per month
            $table->unsignedInteger('render_minutes'); // output minutes per month
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        DB::table('plans')->insert([
            ['slug' => 'free', 'name' => 'Free', 'price_usd_cents' => 0, 'render_minutes' => 30, 'sort' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'pro', 'name' => 'Pro', 'price_usd_cents' => 2900, 'render_minutes' => 300, 'sort' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('is_admin')->constrained('plans')->nullOnDelete(); // null = free
        });

        // One row per render attempt. reserved = counted against the quota while queued/running,
        // charged = finished, with the real output length and cost.
        Schema::create('render_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('generated_clip_id')->index();
            $table->string('period', 7); // Y-m
            $table->string('status', 8)->default('reserved');
            $table->unsignedInteger('seconds');
            $table->unsignedInteger('cpu_seconds')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->timestamps();

            $table->index(['user_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('render_usages');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
        });
        Schema::dropIfExists('plans');
    }
};

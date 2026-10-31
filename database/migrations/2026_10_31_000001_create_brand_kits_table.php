<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_kits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('logo_path')->nullable();
            $table->string('primary_color', 7)->nullable();   // kata penting hook & subtitle
            $table->string('secondary_color', 7)->nullable(); // teks dasar hook & subtitle
            $table->string('font_family', 32)->nullable();
            $table->string('hook_style', 8)->default('semi'); // none|semi|full, = hook background_style
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_kits');
    }
};

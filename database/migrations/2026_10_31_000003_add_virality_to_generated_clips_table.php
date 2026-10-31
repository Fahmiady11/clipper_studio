<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_clips', function (Blueprint $table) {
            $table->unsignedTinyInteger('viral_score')->nullable()->after('viral_potential');
            // Canonical short reasons, in stable order: hook / flow / value
            $table->json('viral_reasons')->nullable()->after('viral_score');
        });
    }

    public function down(): void
    {
        Schema::table('generated_clips', function (Blueprint $table) {
            $table->dropColumn(['viral_score', 'viral_reasons']);
        });
    }
};

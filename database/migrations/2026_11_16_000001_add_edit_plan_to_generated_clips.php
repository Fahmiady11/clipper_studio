<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_clips', function (Blueprint $table) {
            // EditPlan made at the first render: {key, broll, emoji, punch}, source seconds
            $table->json('edit_plan')->nullable()->after('broll_json');
        });
    }

    public function down(): void
    {
        Schema::table('generated_clips', function (Blueprint $table) {
            $table->dropColumn('edit_plan');
        });
    }
};

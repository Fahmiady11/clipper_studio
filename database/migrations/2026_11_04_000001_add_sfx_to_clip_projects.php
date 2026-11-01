<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clip_projects', function (Blueprint $table) {
            // Whoosh/pop/ding sound effects. Off for existing projects so a re-render sounds as before
            $table->boolean('sfx')->default(false)->after('broll');
        });
    }

    public function down(): void
    {
        Schema::table('clip_projects', function (Blueprint $table) {
            $table->dropColumn('sfx');
        });
    }
};

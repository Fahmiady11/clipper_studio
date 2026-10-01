<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subtitle_settings', function (Blueprint $table) {
            // Dragged position: vertical centre of the subtitle as % of frame height.
            // Null = use the top/center/bottom preset in `position`.
            $table->decimal('position_y', 5, 2)->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('subtitle_settings', function (Blueprint $table) {
            $table->dropColumn('position_y');
        });
    }
};

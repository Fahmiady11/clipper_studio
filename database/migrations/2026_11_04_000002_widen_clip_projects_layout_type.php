<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * layout_type was an enum of reframe/gaussian; the split-screen "gameplay"
 * layout needs a third value. A plain string keeps the next layout from
 * needing another migration (the allowed values are checked in ClipSettings).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // PostgreSQL enum = CHECK constraint; drop it before the type change
            DB::statement('ALTER TABLE clip_projects DROP CONSTRAINT IF EXISTS clip_projects_layout_type_check');
        }

        Schema::table('clip_projects', function (Blueprint $table) {
            $table->string('layout_type', 20)->default('reframe')->change();
        });
    }

    public function down(): void
    {
        // A string column holds the old values too; nothing to restore
    }
};

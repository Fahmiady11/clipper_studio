<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->json('abilities')->nullable()->after('token_hash');
        });

        // Tokens issued before scopes existed keep working: they get everything
        DB::table('api_tokens')->update(['abilities' => json_encode(['read', 'render', 'publish'])]);
    }

    public function down(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->dropColumn('abilities');
        });
    }
};

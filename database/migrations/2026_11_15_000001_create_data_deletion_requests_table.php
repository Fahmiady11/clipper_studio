<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A data deletion request from Meta (Facebook or Threads). The status page looks it up by
        // confirmation code; the platform user id is not kept, the deleted rows were the only link.
        Schema::create('data_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->string('confirmation_code', 32)->unique();
            $table->string('provider', 16); // facebook | threads
            $table->string('status', 16); // completed
            $table->unsignedInteger('connections_removed')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_deletion_requests');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_queue_entries', function (Blueprint $table) {
            $table->string('guestName')->nullable();
            $table->string('guestEmail')->nullable()->index();
            $table->string('guestPhone', 50)->nullable();
            $table->string('guestAccessTokenHash', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        if (DB::table('appointment_queue_entries')->whereNotNull('guestAccessTokenHash')->exists()) {
            throw new RuntimeException('Guest virtual queue tickets exist; archive them before rolling back this migration.');
        }

        Schema::table('appointment_queue_entries', function (Blueprint $table) {
            $table->dropUnique(['guestAccessTokenHash']);
            $table->dropIndex(['guestEmail']);
            $table->dropColumn(['guestName', 'guestEmail', 'guestPhone', 'guestAccessTokenHash']);
        });
    }
};

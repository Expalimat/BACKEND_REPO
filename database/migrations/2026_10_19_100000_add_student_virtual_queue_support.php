<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_services', function (Blueprint $table) {
            $table->boolean('queueEnabled')->default(false);
            $table->boolean('queuePaused')->default(false);
            $table->unsignedInteger('queueCapacity')->default(50);
        });

        Schema::table('appointment_queue_entries', function (Blueprint $table) {
            $table->string('appointmentId')->nullable()->change();
            $table->string('userId')->nullable()->after('appointmentId')->index();
            $table->foreign('userId')->references('id')->on('users')->nullOnDelete();
        });

        DB::table('appointment_services')
            ->where('code', 'CTC')
            ->update(['queueEnabled' => true]);
    }

    public function down(): void
    {
        if (DB::table('appointment_queue_entries')->whereNull('appointmentId')->exists()) {
            throw new RuntimeException('Virtual queue tickets exist; cancel or archive them before rolling back this migration.');
        }

        Schema::table('appointment_queue_entries', function (Blueprint $table) {
            $table->dropForeign(['userId']);
            $table->dropIndex(['userId']);
            $table->dropColumn('userId');
            $table->string('appointmentId')->nullable(false)->change();
        });

        Schema::table('appointment_services', function (Blueprint $table) {
            $table->dropColumn(['queueEnabled', 'queuePaused', 'queueCapacity']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->unique(['id', 'serviceId'], 'appointments_id_service_unique');
        });

        Schema::table('appointment_queue_entries', function (Blueprint $table) {
            $table->foreign(['appointmentId', 'serviceId'], 'queue_entry_appointment_service_foreign')
                ->references(['id', 'serviceId'])
                ->on('appointments')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointment_queue_entries', function (Blueprint $table) {
            $table->dropForeign('queue_entry_appointment_service_foreign');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropUnique('appointments_id_service_unique');
        });
    }
};

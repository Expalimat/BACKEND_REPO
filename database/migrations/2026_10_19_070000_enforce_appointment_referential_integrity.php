<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_slots', function (Blueprint $table) {
            $table->unique(['serviceId', 'id'], 'appointment_slots_service_id_id_unique');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreign('studentNumber', 'appointments_student_number_foreign')
                ->references('studentNumber')
                ->on('students')
                ->restrictOnDelete();
            $table->foreign(['serviceId', 'slotId'], 'appointments_service_slot_foreign')
                ->references(['serviceId', 'id'])
                ->on('appointment_slots')
                ->restrictOnDelete();
            $table->foreign('rescheduledFromId', 'appointments_rescheduled_from_foreign')
                ->references('id')
                ->on('appointments')
                ->nullOnDelete();
            $table->foreign('rescheduledToId', 'appointments_rescheduled_to_foreign')
                ->references('id')
                ->on('appointments')
                ->nullOnDelete();
            $table->index(['studentNumber', 'appointmentDate', 'status'], 'appointments_student_date_status_index');
        });

        Schema::table('appointment_status_history', function (Blueprint $table) {
            $table->foreign('actorUserId', 'appointment_history_actor_user_foreign')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->index(['appointmentId', 'seq'], 'appointment_history_appointment_seq_index');
        });
    }

    public function down(): void
    {
        Schema::table('appointment_status_history', function (Blueprint $table) {
            $table->dropForeign('appointment_history_actor_user_foreign');
            $table->dropIndex('appointment_history_appointment_seq_index');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign('appointments_student_number_foreign');
            $table->dropForeign('appointments_service_slot_foreign');
            $table->dropForeign('appointments_rescheduled_from_foreign');
            $table->dropForeign('appointments_rescheduled_to_foreign');
            $table->dropIndex('appointments_student_date_status_index');
        });

        Schema::table('appointment_slots', function (Blueprint $table) {
            $table->dropUnique('appointment_slots_service_id_id_unique');
        });
    }
};

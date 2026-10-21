<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_services', function (Blueprint $table) {
            $table->boolean('publicBookable')->default(false)->index();
        });

        DB::table('appointment_services')->insertOrIgnore([
            'id' => 'SVC-FRESHMAN-ENROLLMENT',
            'code' => 'FRESHMAN-ENROLLMENT',
            'name' => 'Freshman Enrollment Appointment',
            'description' => 'Schedule an appointment for incoming freshman enrollment.',
            'isActive' => true,
            'publicBookable' => true,
            'createdAt' => now('Asia/Manila')->format('Y-m-d h:i A'),
            'updatedAt' => now('Asia/Manila')->format('Y-m-d h:i A'),
        ]);

        Schema::table('appointments', function (Blueprint $table) {
            $table->string('studentNumber')->nullable()->change();
            $table->string('studentName')->nullable()->change();
            $table->string('applicantEmail')->nullable();
            $table->string('applicantPhone', 50)->nullable();
            $table->index('applicantEmail');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['applicantEmail']);
            $table->dropColumn(['applicantEmail', 'applicantPhone']);
            $table->string('studentNumber')->nullable(false)->change();
            $table->string('studentName')->nullable(false)->change();
        });

        Schema::table('appointment_services', function (Blueprint $table) {
            $table->dropIndex(['publicBookable']);
            $table->dropColumn('publicBookable');
        });
    }
};

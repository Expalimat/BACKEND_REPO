<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('appointment_services')->updateOrInsert(
            ['code' => 'FRESHMAN-ENROLLMENT'],
            [
                'id' => 'SVC-FRESHMAN-ENROLLMENT',
                'name' => 'Freshman Enrollment Appointment',
                'description' => 'Schedule an appointment for incoming freshman enrollment.',
                'isActive' => true,
                'publicBookable' => true,
                'createdAt' => now('Asia/Manila')->format('Y-m-d h:i A'),
                'updatedAt' => now('Asia/Manila')->format('Y-m-d h:i A'),
            ]
        );
    }

    public function down(): void
    {
        DB::table('appointment_services')->where('code', 'FRESHMAN-ENROLLMENT')->delete();
    }
};

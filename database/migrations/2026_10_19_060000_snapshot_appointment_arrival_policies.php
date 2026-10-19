<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->unsignedSmallInteger('cancellationCutoffMinutes')->default(0);
            $table->unsignedSmallInteger('lateArrivalGraceMinutes')->default(0);
            $table->unsignedSmallInteger('earlyArrivalWindowMinutes')->default(30);
        });

        foreach (DB::table('appointments')->orderBy('seq')->get() as $appointment) {
            $service = DB::table('appointment_services')->where('id', $appointment->serviceId)->first();
            if (! $service) {
                continue;
            }
            DB::table('appointments')->where('id', $appointment->id)->update([
                'cancellationCutoffMinutes' => $service->cancellationCutoffMinutes,
                'lateArrivalGraceMinutes' => $service->lateArrivalGraceMinutes,
                'earlyArrivalWindowMinutes' => $service->earlyArrivalWindowMinutes,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn([
                'cancellationCutoffMinutes',
                'lateArrivalGraceMinutes',
                'earlyArrivalWindowMinutes',
            ]);
        });
    }
};

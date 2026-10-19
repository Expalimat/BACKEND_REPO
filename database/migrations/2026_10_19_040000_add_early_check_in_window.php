<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointment_services', function (Blueprint $table) {
            $table->unsignedSmallInteger('earlyArrivalWindowMinutes')->default(30);
        });
    }

    public function down(): void
    {
        Schema::table('appointment_services', function (Blueprint $table) {
            $table->dropColumn('earlyArrivalWindowMinutes');
        });
    }
};

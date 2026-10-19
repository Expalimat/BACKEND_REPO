<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_service_staff', function (Blueprint $table) {
            $table->bigIncrements('seq');
            $table->string('serviceId');
            $table->string('userId');
            $table->string('assignedByUserId')->nullable();
            $table->string('createdAt');
            $table->unique(['serviceId', 'userId'], 'appointment_service_staff_service_user_unique');
            $table->foreign('serviceId')->references('id')->on('appointment_services')->cascadeOnDelete();
            $table->foreign('userId')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('assignedByUserId')->references('id')->on('users')->nullOnDelete();
            $table->index(['userId', 'serviceId'], 'appointment_service_staff_user_service_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_service_staff');
    }
};

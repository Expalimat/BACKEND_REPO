<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_notification_deliveries', function (Blueprint $table) {
            $table->bigIncrements('seq');
            $table->string('id')->unique();
            $table->string('appointmentId');
            $table->string('notificationType');
            $table->string('status')->default('QUEUED');
            $table->string('queuedAt');
            $table->string('deliveredAt')->nullable();
            $table->text('failureMessage')->nullable();
            $table->foreign('appointmentId')->references('id')->on('appointments')->cascadeOnDelete();
            $table->unique(['appointmentId', 'notificationType']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_notification_deliveries');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_services', function (Blueprint $table) {
            $table->bigIncrements('seq');
            $table->string('id')->unique();
            $table->string('serviceName');
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->text('requiredDocuments')->nullable();
            $table->string('estimatedDuration')->default('30 minutes');
            $table->string('status')->default('Active');
            $table->string('createdAt')->nullable();
            $table->string('updatedAt')->nullable();
        });

        Schema::create('appointment_slots', function (Blueprint $table) {
            $table->bigIncrements('seq');
            $table->string('id')->unique();
            $table->string('serviceId')->index();
            $table->date('appointmentDate');
            $table->time('startTime');
            $table->time('endTime');
            $table->integer('maxCapacity')->default(1);
            $table->integer('currentBookings')->default(0);
            $table->string('status')->default('Open');
            $table->string('createdAt')->nullable();
            $table->string('updatedAt')->nullable();
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->bigIncrements('seq');
            $table->string('id')->unique();
            $table->string('serviceId')->index();
            $table->string('slotId')->index();
            $table->string('studentNumber')->index();
            $table->string('studentName');
            $table->string('referenceNumber')->unique();
            $table->string('status')->default('PENDING');
            $table->text('notes')->nullable();
            $table->string('rescheduledFromId')->nullable();
            $table->text('overrideReason')->nullable();
            $table->string('createdAt')->nullable();
            $table->string('updatedAt')->nullable();
        });

        Schema::create('appointment_queue_entries', function (Blueprint $table) {
            $table->bigIncrements('seq');
            $table->string('id')->unique();
            $table->string('appointmentId')->unique();
            $table->integer('queueNumber');
            $table->date('queueDate');
            $table->string('queueScope');
            $table->string('status')->default('WAITING');
            $table->string('checkInAt')->nullable();
            $table->string('calledAt')->nullable();
            $table->string('serviceStartAt')->nullable();
            $table->string('completionAt')->nullable();
            $table->text('overrideReason')->nullable();
            $table->string('createdAt')->nullable();
            $table->string('updatedAt')->nullable();
            $table->unique(['queueScope', 'queueDate', 'queueNumber']);
        });
    }

    public function down(): void
    {
        foreach (['appointment_queue_entries', 'appointments', 'appointment_slots', 'appointment_services'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

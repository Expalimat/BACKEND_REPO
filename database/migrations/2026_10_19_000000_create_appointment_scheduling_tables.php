<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_services', function (Blueprint $table) {
            $table->bigIncrements('seq');
            $table->string('id')->unique();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('isActive')->default(true);
            $table->string('createdAt');
            $table->string('updatedAt');
        });

        Schema::create('appointment_slots', function (Blueprint $table) {
            $table->bigIncrements('seq');
            $table->string('id')->unique();
            $table->string('serviceId');
            $table->date('appointmentDate');
            $table->time('startTime');
            $table->time('endTime');
            $table->unsignedInteger('capacity');
            $table->string('status')->default('Open');
            $table->string('createdAt');
            $table->foreign('serviceId')->references('id')->on('appointment_services')->cascadeOnDelete();
            $table->unique(['serviceId', 'appointmentDate', 'startTime']);
            $table->index(['appointmentDate', 'status']);
        });

        Schema::create('appointment_daily_sequences', function (Blueprint $table) {
            $table->id();
            $table->date('appointmentDate')->unique();
            $table->unsignedInteger('lastNumber')->default(0);
        });

        Schema::create('appointment_queue_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('serviceId');
            $table->date('appointmentDate');
            $table->unsignedInteger('lastNumber')->default(0);
            $table->foreign('serviceId')->references('id')->on('appointment_services')->cascadeOnDelete();
            $table->unique(['serviceId', 'appointmentDate']);
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->bigIncrements('seq');
            $table->string('id')->unique();
            $table->string('appointmentNumber')->unique();
            $table->string('serviceId');
            $table->string('slotId');
            $table->string('documentRequestId')->nullable();
            $table->string('studentNumber')->index();
            $table->string('studentName');
            $table->text('purpose')->nullable();
            $table->string('appointmentDate');
            $table->string('status')->default('Confirmed');
            $table->unsignedInteger('queueNumber')->nullable();
            $table->string('queueTicket')->nullable();
            $table->string('createdAt');
            $table->string('updatedAt');
            $table->string('checkedInAt')->nullable();
            $table->string('calledAt')->nullable();
            $table->string('completedAt')->nullable();
            $table->text('staffNotes')->nullable();
            $table->foreign('serviceId')->references('id')->on('appointment_services')->restrictOnDelete();
            $table->foreign('slotId')->references('id')->on('appointment_slots')->restrictOnDelete();
            $table->foreign('documentRequestId')->references('id')->on('document_requests')->nullOnDelete();
            $table->index(['serviceId', 'appointmentDate', 'status', 'queueNumber']);
            $table->index(['slotId', 'status']);
        });

        DB::table('appointment_services')->insert([
            'id' => 'SVC-CTC-CLAIM',
            'code' => 'CTC',
            'name' => 'Certified True Copy (CTC) / Document Claiming',
            'description' => 'Schedule a visit to claim approved certified true copies and other documents.',
            'isActive' => true,
            'createdAt' => now('Asia/Manila')->format('Y-m-d h:i A'),
            'updatedAt' => now('Asia/Manila')->format('Y-m-d h:i A'),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('appointment_queue_sequences');
        Schema::dropIfExists('appointment_daily_sequences');
        Schema::dropIfExists('appointment_slots');
        Schema::dropIfExists('appointment_services');
    }
};

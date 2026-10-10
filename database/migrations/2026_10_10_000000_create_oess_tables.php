<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Replaces the default Laravel users table (it uses numeric ids, the frontend uses "USR-001")
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('seq');
            $t->string('id')->unique();
            $t->string('username')->unique();
            $t->string('password');
            $t->string('role');
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('studentNumber')->nullable();
            $t->string('program')->nullable();
            $t->string('yearLevel')->nullable();
            $t->string('department')->nullable();
            $t->string('status')->default('Active');
            $t->string('avatar')->nullable();
        });

        Schema::create('students', function (Blueprint $t) {
            $t->bigIncrements('seq');
            $t->string('studentNumber')->unique();
            $t->string('name');
            $t->string('program')->nullable();
            $t->string('yearLevel')->nullable();
            $t->string('academicStatus')->nullable();
            $t->string('gpa')->nullable();
            $t->string('contact')->nullable();
            $t->string('email')->nullable();
            $t->text('address')->nullable();
        });

        Schema::create('terms', function (Blueprint $t) {
            $t->bigIncrements('seq');
            $t->string('id')->unique();
            $t->string('academicYear');
            $t->string('semester');
            $t->boolean('isCurrent')->default(false);
            $t->string('status');
        });

        Schema::create('subject_offerings', function (Blueprint $t) {
            $t->bigIncrements('seq');
            $t->string('id')->unique();
            $t->string('subjectCode');
            $t->string('subjectTitle');
            $t->integer('units');
            $t->string('section');
            $t->string('term');
            $t->string('schedule')->nullable();
            $t->string('room')->nullable();
            $t->string('instructor')->nullable();
            $t->integer('totalSlots')->default(0);
            $t->integer('availableSlots')->default(0);
            $t->string('prerequisites')->nullable();
            $t->boolean('isPrerequisiteSatisfied')->default(true);
            $t->string('status')->default('Open');
        });

        Schema::create('enrollments', function (Blueprint $t) {
            $t->bigIncrements('seq');
            $t->string('id')->unique();
            $t->string('studentNumber')->index();
            $t->string('studentName');
            $t->string('program')->nullable();
            $t->string('term');
            $t->string('academicYear')->nullable();
            $t->string('semester')->nullable();
            $t->string('dateSubmitted');
            $t->string('status')->default('Pending');
            $t->integer('totalUnits')->default(0);
            $t->text('remarks')->nullable();
            $t->json('items');
            $t->json('fees');
        });

        Schema::create('payments', function (Blueprint $t) {
            $t->bigIncrements('seq');
            $t->string('id')->unique();
            $t->string('enrollmentId')->nullable();
            $t->string('studentNumber')->index();
            $t->string('studentName');
            $t->decimal('amount', 12, 2);
            $t->string('paymentMethod');
            $t->string('referenceNumber');
            $t->string('paymentDate');
            $t->string('status')->default('Pending');
            $t->string('proofFileName')->nullable();
            $t->string('proofFileSize')->nullable();
            $t->string('verifiedBy')->default('');
            $t->string('verifiedDate')->default('');
            $t->text('remarks')->nullable();
        });

        Schema::create('clearances', function (Blueprint $t) {
            $t->bigIncrements('seq');
            $t->string('id')->unique();
            $t->string('studentNumber')->index();
            $t->string('studentName');
            $t->string('program')->nullable();
            $t->string('term');
            $t->string('overallStatus');
            $t->json('signoffs');
        });

        Schema::create('grades', function (Blueprint $t) {
            $t->bigIncrements('seq');
            $t->string('id')->unique();
            $t->string('studentNumber')->index();
            $t->string('studentName');
            $t->string('term');
            $t->string('subjectCode');
            $t->string('subjectTitle');
            $t->integer('units')->default(3);
            $t->string('grade');
            $t->string('status')->default('Encoded');
            $t->string('encodedBy')->default('');
            $t->string('dateEncoded')->default('');
            $t->string('validatedBy')->default('');
            $t->string('dateValidated')->default('');
        });

        Schema::create('document_requests', function (Blueprint $t) {
            $t->bigIncrements('seq');
            $t->string('id')->unique();
            $t->string('trackingNumber')->unique();
            $t->string('studentNumber')->index();
            $t->string('studentName');
            $t->string('documentType');
            $t->text('purpose')->nullable();
            $t->string('requestDate');
            $t->string('status')->default('Pending');
            $t->string('releaseDate')->default('');
            $t->text('remarks')->nullable();
            $t->string('downloadUrl')->default('');
            $t->string('processedBy')->default('');
        });

        Schema::create('notifications', function (Blueprint $t) {
            $t->bigIncrements('seq');
            $t->string('id')->unique();
            $t->string('userId')->nullable()->index();
            $t->string('title');
            $t->text('message');
            $t->string('date');
            $t->boolean('isRead')->default(false);
            $t->string('relatedModule')->nullable();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->bigIncrements('seq');
            $t->string('id')->unique();
            $t->string('user');
            $t->string('action');
            $t->string('dateTime');
            $t->string('module');
            $t->text('details')->nullable();
        });

        Schema::create('system_settings', function (Blueprint $t) {
            $t->id();
            $t->json('data');
        });
    }

    public function down(): void
    {
        foreach (['system_settings', 'audit_logs', 'notifications', 'document_requests', 'grades',
                  'clearances', 'payments', 'enrollments', 'subject_offerings', 'terms', 'students', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        Schema::create('queue_services', function (Blueprint $table) {
            $table->bigIncrements('seq');
            $table->string('id')->unique();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->boolean('isActive')->default(true);
            $table->boolean('publicBookable')->default(false)->index();
            $table->boolean('queueEnabled')->default(false);
            $table->boolean('queuePaused')->default(false);
            $table->unsignedInteger('queueCapacity')->default(50);
            $table->unsignedSmallInteger('serviceDurationMinutes')->default(30);
            $table->string('createdAt');
            $table->string('updatedAt');
        });

        DB::table('queue_services')->insertUsing(
            ['seq', 'id', 'code', 'name', 'description', 'instructions', 'isActive', 'publicBookable', 'queueEnabled', 'queuePaused', 'queueCapacity', 'serviceDurationMinutes', 'createdAt', 'updatedAt'],
            DB::table('appointment_services')->select([
                'seq', 'id', 'code', 'name', 'description', 'instructions', 'isActive', 'publicBookable', 'queueEnabled', 'queuePaused', 'queueCapacity', 'serviceDurationMinutes', 'createdAt', 'updatedAt',
            ])
        );
        DB::table('queue_services')->where('code', 'FRESHMAN-ENROLLMENT')->update([
            'name' => 'Freshman Enrollment',
            'description' => 'Join the virtual queue for incoming freshman enrollment.',
            'publicBookable' => false,
        ]);
        DB::table('queue_services')->where('code', 'CTC')
            ->where('description', 'Schedule a visit to claim approved certified true copies and other documents.')
            ->update([
                'description' => 'Join the virtual queue to claim approved certified true copies and other documents.',
            ]);

        Schema::create('queue_tickets', function (Blueprint $table) {
            $table->bigIncrements('seq');
            $table->string('id')->unique();
            $table->string('userId')->nullable()->index();
            $table->string('guestName')->nullable();
            $table->string('guestEmail')->nullable()->index();
            $table->string('guestPhone', 50)->nullable();
            $table->string('guestAccessTokenHash', 64)->nullable()->unique();
            $table->string('serviceId');
            $table->date('queueDate');
            $table->unsignedInteger('queueNumber');
            $table->string('status');
            $table->string('checkedInAt')->nullable();
            $table->string('calledAt')->nullable();
            $table->string('serviceStartedAt')->nullable();
            $table->string('completedAt')->nullable();
            $table->text('overrideReason')->nullable();
            $table->string('createdAt');
            $table->string('updatedAt');
            $table->foreign('userId')->references('id')->on('users')->nullOnDelete();
            $table->foreign('serviceId')->references('id')->on('queue_services')->restrictOnDelete();
            $table->unique(['serviceId', 'queueDate', 'queueNumber']);
            $table->index(['serviceId', 'queueDate', 'status', 'queueNumber']);
        });

        DB::table('queue_tickets')->insertUsing(
            ['seq', 'id', 'userId', 'guestName', 'guestEmail', 'guestPhone', 'guestAccessTokenHash', 'serviceId', 'queueDate', 'queueNumber', 'status', 'checkedInAt', 'calledAt', 'serviceStartedAt', 'completedAt', 'createdAt', 'updatedAt'],
            DB::table('appointment_queue_entries')->whereNull('appointmentId')->select([
                'seq', 'id', 'userId', 'guestName', 'guestEmail', 'guestPhone', 'guestAccessTokenHash', 'serviceId', 'queueDate', 'queueNumber', 'status', 'checkedInAt', 'calledAt', 'serviceStartedAt', 'completedAt', 'createdAt', 'updatedAt',
            ])
        );

        Schema::create('queue_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('serviceId');
            $table->date('queueDate');
            $table->unsignedInteger('lastNumber')->default(0);
            $table->foreign('serviceId')->references('id')->on('queue_services')->cascadeOnDelete();
            $table->unique(['serviceId', 'queueDate']);
        });

        foreach (DB::table('appointment_queue_sequences')->get() as $sequence) {
            DB::table('queue_sequences')->insert([
                'id' => $sequence->id,
                'serviceId' => $sequence->serviceId,
                'queueDate' => $sequence->appointmentDate,
                'lastNumber' => max(
                    (int) $sequence->lastNumber,
                    (int) DB::table('queue_tickets')->where('serviceId', $sequence->serviceId)
                        ->where('queueDate', $sequence->appointmentDate)->max('queueNumber')
                ),
            ]);
        }
        foreach (DB::table('queue_tickets')->select('serviceId', 'queueDate')->distinct()->get() as $scope) {
            DB::table('queue_sequences')->insertOrIgnore([
                'serviceId' => $scope->serviceId,
                'queueDate' => $scope->queueDate,
                'lastNumber' => DB::table('queue_tickets')->where('serviceId', $scope->serviceId)
                    ->where('queueDate', $scope->queueDate)->max('queueNumber'),
            ]);
        }

        Schema::create('queue_service_staff', function (Blueprint $table) {
            $table->bigIncrements('seq');
            $table->string('serviceId');
            $table->string('userId');
            $table->string('assignedByUserId')->nullable();
            $table->string('createdAt');
            $table->unique(['serviceId', 'userId'], 'queue_service_staff_service_user_unique');
            $table->foreign('serviceId')->references('id')->on('queue_services')->cascadeOnDelete();
            $table->foreign('userId')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('assignedByUserId')->references('id')->on('users')->nullOnDelete();
            $table->index(['userId', 'serviceId'], 'queue_service_staff_user_service_index');
        });
        DB::table('queue_service_staff')->insertUsing(
            ['seq', 'serviceId', 'userId', 'assignedByUserId', 'createdAt'],
            DB::table('appointment_service_staff')->select(['seq', 'serviceId', 'userId', 'assignedByUserId', 'createdAt'])
        );

        DB::table('notifications')->whereRaw(
            "LOWER(COALESCE(relatedModule, '')) LIKE ? OR LOWER(title) LIKE ? OR LOWER(message) LIKE ?",
            ['%appointment%', '%appointment%', '%appointment%']
        )->delete();

        DB::table('audit_logs')->whereRaw(
            "LOWER(COALESCE(action, '')) LIKE ? OR LOWER(COALESCE(details, '')) LIKE ?",
            ['%appointment%', '%appointment%']
        )->delete();

        if (Schema::hasTable('jobs') && Schema::hasColumn('jobs', 'payload')) {
            DB::table('jobs')->whereRaw('LOWER(payload) LIKE ?', ['%appointment%'])->delete();
        }
        if (Schema::hasTable('failed_jobs') && Schema::hasColumn('failed_jobs', 'payload')) {
            DB::table('failed_jobs')->whereRaw('LOWER(payload) LIKE ?', ['%appointment%'])->delete();
        }
        if (Schema::hasTable('failed_jobs') && Schema::hasColumn('failed_jobs', 'exception')) {
            DB::table('failed_jobs')->whereRaw('LOWER(exception) LIKE ?', ['%appointment%'])->delete();
        }

        foreach ([
            'appointment_notification_deliveries', 'appointment_status_history', 'appointment_service_closures',
            'appointments', 'appointment_slots', 'appointment_daily_sequences', 'appointment_queue_entries',
            'appointment_queue_sequences', 'appointment_service_staff', 'appointment_services',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        throw new RuntimeException('The queue-only migration intentionally deletes appointment records and cannot be reversed.');
    }
};

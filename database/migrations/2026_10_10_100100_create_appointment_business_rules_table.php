<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_business_rules', function (Blueprint $table) {
            $table->bigIncrements('seq');
            $table->string('id')->unique();
            $table->string('name');
            $table->string('ruleType');
            $table->string('serviceId')->nullable()->index();
            $table->tinyInteger('dayOfWeek')->nullable();
            $table->time('startTime')->nullable();
            $table->time('endTime')->nullable();
            $table->boolean('isActive')->default(true);
            $table->string('createdAt')->nullable();
            $table->string('updatedAt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_business_rules');
    }
};

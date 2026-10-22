<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['queue_services' => 'seq', 'queue_tickets' => 'seq', 'queue_service_staff' => 'seq', 'queue_sequences' => 'id'] as $table => $column) {
            DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', '{$column}'), COALESCE((SELECT MAX({$column}) FROM {$table}), 0) + 1, false)");
        }
    }

    public function down(): void
    {
    }
};
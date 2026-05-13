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

        DB::statement(<<<'SQL'
DO $$
BEGIN
    IF to_regtype('notification_type_enum') IS NOT NULL
       AND NOT EXISTS (
           SELECT 1
           FROM pg_enum
           WHERE enumlabel = 'app_update'
           AND enumtypid = 'notification_type_enum'::regtype
       ) THEN
        ALTER TYPE notification_type_enum ADD VALUE 'app_update';
    END IF;
END $$;
SQL);
    }

    public function down(): void
    {
        // PostgreSQL enum values cannot be removed safely without rebuilding dependent columns.
    }
};

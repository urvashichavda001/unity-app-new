<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TYPE notification_type_enum ADD VALUE IF NOT EXISTS 'admin_campaign'");
    }

    public function down(): void
    {
        // PostgreSQL does not support removing enum values safely.
    }
};

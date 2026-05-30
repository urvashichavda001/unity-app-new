<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('event_attendances')) {
            Schema::create('event_attendances', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('event_id');
                $table->uuid('event_registration_id')->nullable();
                $table->uuid('attendee_user_id')->nullable();
                $table->text('qr_token')->nullable();
                $table->uuid('checked_in_by_user_id');
                $table->timestampTz('checked_in_at')->useCurrent();
                $table->string('status', 30)->default('checked_in');
                $table->jsonb('scan_meta')->nullable();
                $table->timestampsTz();

                $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
                $table->foreign('event_registration_id')->references('id')->on('event_registrations')->nullOnDelete();
                $table->foreign('attendee_user_id')->references('id')->on('users')->nullOnDelete();
                $table->foreign('checked_in_by_user_id')->references('id')->on('users')->restrictOnDelete();
            });
        } else {
            Schema::table('event_attendances', function (Blueprint $table): void {
                if (! Schema::hasColumn('event_attendances', 'event_id')) {
                    $table->uuid('event_id');
                }
                if (! Schema::hasColumn('event_attendances', 'event_registration_id')) {
                    $table->uuid('event_registration_id')->nullable();
                }
                if (! Schema::hasColumn('event_attendances', 'attendee_user_id')) {
                    $table->uuid('attendee_user_id')->nullable();
                }
                if (! Schema::hasColumn('event_attendances', 'qr_token')) {
                    $table->text('qr_token')->nullable();
                }
                if (! Schema::hasColumn('event_attendances', 'checked_in_by_user_id')) {
                    $table->uuid('checked_in_by_user_id');
                }
                if (! Schema::hasColumn('event_attendances', 'checked_in_at')) {
                    $table->timestampTz('checked_in_at')->useCurrent();
                }
                if (! Schema::hasColumn('event_attendances', 'status')) {
                    $table->string('status', 30)->default('checked_in');
                }
                if (! Schema::hasColumn('event_attendances', 'scan_meta')) {
                    $table->jsonb('scan_meta')->nullable();
                }
            });
        }

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_event_attendances_event_registration ON event_attendances(event_id, event_registration_id) WHERE event_registration_id IS NOT NULL');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_event_attendances_event_id ON event_attendances(event_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_event_attendances_event_registration_id ON event_attendances(event_registration_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_event_attendances_attendee_user_id ON event_attendances(attendee_user_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_event_attendances_checked_in_by_user_id ON event_attendances(checked_in_by_user_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_event_attendances_qr_token ON event_attendances(qr_token)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_event_attendances_qr_token');
        DB::statement('DROP INDEX IF EXISTS idx_event_attendances_checked_in_by_user_id');
        DB::statement('DROP INDEX IF EXISTS idx_event_attendances_attendee_user_id');
        DB::statement('DROP INDEX IF EXISTS idx_event_attendances_event_registration_id');
        DB::statement('DROP INDEX IF EXISTS idx_event_attendances_event_id');
    }
};

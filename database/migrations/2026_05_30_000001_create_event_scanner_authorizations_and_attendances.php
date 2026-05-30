<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_scanner_authorizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('scanner_user_id');
            $table->uuid('assigned_by_user_id')->nullable();
            $table->string('status', 30)->default('active');
            $table->timestampTz('assigned_at')->useCurrent();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('scanner_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('assigned_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['event_id', 'scanner_user_id'], 'uq_event_scanner_authorizations_event_scanner');
            $table->index(['scanner_user_id', 'status'], 'idx_event_scanner_authorizations_scanner_status');
            $table->index(['event_id', 'status'], 'idx_event_scanner_authorizations_event_status');
        });

        DB::statement("ALTER TABLE event_scanner_authorizations ADD CONSTRAINT chk_event_scanner_authorizations_status CHECK (status IN ('active', 'revoked'))");

        Schema::create('event_attendances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('attendee_user_id')->nullable();
            $table->uuid('event_registration_id')->nullable();
            $table->text('qr_token')->nullable();
            $table->uuid('checked_in_by_user_id');
            $table->timestampTz('checked_in_at')->useCurrent();
            $table->string('status', 30)->default('checked_in');
            $table->jsonb('scan_meta')->nullable();
            $table->timestampsTz();

            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('attendee_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('checked_in_by_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->index('event_id', 'idx_event_attendances_event_id');
            $table->index('event_registration_id', 'idx_event_attendances_event_registration_id');
            $table->index('attendee_user_id', 'idx_event_attendances_attendee_user_id');
            $table->index('checked_in_by_user_id', 'idx_event_attendances_checked_in_by_user_id');
            $table->index('qr_token', 'idx_event_attendances_qr_token');
            $table->index(['event_id', 'status'], 'idx_event_attendances_event_status');
        });

        if (Schema::hasTable('event_registrations')) {
            Schema::table('event_attendances', function (Blueprint $table): void {
                $table->foreign('event_registration_id')->references('id')->on('event_registrations')->nullOnDelete();
            });
        }

        DB::statement("ALTER TABLE event_attendances ADD CONSTRAINT chk_event_attendances_status CHECK (status IN ('checked_in'))");
        DB::statement('CREATE UNIQUE INDEX uq_event_attendances_event_registration ON event_attendances(event_id, event_registration_id) WHERE event_registration_id IS NOT NULL');

        if (Schema::hasTable('event_registrations')) {
            if (! Schema::hasColumn('event_registrations', 'qr_token')) {
                Schema::table('event_registrations', function (Blueprint $table): void {
                    $table->text('qr_token')->nullable();
                });
            }

            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS uq_event_registrations_qr_token_not_null ON event_registrations(qr_token) WHERE qr_token IS NOT NULL');
        }

        if (Schema::hasTable('event_registrations') && Schema::hasColumn('event_registrations', 'qr_token')) {
            DB::table('event_registrations')
                ->whereNull('qr_token')
                ->orderBy('id')
                ->select('id')
                ->chunkById(500, function ($registrations): void {
                    foreach ($registrations as $registration) {
                        DB::table('event_registrations')
                            ->where('id', $registration->id)
                            ->whereNull('qr_token')
                            ->update(['qr_token' => Str::random(64)]);
                    }
                }, 'id');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_attendances');
        Schema::dropIfExists('event_scanner_authorizations');
        DB::statement('DROP INDEX IF EXISTS uq_event_registrations_qr_token_not_null');
    }
};

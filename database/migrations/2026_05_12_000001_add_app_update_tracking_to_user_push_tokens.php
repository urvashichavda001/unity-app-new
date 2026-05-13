<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_push_tokens')) {
            Schema::create('user_push_tokens', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
                $table->text('token')->unique();
                $table->string('platform')->nullable();
                $table->string('device_id')->nullable();
                $table->string('app_version')->nullable();
                $table->timestampTz('last_seen_at')->nullable();
                $table->timestampTz('last_update_notification_sent_at')->nullable();
                $table->timestampsTz();

                $table->index('user_id');
                $table->index('app_version');
                $table->index('last_update_notification_sent_at');
            });

            return;
        }

        Schema::table('user_push_tokens', function (Blueprint $table): void {
            if (! Schema::hasColumn('user_push_tokens', 'platform')) {
                $table->string('platform')->nullable();
            }

            if (! Schema::hasColumn('user_push_tokens', 'device_id')) {
                $table->string('device_id')->nullable();
            }

            if (! Schema::hasColumn('user_push_tokens', 'app_version')) {
                $table->string('app_version')->nullable()->index();
            }

            if (! Schema::hasColumn('user_push_tokens', 'last_seen_at')) {
                $table->timestampTz('last_seen_at')->nullable();
            }

            if (! Schema::hasColumn('user_push_tokens', 'last_update_notification_sent_at')) {
                $table->timestampTz('last_update_notification_sent_at')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_push_tokens')) {
            return;
        }

        Schema::table('user_push_tokens', function (Blueprint $table): void {
            foreach (['app_version', 'last_update_notification_sent_at'] as $column) {
                if (Schema::hasColumn('user_push_tokens', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

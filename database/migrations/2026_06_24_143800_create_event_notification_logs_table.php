<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('event_notification_logs')) {
            Schema::create('event_notification_logs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('event_id');
                $table->string('notification_type');
                $table->string('status')->default('pending');
                $table->integer('total_users')->default(0);
                $table->integer('in_app_notifications_created')->default(0);
                $table->integer('active_push_tokens')->default(0);
                $table->integer('push_sent_successfully')->default(0);
                $table->integer('push_failed')->default(0);
                $table->jsonb('failed_details')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
                $table->index(['event_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_notification_logs');
    }
};

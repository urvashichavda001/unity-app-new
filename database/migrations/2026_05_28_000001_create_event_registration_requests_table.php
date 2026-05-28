<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('event_registration_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('occurrence_id');
            $table->uuid('user_id');
            $table->uuid('user_circle_id')->nullable();
            $table->uuid('event_circle_id')->nullable();
            $table->string('status',30)->default('pending');
            $table->text('request_reason')->nullable();
            $table->text('admin_note')->nullable();
            $table->uuid('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('rejected_by_user_id')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->uuid('registration_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['event_id','occurrence_id','user_id','status'], 'idx_event_reg_req_lookup');
        });

        Schema::table('event_registrations', function (Blueprint $table) {
            if (!Schema::hasColumn('event_registrations','registration_request_id')) {
                $table->uuid('registration_request_id')->nullable()->after('registration_type');
            }
        });
    }
    public function down(): void {
        Schema::table('event_registrations', function (Blueprint $table) {
            if (Schema::hasColumn('event_registrations','registration_request_id')) $table->dropColumn('registration_request_id');
        });
        Schema::dropIfExists('event_registration_requests');
    }
};

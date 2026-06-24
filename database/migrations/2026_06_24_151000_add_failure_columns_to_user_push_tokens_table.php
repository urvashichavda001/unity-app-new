<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_push_tokens', function (Blueprint $table) {
            if (!Schema::hasColumn('user_push_tokens', 'failed_at')) {
                $table->timestamp('failed_at')->nullable();
            }
            if (!Schema::hasColumn('user_push_tokens', 'failure_reason')) {
                $table->text('failure_reason')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_push_tokens', function (Blueprint $table) {
            if (Schema::hasColumn('user_push_tokens', 'failed_at')) {
                $table->dropColumn('failed_at');
            }
            if (Schema::hasColumn('user_push_tokens', 'failure_reason')) {
                $table->dropColumn('failure_reason');
            }
        });
    }
};

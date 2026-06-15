<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certification_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('certification_type');
            $table->uuid('user_id')->nullable();
            $table->string('full_name');
            $table->string('business_name')->nullable();
            $table->string('email');
            $table->string('contact_no')->nullable();
            $table->integer('total_score')->default(0);
            $table->integer('percentage')->default(0);
            $table->string('certification_level')->nullable();
            $table->string('certification_title')->nullable();
            $table->jsonb('answers')->nullable();
            $table->string('status')->default('new');
            $table->text('admin_note')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->index('certification_type');
            $table->index('status');
            $table->index('created_at');
            $table->index('email');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE certification_submissions ADD CONSTRAINT certification_submissions_type_check CHECK (certification_type IN ('leadership', 'entrepreneur'))");
            DB::statement("ALTER TABLE certification_submissions ADD CONSTRAINT certification_submissions_status_check CHECK (status IN ('new', 'approved', 'rejected'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('certification_submissions');
    }
};

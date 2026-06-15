<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certification_submissions', function (Blueprint $table) {
            $table->string('certificate_number')->nullable()->unique();
            $table->text('certificate_file_path')->nullable();
            $table->text('certificate_download_url')->nullable();
            $table->timestamp('certificate_generated_at')->nullable();
            $table->timestamp('issued_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('certification_submissions', function (Blueprint $table) {
            $table->dropUnique(['certificate_number']);
            $table->dropColumn([
                'certificate_number',
                'certificate_file_path',
                'certificate_download_url',
                'certificate_generated_at',
                'issued_at',
            ]);
        });
    }
};

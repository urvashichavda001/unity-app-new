<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('support_tickets')) {
            return;
        }

        Schema::table('support_tickets', function (Blueprint $table): void {
            if (! Schema::hasColumn('support_tickets', 'media_file_id')) {
                $table->uuid('media_file_id')->nullable()->after('description');
            }

            if (! Schema::hasColumn('support_tickets', 'media_type')) {
                $table->string('media_type', 20)->nullable()->after('media_file_id');
            }

            if (! Schema::hasColumn('support_tickets', 'media_url')) {
                $table->text('media_url')->nullable()->after('media_type');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('support_tickets')) {
            return;
        }

        Schema::table('support_tickets', function (Blueprint $table): void {
            $columns = ['media_file_id', 'media_type', 'media_url'];

            foreach ($columns as $column) {
                if (Schema::hasColumn('support_tickets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

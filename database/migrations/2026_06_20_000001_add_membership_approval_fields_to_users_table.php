<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'membership_starts_at')) {
                $table->timestamp('membership_starts_at')->nullable();
            }

            if (! Schema::hasColumn('users', 'membership_ends_at')) {
                $table->timestamp('membership_ends_at')->nullable();
            }

            if (! Schema::hasColumn('users', 'approval_status')) {
                $table->string('approval_status')->nullable()->default('pending');
            }
        });

        if (! Schema::hasColumn('users', 'membership_status') && ! Schema::hasColumn('users', 'membership_type')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('membership_type')->nullable();
            });
        }

        $membershipColumn = Schema::hasColumn('users', 'membership_status') ? 'membership_status' : (Schema::hasColumn('users', 'membership_type') ? 'membership_type' : null);

        if ($membershipColumn !== null) {
            DB::table('users')->where($membershipColumn, 'Only_Unity_Peer')->update([$membershipColumn => 'only_unity_peer']);
            DB::table('users')->where($membershipColumn, 'Only Unity Peer')->update([$membershipColumn => 'only_unity_peer']);
            DB::table('users')->where($membershipColumn, 'Circle Peer')->update([$membershipColumn => 'circle_peer']);
            DB::table('users')->where($membershipColumn, 'Multi Circle Peer')->update([$membershipColumn => 'multi_circle_peer']);
            DB::table('users')->where($membershipColumn, 'Free_peer')->update([$membershipColumn => 'free_peer']);
            DB::table('users')->where($membershipColumn, 'Free_trial_peer')->update([$membershipColumn => 'free_trial_peer']);

            DB::statement("CREATE INDEX IF NOT EXISTS users_{$membershipColumn}_index ON users ({$membershipColumn})");
        }

        DB::statement('CREATE INDEX IF NOT EXISTS users_membership_ends_at_index ON users (membership_ends_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS users_approval_status_index ON users (approval_status)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_approval_status_index');
        DB::statement('DROP INDEX IF EXISTS users_membership_ends_at_index');
        DB::statement('DROP INDEX IF EXISTS users_membership_status_index');
        DB::statement('DROP INDEX IF EXISTS users_membership_type_index');
    }
};

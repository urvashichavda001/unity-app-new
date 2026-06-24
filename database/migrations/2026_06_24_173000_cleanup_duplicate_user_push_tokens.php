<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('user_push_tokens')) {
            return;
        }

        $column = Schema::hasColumn('user_push_tokens', 'usr_id') ? 'usr_id' : 'user_id';

        // Keep only the latest token for each user_id + device_id pair
        $duplicates = DB::table('user_push_tokens')
            ->whereNotNull('device_id')
            ->where('device_id', '!=', '')
            ->select($column, 'device_id')
            ->groupBy($column, 'device_id')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicates as $dup) {
            $userId = $dup->{$column};
            $deviceId = $dup->device_id;

            // Find the latest token ID (using raw queries to avoid Eloquent scope interference)
            $latestId = DB::table('user_push_tokens')
                ->where($column, $userId)
                ->where('device_id', $deviceId)
                ->orderByDesc('last_seen_at')
                ->orderByDesc('updated_at')
                ->orderByDesc('created_at')
                ->value('id');

            if ($latestId) {
                DB::table('user_push_tokens')
                    ->where($column, $userId)
                    ->where('device_id', $deviceId)
                    ->where('id', '!=', $latestId)
                    ->delete();
            }
        }
    }

    public function down(): void
    {
        // Clean-up migration does not need reverse operations
    }
};

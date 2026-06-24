<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('app_instances') || ! Schema::hasTable('app_navigation_items')) {
            return;
        }

        $appInstanceId = DB::table('app_instances')->where('slug', 'greenpreneur')->value('id');
        if (! $appInstanceId) {
            return;
        }

        $now = now();

        if (Schema::hasTable('app_features')) {
            $featureExists = DB::table('app_features')
                ->where('app_instance_id', $appInstanceId)
                ->where('feature_key', 'feedback')
                ->exists();

            if (! $featureExists) {
                DB::table('app_features')->insert([
                    'id' => (string) Str::uuid(),
                    'app_instance_id' => $appInstanceId,
                    'feature_key' => 'feedback',
                    'feature_name' => 'Feedback',
                    'is_enabled' => true,
                    'sort_order' => (int) DB::table('app_features')->where('app_instance_id', $appInstanceId)->max('sort_order') + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $existing = DB::table('app_navigation_items')
            ->where('app_instance_id', $appInstanceId)
            ->where('menu_type', 'drawer')
            ->where('item_key', 'feedback')
            ->first();

        $sortOrder = $existing?->sort_order
            ?? $this->nextDrawerSortOrder($appInstanceId, 'logout')
            ?? ((int) DB::table('app_navigation_items')
                ->where('app_instance_id', $appInstanceId)
                ->where('menu_type', 'drawer')
                ->max('sort_order') + 1);

        $payload = [
            'menu_type' => 'drawer',
            'item_key' => 'feedback',
            'label_key' => null,
            'display_label' => 'Help & Support',
            'icon' => 'help_support',
            'route_name' => 'feedback',
            'feature_key' => 'feedback',
            'sort_order' => $sortOrder,
            'updated_at' => $now,
        ];

        foreach (['nav_key', 'v_key'] as $column) {
            if (Schema::hasColumn('app_navigation_items', $column)) {
                $payload[$column] = 'feedback';
            }
        }

        foreach (['nav_label', 'v_label'] as $column) {
            if (Schema::hasColumn('app_navigation_items', $column)) {
                $payload[$column] = 'Help & Support';
            }
        }

        if (Schema::hasColumn('app_navigation_items', 'position')) {
            $payload['position'] = $sortOrder;
        }

        if ($existing) {
            DB::table('app_navigation_items')->where('id', $existing->id)->update($payload);
        } else {
            DB::table('app_navigation_items')->insert($payload + [
                'id' => (string) Str::uuid(),
                'app_instance_id' => $appInstanceId,
                'is_enabled' => true,
                'created_at' => $now,
            ]);
        }

        if (Schema::hasTable('app_icon_assets')) {
            $iconExists = DB::table('app_icon_assets')
                ->where('app_instance_id', $appInstanceId)
                ->where('icon_key', 'drawer_feedback')
                ->exists();

            DB::table('app_icon_assets')->updateOrInsert(
                ['app_instance_id' => $appInstanceId, 'icon_key' => 'drawer_feedback'],
                array_merge(
                    $iconExists ? [] : [
                        'id' => (string) Str::uuid(),
                        'icon_url' => null,
                        'selected_icon_url' => null,
                        'is_active' => true,
                        'created_at' => $now,
                    ],
                    [
                        'icon_name' => 'Help & Support',
                        'icon_group' => 'drawer_menu',
                        'source_type' => 'iconsax',
                        'icon_library' => 'Iconsax',
                        'default_icon' => 'Iconsax.message_question',
                        'feature_key' => 'feedback',
                        'menu_key' => 'feedback',
                        'screen_name' => 'HomeDrawer',
                        'usage_location' => 'Side Drawer / More Menu',
                        'sort_order' => $sortOrder,
                        'updated_at' => $now,
                    ]
                )
            );
        }
    }

    public function down(): void
    {
        // Keep the idempotently-added configuration row to avoid removing a
        // production toggle that administrators may already have configured.
    }

    private function nextDrawerSortOrder(string $appInstanceId, string $beforeKey): ?int
    {
        $before = DB::table('app_navigation_items')
            ->where('app_instance_id', $appInstanceId)
            ->where('menu_type', 'drawer')
            ->where('item_key', $beforeKey)
            ->value('sort_order');

        return $before !== null ? max(0, (int) $before - 1) : null;
    }
};

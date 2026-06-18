<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\V1\AppConfigController;
use App\Http\Controllers\Controller;
use App\Services\AppConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AppConfigPageController extends Controller
{
    public function __construct(private readonly AppConfigService $appConfigService) {}

    public function index()
    {
        $app = $this->appConfigService->getGreenpreneurAppInstance();
        $id = $app->id;
        $branding = DB::table('app_config_settings')->where('app_instance_id', $id)->latest('updated_at')->first();
        $labels = DB::table('app_labels')->where('app_instance_id', $id)->orderBy('label_key')->get();
        $features = DB::table('app_features')->where('app_instance_id', $id)->orderBy('sort_order')->orderBy('feature_key')->get();
        $navigation = DB::table('app_navigation_items')->where('app_instance_id', $id)->orderBy('menu_type')->orderBy('sort_order')->get();
        $widgets = DB::table('app_dashboard_widgets')->where('app_instance_id', $id)->orderBy('sort_order')->get();
        $socialLinks = DB::table('app_social_links')->where('app_instance_id', $id)->orderBy('sort_order')->get();
        $membershipQuery = DB::table('app_membership_labels');
        if (Schema::hasColumn('app_membership_labels', 'app_instance_id')) {
            $membershipQuery->where('app_instance_id', $id);
        }
        $membershipLabels = $membershipQuery->orderBy('membership_key')->get();
        $icons = Schema::hasTable('app_icon_assets') ? DB::table('app_icon_assets')->where('app_instance_id', $id)->orderBy('sort_order')->orderBy('icon_key')->get() : collect();
        $lastUpdated = collect([$branding, ...$labels, ...$features, ...$navigation, ...$widgets, ...$socialLinks, ...$membershipLabels, ...$icons])
            ->pluck('updated_at')->filter()->sortDesc()->first();

        return view('admin.app-config.index', compact('app', 'branding', 'labels', 'features', 'navigation', 'widgets', 'socialLinks', 'membershipLabels', 'icons', 'lastUpdated'));
    }


    public function uploadBrandAsset(Request $request)
    {
        $data = $request->validate([
            'field' => ['required', 'string', Rule::in(['logo_url_light', 'logo_url_dark', 'logo_url_splash', 'app_logo_url', 'splash_logo_url'])],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,svg', 'max:5120'],
        ]);

        $extension = strtolower($request->file('file')->getClientOriginalExtension());
        if ($extension === 'svg') {
            $svg = strtolower((string) file_get_contents($request->file('file')->getRealPath()));
            abort_if(str_contains($svg, '<script') || str_contains($svg, 'javascript:') || str_contains($svg, '<foreignobject'), 422, 'SVG contains unsafe content.');
        }

        $filename = Str::of($data['field'])->replace('_', '-')->slug('-')
            . '-' . now()->format('Ymd-His')
            . '-' . Str::lower(Str::random(8))
            . '.' . $extension;

        $path = $request->file('file')->storeAs('app-config/greenpreneur/branding', $filename, 'public');

        return response()->json([
            'success' => true,
            'message' => 'Brand asset uploaded successfully.',
            'data' => [
                'field' => $data['field'],
                'url' => asset('storage/' . $path),
            ],
        ]);
    }

    public function updateBranding(Request $request)
    {
        $hex = ['nullable', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{8})$/'];
        $data = $request->validate([
            'app_name' => ['nullable', 'string', 'max:255'], 'app_logo_url' => ['nullable', 'url'], 'splash_logo_url' => ['nullable', 'url'], 'logo_url_light' => ['nullable', 'url'], 'logo_url_dark' => ['nullable', 'url'], 'logo_url_splash' => ['nullable', 'url'],
            'primary_color' => $hex, 'primary_dark_color' => $hex, 'primary_light_color' => $hex, 'primary_ultra_light_color' => $hex, 'secondary_color' => $hex, 'secondary_light_color' => $hex, 'background_color' => $hex, 'background_light_color' => $hex, 'background_secondary_color' => $hex, 'background_dark_color' => $hex, 'card_background_color' => $hex, 'card_border_color' => $hex, 'text_primary_color' => $hex, 'text_secondary_color' => $hex, 'secondary_color' => $hex, 'accent_color' => $hex, 'splash_bg_color' => $hex, 'button_color' => $hex, 'text_color' => $hex,
            'playstore_url' => ['nullable', 'url'], 'appstore_url' => ['nullable', 'url'], 'website_url' => ['nullable', 'url'], 'support_email' => ['nullable', 'email'], 'support_phone' => ['nullable', 'string', 'max:50'],
        ]);
        DB::table('app_config_settings')->updateOrInsert(['app_instance_id' => $this->appId(), 'app_key' => 'greenpreneur'], $data + ['is_active' => true, 'updated_at' => now(), 'created_at' => now()]);
        return $this->done('Branding updated successfully.', 'branding');
    }

    public function bulkLabels(Request $request)
    {
        $data = $request->validate(['labels' => ['required', 'array'], 'labels.*.label_value' => ['required', 'string'], 'labels.*.group_name' => ['nullable', 'string'], 'labels.*.description' => ['nullable', 'string'], 'labels.*.is_active' => ['nullable']]);
        foreach ($data['labels'] as $key => $row) {
            DB::table('app_labels')->updateOrInsert(['app_instance_id' => $this->appId(), 'label_key' => $key], ['label_value' => $row['label_value'], 'group_name' => $row['group_name'] ?? null, 'description' => $row['description'] ?? null, 'is_active' => $request->boolean("labels.$key.is_active"), 'updated_at' => now(), 'created_at' => now()]);
        }
        return $this->done('Labels updated successfully.', 'labels');
    }

    public function bulkFeatures(Request $request)
    {
        $data = $request->validate(['features' => ['required', 'array'], 'features.*.sort_order' => ['nullable', 'integer', 'min:0'], 'features.*.is_enabled' => ['nullable']]);
        foreach ($data['features'] as $key => $row) {
            DB::table('app_features')->where('app_instance_id', $this->appId())->where('feature_key', $key)->update(['is_enabled' => $request->boolean("features.$key.is_enabled"), 'sort_order' => $row['sort_order'] ?? 0, 'updated_at' => now()]);
        }
        return $this->done('Features updated successfully.', 'features');
    }

    public function saveNavigation(Request $request, ?string $id = null)
    {
        $data = $request->validate(['menu_type' => ['required', Rule::in(['bottom_nav','plus_menu','impact_menu','drawer'])], 'item_key' => ['required','string','max:150'], 'label_key' => ['nullable','string','max:150'], 'display_label' => ['required','string','max:255'], 'icon' => ['nullable','string','max:100'], 'route_name' => ['nullable','string','max:150'], 'feature_key' => ['nullable','string','max:150'], 'is_enabled' => ['nullable'], 'sort_order' => ['required','integer','min:0']]);
        $payload = $data + ['app_instance_id' => $this->appId()];
        $payload['nav_key'] = $payload['v_key'] = $data['item_key']; $payload['nav_label'] = $payload['v_label'] = $data['display_label']; $payload['position'] = $data['sort_order']; $payload['is_enabled'] = $request->boolean('is_enabled'); $payload['updated_at'] = now();
        if ($id) DB::table('app_navigation_items')->where('app_instance_id', $this->appId())->where('id', $id)->update($payload); else DB::table('app_navigation_items')->insert($payload + ['id' => (string) \Illuminate\Support\Str::uuid(), 'created_at' => now()]);
        return $this->done('Navigation item saved successfully.', 'navigation');
    }


    public function bulkUpdateNavigationGroup(Request $request, string $menuType)
    {
        abort_unless(in_array($menuType, ['bottom_nav', 'plus_menu', 'impact_menu', 'drawer'], true), 404);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'uuid'],
            'items.*.item_key' => ['required', 'string', 'max:150'],
            'items.*.label_key' => ['nullable', 'string', 'max:150'],
            'items.*.display_label' => ['required', 'string', 'max:255'],
            'items.*.icon' => ['nullable', 'string', 'max:150'],
            'items.*.route_name' => ['nullable', 'string', 'max:150'],
            'items.*.feature_key' => ['nullable', 'string', 'max:150'],
            'items.*.is_enabled' => ['boolean'],
            'items.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $appId = $this->appId();
        $updatedCount = 0;

        DB::transaction(function () use ($data, $menuType, $appId, &$updatedCount) {
            foreach ($data['items'] as $item) {
                $sortOrder = $item['sort_order'] ?? 0;
                $payload = [
                    'app_instance_id' => $appId,
                    'menu_type' => $menuType,
                    'item_key' => $item['item_key'],
                    'label_key' => $item['label_key'] ?? null,
                    'display_label' => $item['display_label'],
                    'icon' => $item['icon'] ?? null,
                    'route_name' => $item['route_name'] ?? null,
                    'feature_key' => $item['feature_key'] ?? null,
                    'is_enabled' => (bool) ($item['is_enabled'] ?? false),
                    'sort_order' => $sortOrder,
                    'position' => $sortOrder,
                    'nav_key' => $item['item_key'],
                    'nav_label' => $item['display_label'],
                    'v_key' => $item['item_key'],
                    'v_label' => $item['display_label'],
                    'updated_at' => now(),
                ];

                $query = DB::table('app_navigation_items')->where('app_instance_id', $appId);
                if (!empty($item['id'])) {
                    $query->where('id', $item['id']);
                } else {
                    $query->where('menu_type', $menuType)->where('item_key', $item['item_key']);
                }

                $query->update($payload);
                $updatedCount++;
            }
        });

        AppConfigController::clearCache();

        return response()->json([
            'success' => true,
            'message' => $this->navigationGroupLabel($menuType) . ' saved successfully.',
            'data' => [
                'menu_type' => $menuType,
                'updated_count' => $updatedCount,
            ],
        ]);
    }

    public function deleteNavigation(string $id) { DB::table('app_navigation_items')->where('app_instance_id', $this->appId())->where('id', $id)->delete(); return $this->done('Navigation item deleted successfully.', 'navigation'); }

    public function bulkWidgets(Request $request)
    {
        $data = $request->validate(['widgets' => ['required','array'], 'widgets.*.sort_order' => ['nullable','integer','min:0'], 'widgets.*.is_enabled' => ['nullable']]);
        foreach ($data['widgets'] as $key => $row) { $payload = ['sort_order' => $row['sort_order'] ?? 0, 'updated_at' => now()]; if (Schema::hasColumn('app_dashboard_widgets','is_enabled')) $payload['is_enabled'] = $request->boolean("widgets.$key.is_enabled"); if (Schema::hasColumn('app_dashboard_widgets','is_enable')) $payload['is_enable'] = $request->boolean("widgets.$key.is_enabled"); DB::table('app_dashboard_widgets')->where('app_instance_id', $this->appId())->where('widget_key', $key)->update($payload); }
        return $this->done('Dashboard widgets updated successfully.', 'widgets');
    }

    public function bulkSocial(Request $request)
    {
        $data = $request->validate(['social_links' => ['required','array'], 'social_links.*.display_name' => ['required','string','max:255'], 'social_links.*.url' => ['nullable','url'], 'social_links.*.icon' => ['nullable','string','max:100'], 'social_links.*.is_enabled' => ['nullable'], 'social_links.*.sort_order' => ['nullable','integer','min:0']]);
        foreach ($data['social_links'] as $platform => $row) { DB::table('app_social_links')->updateOrInsert(['app_instance_id' => $this->appId(), 'platform' => $platform], ['platform_key' => $platform, 'label' => $row['display_name'], 'display_name' => $row['display_name'], 'url' => $row['url'] ?? null, 'icon' => $row['icon'] ?? null, 'is_enabled' => $request->boolean("social_links.$platform.is_enabled"), 'sort_order' => $row['sort_order'] ?? 0, 'updated_at' => now(), 'created_at' => now()]); }
        return $this->done('Social links updated successfully.', 'social');
    }



    public function uploadIconAsset(Request $request)
    {
        $data = $request->validate([
            'icon_key' => ['required', 'string', 'max:150'],
            'target_field' => ['required', Rule::in(['icon_url', 'selected_icon_url'])],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,svg', 'max:2048'],
        ]);

        $extension = strtolower($request->file('file')->getClientOriginalExtension());
        if ($extension === 'svg') {
            $svg = strtolower((string) file_get_contents($request->file('file')->getRealPath()));
            abort_if(str_contains($svg, '<script') || str_contains($svg, 'javascript:') || str_contains($svg, '<foreignobject'), 422, 'SVG contains unsafe content.');
        }

        $filename = Str::of($data['icon_key'])->replace('_', '-')->slug('-')
            . '-' . $data['target_field']
            . '-' . now()->format('Ymd-His')
            . '-' . Str::lower(Str::random(8))
            . '.' . $extension;
        $path = $request->file('file')->storeAs('app-config/greenpreneur/icons', $filename, 'public');
        $url = asset('storage/' . $path);

        DB::table('app_icon_assets')
            ->where('app_instance_id', $this->appId())
            ->where('icon_key', $data['icon_key'])
            ->update([$data['target_field'] => $url, 'updated_at' => now()]);

        AppConfigController::clearCache();

        return response()->json([
            'success' => true,
            'message' => 'Icon uploaded successfully.',
            'data' => [
                'icon_key' => $data['icon_key'],
                'target_field' => $data['target_field'],
                'url' => $url,
            ],
        ]);
    }

    public function bulkIcons(Request $request)
    {
        $data = $request->validate(['icons' => ['required','array'], 'icons.*.icon_name' => ['nullable','string','max:255'], 'icons.*.icon_url' => ['nullable','url'], 'icons.*.selected_icon_url' => ['nullable','url'], 'icons.*.fallback_asset' => ['nullable','string','max:255'], 'icons.*.feature_key' => ['nullable','string','max:100'], 'icons.*.menu_key' => ['nullable','string','max:100'], 'icons.*.is_active' => ['nullable'], 'icons.*.sort_order' => ['nullable','integer','min:0']]);
        foreach ($data['icons'] as $key => $row) { DB::table('app_icon_assets')->updateOrInsert(['app_instance_id' => $this->appId(), 'icon_key' => $key], ['icon_name' => $row['icon_name'] ?? null, 'icon_url' => $row['icon_url'] ?? null, 'selected_icon_url' => $row['selected_icon_url'] ?? null, 'fallback_asset' => $row['fallback_asset'] ?? null, 'feature_key' => $row['feature_key'] ?? null, 'menu_key' => $row['menu_key'] ?? null, 'is_active' => $request->boolean("icons.$key.is_active"), 'sort_order' => $row['sort_order'] ?? 0, 'updated_at' => now(), 'created_at' => now()]); }
        return $this->done('Icons updated successfully.', 'icons');
    }

    public function membershipLabels(Request $request)
    {
        $data = $request->validate(['membership_labels' => ['required','array'], 'membership_labels.*.display_label' => ['required','string','max:255'], 'membership_labels.*.description' => ['nullable','string']]);
        foreach ($data['membership_labels'] as $key => $row) {
            $query = DB::table('app_membership_labels')->where('membership_key', $key);
            if (Schema::hasColumn('app_membership_labels', 'app_instance_id')) {
                $query->where('app_instance_id', $this->appId());
            }
            $query->update(['display_label' => $row['display_label'], 'description' => $row['description'] ?? null, 'updated_at' => now()]);
        }
        return $this->done('Membership labels updated successfully.', 'membership');
    }

    public function clearCache() { return $this->done('App configuration cache cleared successfully.', request('tab', 'overview')); }
    private function appId(): string { return $this->appConfigService->getGreenpreneurAppInstance()->id; }
    private function navigationGroupLabel(string $menuType): string { return ['bottom_nav' => 'Bottom Navigation', 'plus_menu' => 'Plus Menu', 'impact_menu' => 'Impact Menu', 'drawer' => 'Drawer Menu'][$menuType] ?? 'Navigation group'; }
    private function done(string $message, string $tab) { AppConfigController::clearCache(); return redirect()->route('admin.app-config.index', ['tab' => $tab])->with('success', $message); }
}

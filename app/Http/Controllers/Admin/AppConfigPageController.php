<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\V1\AppConfigController;
use App\Http\Controllers\Controller;
use App\Services\AppConfigService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $lastUpdated = collect([$branding, ...$labels, ...$features, ...$navigation, ...$widgets, ...$socialLinks, ...$membershipLabels])
            ->pluck('updated_at')->filter()->sortDesc()->first();

        return view('admin.app-config.index', compact('app', 'branding', 'labels', 'features', 'navigation', 'widgets', 'socialLinks', 'membershipLabels', 'lastUpdated'));
    }

    public function updateBranding(Request $request)
    {
        $hex = ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'];
        $data = $request->validate([
            'app_name' => ['required', 'string', 'max:255'], 'app_logo_url' => ['nullable', 'url'], 'splash_logo_url' => ['nullable', 'url'],
            'primary_color' => $hex, 'secondary_color' => $hex, 'accent_color' => $hex, 'splash_bg_color' => $hex, 'button_color' => $hex, 'text_color' => $hex,
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
    private function done(string $message, string $tab) { AppConfigController::clearCache(); return redirect()->route('admin.app-config.index', ['tab' => $tab])->with('success', $message); }
}

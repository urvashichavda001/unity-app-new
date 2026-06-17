<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\AppConfigController;
use App\Http\Controllers\Controller;
use App\Models\AppConfigSetting;
use App\Models\AppDashboardWidget;
use App\Models\AppFeature;
use App\Models\AppIconAsset;
use App\Models\AppLabel;
use App\Models\AppMembershipLabel;
use App\Models\AppNavigationItem;
use App\Models\AppSocialLink;
use App\Services\AppConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AppConfigAdminController extends Controller
{
    public function __construct(private readonly AppConfigService $appConfigService) {}

    public function adminConfig(): JsonResponse
    {
        $appInstanceId = $this->appInstanceId();

        $branding = AppConfigSetting::query()
            ->where('app_instance_id', $appInstanceId)
            ->latest('updated_at')
            ->first();

        return $this->ok([
            'branding' => $branding?->toArray() ?? [],
            'labels' => AppLabel::query()
                ->where('app_instance_id', $appInstanceId)
                ->orderBy('label_key')
                ->get()
                ->values()
                ->toArray(),
            'icons' => Schema::hasTable('app_icon_assets') ? AppIconAsset::query()->where('app_instance_id', $appInstanceId)->orderBy('sort_order')->get()->values()->toArray() : [],
            'features' => AppFeature::query()
                ->where('app_instance_id', $appInstanceId)
                ->orderBy('sort_order')
                ->get()
                ->values()
                ->toArray(),
            'navigation_items' => AppNavigationItem::query()
                ->where('app_instance_id', $appInstanceId)
                ->orderBy('menu_type')
                ->orderBy('sort_order')
                ->get()
                ->values()
                ->toArray(),
            'dashboard_widgets' => AppDashboardWidget::query()
                ->where('app_instance_id', $appInstanceId)
                ->orderBy('sort_order')
                ->get()
                ->values()
                ->toArray(),
            'social_links' => AppSocialLink::query()
                ->where('app_instance_id', $appInstanceId)
                ->orderBy('sort_order')
                ->get()
                ->values()
                ->toArray(),
            'membership_labels' => AppMembershipLabel::query()
                ->when(\Illuminate\Support\Facades\Schema::hasColumn('app_membership_labels', 'app_instance_id'), fn ($query) => $query->where('app_instance_id', $appInstanceId))
                ->orderBy('membership_key')
                ->get()
                ->values()
                ->toArray(),
        ], 'Admin app configuration fetched successfully.');
    }

    public function updateBranding(Request $request): JsonResponse
    {
        $data = $request->validate($this->brandingRules());
        $model = AppConfigSetting::query()->updateOrCreate(
            ['app_instance_id' => $this->appInstanceId(), 'app_key' => 'greenpreneur'],
            $data + ['is_active' => true]
        );

        return $this->changed($model, 'Branding updated successfully.');
    }



    public function icons(): JsonResponse
    {
        $icons = AppIconAsset::query()
            ->where('app_instance_id', $this->appInstanceId())
            ->orderBy('icon_group')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('icon_group')
            ->toArray();

        return $this->ok($icons, 'Icon assets fetched successfully.');
    }

    public function updateColors(Request $request): JsonResponse
    {
        $data = $request->validate($this->colorRules());
        $model = AppConfigSetting::query()->updateOrCreate(
            ['app_instance_id' => $this->appInstanceId(), 'app_key' => 'greenpreneur'],
            $data + ['is_active' => true]
        );

        return $this->changed($model, 'Colors updated successfully.');
    }

    public function updateIcon(Request $request, string $icon_key): JsonResponse
    {
        $data = $request->validate($this->iconRules());
        $model = AppIconAsset::query()->updateOrCreate(
            ['app_instance_id' => $this->appInstanceId(), 'icon_key' => $icon_key],
            $data + ['icon_key' => $icon_key]
        );

        return $this->changed($model, 'Icon updated successfully.');
    }

    public function bulkUpdateIcons(Request $request): JsonResponse
    {
        $data = $request->validate(['icons' => ['required', 'array'], 'icons.*' => ['required', 'array']]);
        foreach ($data['icons'] as $key => $row) {
            $validated = validator($row, $this->iconRules())->validate();
            AppIconAsset::query()->updateOrCreate(
                ['app_instance_id' => $this->appInstanceId(), 'icon_key' => $key],
                $validated + ['icon_key' => $key]
            );
        }

        return $this->changed(null, 'Icons updated successfully.');
    }

    public function uploadIcon(Request $request): JsonResponse
    {
        $data = $request->validate([
            'icon_key' => ['required', 'string', 'max:150'],
            'target_field' => ['required', 'string', 'in:icon_url,selected_icon_url'],
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

        AppIconAsset::query()
            ->where('app_instance_id', $this->appInstanceId())
            ->where('icon_key', $data['icon_key'])
            ->update([$data['target_field'] => $url]);

        AppConfigController::clearCache();

        return $this->ok([
            'icon_key' => $data['icon_key'],
            'target_field' => $data['target_field'],
            'url' => $url,
        ], 'Icon uploaded successfully.');
    }

    public function updateLabel(Request $request, string $label_key): JsonResponse
    {
        $data = $request->validate([
            'label_value' => 'required|string',
            'group_name' => 'nullable|string|max:100',
            'description' => 'nullable|string',
        ]);

        $model = AppLabel::query()->updateOrCreate(
            ['app_instance_id' => $this->appInstanceId(), 'label_key' => $label_key],
            $data + ['is_active' => true]
        );

        return $this->changed($model, 'Label updated successfully.');
    }

    public function bulkUpdateLabels(Request $request): JsonResponse
    {
        $data = $request->validate(['labels' => 'required|array', 'labels.*' => 'required|string']);
        $appInstanceId = $this->appInstanceId();

        foreach ($data['labels'] as $key => $value) {
            AppLabel::query()->updateOrCreate(
                ['app_instance_id' => $appInstanceId, 'label_key' => $key],
                ['label_value' => $value, 'is_active' => true]
            );
        }

        return $this->changed(null, 'Labels updated successfully.');
    }

    public function updateFeature(Request $request, string $feature_key): JsonResponse
    {
        $data = $request->validate(['is_enabled' => 'required|boolean']);
        $model = AppFeature::query()
            ->where('app_instance_id', $this->appInstanceId())
            ->where('feature_key', $feature_key)
            ->firstOrFail();
        $model->update($data);

        return $this->changed($model, 'Feature toggle updated successfully.');
    }

    public function bulkUpdateFeatures(Request $request): JsonResponse
    {
        $data = $request->validate(['features' => 'required|array', 'features.*' => 'required|boolean']);
        $appInstanceId = $this->appInstanceId();

        foreach ($data['features'] as $key => $enabled) {
            AppFeature::query()
                ->where('app_instance_id', $appInstanceId)
                ->where('feature_key', $key)
                ->update(['is_enabled' => $enabled]);
        }

        return $this->changed(null, 'Feature toggles updated successfully.');
    }

    public function createNavigationItem(Request $request): JsonResponse
    {
        $data = $request->validate($this->navigationRules(requireCoreFields: true));
        $sortOrder = $data['sort_order'] ?? $data['position'] ?? 0;
        $itemKey = $data['item_key'];
        $displayLabel = $data['display_label'];

        $model = AppNavigationItem::query()->updateOrCreate(
            [
                'app_instance_id' => $this->appInstanceId(),
                'menu_type' => $data['menu_type'],
                'item_key' => $itemKey,
            ],
            [
                'nav_key' => $data['nav_key'] ?? $itemKey,
                'nav_label' => $data['nav_label'] ?? $displayLabel,
                'v_key' => $data['v_key'] ?? $itemKey,
                'v_label' => $data['v_label'] ?? $displayLabel,
                'label_key' => $data['label_key'] ?? null,
                'display_label' => $displayLabel,
                'icon' => $data['icon'] ?? null,
                'route_name' => $data['route_name'] ?? null,
                'feature_key' => $data['feature_key'] ?? null,
                'is_enabled' => $data['is_enabled'] ?? true,
                'position' => $sortOrder,
                'sort_order' => $sortOrder,
            ]
        );

        return $this->changed($model, 'Navigation item created successfully.');
    }

    public function updateNavigationItem(Request $request, string $id): JsonResponse
    {
        $data = $request->validate($this->navigationRules(requireCoreFields: false));
        $model = AppNavigationItem::query()->where('app_instance_id', $this->appInstanceId())->findOrFail($id);

        if (array_key_exists('item_key', $data)) {
            $data['nav_key'] = $data['nav_key'] ?? $data['item_key'];
            $data['v_key'] = $data['v_key'] ?? $data['item_key'];
        }

        if (array_key_exists('display_label', $data)) {
            $data['nav_label'] = $data['nav_label'] ?? $data['display_label'];
            $data['v_label'] = $data['v_label'] ?? $data['display_label'];
        }

        if (array_key_exists('sort_order', $data) && ! array_key_exists('position', $data)) {
            $data['position'] = $data['sort_order'];
        }

        if (array_key_exists('position', $data) && ! array_key_exists('sort_order', $data)) {
            $data['sort_order'] = $data['position'];
        }

        $model->update($data);

        return $this->changed($model, 'Navigation item updated successfully.');
    }

    public function deleteNavigationItem(string $id): JsonResponse
    {
        $model = AppNavigationItem::query()->where('app_instance_id', $this->appInstanceId())->findOrFail($id);
        $model->delete();

        return $this->changed(null, 'Navigation item deleted successfully.');
    }

    public function updateDashboardWidget(Request $request, string $widget_key): JsonResponse
    {
        $data = $request->validate([
            'is_enabled' => ['sometimes', 'required', 'boolean'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0'],
        ]);

        $model = AppDashboardWidget::query()
            ->where('app_instance_id', $this->appInstanceId())
            ->where('widget_key', $widget_key)
            ->firstOrFail();

        $payload = [
            'sort_order' => $data['sort_order'] ?? $model->sort_order,
        ];

        if (\Illuminate\Support\Facades\Schema::hasColumn('app_dashboard_widgets', 'is_enabled')) {
            $payload['is_enabled'] = array_key_exists('is_enabled', $data) ? $request->boolean('is_enabled') : $model->is_enabled;
        }

        if (\Illuminate\Support\Facades\Schema::hasColumn('app_dashboard_widgets', 'is_enable')) {
            $payload['is_enable'] = array_key_exists('is_enabled', $data) ? $request->boolean('is_enabled') : ($model->is_enable ?? $model->is_enabled);
        }

        $model->update($payload);

        return $this->changed($model->fresh(), 'Dashboard widget updated successfully.');
    }

    public function updateSocialLink(Request $request, string $platform): JsonResponse
    {
        $data = $request->validate([
            'display_name' => ['sometimes', 'required', 'string', 'max:255'],
            'url' => ['sometimes', 'nullable', 'url'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_enabled' => ['sometimes', 'required', 'boolean'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0'],
        ]);

        $appInstanceId = $this->appInstanceId();
        $existing = AppSocialLink::query()
            ->where('app_instance_id', $appInstanceId)
            ->where('platform', $platform)
            ->first();

        $model = AppSocialLink::query()->updateOrCreate(
            ['app_instance_id' => $appInstanceId, 'platform' => $platform],
            [
                'platform_key' => $platform,
                'label' => $data['display_name'] ?? $existing?->display_name ?? str($platform)->replace('_', ' ')->title()->toString(),
                'display_name' => $data['display_name'] ?? $existing?->display_name ?? str($platform)->replace('_', ' ')->title()->toString(),
                'url' => array_key_exists('url', $data) ? $data['url'] : $existing?->url,
                'icon' => array_key_exists('icon', $data) ? $data['icon'] : ($existing?->icon ?? $platform),
                'is_enabled' => array_key_exists('is_enabled', $data) ? $request->boolean('is_enabled') : ($existing?->is_enabled ?? true),
                'sort_order' => $data['sort_order'] ?? $existing?->sort_order ?? 0,
            ]
        );

        return $this->changed($model, 'Social link updated successfully.');
    }

    public function updateMembershipLabel(Request $request, string $membership_key): JsonResponse
    {
        $data = $request->validate(['display_label' => 'required|string|max:255', 'description' => 'nullable|string']);
        $query = AppMembershipLabel::query()->where('membership_key', $membership_key);
        if (\Illuminate\Support\Facades\Schema::hasColumn('app_membership_labels', 'app_instance_id')) {
            $query->where('app_instance_id', $this->appInstanceId());
        }
        $model = $query->firstOrFail();
        $model->update($data);

        return $this->changed($model, 'Membership label updated successfully.');
    }

    public function clearCache(): JsonResponse
    {
        AppConfigController::clearCache();

        return $this->ok(null, 'App configuration cache cleared successfully.');
    }

    private function navigationRules(bool $requireCoreFields): array
    {
        if ($requireCoreFields) {
            return [
                'menu_type' => ['required', 'string', 'in:bottom_nav,drawer,plus_menu,impact_menu'],
                'item_key' => ['required', 'string', 'max:150'],
                'nav_key' => ['sometimes', 'nullable', 'string', 'max:150'],
                'nav_label' => ['sometimes', 'nullable', 'string', 'max:255'],
                'v_key' => ['sometimes', 'nullable', 'string', 'max:150'],
                'v_label' => ['sometimes', 'nullable', 'string', 'max:255'],
                'label_key' => ['sometimes', 'nullable', 'string', 'max:150'],
                'display_label' => ['required', 'string', 'max:255'],
                'icon' => ['sometimes', 'nullable', 'string', 'max:100'],
                'route_name' => ['sometimes', 'nullable', 'string', 'max:150'],
                'feature_key' => ['sometimes', 'nullable', 'string', 'max:150'],
                'is_enabled' => ['sometimes', 'required', 'boolean'],
                'position' => ['sometimes', 'required', 'integer', 'min:0'],
                'sort_order' => ['sometimes', 'required', 'integer', 'min:0'],
            ];
        }

        return [
            'menu_type' => ['sometimes', 'nullable', 'string', 'in:bottom_nav,drawer,plus_menu,impact_menu'],
            'item_key' => ['sometimes', 'nullable', 'string', 'max:150'],
            'nav_key' => ['sometimes', 'nullable', 'string', 'max:150'],
            'nav_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'v_key' => ['sometimes', 'nullable', 'string', 'max:150'],
            'v_label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'label_key' => ['sometimes', 'nullable', 'string', 'max:150'],
            'display_label' => ['sometimes', 'required', 'string', 'max:255'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:100'],
            'route_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'feature_key' => ['sometimes', 'nullable', 'string', 'max:150'],
            'is_enabled' => ['sometimes', 'required', 'boolean'],
            'position' => ['sometimes', 'required', 'integer', 'min:0'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0'],
        ];
    }

    private function brandingRules(): array
    {
        return [
            'app_name' => 'nullable|string|max:255',
            'logo_url_light' => 'nullable|url',
            'logo_url_dark' => 'nullable|url',
            'logo_url_splash' => 'nullable|url',
            'app_logo_url' => 'nullable|url',
            'splash_logo_url' => 'nullable|url',
            'playstore_url' => 'nullable|url',
            'appstore_url' => 'nullable|url',
            'website_url' => 'nullable|url',
            'support_email' => 'nullable|email|max:255',
            'support_phone' => 'nullable|string|max:50',
        ] + $this->colorRules();
    }

    private function colorRules(): array
    {
        $hex = ['nullable', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{8})$/'];

        return collect([
            'primary_color', 'primary_dark_color', 'primary_light_color', 'primary_ultra_light_color',
            'secondary_color', 'secondary_light_color', 'background_color', 'background_light_color',
            'background_secondary_color', 'background_dark_color', 'card_background_color', 'card_border_color',
            'text_primary_color', 'text_secondary_color',
        ])->mapWithKeys(fn ($field) => [$field => $hex])->all();
    }

    private function iconRules(): array
    {
        return [
            'icon_name' => ['nullable', 'string', 'max:255'],
            'icon_group' => ['nullable', 'string', 'max:100'],
            'source_type' => ['nullable', 'string', 'in:iconsax,material,custom_asset,remote_url'],
            'icon_library' => ['nullable', 'string', 'max:100'],
            'default_icon' => ['nullable', 'string', 'max:255'],
            'selected_icon' => ['nullable', 'string', 'max:255'],
            'icon_url' => ['nullable', 'url'],
            'selected_icon_url' => ['nullable', 'url'],
            'fallback_asset' => ['nullable', 'string', 'max:255'],
            'feature_key' => ['nullable', 'string', 'max:100'],
            'menu_key' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0'],
        ];
    }

    private function appInstanceId(): string
    {
        return $this->appConfigService->getGreenpreneurAppInstance()->id;
    }

    private function changed($data, string $message): JsonResponse
    {
        AppConfigController::clearCache();

        return $this->ok($data, $message);
    }

    private function ok($data, string $message): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data]);
    }
}

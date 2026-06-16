<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\AppConfigController;
use App\Http\Controllers\Controller;
use App\Models\AppConfigSetting;
use App\Models\AppDashboardWidget;
use App\Models\AppFeature;
use App\Models\AppLabel;
use App\Models\AppMembershipLabel;
use App\Models\AppNavigationItem;
use App\Models\AppSocialLink;
use App\Services\AppConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $model->update([
            'is_enabled' => array_key_exists('is_enabled', $data) ? $request->boolean('is_enabled') : $model->is_enabled,
            'sort_order' => $data['sort_order'] ?? $model->sort_order,
        ]);

        return $this->changed($model->fresh(), 'Dashboard widget updated successfully.');
    }

    public function updateSocialLink(Request $request, string $platform): JsonResponse
    {
        $data = $request->validate([
            'display_name' => 'sometimes|required|string|max:255',
            'url' => 'nullable|url',
            'icon' => 'nullable|string|max:100',
            'is_enabled' => 'sometimes|required|boolean',
            'sort_order' => 'sometimes|required|integer',
        ]);
        $model = AppSocialLink::query()
            ->where('app_instance_id', $this->appInstanceId())
            ->where('platform', $platform)
            ->firstOrFail();
        $model->update($data);

        return $this->changed($model, 'Social link updated successfully.');
    }

    public function updateMembershipLabel(Request $request, string $membership_key): JsonResponse
    {
        $data = $request->validate(['display_label' => 'required|string|max:255', 'description' => 'nullable|string']);
        $model = AppMembershipLabel::query()->where('membership_key', $membership_key)->firstOrFail();
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
        $hex = ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'];

        return [
            'app_name' => 'sometimes|required|string|max:255',
            'app_logo_url' => 'nullable|url',
            'splash_logo_url' => 'nullable|url',
            'primary_color' => $hex,
            'secondary_color' => $hex,
            'accent_color' => $hex,
            'splash_bg_color' => $hex,
            'button_color' => $hex,
            'text_color' => $hex,
            'playstore_url' => 'nullable|url',
            'appstore_url' => 'nullable|url',
            'website_url' => 'nullable|url',
            'support_email' => 'nullable|email|max:255',
            'support_phone' => 'nullable|string|max:50',
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

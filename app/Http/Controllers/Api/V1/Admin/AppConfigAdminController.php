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

    public function index(): JsonResponse
    {
        $appInstanceId = $this->appInstanceId();

        return $this->ok([
            'branding' => AppConfigSetting::query()->where('app_instance_id', $appInstanceId)->first(),
            'labels' => AppLabel::query()->where('app_instance_id', $appInstanceId)->orderBy('label_key')->get(),
            'features' => AppFeature::query()->where('app_instance_id', $appInstanceId)->orderBy('sort_order')->get(),
            'navigation' => AppNavigationItem::query()->where('app_instance_id', $appInstanceId)->orderBy('menu_type')->orderBy('sort_order')->get(),
            'dashboard_widgets' => AppDashboardWidget::query()->where('app_instance_id', $appInstanceId)->orderBy('sort_order')->get(),
            'social_links' => AppSocialLink::query()->where('app_instance_id', $appInstanceId)->orderBy('sort_order')->get(),
            'membership_labels' => AppMembershipLabel::query()->orderBy('membership_key')->get(),
        ], 'App configuration fetched successfully.');
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

    public function updateNavigation(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'display_label' => 'sometimes|required|string|max:255',
            'icon' => 'nullable|string|max:100',
            'route_name' => 'nullable|string|max:255',
            'feature_key' => 'nullable|string|max:255',
            'is_enabled' => 'sometimes|required|boolean',
            'sort_order' => 'sometimes|required|integer',
        ]);
        $model = AppNavigationItem::query()->where('app_instance_id', $this->appInstanceId())->findOrFail($id);
        $model->update($data);

        return $this->changed($model, 'Navigation item updated successfully.');
    }

    public function updateDashboardWidget(Request $request, string $widget_key): JsonResponse
    {
        $data = $request->validate(['is_enabled' => 'required|boolean', 'sort_order' => 'sometimes|required|integer']);
        $model = AppDashboardWidget::query()
            ->where('app_instance_id', $this->appInstanceId())
            ->where('widget_key', $widget_key)
            ->firstOrFail();
        $model->update($data);

        return $this->changed($model, 'Dashboard widget updated successfully.');
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

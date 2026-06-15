<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppConfigSetting;
use App\Models\AppDashboardWidget;
use App\Models\AppFeature;
use App\Models\AppInstance;
use App\Models\AppLabel;
use App\Models\AppMembershipLabel;
use App\Models\AppNavigationItem;
use App\Models\AppSocialLink;
use App\Services\AppConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AppConfigController extends Controller
{
    public const CACHE_KEY = 'greenpreneur_app_config.v1';

    public function show(AppConfigService $appConfigService): JsonResponse
    {
        try {
            $appInstance = $appConfigService->getGreenpreneurAppInstance();

            if (! $appInstance->is_active) {
                return $this->error('App instance not configured.');
            }

            $data = Cache::remember(
                self::CACHE_KEY,
                now()->addMinutes(30),
                fn () => self::buildPublicConfig($appInstance)
            );

            return response()->json([
                'success' => true,
                'message' => 'App configuration fetched successfully.',
                'data' => $data,
            ]);
        } catch (Throwable $exception) {
            Log::error('Failed to fetch Greenpreneur app configuration.', [
                'exception' => $exception,
            ]);

            return $this->error('App configuration is currently unavailable.');
        }
    }

    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function buildPublicConfig(AppInstance $appInstance): array
    {
        $appInstanceId = $appInstance->id;
        $branding = AppConfigSetting::query()
            ->where('app_instance_id', $appInstanceId)
            ->where('is_active', true)
            ->latest('updated_at')
            ->first();

        $features = AppFeature::query()
            ->where('app_instance_id', $appInstanceId)
            ->orderBy('sort_order')
            ->pluck('is_enabled', 'feature_key')
            ->map(fn ($value) => (bool) $value)
            ->all();

        $enabledFeatureKeys = array_keys(array_filter($features));

        return [
            'app_info' => $branding ? collect($branding)->only([
                'app_name',
                'app_logo_url',
                'splash_logo_url',
                'primary_color',
                'secondary_color',
                'accent_color',
                'splash_bg_color',
                'button_color',
                'text_color',
                'playstore_url',
                'appstore_url',
                'website_url',
                'support_email',
                'support_phone',
            ])->all() : null,
            'labels' => AppLabel::query()
                ->where('app_instance_id', $appInstanceId)
                ->where('is_active', true)
                ->pluck('label_value', 'label_key')
                ->all(),
            'features' => $features,
            'navigation_menu' => self::navigationMenu($appInstanceId, $enabledFeatureKeys),
            'dashboard_widgets' => AppDashboardWidget::query()
                ->where('app_instance_id', $appInstanceId)
                ->orderBy('sort_order')
                ->pluck('is_enabled', 'widget_key')
                ->map(fn ($value) => (bool) $value)
                ->all(),
            'membership_labels' => self::membershipLabels(),
            'social_links' => AppSocialLink::query()
                ->where('app_instance_id', $appInstanceId)
                ->where('is_enabled', true)
                ->orderBy('sort_order')
                ->pluck('url', 'platform')
                ->all(),
        ];
    }

    private static function navigationMenu(string $appInstanceId, array $enabledFeatureKeys): array
    {
        $navigation = AppNavigationItem::query()
            ->where('app_instance_id', $appInstanceId)
            ->where('is_enabled', true)
            ->where(function ($query) use ($enabledFeatureKeys) {
                $query->whereNull('feature_key')
                    ->orWhereIn('feature_key', $enabledFeatureKeys);
            })
            ->orderBy('sort_order')
            ->get()
            ->groupBy('menu_type')
            ->map(fn ($items) => $items->values()->map(fn ($item) => collect($item)->only([
                'id',
                'item_key',
                'label_key',
                'display_label',
                'icon',
                'route_name',
                'feature_key',
                'sort_order',
            ])->all())->all())
            ->all();

        return array_merge([
            'bottom_nav' => [],
            'drawer' => [],
            'plus_menu' => [],
            'impact_menu' => [],
        ], $navigation);
    }

    private static function membershipLabels(): array
    {
        if (! Schema::hasTable('app_membership_labels')) {
            return self::defaultMembershipLabels();
        }

        $labels = AppMembershipLabel::query()
            ->where('is_enabled', true)
            ->pluck('display_label', 'membership_key')
            ->all();

        return $labels ?: self::defaultMembershipLabels();
    }

    private static function defaultMembershipLabels(): array
    {
        return [
            'free_peer' => 'Free Member',
            'unity_peer' => 'Green Member',
            'only_unity_peer' => 'Eco Member',
            'chartered_peer' => 'Premium Green Member',
            'charter_investor' => 'Green Investor',
        ];
    }

    private function error(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
        ], 500);
    }
}

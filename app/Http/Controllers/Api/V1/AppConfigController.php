<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppBrandingSetting;
use App\Models\AppDashboardWidget;
use App\Models\AppFeatureToggle;
use App\Models\AppLabel;
use App\Models\AppMembershipLabel;
use App\Models\AppNavigationItem;
use App\Models\AppSocialLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class AppConfigController extends Controller
{
    public const CACHE_KEY = 'greenpreneur_app_config.v1';

    public function show(): JsonResponse
    {
        $data = Cache::remember(self::CACHE_KEY, now()->addMinutes(30), fn () => self::buildPublicConfig());

        return response()->json(['success' => true, 'message' => 'App configuration fetched successfully.', 'data' => $data]);
    }

    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function buildPublicConfig(): array
    {
        $branding = AppBrandingSetting::query()->where('is_active', true)->orderByDesc('updated_at')->first();
        $features = AppFeatureToggle::query()->orderBy('sort_order')->pluck('is_enabled', 'feature_key')->map(fn ($v) => (bool) $v)->all();
        $enabledFeatureKeys = array_keys(array_filter($features));

        return [
            'app_info' => $branding ? collect($branding)->only(['app_name','app_logo_url','splash_logo_url','primary_color','secondary_color','accent_color','splash_bg_color','button_color','text_color','playstore_url','appstore_url','website_url','support_email','support_phone'])->all() : null,
            'labels' => AppLabel::query()->where('is_active', true)->pluck('label_value', 'label_key')->all(),
            'features' => $features,
            'navigation_menu' => AppNavigationItem::query()->where('is_enabled', true)->where(function ($q) use ($enabledFeatureKeys) { $q->whereNull('feature_key')->orWhereIn('feature_key', $enabledFeatureKeys); })->orderBy('sort_order')->get()->groupBy('menu_type')->map(fn ($items) => $items->values()->map(fn ($item) => collect($item)->only(['id','item_key','label_key','display_label','icon','route_name','feature_key','sort_order'])->all())->all())->union(['bottom_nav'=>[],'drawer'=>[],'plus_menu'=>[],'impact_menu'=>[]])->all(),
            'dashboard_widgets' => AppDashboardWidget::query()->orderBy('sort_order')->pluck('is_enabled', 'widget_key')->map(fn ($v) => (bool) $v)->all(),
            'membership_labels' => self::membershipLabels(),
            'social_links' => AppSocialLink::query()->where('is_enabled', true)->orderBy('sort_order')->pluck('url', 'platform')->all(),
        ];
    }

    private static function membershipLabels(): array
    {
        if (! Schema::hasTable('app_membership_labels')) {
            return [
                'free_peer' => 'Free Member',
                'unity_peer' => 'Green Member',
                'only_unity_peer' => 'Eco Member',
                'chartered_peer' => 'Premium Green Member',
                'charter_investor' => 'Green Investor',
            ];
        }

        return AppMembershipLabel::query()
            ->where('is_enabled', true)
            ->pluck('display_label', 'membership_key')
            ->all();
    }
}

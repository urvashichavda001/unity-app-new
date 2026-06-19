<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppConfigSetting;
use App\Models\AppDashboardWidget;
use App\Models\AppFeature;
use App\Models\AppIconAsset;
use App\Models\AppInstance;
use App\Models\AppLabel;
use App\Models\AppMembershipLabel;
use App\Models\AppNavigationItem;
use App\Models\AppSocialLink;
use App\Services\AppConfigService;
use App\Support\GreenpreneurIconCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AppConfigController extends Controller
{
    public const CACHE_KEY = 'app_config:greenpreneur:v2';

    public function publicConfig(AppConfigService $appConfigService): JsonResponse
    {
        try {
            $appInstance = $appConfigService->getGreenpreneurAppInstance();

            if (! $appInstance->is_active) {
                return $this->error('App instance not configured.');
            }

            $data = Cache::remember(
                self::CACHE_KEY,
                now()->addSeconds(300),
                fn () => self::buildPublicConfig($appInstance)
            );

            return response()->json([
                'success' => true,
                'message' => 'App configuration loaded successfully.',
                'data' => $data,
            ])->header('Cache-Control', 'public, max-age=300');
        } catch (Throwable $exception) {
            Log::error('Failed to fetch Greenpreneur app configuration.', [
                'exception' => $exception,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'App configuration loaded successfully.',
                'data' => self::defaultPublicConfig(),
            ])->header('Cache-Control', 'public, max-age=300');
        }
    }

    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget('greenpreneur_app_config.v2');
        Cache::forget('app_config:greenpreneur');
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
            ->all() ?: self::defaultFeatures();

        $enabledFeatureKeys = AppFeature::query()
            ->where('app_instance_id', $appInstanceId)
            ->where('is_enabled', true)
            ->pluck('feature_key')
            ->toArray() ?: array_keys(array_filter($features));

        return [
            'app_info' => self::appInfo($branding),
            'colors' => self::colors($branding),
            'icons' => self::icons($appInstanceId),
            'features' => $features,
            'labels' => AppLabel::query()
                ->where('app_instance_id', $appInstanceId)
                ->where('is_active', true)
                ->pluck('label_value', 'label_key')
                ->all() ?: self::defaultLabels(),
            'navigation_menu' => self::navigationMenu($appInstanceId, $enabledFeatureKeys),
            'dashboard_widgets' => AppDashboardWidget::query()
                ->where('app_instance_id', $appInstanceId)
                ->orderBy('sort_order')
                ->pluck('is_enabled', 'widget_key')
                ->map(fn ($value) => (bool) $value)
                ->all() ?: self::defaultDashboardWidgets(),
            'membership_labels' => self::membershipLabels(),
            'social_links' => AppSocialLink::query()
                ->where('app_instance_id', $appInstanceId)
                ->orderBy('sort_order')
                ->pluck('url', 'platform')
                ->all() ?: self::defaultSocialLinks(),
        ];
    }


    private static function appInfo(?AppConfigSetting $branding): array
    {
        $defaults = self::defaultAppInfo();
        $light = $branding?->logo_url_light ?: $branding?->app_logo_url ?: $defaults['logo_url_light'];
        $splash = $branding?->logo_url_splash ?: $branding?->splash_logo_url ?: $defaults['logo_url_splash'];

        return [
            'app_name' => $branding?->app_name ?: $defaults['app_name'],
            'logo_url_light' => $light,
            'logo_url_dark' => $branding?->logo_url_dark ?: $light,
            'logo_url_splash' => $splash,
            'app_logo_url' => $light,
            'splash_logo_url' => $splash,
            'playstore_url' => $branding?->playstore_url ?: $defaults['playstore_url'],
            'appstore_url' => $branding?->appstore_url ?: $defaults['appstore_url'],
        ];
    }

    private static function colors(?AppConfigSetting $branding): array
    {
        $defaults = self::defaultColors();
        $colors = [];
        foreach ($defaults as $key => $default) {
            $value = $branding?->{$key};
            $colors[$key] = is_string($value) && preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{8})$/', $value) ? $value : $default;
        }

        return $colors;
    }

    private static function icons(string $appInstanceId): array
    {
        if (! Schema::hasTable('app_icon_assets')) {
            return self::defaultIcons();
        }

        $icons = AppIconAsset::query()
            ->where('app_instance_id', $appInstanceId)
            ->where('is_active', true)
            ->orderBy('icon_group')
            ->orderBy('sort_order')
            ->get();

        if ($icons->isEmpty()) {
            return self::defaultIcons();
        }

        $grouped = collect(GreenpreneurIconCatalog::GROUPS)
            ->mapWithKeys(fn ($label, $group) => [$group => []])
            ->all();

        foreach ($icons as $icon) {
            $group = $icon->icon_group ?: 'custom_assets';
            if (! array_key_exists($group, $grouped)) {
                $grouped[$group] = [];
            }

            $grouped[$group][] = self::formatIcon($icon);
        }

        $byKey = $icons->keyBy('icon_key');
        $flat = collect(GreenpreneurIconCatalog::FLAT_MAP)
            ->mapWithKeys(fn ($iconKey, $flatKey) => [$flatKey => $byKey->get($iconKey)?->icon_url])
            ->all();
        $grouped['flat'] = $flat;

        return array_merge($grouped, $flat);
    }

    private static function formatIcon(AppIconAsset $icon): array
    {
        return [
            'icon_key' => $icon->icon_key,
            'icon_name' => $icon->icon_name,
            'icon_group' => $icon->icon_group,
            'source_type' => $icon->source_type,
            'icon_library' => $icon->icon_library,
            'default_icon' => $icon->default_icon,
            'selected_icon' => $icon->selected_icon,
            'icon_url' => $icon->icon_url,
            'selected_icon_url' => $icon->selected_icon_url,
            'fallback_asset' => $icon->fallback_asset,
            'feature_key' => $icon->feature_key,
            'menu_key' => $icon->menu_key,
            'screen_name' => $icon->screen_name,
            'usage_location' => $icon->usage_location,
            'is_active' => (bool) $icon->is_active,
            'sort_order' => (int) $icon->sort_order,
        ];
    }

    public static function supportedIconKeys(): array
    {
        return array_keys(GreenpreneurIconCatalog::FLAT_MAP);
    }

    private static function defaultIcons(): array
    {
        return GreenpreneurIconCatalog::blankGroupedResponse();
    }

    private static function defaultColors(): array
    {
        return [
            'primary_color' => '#44A268',
            'primary_dark_color' => '#1B5E20',
            'primary_ultra_light_color' => '#E8F5E9',
            'secondary_color' => '#0F172A',
            'text_primary_color' => '#466186',
            'text_secondary_color' => '#6B7280',
            'background_color' => '#F5F7FA',
            'card_background_color' => '#FFFFFF',
        ];
    }

    private static function navigationMenu(string $appInstanceId, array $enabledFeatureKeys): array
    {
        $navigationItems = AppNavigationItem::query()
            ->where('app_instance_id', $appInstanceId)
            ->where('is_enabled', true)
            ->where(function ($query) use ($enabledFeatureKeys) {
                $query->whereNull('feature_key')
                    ->orWhereIn('feature_key', $enabledFeatureKeys);
            })
            ->orderBy('sort_order')
            ->get();

        $navigationGrouped = $navigationItems->groupBy('menu_type');

        return [
            'bottom_nav' => self::formatNavigationItems($navigationGrouped->get('bottom_nav', collect())),
            'drawer' => self::formatNavigationItems($navigationGrouped->get('drawer', collect())),
            'plus_menu' => self::formatNavigationItems($navigationGrouped->get('plus_menu', collect())),
            'impact_menu' => self::formatNavigationItems($navigationGrouped->get('impact_menu', collect())),
        ];
    }

    private static function formatNavigationItems($items): array
    {
        return $items->values()
            ->map(fn ($item) => [
                'key' => $item->item_key ?? $item->nav_key ?? $item->v_key,
                'label_key' => $item->label_key,
                'label' => $item->display_label ?? $item->nav_label ?? $item->v_label,
                'icon' => $item->icon,
                'route_name' => $item->route_name,
                'feature_key' => $item->feature_key,
                'order' => $item->sort_order ?? $item->position,
            ])
            ->toArray();
    }


    private static function defaultPublicConfig(): array
    {
        return [
            'app_info' => self::defaultAppInfo(),
            'colors' => self::defaultColors(),
            'icons' => self::defaultIcons(),
            'features' => self::defaultFeatures(),
            'navigation_menu' => [
                'bottom_nav' => [],
                'drawer' => [],
                'plus_menu' => [],
                'impact_menu' => [],
            ],
            'dashboard_widgets' => self::defaultDashboardWidgets(),
            'membership_labels' => self::defaultMembershipLabels(),
            'social_links' => self::defaultSocialLinks(),
        ];
    }

    private static function defaultFeatures(): array
    {
        return [
            'events' => true,
            'referrals' => true,
            'business_deals' => true,
            'p2p_meetings' => true,
            'testimonials' => true,
            'requirements' => true,
            'collaborations' => true,
            'collaboration_ask' => true,
            'visitor_registration' => true,
            'add_impact' => true,
            'claim_coins' => true,
            'coins_wallet' => true,
            'leaderboard' => true,
            'impact_score' => true,
            'badges' => true,
            'gratitude_score' => false,
            'circles' => true,
            'chat_messaging' => true,
            'geo_nearby' => false,
            'circulars' => true,
            'gallery' => true,
            'videos' => true,
            'meeting_schedule' => true,
            'invoices' => true,
            'blocked_users' => true,
            'welcome_creative' => true,
            'feedback' => true,
            'qr_scan' => true,
            'community_feed' => true,
            'leadership_form' => true,
            'recommend_peer' => true,
            'peers' => true,
        ];
    }

    private static function defaultDashboardWidgets(): array
    {
        return [
            'banner_carousel' => true,
            'leaderboard_preview' => true,
            'impact_tracker' => true,
            'upcoming_events' => true,
            'hot_deals' => true,
            'membership_banner' => true,
            'feed_composer' => true,
            'circle_preview' => true,
            'community_feed' => true,
        ];
    }

    private static function defaultSocialLinks(): array
    {
        return [
            'linkedin' => 'https://linkedin.com/company/greenpreneur',
            'instagram' => 'https://instagram.com/greenpreneur',
            'facebook' => 'https://facebook.com/greenpreneur',
            'youtube' => null,
            'website' => 'https://greenpreneur.in',
        ];
    }

    private static function defaultAppInfo(): array
    {
        return [
            'app_name' => 'Greenpreneur',
            'logo_url_light' => 'https://peersunity.com/assets/brand/logo_light.png',
            'logo_url_dark' => 'https://peersunity.com/assets/brand/logo_dark.png',
            'logo_url_splash' => 'https://peersunity.com/assets/brand/logo_splash.png',
            'app_logo_url' => 'https://peersunity.com/assets/brand/logo_light.png',
            'splash_logo_url' => 'https://peersunity.com/assets/brand/logo_splash.png',
            'playstore_url' => 'https://play.google.com/store/apps/details?id=com.greenpreneur.greenpreneur',
            'appstore_url' => 'https://apps.apple.com/app/id1234567890',
        ];
    }

    private static function defaultLabels(): array
    {
        return [
            'app_name' => 'Greenpreneur',
            'peer' => 'Green Member',
            'peers' => 'Green Network',
            'my_peers' => 'My Network',
            'circle' => 'Eco-Circle',
            'circles' => 'Eco-Circles',
            'event' => 'Eco Event',
            'events' => 'Eco Events',
            'coin' => 'Green Coin',
            'coins' => 'Green Coins',
            'impact' => 'Green Impact',
            'lives_impacted' => 'Impact Score',
            'referral' => 'Green Referral',
            'business_deal' => 'Green Deal',
            'p2p_meeting' => 'Peer Meeting',
            'requirement' => 'Need',
            'post_an_ask' => 'Post a Need',
            'visitor' => 'Guest',
            'register_visitor' => 'Guest Pass',
            'circular' => 'Announcement',
            'circulars' => 'Announcements',
            'chat' => 'Messages',
            'leaderboard' => 'Green Leaderboard',
            'badge' => 'Green Badge',
            'welcome_title' => 'Welcome to the Greenpreneur Ecosystem',
            'welcome_subtitle' => "Join India's growing green entrepreneurship network",
            'register_button' => 'Join Now',
            'login_button' => 'Login',
            'activities_section_title' => 'GREEN ACTIONS',
            'impact_section_title' => 'GREEN IMPACT DASHBOARD',
            'share_message' => "I am part of Greenpreneur, India's green entrepreneurship network. Join now and become part of the green movement.",
        ];
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

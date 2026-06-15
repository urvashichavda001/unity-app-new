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

            return response()->json([
                'success' => true,
                'message' => 'Default app configuration fetched successfully.',
                'data' => self::defaultPublicConfig(),
            ]);
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
            ->all() ?: self::defaultFeatures();

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
            ])->all() : self::defaultAppInfo(),
            'labels' => AppLabel::query()
                ->where('app_instance_id', $appInstanceId)
                ->where('is_active', true)
                ->pluck('label_value', 'label_key')
                ->all() ?: self::defaultLabels(),
            'features' => $features,
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
                ->where('is_enabled', true)
                ->orderBy('sort_order')
                ->pluck('url', 'platform')
                ->all() ?: self::defaultSocialLinks(),
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


    private static function defaultPublicConfig(): array
    {
        return [
            'app_info' => self::defaultAppInfo(),
            'labels' => self::defaultLabels(),
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
            'website' => 'https://greenpreneur.in',
        ];
    }

    private static function defaultAppInfo(): array
    {
        return [
            'app_name' => 'Greenpreneur',
            'app_logo_url' => 'https://greenpreneur.in/uploads/greenpreneur_logo.png',
            'splash_logo_url' => 'https://greenpreneur.in/uploads/greenpreneur_logo.png',
            'primary_color' => '#2E7D32',
            'secondary_color' => '#81C784',
            'accent_color' => '#FFC107',
            'splash_bg_color' => '#FFFFFF',
            'button_color' => '#2E7D32',
            'text_color' => '#212121',
            'playstore_url' => null,
            'appstore_url' => null,
            'website_url' => 'https://greenpreneur.in',
            'support_email' => 'support@greenpreneur.in',
            'support_phone' => '+91XXXXXXXXXX',
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

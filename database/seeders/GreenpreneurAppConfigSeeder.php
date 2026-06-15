<?php

namespace Database\Seeders;

use App\Models\AppConfigSetting;
use App\Models\AppDashboardWidget;
use App\Models\AppFeature;
use App\Models\AppLabel;
use App\Models\AppMembershipLabel;
use App\Models\AppNavigationItem;
use App\Models\AppSocialLink;
use App\Services\AppConfigService;
use Illuminate\Database\Seeder;

class GreenpreneurAppConfigSeeder extends Seeder
{
    public function run(): void
    {
        $appInstance = app(AppConfigService::class)->getGreenpreneurAppInstance();
        $appInstanceId = $appInstance->id;

        AppConfigSetting::query()->updateOrCreate(
            ['app_instance_id' => $appInstanceId, 'app_key' => 'greenpreneur'],
            [
                'app_name' => 'Greenpreneur',
                'app_logo_url' => 'https://greenpreneur.in/uploads/greenpreneur_logo.png',
                'splash_logo_url' => 'https://greenpreneur.in/uploads/greenpreneur_logo.png',
                'primary_color' => '#2E7D32',
                'secondary_color' => '#81C784',
                'accent_color' => '#FFC107',
                'splash_bg_color' => '#FFFFFF',
                'button_color' => '#2E7D32',
                'text_color' => '#212121',
                'website_url' => 'https://greenpreneur.in',
                'support_email' => 'support@greenpreneur.in',
                'support_phone' => '+91XXXXXXXXXX',
                'is_active' => true,
            ]
        );

        foreach ($this->labels() as $key => $value) {
            AppLabel::query()->updateOrCreate(
                ['app_instance_id' => $appInstanceId, 'label_key' => $key],
                [
                    'label_value' => $value,
                    'group_name' => $this->labelGroup($key),
                    'is_active' => true,
                ]
            );
        }

        $sortOrder = 1;
        foreach ($this->features() as $key => $enabled) {
            AppFeature::query()->updateOrCreate(
                ['app_instance_id' => $appInstanceId, 'feature_key' => $key],
                [
                    'feature_name' => str($key)->replace('_', ' ')->title()->toString(),
                    'is_enabled' => $enabled,
                    'sort_order' => $sortOrder++,
                ]
            );
        }

        $this->seedNavigation($appInstanceId, 'bottom_nav', [
            ['home', 'Feed', 'home', 'home', 'home', 'community_feed', true, 1],
            ['my_peers', 'My Network', 'people', 'my_peers', 'my_network', 'peers', true, 2],
            ['impact', 'Green Impact', 'impact', 'impact', 'impact', 'impact_score', true, 3],
            ['circle', 'Eco-Circle', 'circle', 'circle', 'circle', 'circles', true, 4],
            ['highlights', 'Highlights', 'star', null, 'highlights', null, true, 5],
        ]);

        $this->seedNavigation($appInstanceId, 'plus_menu', [
            ['events', 'Eco Events', null, 'events', 'events', 'events', true, 1],
            ['referrals', 'Green Referral', null, 'referral', 'referrals', 'referrals', true, 2],
            ['business_deals', 'Green Deal', null, 'business_deal', 'business_deals', 'business_deals', true, 3],
            ['p2p_meetings', 'Peer Meeting', null, 'p2p_meeting', 'p2p_meetings', 'p2p_meetings', true, 4],
            ['testimonials', 'Testimonial', null, null, 'testimonials', 'testimonials', true, 5],
            ['requirements', 'Post a Need', null, 'post_an_ask', 'requirements', 'requirements', true, 6],
            ['collaborations', 'Find Collaboration', null, null, 'collaborations', 'collaborations', true, 7],
            ['visitor_registration', 'Guest Pass', null, 'register_visitor', 'visitor_registration', 'visitor_registration', true, 8],
            ['add_impact', 'Log Impact', null, 'impact', 'add_impact', 'add_impact', true, 9],
            ['claim_coins', 'Claim Green Coins', null, 'coins', 'claim_coins', 'claim_coins', true, 10],
        ]);

        $this->seedNavigation($appInstanceId, 'impact_menu', [
            ['impact_score', 'My Green Impact', null, 'impact', 'impact_score', 'impact_score', true, 1],
            ['badges', 'Green Badges', null, 'badge', 'badges', 'badges', true, 2],
            ['coins_wallet', 'My Green Coins', null, 'coins', 'coins_wallet', 'coins_wallet', true, 3],
            ['collaboration_history', 'Collaboration History', null, null, 'collaboration_history', 'collaborations', true, 4],
            ['referrals', 'Green Referrals', null, 'referral', 'referrals', 'referrals', true, 5],
            ['gratitude_score', 'Gratitude Score', null, null, 'gratitude_score', 'gratitude_score', false, 6],
        ]);

        $this->seedNavigation($appInstanceId, 'drawer', [
            ['circulars', 'Announcements', null, 'circulars', 'circulars', 'circulars', true, 1],
            ['gallery', 'Photo Gallery', null, null, 'gallery', 'gallery', true, 2],
            ['videos', 'Video Library', null, null, 'videos', 'videos', true, 3],
            ['meeting_schedule', 'Meeting Schedule', null, null, 'meeting_schedule', 'meeting_schedule', true, 4],
            ['invoices', 'Invoices', null, null, 'invoices', 'invoices', true, 5],
            ['blocked_users', 'Blocked Users', null, null, 'blocked_users', 'blocked_users', true, 6],
            ['welcome_creative', 'Welcome Card', null, null, 'welcome_creative', 'welcome_creative', true, 7],
            ['rate_app', 'Rate App', null, null, 'rate_app', null, true, 8],
            ['share_app', 'Share App', null, null, 'share_app', null, true, 9],
            ['settings', 'Settings', null, null, 'settings', null, true, 10],
            ['feedback', 'Feedback', null, null, 'feedback', 'feedback', true, 11],
            ['logout', 'Logout', null, null, 'logout', null, true, 12],
        ]);

        foreach ($this->dashboardWidgets() as $index => $key) {
            AppDashboardWidget::query()->updateOrCreate(
                ['app_instance_id' => $appInstanceId, 'widget_key' => $key],
                [
                    'widget_name' => str($key)->replace('_', ' ')->title()->toString(),
                    'is_enabled' => true,
                    'sort_order' => $index + 1,
                ]
            );
        }

        foreach ($this->socialLinks() as $link) {
            AppSocialLink::query()->updateOrCreate(
                ['app_instance_id' => $appInstanceId, 'platform' => $link['platform']],
                $link + ['is_enabled' => $link['platform'] !== 'youtube']
            );
        }

        foreach ($this->membershipLabels() as $key => $label) {
            AppMembershipLabel::query()->updateOrCreate(
                ['membership_key' => $key],
                ['display_label' => $label, 'is_enabled' => true]
            );
        }
    }

    private function seedNavigation(string $appInstanceId, string $type, array $items): void
    {
        foreach ($items as [$key, $label, $icon, $labelKey, $routeName, $featureKey, $enabled, $sortOrder]) {
            AppNavigationItem::query()->updateOrCreate(
                ['app_instance_id' => $appInstanceId, 'menu_type' => $type, 'item_key' => $key],
                [
                    'label_key' => $labelKey,
                    'display_label' => $label,
                    'icon' => $icon,
                    'route_name' => $routeName,
                    'feature_key' => $featureKey,
                    'is_enabled' => $enabled,
                    'sort_order' => $sortOrder,
                ]
            );
        }
    }

    private function labels(): array
    {
        return [
            'app_name' => 'Greenpreneur', 'peer' => 'Green Member', 'peers' => 'Green Network', 'my_peers' => 'My Network', 'circle' => 'Eco-Circle', 'circles' => 'Eco-Circles', 'event' => 'Eco Event', 'events' => 'Eco Events', 'coin' => 'Green Coin', 'coins' => 'Green Coins', 'impact' => 'Green Impact', 'lives_impacted' => 'Impact Score', 'referral' => 'Green Referral', 'business_deal' => 'Green Deal', 'p2p_meeting' => 'Peer Meeting', 'requirement' => 'Need', 'post_an_ask' => 'Post a Need', 'visitor' => 'Guest', 'register_visitor' => 'Guest Pass', 'circular' => 'Announcement', 'circulars' => 'Announcements', 'chat' => 'Messages', 'leaderboard' => 'Green Leaderboard', 'badge' => 'Green Badge', 'welcome_title' => 'Welcome to the Greenpreneur Ecosystem', 'welcome_subtitle' => "Join India's growing green entrepreneurship network", 'register_button' => 'Join Now', 'login_button' => 'Login', 'activities_section_title' => 'GREEN ACTIONS', 'impact_section_title' => 'GREEN IMPACT DASHBOARD', 'share_message' => "I am part of Greenpreneur, India's green entrepreneurship network. Join now and become part of the green movement.",
        ];
    }

    private function features(): array
    {
        return ['events' => true, 'referrals' => true, 'business_deals' => true, 'p2p_meetings' => true, 'testimonials' => true, 'requirements' => true, 'collaborations' => true, 'collaboration_ask' => true, 'visitor_registration' => true, 'add_impact' => true, 'claim_coins' => true, 'coins_wallet' => true, 'leaderboard' => true, 'impact_score' => true, 'badges' => true, 'gratitude_score' => false, 'circles' => true, 'chat_messaging' => true, 'geo_nearby' => false, 'circulars' => true, 'gallery' => true, 'videos' => true, 'meeting_schedule' => true, 'invoices' => true, 'blocked_users' => true, 'welcome_creative' => true, 'feedback' => true, 'qr_scan' => true, 'community_feed' => true, 'leadership_form' => true, 'recommend_peer' => true, 'peers' => true];
    }

    private function dashboardWidgets(): array
    {
        return ['banner_carousel', 'leaderboard_preview', 'impact_tracker', 'upcoming_events', 'hot_deals', 'membership_banner', 'feed_composer', 'circle_preview', 'community_feed'];
    }

    private function socialLinks(): array
    {
        return [
            ['platform' => 'linkedin', 'display_name' => 'LinkedIn', 'url' => 'https://linkedin.com/company/greenpreneur', 'icon' => 'linkedin', 'sort_order' => 1],
            ['platform' => 'instagram', 'display_name' => 'Instagram', 'url' => 'https://instagram.com/greenpreneur', 'icon' => 'instagram', 'sort_order' => 2],
            ['platform' => 'facebook', 'display_name' => 'Facebook', 'url' => 'https://facebook.com/greenpreneur', 'icon' => 'facebook', 'sort_order' => 3],
            ['platform' => 'youtube', 'display_name' => 'YouTube', 'url' => null, 'icon' => 'youtube', 'sort_order' => 4],
            ['platform' => 'website', 'display_name' => 'Website', 'url' => 'https://greenpreneur.in', 'icon' => 'website', 'sort_order' => 5],
        ];
    }

    private function membershipLabels(): array
    {
        return ['free_peer' => 'Free Member', 'unity_peer' => 'Green Member', 'only_unity_peer' => 'Eco Member', 'chartered_peer' => 'Premium Green Member', 'charter_investor' => 'Green Investor'];
    }

    private function labelGroup(string $key): string
    {
        return str_contains($key, 'welcome') ? 'welcome' : (str_contains($key, 'button') ? 'buttons' : 'general');
    }
}

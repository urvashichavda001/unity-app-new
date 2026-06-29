<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class NotificationFeatureController extends Controller
{
    /**
     * GET 'api/v1/activities/daily-summary'
     */
    public function dailySummary(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'summary_date' => '2026-06-29',
                'activity_score' => 85,
                'unread_messages_count' => 4,
                'trending_topics' => ['SaaS Growth', 'Real Estate Tokenization', 'AI Networking'],
            ],
        ]);
    }

    /**
     * GET 'api/v1/insights/industry'
     */
    public function industryInsight(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'insight_id' => 'ins_1029',
                'target_industry' => 'Technology & Software',
                'published_at' => '2026-06-29T11:52:00Z',
                'title' => 'The Shift to Hybrid B2B Models',
                'snippet' => 'Recent telemetry indicates a 14% increase in procurement cycles when self-serve tiers are missing...',
                'content_markdown' => "### Industry Briefing\n\nSoftware procurement is shifting heavily toward self-serve onboarding.",
            ],
        ]);
    }

    /**
     * GET 'api/v1/rewards/store/items'
     */
    public function rewardItems(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                [
                    'item_id' => 'reward_pass_premium',
                    'title' => 'Premium Network Pass (1 Month)',
                    'cost_coins' => 250,
                    'inventory_status' => 'available',
                    'image_url' => 'https://peersunity.com/assets/rewards/premium-pass.png',
                ],
                [
                    'item_id' => 'reward_ad_credit_50',
                    'title' => '$50 Targeted Ad Campaign Credit',
                    'cost_coins' => 500,
                    'inventory_status' => 'available',
                    'image_url' => 'https://peersunity.com/assets/rewards/ad-credits.png',
                ],
            ],
        ]);
    }

    /**
     * GET 'api/v1/newsletter/latest'
     */
    public function latestNewsletter(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'newsletter_id' => 'news_wk_26',
                'title' => 'This Week in Peers Global',
                'content_html' => '<h1>Top Deals & Circle News</h1><p>Our network closed $150k in deals this week...</p>',
                'highlights' => [
                    'new_members_count' => 82,
                    'top_circle' => 'Innovators Hub',
                ],
            ],
        ]);
    }

    /**
     * GET 'api/v1/circle-categories'
     */
    public function circleCategories(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                [
                    'category_id' => 'cat_it',
                    'name' => 'Technology & SaaS',
                    'circles_count' => 14,
                    'icon_slug' => 'cpu',
                ],
                [
                    'category_id' => 'cat_fin',
                    'name' => 'Finance & Real Estate',
                    'circles_count' => 8,
                    'icon_slug' => 'money',
                ],
            ],
        ]);
    }

    /**
     * GET 'api/v1/life-impact/cycles/active'
     */
    public function activeLifeImpactCycle(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'cycle_id' => 'q2_2026_impact',
                'cycle_name' => 'Q2 Growth Sprint',
                'ends_at' => '2026-06-30T23:59:59Z',
                'user_stats' => [
                    'points_earned' => 1420,
                    'current_rank' => 12,
                    'next_milestone_points' => 1500,
                    'badge_tier' => 'Gold Professional',
                ],
            ],
        ]);
    }
}

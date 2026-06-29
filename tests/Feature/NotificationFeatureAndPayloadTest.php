<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Firebase\FcmService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationFeatureAndPayloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Test User',
            'email' => $email,
            'status' => 'active',
        ]);
    }

    public function test_endpoints_return_correct_dummy_data(): void
    {
        $user = $this->createUser('test@example.com');
        Sanctum::actingAs($user);

        // 1. GET 'api/v1/activities/daily-summary'
        $this->getJson('/api/v1/activities/daily-summary')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'summary_date' => '2026-06-29',
                    'activity_score' => 85,
                    'unread_messages_count' => 4,
                    'trending_topics' => ["SaaS Growth", "Real Estate Tokenization", "AI Networking"]
                ]
            ]);

        // 2. GET 'api/v1/insights/industry'
        $this->getJson('/api/v1/insights/industry')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'insight_id' => 'ins_1029',
                    'target_industry' => 'Technology & Software',
                    'published_at' => '2026-06-29T11:52:00Z',
                    'title' => 'The Shift to Hybrid B2B Models',
                    'snippet' => 'Recent telemetry indicates a 14% increase in procurement cycles when self-serve tiers are missing...',
                    'content_markdown' => "### Industry Briefing\n\nSoftware procurement is shifting heavily toward self-serve onboarding."
                ]
            ]);

        // 3. GET 'api/v1/rewards/store/items'
        $this->getJson('/api/v1/rewards/store/items')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    [ 'item_id' => 'reward_pass_premium', 'title' => 'Premium Network Pass (1 Month)', 'cost_coins' => 250, 'inventory_status' => 'available', 'image_url' => 'https://peersunity.com/assets/rewards/premium-pass.png' ],
                    [ 'item_id' => 'reward_ad_credit_50', 'title' => '$50 Targeted Ad Campaign Credit', 'cost_coins' => 500, 'inventory_status' => 'available', 'image_url' => 'https://peersunity.com/assets/rewards/ad-credits.png' ]
                ]
            ]);

        // 4. GET 'api/v1/newsletter/latest'
        $this->getJson('/api/v1/newsletter/latest')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'newsletter_id' => 'news_wk_26',
                    'title' => 'This Week in Peers Global',
                    'content_html' => '<h1>Top Deals & Circle News</h1><p>Our network closed $150k in deals this week...</p>',
                    'highlights' => [ "new_members_count" => 82, "top_circle" => "Innovators Hub" ]
                ]
            ]);

        // 5. GET 'api/v1/circle-categories'
        $this->getJson('/api/v1/circle-categories')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    [ 'category_id' => 'cat_it', 'name' => 'Technology & SaaS', 'circles_count' => 14, 'icon_slug' => 'cpu' ],
                    [ 'category_id' => 'cat_fin', 'name' => 'Finance & Real Estate', 'circles_count' => 8, 'icon_slug' => 'money' ]
                ]
            ]);

        // 6. GET 'api/v1/life-impact/cycles/active'
        $this->getJson('/api/v1/life-impact/cycles/active')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'cycle_id' => 'q2_2026_impact',
                    'cycle_name' => 'Q2 Growth Sprint',
                    'ends_at' => '2026-06-30T23:59:59Z',
                    'user_stats' => [ 'points_earned' => 1420, 'current_rank' => 12, 'next_milestone_points' => 1500, 'badge_tier' => 'Gold Professional' ]
                ]
            ]);
    }

    public function test_fcm_payload_normalization_for_circle_details(): void
    {
        $fcm = app(FcmService::class);
        $circleId = (string) Str::uuid();

        // Testing direct service normalization
        $payload1 = [
            'tap_destination' => 'circle_details',
            'reference_id' => $circleId,
            'type' => 'circle_post_created'
        ];

        $normalized1 = $fcm->normalizePushPayload($payload1);

        $this->assertEquals('circle_update', $normalized1['type']);
        $this->assertEquals('circle_details', $normalized1['notification_type']);
        $this->assertEquals($circleId, $normalized1['circle_id']);
    }

    public function test_fcm_payload_normalization_for_leader_profile(): void
    {
        $fcm = app(FcmService::class);
        $memberId = (string) Str::uuid();

        $payload = [
            'tap_destination' => 'member_profile',
            'user_id' => $memberId,
        ];

        $normalized = $fcm->normalizePushPayload($payload);

        $this->assertEquals('member_profile', $normalized['type']);
        $this->assertEquals('leader_profile', $normalized['notification_type']);
        $this->assertEquals($memberId, $normalized['member_id']);
    }

    public function test_fcm_payload_normalization_for_business_deals(): void
    {
        $fcm = app(FcmService::class);
        $dealId = (string) Str::uuid();

        $payload = [
            'screen' => 'business_deal',
            'business_deal_id' => $dealId,
        ];

        $normalized = $fcm->normalizePushPayload($payload);

        $this->assertEquals('business_deal_finalized', $normalized['type']);
        $this->assertEquals('business_deal', $normalized['notification_type']);
        $this->assertEquals($dealId, $normalized['deal_id']);
    }

    public function test_fcm_payload_normalization_for_advertiser_ads(): void
    {
        $fcm = app(FcmService::class);

        $payload = [
            'tap_destination' => '/deals',
            'ad_url' => 'https://example.com/ad',
        ];

        $normalized = $fcm->normalizePushPayload($payload);

        $this->assertEquals('external_promo', $normalized['type']);
        $this->assertEquals('marketing_ad', $normalized['notification_type']);
        $this->assertEquals('/external-promo', $normalized['tap_destination']);
        $this->assertEquals('https://example.com/ad', $normalized['ad_url']);
    }
}

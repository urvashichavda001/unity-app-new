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
            ->assertJsonStructure([
                'success',
                'data' => [
                    'summary_date',
                    'activity_score',
                    'unread_messages_count',
                    'trending_topics',
                ]
            ]);

        // 2. GET 'api/v1/insights/industry'
        $this->getJson('/api/v1/insights/industry')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'insight_id',
                    'target_industry',
                    'published_at',
                    'title',
                    'snippet',
                    'content_markdown',
                ]
            ]);

        // 3. GET 'api/v1/rewards/store/items'
        $this->getJson('/api/v1/rewards/store/items')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'item_id',
                        'title',
                        'cost_coins',
                        'inventory_status',
                        'image_url',
                    ]
                ]
            ]);

        // 4. GET 'api/v1/newsletter/latest'
        $this->getJson('/api/v1/newsletter/latest')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'newsletter_id',
                    'title',
                    'content_html',
                    'highlights' => [
                        'new_members_count',
                        'top_circle',
                    ]
                ]
            ]);

        // 5. GET 'api/v1/circle-categories'
        $this->getJson('/api/v1/circle-categories')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'category_id',
                        'name',
                        'circles_count',
                        'icon_slug',
                    ]
                ]
            ]);

        // 6. GET 'api/v1/life-impact/cycles/active'
        $this->getJson('/api/v1/life-impact/cycles/active')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'cycle_id',
                    'cycle_name',
                    'ends_at',
                    'user_stats' => [
                        'points_earned',
                        'current_rank',
                        'next_milestone_points',
                        'badge_tier',
                    ]
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

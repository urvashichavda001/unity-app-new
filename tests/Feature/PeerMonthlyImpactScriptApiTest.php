<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PeerMonthlyImpactScriptApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('referrals');
        Schema::dropIfExists('p2p_meetings');
        Schema::dropIfExists('business_deals');
        Schema::dropIfExists('life_impact_histories');
        Schema::dropIfExists('impacts');
        Schema::dropIfExists('users');

        $this->createUsersTable();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_user_without_referrals_returns_safe_defaults_and_successful_script(): void
    {
        $user = $this->createUser([
            'first_name' => 'Demo1',
            'last_name' => 'Demo1',
            'display_name' => 'Demo1 Demo1',
            'company_name' => 'Aequitas Information Technology Pvt Ltd',
            'business_type' => null,
            'industry_tags' => json_encode([]),
            'life_impacted_count' => 0,
        ]);
        $user->setAttribute('category', null);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/peer-monthly-impact-script');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.category', 'your category')
            ->assertJsonPath(
                'data.script.introduction_text',
                'My name is Demo1 Demo1. I run Aequitas Information Technology Pvt Ltd in your category.'
            )
            ->assertJsonPath('data.script.monthly_business_done_text', 'This month I did business worth ₹ 0.00 with Peers.')
            ->assertJsonPath('data.script.business_deals_text', 'I recorded 0 business deal(s) this month totalling ₹ 0.00.');

        $qualifiedReferrals = collect($response->json('data.checklist_items'))
            ->firstWhere('key', 'qualified_referrals_given');

        $this->assertSame(0, $qualifiedReferrals['count']);
        $this->assertSame([], $qualifiedReferrals['related_items']);
        $this->assertFalse($qualifiedReferrals['is_available']);
    }

    public function test_multiple_monthly_referrals_return_detailed_related_items(): void
    {
        Carbon::setTestNow('2026-05-09 10:00:00');
        $this->createReferralsTable();

        $user = $this->createUser([
            'display_name' => 'Demo User',
            'company_name' => 'Demo Co',
        ]);
        $rahul = $this->createUser([
            'display_name' => 'Rahul Shah',
            'company_name' => 'ABC Pvt Ltd',
        ]);
        $jay = $this->createUser([
            'display_name' => 'Jay Patel',
            'company_name' => 'Jay Industries',
        ]);
        $oldPeer = $this->createUser([
            'display_name' => 'Old Peer',
            'company_name' => 'Old Co',
        ]);

        $this->createReferral($user, $rahul, 'Vijay Traders', '2026-05-03');
        $this->createReferral($user, $jay, 'Patel Packaging', '2026-05-08');
        $this->createReferral($user, $oldPeer, 'Old Lead', '2026-04-30');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/peer-monthly-impact-script');

        $response->assertOk()->assertJsonPath('success', true);

        $qualifiedReferrals = collect($response->json('data.checklist_items'))
            ->firstWhere('key', 'qualified_referrals_given');

        $this->assertCount(2, $qualifiedReferrals['related_items']);
        $this->assertSame(2, $qualifiedReferrals['count']);
        $this->assertTrue($qualifiedReferrals['is_available']);
        $this->assertSame(
            'I gave a qualified referral to Jay Patel — connecting them with Patel Packaging',
            $qualifiedReferrals['related_items'][0]['display_text']
        );
        $this->assertSame('Jay Industries', $qualifiedReferrals['related_items'][0]['peer_company_name']);
        $this->assertSame('Patel Packaging', $qualifiedReferrals['related_items'][0]['connected_with_business_name']);

        $emptyChecklistItem = collect($response->json('data.checklist_items'))
            ->firstWhere('key', 'vendor_or_service_help');

        $this->assertSame([], $emptyChecklistItem['related_items']);
        $this->assertSame(0, $emptyChecklistItem['count']);
        $this->assertFalse($emptyChecklistItem['is_available']);
    }

    public function test_monthly_business_deals_return_totals_peer_history_and_display_text(): void
    {
        Carbon::setTestNow('2026-05-09 10:00:00');
        $this->createBusinessDealsTable();

        $user = $this->createUser(['display_name' => 'Demo User']);
        $rahul = $this->createUser([
            'display_name' => 'Rahul Shah',
            'company_name' => 'ABC Pvt Ltd',
        ]);
        $jay = $this->createUser([
            'display_name' => 'Jay Patel',
            'company_name' => 'Jay Industries',
        ]);
        $pendingPeer = $this->createUser(['display_name' => 'Pending Peer']);

        $this->createBusinessDeal($user, $rahul, 5000, '2026-05-04', 'completed');
        $this->createBusinessDeal($jay, $user, 2500, '2026-05-07', 'approved');
        $this->createBusinessDeal($user, $pendingPeer, 9000, '2026-05-08', 'pending');
        $this->createBusinessDeal($user, $rahul, 1000, '2026-04-28', 'completed');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/peer-monthly-impact-script');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.business_deals_this_month.total_amount', 7500)
            ->assertJsonPath('data.summary.total_business_done_with_peers_this_month', 7500)
            ->assertJsonPath('data.period.total_business_done_with_peers_this_month', 7500);

        $businessDeals = $response->json('data.business_deals_this_month');

        $this->assertCount(2, $businessDeals['peers']);
        $this->assertSame(count($businessDeals['peers']), $businessDeals['deals_count']);
        $this->assertSame('I completed business worth ₹ 2,500.00 with Jay Patel', $businessDeals['peers'][0]['display_text']);
        $this->assertSame('Jay Industries', $businessDeals['peers'][0]['company_name']);
    }

    public function test_referral_activity_source_populates_qualified_referral_history(): void
    {
        Carbon::setTestNow('2026-05-09 10:00:00');
        $this->createActivitiesTable();

        $user = $this->createUser(['display_name' => 'Demo User']);
        $rahul = $this->createUser([
            'display_name' => 'Rahul Shah',
            'company_name' => 'ABC Pvt Ltd',
        ]);

        $this->createActivity($user, $rahul, 'pass_referral', 'Vijay Traders', 'approved', '2026-05-09');
        $this->createActivity($user, $rahul, 'pass_referral', 'Pending Referral', 'pending', '2026-05-09');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/peer-monthly-impact-script');

        $response->assertOk()->assertJsonPath('success', true);

        $qualifiedReferrals = collect($response->json('data.checklist_items'))
            ->firstWhere('key', 'qualified_referrals_given');

        $this->assertSame(1, $qualifiedReferrals['count']);
        $this->assertCount(1, $qualifiedReferrals['related_items']);
        $this->assertTrue($qualifiedReferrals['is_available']);
        $this->assertSame(
            'I gave a qualified referral to Rahul Shah — connecting them with Vijay Traders',
            $qualifiedReferrals['related_items'][0]['display_text']
        );
    }

    public function test_profile_photo_file_url_is_clean_unescaped_and_browser_usable(): void
    {
        $fileId = (string) Str::uuid();
        $user = $this->createUser([
            'profile_photo_file_id' => $fileId,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/peer-monthly-impact-script');

        $expectedUrl = url('/api/v1/files/' . $fileId);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.profile_photo_url', $expectedUrl);

        $this->assertStringContainsString('"profile_photo_url":"' . $expectedUrl . '"', $response->getContent());
        $this->assertStringNotContainsString('http:\/\/', $response->getContent());
    }

    private function createUsersTable(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('display_name')->nullable();
            $table->string('email')->nullable();
            $table->string('password_hash')->nullable();
            $table->string('company_name')->nullable();
            $table->string('business_type')->nullable();
            $table->json('industry_tags')->nullable();
            $table->string('profile_photo_url')->nullable();
            $table->uuid('profile_photo_file_id')->nullable();
            $table->integer('life_impacted_count')->default(0);
            $table->integer('coins_balance')->default(0);
            $table->integer('members_introduced_count')->default(0);
            $table->string('membership_status')->nullable();
            $table->timestamp('membership_ends_at')->nullable();
            $table->timestamp('membership_expiry')->nullable();
            $table->string('coin_medal_rank')->nullable();
            $table->string('coin_milestone_title')->nullable();
            $table->text('coin_milestone_meaning')->nullable();
            $table->string('contribution_award_name')->nullable();
            $table->text('contribution_award_recognition')->nullable();
            $table->string('public_profile_slug')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function createReferralsTable(): void
    {
        Schema::create('referrals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('from_user_id');
            $table->uuid('to_user_id')->nullable();
            $table->string('referral_type')->nullable();
            $table->date('referral_date')->nullable();
            $table->string('referral_of')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('hot_value')->nullable();
            $table->text('remarks')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function createBusinessDealsTable(): void
    {
        Schema::create('business_deals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('from_user_id');
            $table->uuid('to_user_id')->nullable();
            $table->date('deal_date')->nullable();
            $table->decimal('deal_amount', 12, 2)->nullable();
            $table->string('business_type')->nullable();
            $table->string('status')->nullable();
            $table->text('comment')->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    private function createActivitiesTable(): void
    {
        Schema::create('activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('related_user_id')->nullable();
            $table->string('type');
            $table->string('status')->default('pending');
            $table->text('description')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    private function createUser(array $attributes = []): User
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('users')->insert(array_merge([
            'id' => $id,
            'first_name' => 'Test',
            'last_name' => 'User',
            'display_name' => 'Test User',
            'email' => $id . '@example.com',
            'password_hash' => 'password',
            'company_name' => 'Test Co',
            'business_type' => null,
            'industry_tags' => json_encode([]),
            'life_impacted_count' => 0,
            'coins_balance' => 0,
            'members_introduced_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $attributes));

        return User::query()->findOrFail($id);
    }

    private function createReferral(User $fromUser, User $toUser, string $referralOf, string $referralDate): void
    {
        DB::table('referrals')->insert([
            'id' => (string) Str::uuid(),
            'from_user_id' => (string) $fromUser->id,
            'to_user_id' => (string) $toUser->id,
            'referral_type' => 'business',
            'referral_date' => $referralDate,
            'referral_of' => $referralOf,
            'is_deleted' => false,
            'created_at' => Carbon::parse($referralDate)->setTime(9, 0),
            'updated_at' => Carbon::parse($referralDate)->setTime(9, 0),
        ]);
    }

    private function createBusinessDeal(User $fromUser, User $toUser, float $amount, string $dealDate, string $status): void
    {
        DB::table('business_deals')->insert([
            'id' => (string) Str::uuid(),
            'from_user_id' => (string) $fromUser->id,
            'to_user_id' => (string) $toUser->id,
            'deal_date' => $dealDate,
            'deal_amount' => $amount,
            'business_type' => 'new',
            'status' => $status,
            'is_deleted' => false,
            'created_at' => Carbon::parse($dealDate)->setTime(10, 0),
            'updated_at' => Carbon::parse($dealDate)->setTime(10, 0),
        ]);
    }

    private function createActivity(User $user, User $relatedUser, string $type, string $description, string $status, string $date): void
    {
        DB::table('activities')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => (string) $user->id,
            'related_user_id' => (string) $relatedUser->id,
            'type' => $type,
            'status' => $status,
            'description' => $description,
            'verified_at' => $status === 'approved' ? Carbon::parse($date)->setTime(12, 0) : null,
            'created_at' => Carbon::parse($date)->setTime(12, 0),
            'updated_at' => Carbon::parse($date)->setTime(12, 0),
        ]);
    }
}

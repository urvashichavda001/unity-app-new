<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\DailyNotificationReminder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class DailyNotificationReminderTest extends TestCase
{
    protected AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('admin_users');
        Schema::create('admin_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email');
            $table->timestamps();
        });

        Schema::dropIfExists('roles');
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key');
            $table->timestamps();
        });

        Schema::dropIfExists('admin_user_roles');
        Schema::create('admin_user_roles', function (Blueprint $table) {
            $table->uuid('user_id');
            $table->uuid('role_id');
        });

        Schema::dropIfExists('daily_notifications_reminder');
        Schema::create('daily_notifications_reminder', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('feature');
            $table->text('activity');
            $table->string('notification_title');
            $table->text('notification_body');
            $table->string('action_trigger_timing');
            $table->timestamps();
        });

        // Create all core roles to satisfy middleware check
        $coreRoles = ['global_admin', 'industry_director', 'ded', 'circle_leader'];
        $globalAdminRoleId = null;
        
        foreach ($coreRoles as $roleKey) {
            $uuid = Str::uuid()->toString();
            if ($roleKey === 'global_admin') {
                $globalAdminRoleId = $uuid;
            }
            DB::table('roles')->insert([
                'id' => $uuid,
                'key' => $roleKey,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Create admin user
        $adminId = Str::uuid()->toString();
        $this->admin = AdminUser::query()->create([
            'id' => $adminId,
            'name' => 'Test Admin',
            'email' => 'admin@example.com',
        ]);

        // Link global_admin role to admin
        DB::table('admin_user_roles')->insert([
            'user_id' => $adminId,
            'role_id' => $globalAdminRoleId,
        ]);
    }

    public function test_admin_can_view_daily_notifications_page(): void
    {
        // 1. Create some reminders
        DailyNotificationReminder::query()->create([
            'feature' => 'Test Feature',
            'activity' => 'Test Activity',
            'notification_title' => 'Test Title',
            'notification_body' => 'Test Body',
            'action_trigger_timing' => '10:00 AM',
        ]);

        // 2. Act as admin and visit index route
        $response = $this->actingAs($this->admin, 'admin')->get(route('admin.daily-notifications.index'));

        $response->assertStatus(200);
        $response->assertSee('Test Feature');
        $response->assertSee('Test Title');
    }

    public function test_admin_can_update_notification_reminder(): void
    {
        $reminder = DailyNotificationReminder::query()->create([
            'feature' => 'Old Feature',
            'activity' => 'Old Activity',
            'notification_title' => 'Old Title',
            'notification_body' => 'Old Body',
            'action_trigger_timing' => '10:00 AM',
        ]);

        $updateData = [
            'feature' => 'New Feature',
            'activity' => 'New Activity',
            'notification_title' => 'New Title',
            'notification_body' => 'New Body',
            'action_trigger_timing' => '11:00 AM',
        ];

        $response = $this->actingAs($this->admin, 'admin')
            ->put(route('admin.daily-notifications.update', $reminder->id), $updateData);

        $response->assertRedirect(route('admin.daily-notifications.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('daily_notifications_reminder', [
            'id' => $reminder->id,
            'feature' => 'New Feature',
            'notification_title' => 'New Title',
            'action_trigger_timing' => '11:00 AM',
        ]);
    }

    public function test_admin_can_trigger_test_notifications(): void
    {
        $this->createRequiredTables();

        // Seed some dummy reminders
        DailyNotificationReminder::query()->delete();
        $activities = [
            'Daily peer discovery suggestion',
            'Trending circle highlight',
            'Reminder of unused wallet balance',
            'Highlight upcoming events nearby',
            'Highlight open collaboration opportunities',
            'Industry-specific trending news/tip',
            'Throwback to a past event photo',
            'Streak/engagement reminder',
            'Showcase a leader success story',
            'Daily curated offer/deal highlight',
            'Explore new category prompt',
            'Cycle progress reminder'
        ];

        foreach ($activities as $activity) {
            DailyNotificationReminder::query()->create([
                'id' => Str::uuid()->toString(),
                'feature' => 'Test Feature',
                'activity' => $activity,
                'notification_title' => 'Test {Suggested Peer Name} {Circle Name} {X}',
                'notification_body' => 'Test body with {Industry} {Event Name} {Leader Name} {Advertiser Name} {Category Name}',
                'action_trigger_timing' => '10:00 AM',
            ]);
        }

        // Create a test user
        $testUser = \App\Models\User::query()->create([
            'id' => Str::uuid()->toString(),
            'email' => 'missurvashi300@gmail.com',
            'first_name' => 'Jenil',
            'last_name' => 'Joshi',
            'status' => 'active',
            'coins_balance' => 500,
            'designation' => 'Architect',
            'membership_status' => 'premium',
        ]);

        $response = $this->actingAs($this->admin, 'admin')
            ->get(route('admin.daily-notifications.test') . '?user_id=' . $testUser->id);

        if ($response->status() !== 200) {
            $response->dump();
        }

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(count($activities), 'notifications');
    }

    public function test_admin_can_fetch_eligible_users(): void
    {
        $this->createRequiredTables();

        // 1. Create a city and a business category
        $cityId = DB::table('cities')->insertGetId([
            'name' => 'Mumbai',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $categoryId = DB::table('circle_categories')->insertGetId([
            'name' => 'Technology',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Create users
        $userId1 = Str::uuid()->toString();
        DB::table('users')->insert([
            'id' => $userId1,
            'email' => 'user1@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'status' => 'active',
            'company_name' => 'Tech Corp',
            'business_type' => 'Service',
            'business_category_id' => $categoryId,
            'city_id' => $cityId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $userId2 = Str::uuid()->toString();
        DB::table('users')->insert([
            'id' => $userId2,
            'email' => 'user2@example.com',
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'status' => 'inactive',
            'company_name' => 'Logistics Ltd',
            'business_type' => 'Retail',
            'business_category_id' => $categoryId,
            'city_id' => $cityId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Create reminder
        $reminder = DailyNotificationReminder::query()->create([
            'id' => Str::uuid()->toString(),
            'feature' => 'App-Wide',
            'activity' => "User hasn't opened the app today",
            'notification_title' => 'We Miss You!',
            'notification_body' => 'Come back!',
            'action_trigger_timing' => '10:00 AM',
        ]);

        // 4. Act as admin and fetch eligible users
        $response = $this->actingAs($this->admin, 'admin')
            ->get(route('admin.daily-notifications.eligible-users', $reminder->id));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(1, 'users');
        $response->assertJsonFragment([
            'name' => 'John Doe',
            'company_name' => 'Tech Corp',
            'city' => 'Mumbai',
            'business_category' => 'Technology',
        ]);
    }

    public function test_admin_can_send_manual_reminder(): void
    {
        $this->createRequiredTables();

        // 1. Create active user with push token
        $userId = Str::uuid()->toString();
        DB::table('users')->insert([
            'id' => $userId,
            'email' => 'testuser@example.com',
            'first_name' => 'Alice',
            'last_name' => 'Wonder',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_push_tokens')->insert([
            'id' => Str::uuid()->toString(),
            'user_id' => $userId,
            'token' => 'test_token',
            'platform' => 'ios',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 2. Create reminder template
        $reminder = DailyNotificationReminder::query()->create([
            'id' => Str::uuid()->toString(),
            'feature' => 'App-Wide',
            'activity' => "User hasn't opened the app today",
            'notification_title' => 'Hello {X}',
            'notification_body' => 'Body text',
            'action_trigger_timing' => '10:00 AM',
        ]);

        // 3. Act as admin and post to send route
        $response = $this->actingAs($this->admin, 'admin')
            ->post(route('admin.daily-notifications.send', $reminder->id));

        $response->assertRedirect(route('admin.daily-notifications.index'));
        $response->assertSessionHas('success');

        // 4. Assert notification was created in database
        $this->assertDatabaseHas('notifications', [
            'user_id' => $userId,
            'type' => 'system',
        ]);
    }

    private function createRequiredTables(): void
    {
        Schema::dropIfExists('cities');
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::dropIfExists('circle_categories');
        Schema::create('circle_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email');
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('status')->default('active');
            $table->string('designation')->nullable();
            $table->string('membership_status')->nullable();
            $table->integer('coins_balance')->default(0);
            $table->string('company_name')->nullable();
            $table->string('business_type')->nullable();
            $table->unsignedBigInteger('business_category_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->text('leadership_roles')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::dropIfExists('circles');
        Schema::create('circles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('status');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::dropIfExists('events');
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->timestamp('start_at');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::dropIfExists('collaboration_posts');
        Schema::create('collaboration_posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('status');
            $table->timestamps();
        });

        Schema::dropIfExists('ads');
        Schema::create('ads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::dropIfExists('categories');
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::dropIfExists('notifications');
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('type');
            $table->json('payload');
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('user_push_tokens');
        Schema::create('user_push_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('token');
            $table->string('platform');
            $table->string('device_id')->nullable();
            $table->string('app_version')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('coins_ledger');
        Schema::create('coins_ledger', function (Blueprint $table) {
            $table->uuid('transaction_id')->primary();
            $table->uuid('user_id');
            $table->integer('amount');
            $table->integer('balance_after');
            $table->string('reference')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('referrals');
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->uuid('from_user_id');
            $table->timestamps();
        });

        Schema::dropIfExists('business_deals');
        Schema::create('business_deals', function (Blueprint $table) {
            $table->id();
            $table->uuid('from_user_id');
            $table->timestamps();
        });

        Schema::dropIfExists('testimonials');
        Schema::create('testimonials', function (Blueprint $table) {
            $table->id();
            $table->uuid('from_user_id');
            $table->timestamps();
        });

        Schema::dropIfExists('life_impact_histories');
        Schema::create('life_impact_histories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->timestamps();
        });

        Schema::dropIfExists('leader_interest_submissions');
        Schema::create('leader_interest_submissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id');
            $table->timestamps();
        });

        Schema::dropIfExists('user_login_histories');
        Schema::create('user_login_histories', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_id');
            $table->timestamp('logged_in_at');
            $table->timestamps();
        });
    }
}


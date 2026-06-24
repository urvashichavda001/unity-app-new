<?php

namespace Tests\Feature;

use App\Console\Commands\SendMembershipExpiryReminders;
use App\Console\Commands\SendUpcomingMembershipExpiryReminders;
use App\Console\Commands\SendCircleMembershipExpiryReminders;
use App\Mail\MembershipExpiryReminderMail;
use App\Mail\UpcomingMembershipExpiryReminderMail;
use App\Mail\CircleMembershipExpiryReminderMail;
use App\Models\User;
use App\Models\EmailLog;
use App\Models\Notification;
use App\Models\CircleMember;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SendMembershipExpiryRemindersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Recreate tables manually in SQLite memory database to bypass PG-specific DDL issues
        Schema::dropIfExists('email_logs');
        Schema::create('email_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->string('to_email');
            $table->string('to_name')->nullable();
            $table->string('template_key');
            $table->string('subject')->nullable();
            $table->string('source_module')->nullable();
            $table->string('related_type')->nullable();
            $table->string('related_id')->nullable();
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->string('source_event')->nullable();
            $table->string('status');
            $table->text('body_html')->nullable();
            $table->text('payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::dropIfExists('notifications');
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('type');
            $table->text('payload');
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('read_at')->nullable();
        });

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('display_name', 150)->nullable();
            $table->string('email')->nullable();
            $table->string('membership_status', 50)->default('visitor');
            $table->timestamp('membership_ends_at')->nullable();
            $table->timestamp('membership_expiry')->nullable();
            $table->timestamp('membership_starts_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::dropIfExists('circle_members');
        Schema::create('circle_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('circle_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->string('role', 50)->default('member');
            $table->string('status', 50)->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_sends_expiry_reminder_emails_and_notifications_to_expired_users(): void
    {
        Mail::fake();
        Queue::fake();

        // 1. Create a user whose membership has expired yesterday (STATUS_FREE)
        $expiredUser = User::query()->create([
            'id' => (string) Str::uuid(),
            'display_name' => 'Expired User',
            'email' => 'expired@example.com',
            'membership_status' => User::STATUS_FREE,
            'membership_ends_at' => Carbon::now()->subDay(),
        ]);

        // 2. Create an active user whose membership expires tomorrow (STATUS_FREE)
        $activeUser = User::query()->create([
            'id' => (string) Str::uuid(),
            'display_name' => 'Active User',
            'email' => 'active@example.com',
            'membership_status' => User::STATUS_FREE,
            'membership_ends_at' => Carbon::now()->addDay(),
        ]);

        // 3. Create a user with null membership_ends_at (STATUS_FREE)
        $nullUser = User::query()->create([
            'id' => (string) Str::uuid(),
            'display_name' => 'Null User',
            'email' => 'null@example.com',
            'membership_status' => User::STATUS_FREE,
            'membership_ends_at' => null,
        ]);

        // 4. Create an expired non-free user (should NOT receive reminders)
        $nonFreeExpiredUser = User::query()->create([
            'id' => (string) Str::uuid(),
            'display_name' => 'Expired Premium User',
            'email' => 'expired_premium@example.com',
            'membership_status' => 'premium',
            'membership_ends_at' => Carbon::now()->subDay(),
        ]);

        Artisan::call('memberships:send-expiry-reminders');

        // Assert mail was queued to expired free user
        Mail::assertQueued(MembershipExpiryReminderMail::class, function ($mail) use ($expiredUser) {
            return $mail->hasTo($expiredUser->email) && $mail->user->id === $expiredUser->id;
        });

        // Assert mail was not queued to active, null, or non-free expired users
        Mail::assertNotQueued(MembershipExpiryReminderMail::class, function ($mail) use ($activeUser, $nullUser, $nonFreeExpiredUser) {
            return $mail->hasTo($activeUser->email) || $mail->hasTo($nullUser->email) || $mail->hasTo($nonFreeExpiredUser->email);
        });

        // Assert email log was created for the expired user
        $this->assertTrue(
            EmailLog::query()
                ->where('user_id', $expiredUser->id)
                ->where('template_key', 'membership_expiry_reminder')
                ->where('status', 'sent')
                ->exists()
        );

        // Assert notification record was created for the expired user
        $this->assertTrue(
            Notification::query()
                ->where('user_id', $expiredUser->id)
                ->where('type', 'system')
                ->exists()
        );

        // Assert no notification record was created for active, null, or non-free users
        $this->assertFalse(
            Notification::query()
                ->whereIn('user_id', [$activeUser->id, $nullUser->id, $nonFreeExpiredUser->id])
                ->exists()
        );
    }

    public function test_prevents_duplicate_emails_and_notifications_in_same_run(): void
    {
        Mail::fake();
        Queue::fake();

        // Create two users with the exact same email (both expired free)
        $user1 = User::query()->create([
            'id' => (string) Str::uuid(),
            'display_name' => 'User One',
            'email' => 'duplicate@example.com',
            'membership_status' => User::STATUS_FREE,
            'membership_ends_at' => Carbon::now()->subDays(5),
        ]);

        $user2 = User::query()->create([
            'id' => (string) Str::uuid(),
            'display_name' => 'User Two',
            'email' => 'duplicate@example.com',
            'membership_status' => User::STATUS_FREE,
            'membership_ends_at' => Carbon::now()->subDays(3),
        ]);

        Artisan::call('memberships:send-expiry-reminders');

        // Assert the mail was queued exactly once
        Mail::assertQueued(MembershipExpiryReminderMail::class, 1);

        // Assert the notification was created exactly once
        $this->assertSame(
            1,
            Notification::query()
                ->where('type', 'system')
                ->count()
        );
    }

    public function test_sends_upcoming_expiry_reminder_emails_and_notifications_to_eligible_users(): void
    {
        Mail::fake();
        Queue::fake();

        // Expiring in 10 days (eligible)
        $eligibleUser = User::query()->create([
            'id' => (string) Str::uuid(),
            'display_name' => 'Eligible User',
            'email' => 'eligible@example.com',
            'membership_status' => User::STATUS_FREE,
            'membership_ends_at' => Carbon::now()->addDays(10),
        ]);

        // Already expired (ineligible)
        $expiredUser = User::query()->create([
            'id' => (string) Str::uuid(),
            'display_name' => 'Expired User',
            'email' => 'expired_upcoming@example.com',
            'membership_status' => User::STATUS_FREE,
            'membership_ends_at' => Carbon::now()->subDays(2),
        ]);

        // Expiring in 40 days (ineligible)
        $farFutureUser = User::query()->create([
            'id' => (string) Str::uuid(),
            'display_name' => 'Far Future User',
            'email' => 'far_future@example.com',
            'membership_status' => User::STATUS_FREE,
            'membership_ends_at' => Carbon::now()->addDays(40),
        ]);

        // Null expiry (ineligible)
        $nullUser = User::query()->create([
            'id' => (string) Str::uuid(),
            'display_name' => 'Null User',
            'email' => 'null_upcoming@example.com',
            'membership_status' => User::STATUS_FREE,
            'membership_ends_at' => null,
        ]);

        // Expiring in 10 days but non-free (ineligible)
        $nonFreeUpcomingUser = User::query()->create([
            'id' => (string) Str::uuid(),
            'display_name' => 'Non-free Upcoming User',
            'email' => 'nonfree_upcoming@example.com',
            'membership_status' => 'premium',
            'membership_ends_at' => Carbon::now()->addDays(10),
        ]);

        Artisan::call('memberships:send-upcoming-expiry-reminders');

        // Assert mail was queued to eligible user
        Mail::assertQueued(UpcomingMembershipExpiryReminderMail::class, function ($mail) use ($eligibleUser) {
            return $mail->hasTo($eligibleUser->email) && $mail->user->id === $eligibleUser->id;
        });

        // Assert mail was not queued to ineligible users
        Mail::assertNotQueued(UpcomingMembershipExpiryReminderMail::class, function ($mail) use ($expiredUser, $farFutureUser, $nullUser, $nonFreeUpcomingUser) {
            return $mail->hasTo($expiredUser->email) || $mail->hasTo($farFutureUser->email) || $mail->hasTo($nullUser->email) || $mail->hasTo($nonFreeUpcomingUser->email);
        });

        // Assert email log was created for the eligible user
        $this->assertTrue(
            EmailLog::query()
                ->where('user_id', $eligibleUser->id)
                ->where('template_key', 'upcoming_membership_expiry_reminder')
                ->where('status', 'sent')
                ->exists()
        );

        // Assert notification record was created for the eligible user
        $this->assertTrue(
            Notification::query()
                ->where('user_id', $eligibleUser->id)
                ->where('type', 'system')
                ->exists()
        );

        // Assert no notification record was created for ineligible users
        $this->assertFalse(
            Notification::query()
                ->whereIn('user_id', [$expiredUser->id, $farFutureUser->id, $nullUser->id, $nonFreeUpcomingUser->id])
                ->exists()
        );
    }

    public function test_prevents_duplicate_upcoming_emails_and_notifications_in_same_run(): void
    {
        Mail::fake();
        Queue::fake();

        $user1 = User::query()->create([
            'id' => (string) Str::uuid(),
            'display_name' => 'User One',
            'email' => 'duplicate_upcoming@example.com',
            'membership_status' => User::STATUS_FREE,
            'membership_ends_at' => Carbon::now()->addDays(10),
        ]);

        $user2 = User::query()->create([
            'id' => (string) Str::uuid(),
            'display_name' => 'User Two',
            'email' => 'duplicate_upcoming@example.com',
            'membership_status' => User::STATUS_FREE,
            'membership_ends_at' => Carbon::now()->addDays(20),
        ]);

        Artisan::call('memberships:send-upcoming-expiry-reminders');

        Mail::assertQueued(UpcomingMembershipExpiryReminderMail::class, 1);

        $this->assertSame(
            1,
            Notification::query()
                ->where('type', 'system')
                ->count()
        );
    }

    public function test_sends_circle_expiry_reminder_emails_and_notifications_to_eligible_circle_members(): void
    {
        Mail::fake();
        Queue::fake();

        $circleId = (string) Str::uuid();

        // 1. Eligible circle member (expires in 10 days, STATUS_FREE)
        $eligibleUser = User::query()->create([
            'id' => (string) Str::uuid(),
            'display_name' => 'Eligible Circle Member',
            'email' => 'eligible_circle@example.com',
            'membership_status' => User::STATUS_FREE,
        ]);
        CircleMember::query()->create([
            'id' => (string) Str::uuid(),
            'circle_id' => $circleId,
            'user_id' => $eligibleUser->id,
            'expires_at' => Carbon::now()->addDays(10),
            'status' => 'approved',
        ]);

        // 2. Already expired circle member (expires yesterday, STATUS_FREE)
        $expiredUser = User::query()->create([
            'id' => (string) Str::uuid(),
            'display_name' => 'Expired Circle Member',
            'email' => 'expired_circle@example.com',
            'membership_status' => User::STATUS_FREE,
        ]);
        CircleMember::query()->create([
            'id' => (string) Str::uuid(),
            'circle_id' => $circleId,
            'user_id' => $expiredUser->id,
            'expires_at' => Carbon::now()->subDay(),
            'status' => 'approved',
        ]);

        // 3. Null expiry circle member (STATUS_FREE)
        $nullUser = User::query()->create([
            'id' => (string) Str::uuid(),
            'display_name' => 'Null Circle Member',
            'email' => 'null_circle@example.com',
            'membership_status' => User::STATUS_FREE,
        ]);
        CircleMember::query()->create([
            'id' => (string) Str::uuid(),
            'circle_id' => $circleId,
            'user_id' => $nullUser->id,
            'expires_at' => null,
            'status' => 'approved',
        ]);

        // 4. Non-free circle member (expires in 10 days, non-free status)
        $nonFreeCircleUser = User::query()->create([
            'id' => (string) Str::uuid(),
            'display_name' => 'Non-free Circle Member',
            'email' => 'nonfree_circle@example.com',
            'membership_status' => 'premium',
        ]);
        CircleMember::query()->create([
            'id' => (string) Str::uuid(),
            'circle_id' => $circleId,
            'user_id' => $nonFreeCircleUser->id,
            'expires_at' => Carbon::now()->addDays(10),
            'status' => 'approved',
        ]);

        Artisan::call('memberships:send-circle-expiry-reminders');

        // Assert mail was queued to eligible Circle member
        Mail::assertQueued(CircleMembershipExpiryReminderMail::class, function ($mail) use ($eligibleUser) {
            return $mail->hasTo($eligibleUser->email) && $mail->user->id === $eligibleUser->id;
        });

        // Assert mail was not queued to ineligible or non-free users
        Mail::assertNotQueued(CircleMembershipExpiryReminderMail::class, function ($mail) use ($expiredUser, $nullUser, $nonFreeCircleUser) {
            return $mail->hasTo($expiredUser->email) || $mail->hasTo($nullUser->email) || $mail->hasTo($nonFreeCircleUser->email);
        });

        // Assert email log was created for the eligible circle member
        $this->assertTrue(
            EmailLog::query()
                ->where('user_id', $eligibleUser->id)
                ->where('template_key', 'circle_membership_expiry_reminder')
                ->where('status', 'sent')
                ->exists()
        );

        // Assert notification record was created for the eligible circle member
        $this->assertTrue(
            Notification::query()
                ->where('user_id', $eligibleUser->id)
                ->where('type', 'circle_update')
                ->exists()
        );

        // Assert no notification was created for expired, null, or non-free circle members
        $this->assertFalse(
            Notification::query()
                ->whereIn('user_id', [$expiredUser->id, $nullUser->id, $nonFreeCircleUser->id])
                ->exists()
        );
    }

    public function test_prevents_duplicate_circle_emails_and_notifications_in_same_run(): void
    {
        Mail::fake();
        Queue::fake();

        $user = User::query()->create([
            'id' => (string) Str::uuid(),
            'display_name' => 'Duplicate Circle Member',
            'email' => 'duplicate_circle@example.com',
            'membership_status' => User::STATUS_FREE,
        ]);

        // Create two expiring circle member records for the same user (in different circles)
        CircleMember::query()->create([
            'id' => (string) Str::uuid(),
            'circle_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'expires_at' => Carbon::now()->addDays(5),
            'status' => 'approved',
        ]);

        CircleMember::query()->create([
            'id' => (string) Str::uuid(),
            'circle_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'expires_at' => Carbon::now()->addDays(10),
            'status' => 'approved',
        ]);

        Artisan::call('memberships:send-circle-expiry-reminders');

        Mail::assertQueued(CircleMembershipExpiryReminderMail::class, 1);

        $this->assertSame(
            1,
            Notification::query()
                ->where('type', 'circle_update')
                ->count()
        );
    }

    public function test_circle_expiry_reminders_continues_processing_on_individual_user_failure(): void
    {
        Queue::fake();
        $circleId = (string) Str::uuid();

        // 1. Create two users: one that will fail, one that will succeed
        $failUser = User::query()->create([
            'id' => (string) Str::uuid(),
            'display_name' => 'Fail User',
            'email' => 'fail@example.com',
            'membership_status' => User::STATUS_FREE,
        ]);

        $successUser = User::query()->create([
            'id' => (string) Str::uuid(),
            'display_name' => 'Success User',
            'email' => 'success@example.com',
            'membership_status' => User::STATUS_FREE,
        ]);

        CircleMember::query()->create([
            'id' => (string) Str::uuid(),
            'circle_id' => $circleId,
            'user_id' => $failUser->id,
            'expires_at' => Carbon::now()->addDays(5),
            'status' => 'approved',
        ]);

        CircleMember::query()->create([
            'id' => (string) Str::uuid(),
            'circle_id' => $circleId,
            'user_id' => $successUser->id,
            'expires_at' => Carbon::now()->addDays(10),
            'status' => 'approved',
        ]);

        // Mock Mail facade using Mockery to throw exception for fail@example.com and succeed for success@example.com
        $mockMailerSuccess = \Mockery::mock();
        $mockMailerSuccess->shouldReceive('later')->andReturn(null);

        Mail::shouldReceive('to')
            ->with('fail@example.com')
            ->andThrow(new \Exception('SMTP connection failed'));

        Mail::shouldReceive('to')
            ->with('success@example.com')
            ->andReturn($mockMailerSuccess);

        Artisan::call('memberships:send-circle-expiry-reminders');

        // Verify that the success user's notification was created in database
        $this->assertTrue(
            Notification::query()
                ->where('user_id', $successUser->id)
                ->where('type', 'circle_update')
                ->exists()
        );

        // Verify that the fail user's notification was created in database (notifications are triggered even if email dispatch fails)
        $this->assertTrue(
            Notification::query()
                ->where('user_id', $failUser->id)
                ->where('type', 'circle_update')
                ->exists()
        );

        // Verify email logs
        $this->assertTrue(
            EmailLog::query()
                ->where('user_id', $failUser->id)
                ->where('status', 'failed')
                ->where('error_message', 'like', '%SMTP connection failed%')
                ->exists()
        );

        $this->assertTrue(
            EmailLog::query()
                ->where('user_id', $successUser->id)
                ->where('status', 'sent')
                ->exists()
        );
    }

    public function test_scheduler_contains_all_three_reminder_commands(): void
    {
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);

        $reflection = new \ReflectionClass($kernel);
        if ($reflection->hasMethod('schedule')) {
            $method = $reflection->getMethod('schedule');
            $method->setAccessible(true);
            $method->invoke($kernel, $schedule);
        }

        $events = collect($schedule->events());

        // Assert memberships:send-expiry-reminders is scheduled daily at 11:25 AM (25 11 * * *) in Asia/Kolkata timezone
        $expiryEvent = $events->first(fn ($event) => str_contains((string) $event->command, 'memberships:send-expiry-reminders'));
        $this->assertNotNull($expiryEvent);
        $this->assertSame('25 11 * * *', $expiryEvent->expression);
        $this->assertSame('Asia/Kolkata', $expiryEvent->timezone);

        // Assert memberships:send-upcoming-expiry-reminders is scheduled daily at 11:25 AM (25 11 * * *) in Asia/Kolkata timezone
        $upcomingEvent = $events->first(fn ($event) => str_contains((string) $event->command, 'memberships:send-upcoming-expiry-reminders'));
        $this->assertNotNull($upcomingEvent);
        $this->assertSame('25 11 * * *', $upcomingEvent->expression);
        $this->assertSame('Asia/Kolkata', $upcomingEvent->timezone);

        // Assert memberships:send-circle-expiry-reminders is scheduled daily at 11:25 AM (25 11 * * *) in Asia/Kolkata timezone
        $circleEvent = $events->first(fn ($event) => str_contains((string) $event->command, 'memberships:send-circle-expiry-reminders'));
        $this->assertNotNull($circleEvent);
        $this->assertSame('25 11 * * *', $circleEvent->expression);
        $this->assertSame('Asia/Kolkata', $circleEvent->timezone);
    }
}
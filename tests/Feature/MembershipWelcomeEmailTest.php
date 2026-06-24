<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use App\Models\AdminUser;
use App\Models\Role;
use App\Models\EmailLog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class MembershipWelcomeEmailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('display_name', 150)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('membership_status', 50)->default('visitor');
            $table->string('zoho_plan_code', 100)->nullable();
            $table->string('zoho_subscription_id', 100)->nullable();
            $table->string('zoho_last_invoice_id', 100)->nullable();
            $table->timestamp('membership_starts_at')->nullable();
            $table->timestamp('membership_ends_at')->nullable();
            $table->timestamp('membership_expiry')->nullable();
            $table->timestamp('last_payment_at')->nullable();
            $table->timestamp('welcome_membership_email_sent_at')->nullable();
            $table->string('welcome_membership_email_status', 50)->nullable();
            $table->text('welcome_membership_email_error')->nullable();
            $table->string('welcome_membership_email_plan_code', 100)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::dropIfExists('payments');
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('status', 50);
            $table->string('provider', 50)->nullable();
            $table->string('zoho_plan_code', 100)->nullable();
            $table->string('zoho_hostedpage_id', 100)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('admin_users');
        Schema::create('admin_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->string('email');
            $table->timestamps();
        });

        Schema::dropIfExists('roles');
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('key', 100);
            $table->timestamps();
        });

        Schema::dropIfExists('admin_user_roles');
        Schema::create('admin_user_roles', function (Blueprint $table) {
            $table->uuid('user_id');
            $table->uuid('role_id');
        });

        // Seed core roles
        foreach (['global_admin', 'industry_director', 'ded', 'circle_leader'] as $key) {
            Role::query()->forceCreate([
                'id' => (string) Str::uuid(),
                'name' => ucfirst(str_replace('_', ' ', $key)),
                'key' => $key,
            ]);
        }
    }

    public function test_welcome_email_eligibility_and_sending(): void
    {
        Mail::fake();

        // 1. Create user with null last_payment_at (ineligible)
        $user = User::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'first_name' => 'Ineligible',
            'last_name' => 'User',
            'email' => 'ineligible@example.com',
            'last_payment_at' => null,
        ]);

        $service = app(\App\Services\Membership\MembershipWelcomeEmailService::class);
        $result = $service->sendIfEligible($user);

        $this->assertFalse($result['sent']);
        $this->assertSame('not_paid', $result['reason']);
        Mail::assertNotSent(\App\Mail\MembershipWelcomeMail::class);

        // 2. Set last_payment_at (eligible)
        $user->forceFill([
            'last_payment_at' => now(),
        ])->save();

        $result = $service->sendIfEligible($user);

        $this->assertTrue($result['sent']);
        $this->assertSame('sent', $result['reason']);
        Mail::assertSent(\App\Mail\MembershipWelcomeMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });

        // Verify database updates
        $user->refresh();
        $this->assertNotNull($user->welcome_membership_email_sent_at);
        $this->assertSame('Sent', $user->welcome_membership_email_status);
        $this->assertNull($user->welcome_membership_email_error);
    }

    public function test_welcome_email_resending_for_paid_users(): void
    {
        Mail::fake();

        $user = User::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'first_name' => 'Paid',
            'last_name' => 'User',
            'email' => 'paid_member@example.com',
            'last_payment_at' => Carbon::now()->subDay(),
            'welcome_membership_email_sent_at' => Carbon::now()->subHours(12),
            'welcome_membership_email_status' => 'Sent',
        ]);

        $service = app(\App\Services\Membership\MembershipWelcomeEmailService::class);

        // 1. Automatic trigger (not forced) with same payment date -> should skip
        $result = $service->sendIfEligible($user, false);
        $this->assertFalse($result['sent']);
        $this->assertSame('already_sent', $result['reason']);

        // 2. Automatic trigger (not forced) with new payment date -> should send again
        $user->forceFill([
            'last_payment_at' => now(),
        ])->save();

        $result = $service->sendIfEligible($user, false);
        $this->assertTrue($result['sent']);
        $this->assertSame('sent', $result['reason']);

        // 3. Manual trigger (forced) -> should send again regardless of timestamps
        $result = $service->sendIfEligible($user, true);
        $this->assertTrue($result['sent']);
        $this->assertSame('sent', $result['reason']);
    }

    public function test_admin_manual_send_welcome_email(): void
    {
        Mail::fake();

        // Get Global Admin Role
        $role = Role::query()->where('key', 'global_admin')->firstOrFail();

        // Create AdminUser
        $admin = AdminUser::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Admin User',
            'email' => 'admin@example.com',
        ]);

        // Attach Role
        $admin->roles()->attach($role);

        // Authenticate admin
        $this->actingAs($admin, 'admin');

        // Create paid user
        $user = User::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'first_name' => 'Member',
            'last_name' => 'User',
            'email' => 'member@example.com',
            'last_payment_at' => now(),
        ]);

        // Hit the send welcome email admin route
        $response = $this->post("/admin/users/{$user->id}/membership-welcome-email/send");

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Welcome email sent successfully.');

        Mail::assertSent(\App\Mail\MembershipWelcomeMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });

        $user->refresh();
        $this->assertNotNull($user->welcome_membership_email_sent_at);
        $this->assertSame('Sent', $user->welcome_membership_email_status);
    }

    public function test_automatic_welcome_email_on_checkout_status_sync(): void
    {
        Mail::fake();

        Http::fake([
            'https://accounts.zoho.in/oauth/v2/token' => Http::response([
                'access_token' => 'token-123',
                'expires_in' => 3600,
            ]),
            'https://www.zohoapis.in/billing/v1/hostedpages/hp_welcome_123' => Http::response([
                'hostedpage' => [
                    'status' => 'completed',
                    'invoice' => ['invoice_id' => 'inv_welcome_001'],
                    'subscription' => [
                        'subscription_id' => 'sub_welcome_001',
                        'status' => 'active',
                        'current_term_starts_at' => '2026-01-01 00:00:00',
                        'current_term_ends_at' => '2026-02-01 00:00:00',
                        'plan_code' => '01',
                    ],
                ],
            ]),
        ]);

        $user = User::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'first_name' => 'Auto',
            'last_name' => 'User',
            'email' => 'welcome_auto@example.com',
            'phone' => '8888888888',
            'membership_status' => 'free_peer',
        ]);

        $payment = new Payment();
        $payment->id = (string) Str::uuid();
        $payment->forceFill([
            'user_id' => $user->id,
            'status' => 'pending',
            'provider' => 'zoho',
            'zoho_plan_code' => '01',
            'zoho_hostedpage_id' => 'hp_welcome_123',
        ]);
        $payment->save();

        $response = $this->getJson('/api/v1/billing/checkout/hp_welcome_123/status');

        $response->assertOk();

        // Verify welcome email was automatically triggered and sent
        Mail::assertSent(\App\Mail\MembershipWelcomeMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });

        $user->refresh();
        $this->assertNotNull($user->welcome_membership_email_sent_at);
        $this->assertSame('Sent', $user->welcome_membership_email_status);
    }

    public function test_welcome_email_template_rendering(): void
    {
        $user = User::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'created_at' => Carbon::create(2026, 6, 12, 12, 0, 0),
        ]);

        $mailable = new \App\Mail\MembershipWelcomeMail($user);
        $html = $mailable->render();

        $this->assertStringContainsString('Dear <strong>John Doe</strong>,', $html);
        $this->assertStringContainsString('Join Date:</strong> 12 Jun 2026', $html);
        $this->assertStringContainsString('mailto:support@peersglobal.com', $html);
        $this->assertStringContainsString('Warm Regards,', $html);
        $this->assertStringContainsString('Peers Global Unity', $html);
        $this->assertStringNotContainsString('[Member Name]', $html);
        $this->assertStringNotContainsString('[Join Date]', $html);
        $this->assertStringNotContainsString('[Your Support Email]', $html);
        $this->assertStringNotContainsString('[Your Name]', $html);
        $this->assertStringNotContainsString('[Your Role]', $html);
    }
}

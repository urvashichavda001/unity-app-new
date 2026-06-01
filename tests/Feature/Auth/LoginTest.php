<?php

namespace Tests\Feature\Auth;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
    }

    public function test_login_accepts_case_insensitive_email_and_password_hash(): void
    {
        $userId = $this->createUser([
            'email' => 'HarshChauhanWork26@gmail.com',
            'password_hash' => Hash::make('password'),
            'display_name' => 'Harsh Chauhan',
            'profile_photo_url' => 'https://example.test/profile.jpg',
        ]);
        $eventId = $this->createEvent();
        $this->authorizeScanner($eventId, $userId);

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'harshchauhanwork26@gmail.com',
            'password' => 'password',
        ]);

        $loginResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.id', $userId)
            ->assertJsonPath('data.user.email', 'HarshChauhanWork26@gmail.com')
            ->assertJsonPath('data.user.display_name', 'Harsh Chauhan')
            ->assertJsonPath('data.user.profile_photo_url', 'https://example.test/profile.jpg');

        $token = $loginResponse->json('data.token');
        $this->assertNotEmpty($token);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/scanner/events')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items.0.event_id', $eventId);
    }

    public function test_login_falls_back_to_legacy_password_column_when_password_hash_is_empty(): void
    {
        $userId = $this->createUser([
            'email' => 'legacy@example.com',
            'password_hash' => null,
            'password' => Hash::make('password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'LEGACY@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.id', $userId);
    }

    public function test_login_rejects_wrong_password(): void
    {
        $this->createUser([
            'email' => 'wrong-password@example.com',
            'password_hash' => Hash::make('password'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'wrong-password@example.com',
            'password' => 'not-password',
        ])->assertStatus(401)
            ->assertExactJson([
                'success' => false,
                'message' => 'Invalid credentials.',
                'data' => null,
            ]);
    }

    public function test_login_rejects_unknown_email(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'unknown@example.com',
            'password' => 'password',
        ])->assertStatus(401)
            ->assertExactJson([
                'success' => false,
                'message' => 'Invalid credentials.',
                'data' => null,
            ]);
    }


    public function test_scanner_login_allows_only_active_authorized_scanner(): void
    {
        $userId = $this->createUser([
            'email' => 'scanner@example.com',
            'password_hash' => Hash::make('password'),
        ]);
        $eventId = $this->createEvent();
        $this->authorizeScanner($eventId, $userId);

        $loginResponse = $this->postJson('/api/v1/scanner/login', [
            'email' => 'SCANNER@example.com',
            'password' => 'password',
        ]);

        $loginResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Scanner login successful.')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.id', $userId);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $userId,
            'name' => 'unity-event-scanner-token',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$loginResponse->json('data.token'))
            ->getJson('/api/v1/scanner/events')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items.0.event_id', $eventId);
    }

    public function test_scanner_login_rejects_revoked_or_unassigned_users_without_creating_token(): void
    {
        $revokedUserId = $this->createUser([
            'email' => 'revoked-scanner@example.com',
            'password_hash' => Hash::make('password'),
        ]);
        $eventId = $this->createEvent();
        $this->authorizeScanner($eventId, $revokedUserId, 'revoked');

        $this->postJson('/api/v1/scanner/login', [
            'email' => 'revoked-scanner@example.com',
            'password' => 'password',
        ])->assertStatus(403)
            ->assertExactJson([
                'success' => false,
                'message' => 'You are not authorized to use the scanner app.',
                'data' => null,
            ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $revokedUserId,
            'name' => 'unity-event-scanner-token',
        ]);

        $unassignedUserId = $this->createUser([
            'email' => 'unassigned-scanner@example.com',
            'password_hash' => Hash::make('password'),
        ]);

        $this->postJson('/api/v1/scanner/login', [
            'email' => 'unassigned-scanner@example.com',
            'password' => 'password',
        ])->assertStatus(403)
            ->assertExactJson([
                'success' => false,
                'message' => 'You are not authorized to use the scanner app.',
                'data' => null,
            ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $unassignedUserId,
            'name' => 'unity-event-scanner-token',
        ]);
    }

    public function test_revoked_scanner_token_only_returns_empty_scanner_events(): void
    {
        $userId = $this->createUser([
            'email' => 'old-token-scanner@example.com',
            'password_hash' => Hash::make('password'),
        ]);
        $eventId = $this->createEvent();
        $this->authorizeScanner($eventId, $userId);

        $loginResponse = $this->postJson('/api/v1/scanner/login', [
            'email' => 'old-token-scanner@example.com',
            'password' => 'password',
        ])->assertOk();

        DB::table('event_scanner_authorizations')
            ->where('event_id', $eventId)
            ->where('scanner_user_id', $userId)
            ->update(['status' => 'revoked', 'revoked_at' => now(), 'updated_at' => now()]);

        $this->withHeader('Authorization', 'Bearer '.$loginResponse->json('data.token'))
            ->getJson('/api/v1/scanner/events')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Scanner events fetched successfully.')
            ->assertJsonPath('data.items', []);
    }

    private function createUser(array $overrides = []): string
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('users')->insert(array_merge([
            'id' => $id,
            'first_name' => 'Harsh',
            'last_name' => 'Chauhan',
            'display_name' => 'Harsh Chauhan',
            'email' => 'harsh@example.com',
            'phone' => Str::random(10),
            'password_hash' => Hash::make('password'),
            'password' => null,
            'company_name' => 'Unity',
            'profile_photo_url' => null,
            'membership_status' => 'visitor',
            'membership_expiry' => null,
            'membership_starts_at' => null,
            'membership_ends_at' => null,
            'coins_balance' => 0,
            'coin_medal_rank' => null,
            'coin_milestone_title' => null,
            'coin_milestone_meaning' => null,
            'members_introduced_count' => 0,
            'contribution_award_name' => null,
            'contribution_award_recognition' => null,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ], $overrides));

        return $id;
    }

    private function createEvent(): string
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('events')->insert([
            'id' => $id,
            'title' => 'Scanner Test Event',
            'start_at' => $now->copy()->addDay(),
            'location_text' => 'Test Hall',
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        return $id;
    }

    private function authorizeScanner(string $eventId, string $userId, string $status = 'active'): void
    {
        DB::table('event_scanner_authorizations')->insert([
            'id' => (string) Str::uuid(),
            'event_id' => $eventId,
            'scanner_user_id' => $userId,
            'assigned_by_user_id' => null,
            'status' => $status,
            'assigned_at' => now(),
            'revoked_at' => $status === 'revoked' ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function setUpInMemoryDatabase(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('event_registrations');
        Schema::dropIfExists('event_scanner_authorizations');
        Schema::dropIfExists('events');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('display_name', 150)->nullable();
            $table->string('email', 255)->unique();
            $table->string('phone', 20)->nullable()->unique();
            $table->string('password_hash')->nullable();
            $table->string('password')->nullable();
            $table->string('company_name', 150)->nullable();
            $table->text('profile_photo_url')->nullable();
            $table->string('membership_status', 50)->default('visitor');
            $table->timestamp('membership_expiry')->nullable();
            $table->timestamp('membership_starts_at')->nullable();
            $table->timestamp('membership_ends_at')->nullable();
            $table->bigInteger('coins_balance')->default(0);
            $table->string('coin_medal_rank')->nullable();
            $table->string('coin_milestone_title')->nullable();
            $table->text('coin_milestone_meaning')->nullable();
            $table->integer('members_introduced_count')->default(0);
            $table->string('contribution_award_name')->nullable();
            $table->text('contribution_award_recognition')->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->timestamp('start_at')->nullable();
            $table->text('location_text')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('event_scanner_authorizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('scanner_user_id');
            $table->uuid('assigned_by_user_id')->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('event_registrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('user_id')->nullable();
            $table->string('status', 30)->default('registered');
            $table->string('checkin_status', 30)->default('pending');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }
}

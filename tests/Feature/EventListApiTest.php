<?php

namespace Tests\Feature;

use App\Models\Circle;
use App\Models\EventOccurrence;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventListApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-09 15:00:00'));
        $this->setUpInMemoryDatabase();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_events_api_returns_today_midnight_scheduled_event_all_day(): void
    {
        $user = $this->unityUser();
        $circle = $this->circle('Test');
        $eventId = (string) Str::uuid();
        $occurrenceId = (string) Str::uuid();

        $this->insertEvent($eventId, $circle->id, 'test', 'circle_meeting', 'scheduled', '2026-06-09 00:00:00');
        $this->insertOccurrence($occurrenceId, $eventId, 'scheduled', '2026-06-09 00:00:00');
        DB::table('circle_members')->insert([
            'id' => (string) Str::uuid(),
            'circle_id' => $circle->id,
            'user_id' => $user->id,
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Events fetched successfully.')
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.event_id', $eventId)
            ->assertJsonPath('data.items.0.occurrence_id', $occurrenceId)
            ->assertJsonPath('data.items.0.title', 'test')
            ->assertJsonPath('data.items.0.status', 'scheduled')
            ->assertJsonPath('data.items.0.circle.id', $circle->id)
            ->assertJsonPath('data.pagination.current_page', 1)
            ->assertJsonPath('data.pagination.per_page', 20)
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_events_api_excludes_cancelled_and_inactive_events(): void
    {
        $user = $this->unityUser();
        $circle = $this->circle('Test');
        DB::table('circle_members')->insert([
            'id' => (string) Str::uuid(),
            'circle_id' => $circle->id,
            'user_id' => $user->id,
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $scheduledEventId = (string) Str::uuid();
        $cancelledEventId = (string) Str::uuid();
        $inactiveEventId = (string) Str::uuid();
        $cancelledOccurrenceEventId = (string) Str::uuid();

        $this->insertEvent($scheduledEventId, $circle->id, 'visible test', 'circle_meeting', 'scheduled', '2026-06-09 00:00:00');
        $this->insertOccurrence((string) Str::uuid(), $scheduledEventId, 'scheduled', '2026-06-09 00:00:00');

        $this->insertEvent($cancelledEventId, $circle->id, 'cancelled test', 'circle_meeting', 'cancelled', '2026-06-09 10:00:00');
        $this->insertOccurrence((string) Str::uuid(), $cancelledEventId, 'scheduled', '2026-06-09 10:00:00');

        $this->insertEvent($inactiveEventId, $circle->id, 'inactive test', 'circle_meeting', 'inactive', '2026-06-09 11:00:00');
        $this->insertOccurrence((string) Str::uuid(), $inactiveEventId, 'scheduled', '2026-06-09 11:00:00');

        $this->insertEvent($cancelledOccurrenceEventId, $circle->id, 'cancelled occurrence test', 'circle_meeting', 'scheduled', '2026-06-09 12:00:00');
        $this->insertOccurrence((string) Str::uuid(), $cancelledOccurrenceEventId, 'cancelled', '2026-06-09 12:00:00');

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.event_id', $scheduledEventId);
    }

    public function test_events_api_supports_circle_and_search_filters(): void
    {
        $user = $this->unityUser();
        $matchingCircle = $this->circle('Test');
        $otherCircle = $this->circle('Other');

        foreach ([$matchingCircle, $otherCircle] as $circle) {
            DB::table('circle_members')->insert([
                'id' => (string) Str::uuid(),
                'circle_id' => $circle->id,
                'user_id' => $user->id,
                'status' => 'approved',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $matchingEventId = (string) Str::uuid();
        $otherCircleEventId = (string) Str::uuid();
        $otherTitleEventId = (string) Str::uuid();

        $this->insertEvent($matchingEventId, $matchingCircle->id, 'test', 'circle_meeting', 'scheduled', '2026-06-09 00:00:00');
        $this->insertOccurrence((string) Str::uuid(), $matchingEventId, 'scheduled', '2026-06-09 00:00:00');

        $this->insertEvent($otherCircleEventId, $otherCircle->id, 'test', 'circle_meeting', 'scheduled', '2026-06-09 10:00:00');
        $this->insertOccurrence((string) Str::uuid(), $otherCircleEventId, 'scheduled', '2026-06-09 10:00:00');

        $this->insertEvent($otherTitleEventId, $matchingCircle->id, 'different', 'circle_meeting', 'scheduled', '2026-06-09 11:00:00');
        $this->insertOccurrence((string) Str::uuid(), $otherTitleEventId, 'scheduled', '2026-06-09 11:00:00');

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/events?circle_id='.$matchingCircle->id.'&search=test')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.event_id', $matchingEventId)
            ->assertJsonPath('data.pagination.total', 1);
    }

    private function unityUser(): User
    {
        return User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Peer',
            'last_name' => 'Member',
            'display_name' => 'Peer Member',
            'email' => 'peer-'.Str::uuid().'@example.com',
            'phone' => (string) random_int(1000000000, 9999999999),
            'password_hash' => Hash::make('password'),
        ]);
    }

    private function circle(string $name): Circle
    {
        return Circle::query()->create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'active',
        ]);
    }

    private function insertEvent(string $id, string $circleId, string $title, string $eventType, string $status, string $startAt): void
    {
        DB::table('events')->insert([
            'id' => $id,
            'circle_id' => $circleId,
            'title' => $title,
            'description' => 'Event description',
            'start_at' => $startAt,
            'end_at' => Carbon::parse($startAt)->addHour(),
            'is_virtual' => false,
            'location_text' => 'Ahmedabad',
            'visibility' => 'members',
            'is_paid' => false,
            'event_type' => $eventType,
            'event_category' => 'networking',
            'mode' => 'offline',
            'qr_checkin_enabled' => false,
            'is_public' => false,
            'visitor_registration_enabled' => false,
            'member_registration_enabled' => true,
            'status' => $status,
            'is_active' => $status !== 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertOccurrence(string $id, string $eventId, string $status, string $startAt): EventOccurrence
    {
        return EventOccurrence::query()->create([
            'id' => $id,
            'event_id' => $eventId,
            'occurrence_date' => Carbon::parse($startAt)->toDateString(),
            'start_at' => $startAt,
            'end_at' => Carbon::parse($startAt)->addHour(),
            'status' => $status,
            'registered_count' => 0,
            'checked_in_count' => 0,
        ]);
    }

    private function setUpInMemoryDatabase(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('event_registrations');
        Schema::dropIfExists('event_occurrences');
        Schema::dropIfExists('circle_members');
        Schema::dropIfExists('events');
        Schema::dropIfExists('circles');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('display_name', 150)->nullable();
            $table->string('email', 255)->unique();
            $table->string('phone', 20)->nullable()->unique();
            $table->string('password_hash');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('circles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('circle_id')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->boolean('is_virtual')->default(false);
            $table->text('location_text')->nullable();
            $table->json('agenda')->nullable();
            $table->json('speakers')->nullable();
            $table->text('banner_url')->nullable();
            $table->string('visibility')->default('members');
            $table->boolean('is_paid')->default(false);
            $table->json('metadata')->nullable();
            $table->string('event_type')->nullable();
            $table->string('event_category')->nullable();
            $table->string('mode')->nullable();
            $table->integer('registration_limit')->nullable();
            $table->decimal('ticket_price', 10, 2)->default(0);
            $table->boolean('qr_checkin_enabled')->default(false);
            $table->boolean('is_public')->default(false);
            $table->string('recurrence_type')->nullable();
            $table->boolean('visitor_registration_enabled')->default(false);
            $table->boolean('member_registration_enabled')->default(true);
            $table->text('online_meeting_url')->nullable();
            $table->text('zoho_form_url')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('event_occurrences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->date('occurrence_date')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->string('status')->nullable();
            $table->integer('registered_count')->default(0);
            $table->integer('checked_in_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('event_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('occurrence_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->string('status')->nullable();
            $table->boolean('payment_required')->default(false);
            $table->string('payment_status')->nullable();
            $table->string('checkin_status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('circle_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('circle_id');
            $table->uuid('user_id');
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tokenable_type');
            $table->uuid('tokenable_id');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }
}

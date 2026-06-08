<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventQrScanLog;
use App\Models\EventRegistration;
use App\Models\ScanAppUser;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScanAppEventScanFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
    }

    public function test_scan_app_token_checks_in_attendee_from_qr_token_and_logs_scan(): void
    {
        $attendee = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Attendee',
            'last_name' => 'Member',
            'display_name' => 'Attendee Member',
            'email' => 'attendee@example.com',
            'phone' => '9999999999',
            'password_hash' => Hash::make('password'),
        ]);
        $event = Event::query()->create([
            'id' => (string) Str::uuid(),
            'title' => 'Business Networking Event',
            'start_at' => '2026-06-10 10:00:00',
            'end_at' => '2026-06-10 12:00:00',
            'location_text' => 'Ahmedabad',
            'qr_checkin_enabled' => true,
        ]);
        $occurrence = EventOccurrence::query()->create([
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'occurrence_date' => '2026-06-10',
            'start_at' => '2026-06-10 10:00:00',
            'end_at' => '2026-06-10 12:00:00',
            'status' => 'scheduled',
        ]);
        EventRegistration::query()->create([
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'occurrence_id' => $occurrence->id,
            'user_id' => $attendee->id,
            'qr_token' => 'ATTENDEE_QR_TOKEN',
            'qr_code_path' => 'event-qrcodes/'.$event->id.'/attendee.png',
            'status' => 'registered',
            'payment_required' => false,
            'payment_status' => 'not_required',
            'checkin_status' => 'pending',
            'registered_at' => now(),
        ]);
        $scanner = ScanAppUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Front Desk Scanner',
            'username' => 'scanner',
            'password_hash' => Hash::make('password'),
            'hotel_name' => 'Hotel ABC',
            'event_id' => $event->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($scanner);

        $this->postJson('/api/v1/scan-app/events/'.$event->id.'/scan', [
            'qr_token' => 'ATTENDEE_QR_TOKEN',
            'device_info' => [
                'device_id' => 'test-device-001',
                'platform' => 'android',
            ],
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Attendance marked successfully.')
            ->assertJsonPath('data.event_id', $event->id)
            ->assertJsonPath('data.checked_in_user.id', $attendee->id)
            ->assertJsonPath('data.checked_in_user.name', 'Attendee Member')
            ->assertJsonPath('data.checked_in_user.email', 'attendee@example.com')
            ->assertJsonPath('data.scanner.id', $scanner->id)
            ->assertJsonPath('data.scanner.name', 'Front Desk Scanner')
            ->assertJsonPath('data.scanner.hotel_name', 'Hotel ABC');

        $registration = EventRegistration::query()->where('qr_token', 'ATTENDEE_QR_TOKEN')->firstOrFail();
        $this->assertSame('checked_in', $registration->checkin_status);
        $this->assertSame('attended', $registration->status);
        $this->assertNotNull($registration->checked_in_at);

        $scanLog = EventQrScanLog::query()->firstOrFail();
        $this->assertSame($scanner->id, $scanLog->scanner_id);
        $this->assertSame($event->id, $scanLog->event_id);
        $this->assertSame($attendee->id, $scanLog->user_id);
        $this->assertSame('ATTENDEE_QR_TOKEN', $scanLog->qr_token);
        $this->assertSame('success', $scanLog->scan_status);
        $this->assertSame('Attendance marked successfully.', $scanLog->scan_message);
        $this->assertSame('android', $scanLog->device_info['platform']);
    }


    public function test_scan_app_login_token_scans_extracted_qr_token_without_unity_login(): void
    {
        [$attendee, $event, $scanner] = $this->createScanReadyRegistration('LOGIN_FLOW_QR_TOKEN');

        $loginResponse = $this->postJson('/api/v1/scan-app/login', [
            'username' => $scanner->username,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('data.scanner.id', $scanner->id);

        $token = $loginResponse->json('data.token');
        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/scan-app/scan', [
                'qr_token' => 'LOGIN_FLOW_QR_TOKEN',
                'device_info' => ['platform' => 'android'],
            ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Attendance marked successfully.')
            ->assertJsonPath('data.event_id', $event->id)
            ->assertJsonPath('data.checked_in_user.id', $attendee->id)
            ->assertJsonPath('data.scanner.id', $scanner->id);

        $registration = EventRegistration::query()->where('qr_token', 'LOGIN_FLOW_QR_TOKEN')->firstOrFail();
        $this->assertSame('checked_in', $registration->checkin_status);
        $this->assertSame('scan_app', $registration->attendance_source);
    }

    public function test_scan_app_scan_endpoint_accepts_scanned_qr_url_and_stores_only_token(): void
    {
        [$attendee, $event, $scanner] = $this->createScanReadyRegistration('URL_FLOW_QR_TOKEN');

        Sanctum::actingAs($scanner);

        $this->postJson('/api/v1/scan-app/scan', [
            'qr_token' => 'https://peersunity.com/api/v1/events/checkin/qr/URL_FLOW_QR_TOKEN',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.event_id', $event->id)
            ->assertJsonPath('data.checked_in_user.id', $attendee->id);

        $scanLog = EventQrScanLog::query()->firstOrFail();
        $this->assertSame('URL_FLOW_QR_TOKEN', $scanLog->qr_token);
        $this->assertSame('success', $scanLog->scan_status);
    }


    public function test_legacy_checkin_scan_accepts_scan_app_token_without_event_id(): void
    {
        $attendee = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Legacy',
            'last_name' => 'Attendee',
            'display_name' => 'Legacy Attendee',
            'email' => 'legacy-attendee@example.com',
            'phone' => '7777777777',
            'password_hash' => Hash::make('password'),
        ]);
        $event = Event::query()->create([
            'id' => (string) Str::uuid(),
            'title' => 'Legacy Scanner Event',
            'qr_checkin_enabled' => true,
        ]);
        $occurrence = EventOccurrence::query()->create([
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'occurrence_date' => '2026-06-10',
            'start_at' => '2026-06-10 10:00:00',
            'end_at' => '2026-06-10 12:00:00',
        ]);
        EventRegistration::query()->create([
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'occurrence_id' => $occurrence->id,
            'user_id' => $attendee->id,
            'qr_token' => 'LEGACY_ATTENDEE_QR_TOKEN',
            'qr_code_path' => 'event-qrcodes/'.$event->id.'/legacy-attendee.png',
            'status' => 'registered',
            'payment_required' => false,
            'payment_status' => 'not_required',
            'checkin_status' => 'pending',
            'registered_at' => now(),
        ]);
        $scanner = ScanAppUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Legacy Scanner',
            'username' => 'legacy-scanner',
            'password_hash' => Hash::make('password'),
            'hotel_name' => 'Hotel Legacy',
            'event_id' => $event->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($scanner);

        $this->postJson('/api/v1/events/checkin/scan', [
            'qr_token' => 'LEGACY_ATTENDEE_QR_TOKEN',
            'device_info' => ['platform' => 'android'],
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Attendance marked successfully.')
            ->assertJsonPath('data.event_id', $event->id)
            ->assertJsonPath('data.checked_in_user.id', $attendee->id)
            ->assertJsonPath('data.checked_in_user.email', 'legacy-attendee@example.com')
            ->assertJsonPath('data.scanner.id', $scanner->id)
            ->assertJsonPath('data.scanner.name', 'Legacy Scanner')
            ->assertJsonPath('data.scanner.hotel_name', 'Hotel Legacy');

        $registration = EventRegistration::query()->where('qr_token', 'LEGACY_ATTENDEE_QR_TOKEN')->firstOrFail();
        $this->assertSame('checked_in', $registration->checkin_status);
        $this->assertSame('attended', $registration->status);
        $this->assertNotNull($registration->checked_in_at);

        $scanLog = EventQrScanLog::query()->firstOrFail();
        $this->assertSame($scanner->id, $scanLog->scanner_id);
        $this->assertSame($event->id, $scanLog->event_id);
        $this->assertSame($attendee->id, $scanLog->user_id);
        $this->assertSame('LEGACY_ATTENDEE_QR_TOKEN', $scanLog->qr_token);
        $this->assertSame('success', $scanLog->scan_status);
        $this->assertSame('Attendance marked successfully.', $scanLog->scan_message);
    }

    public function test_legacy_checkin_scan_rejects_scanner_assigned_to_different_event(): void
    {
        $attendee = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Wrong',
            'email' => 'wrong-event-attendee@example.com',
            'phone' => '6666666666',
            'password_hash' => Hash::make('password'),
        ]);
        $registrationEvent = Event::query()->create([
            'id' => (string) Str::uuid(),
            'title' => 'Registration Event',
            'qr_checkin_enabled' => true,
        ]);
        $scannerEvent = Event::query()->create([
            'id' => (string) Str::uuid(),
            'title' => 'Scanner Event',
            'qr_checkin_enabled' => true,
        ]);
        $occurrence = EventOccurrence::query()->create([
            'id' => (string) Str::uuid(),
            'event_id' => $registrationEvent->id,
        ]);
        EventRegistration::query()->create([
            'id' => (string) Str::uuid(),
            'event_id' => $registrationEvent->id,
            'occurrence_id' => $occurrence->id,
            'user_id' => $attendee->id,
            'qr_token' => 'WRONG_EVENT_QR_TOKEN',
            'qr_code_path' => 'event-qrcodes/'.$registrationEvent->id.'/wrong-event.png',
            'status' => 'registered',
            'payment_required' => false,
            'payment_status' => 'not_required',
            'checkin_status' => 'pending',
            'registered_at' => now(),
        ]);
        $scanner = ScanAppUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Wrong Event Scanner',
            'username' => 'wrong-event-scanner',
            'password_hash' => Hash::make('password'),
            'event_id' => $scannerEvent->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($scanner);

        $this->postJson('/api/v1/events/checkin/scan', [
            'qr_token' => 'WRONG_EVENT_QR_TOKEN',
        ])->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'You are not allowed to scan QR for this event.',
            ]);

        $registration = EventRegistration::query()->where('qr_token', 'WRONG_EVENT_QR_TOKEN')->firstOrFail();
        $this->assertSame('pending', $registration->checkin_status);

        $scanLog = EventQrScanLog::query()->firstOrFail();
        $this->assertSame($scanner->id, $scanLog->scanner_id);
        $this->assertSame($registrationEvent->id, $scanLog->event_id);
        $this->assertSame($attendee->id, $scanLog->user_id);
        $this->assertSame('WRONG_EVENT_QR_TOKEN', $scanLog->qr_token);
        $this->assertSame('wrong_event', $scanLog->scan_status);
    }

    public function test_unity_user_token_cannot_use_scan_app_scan_api(): void
    {
        $unityUser = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Unity',
            'email' => 'unity@example.com',
            'phone' => '8888888888',
            'password_hash' => Hash::make('password'),
        ]);
        $event = Event::query()->create([
            'id' => (string) Str::uuid(),
            'title' => 'Business Networking Event',
        ]);

        Sanctum::actingAs($unityUser);

        $this->postJson('/api/v1/scan-app/events/'.$event->id.'/scan', [
            'qr_token' => 'ATTENDEE_QR_TOKEN',
        ])->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'This API is only available for scanner app users.',
            ]);

        $this->postJson('/api/v1/scan-app/scan', [
            'qr_token' => 'ATTENDEE_QR_TOKEN',
        ])->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'This API is only available for scanner app users.',
            ]);
    }

    public function test_scanner_token_cannot_use_unity_event_registration_api(): void
    {
        $event = Event::query()->create([
            'id' => (string) Str::uuid(),
            'title' => 'Business Networking Event',
        ]);
        $occurrence = EventOccurrence::query()->create([
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
        ]);
        $scanner = ScanAppUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Front Desk Scanner',
            'username' => 'scanner',
            'password_hash' => Hash::make('password'),
            'event_id' => $event->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($scanner);

        $this->postJson('/api/v1/events/'.$event->id.'/occurrences/'.$occurrence->id.'/register')
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'This API is only available for Unity users.',
            ]);
    }

    public function test_scanner_must_be_assigned_to_route_event(): void
    {
        $assignedEvent = Event::query()->create([
            'id' => (string) Str::uuid(),
            'title' => 'Assigned Event',
        ]);
        $routeEvent = Event::query()->create([
            'id' => (string) Str::uuid(),
            'title' => 'Route Event',
        ]);
        $scanner = ScanAppUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Front Desk Scanner',
            'username' => 'scanner',
            'password_hash' => Hash::make('password'),
            'event_id' => $assignedEvent->id,
            'is_active' => true,
        ]);

        Sanctum::actingAs($scanner);

        $this->postJson('/api/v1/scan-app/events/'.$routeEvent->id.'/scan', [
            'qr_token' => 'ATTENDEE_QR_TOKEN',
        ])->assertForbidden()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Scanner is not assigned to this event.');
    }


    private function createScanReadyRegistration(string $qrToken): array
    {
        $attendee = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Scanner',
            'last_name' => 'Attendee',
            'display_name' => 'Scanner Attendee',
            'email' => Str::lower($qrToken).'@example.com',
            'phone' => (string) random_int(1000000000, 9999999999),
            'password_hash' => Hash::make('password'),
        ]);
        $event = Event::query()->create([
            'id' => (string) Str::uuid(),
            'title' => 'Scanner Login Event',
            'qr_checkin_enabled' => true,
        ]);
        $occurrence = EventOccurrence::query()->create([
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'occurrence_date' => '2026-06-10',
            'start_at' => '2026-06-10 10:00:00',
            'end_at' => '2026-06-10 12:00:00',
            'status' => 'scheduled',
        ]);
        EventRegistration::query()->create([
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'occurrence_id' => $occurrence->id,
            'user_id' => $attendee->id,
            'qr_token' => $qrToken,
            'qr_code_path' => 'event-qrcodes/'.$event->id.'/'.Str::slug($qrToken).'.png',
            'status' => 'registered',
            'payment_required' => false,
            'payment_status' => 'not_required',
            'checkin_status' => 'pending',
            'registered_at' => now(),
        ]);
        $scanner = ScanAppUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Login Flow Scanner',
            'username' => 'scanner-'.Str::lower($qrToken),
            'password_hash' => Hash::make('password'),
            'hotel_name' => 'Hotel Scanner',
            'event_id' => $event->id,
            'is_active' => true,
        ]);

        return [$attendee, $event, $scanner];
    }

    private function setUpInMemoryDatabase(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('event_qr_scan_logs');
        Schema::dropIfExists('event_registrations');
        Schema::dropIfExists('event_occurrences');
        Schema::dropIfExists('scan_app_users');
        Schema::dropIfExists('events');
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

        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->text('location_text')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('qr_checkin_enabled')->default(false);
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
            $table->text('qr_token')->nullable();
            $table->text('qr_code_path')->nullable();
            $table->text('qr_code_url')->nullable();
            $table->string('status')->nullable();
            $table->boolean('payment_required')->default(false);
            $table->string('payment_status')->nullable();
            $table->string('checkin_status')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('last_qr_scan_at')->nullable();
            $table->string('attendance_source')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('scan_app_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('username')->unique();
            $table->string('password_hash');
            $table->string('hotel_name')->nullable();
            $table->uuid('event_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('event_qr_scan_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->uuid('scanner_id')->nullable();
            $table->text('qr_token')->nullable();
            $table->string('scan_status')->nullable();
            $table->text('scan_message')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->json('device_info')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
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

            $table->index(['tokenable_type', 'tokenable_id']);
        });
    }
}

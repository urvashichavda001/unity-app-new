<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserGeoLocation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GeoPeersCountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
        $this->registerSqliteMathFunctions();
    }

    public function test_get_peers_count_within_500km_returns_error_when_no_auth_location_updated(): void
    {
        $authUser = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Auth',
            'email' => 'auth@example.com',
            'status' => 'active',
        ]);

        Sanctum::actingAs($authUser);

        $response = $this->getJson('/api/v1/geo/peers-count-500km');

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Please update your location first.',
            ]);
    }

    public function test_get_peers_count_within_500km_counts_peers_correctly(): void
    {
        $authUser = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Auth',
            'email' => 'auth@example.com',
            'status' => 'active',
        ]);

        // 1. Auth Location: Ahmedabad, India (23.0225, 72.5714)
        UserGeoLocation::query()->create([
            'user_id' => $authUser->id,
            'latitude' => 23.0225,
            'longitude' => 72.5714,
            'is_visible' => true,
        ]);

        // 2. Peer 1: Vadodara (22.3072, 73.1812) - ~110km (visible: true) -> COUNTED
        $peer1 = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Vadodara Peer',
            'email' => 'vadodara@example.com',
            'status' => 'active',
        ]);
        UserGeoLocation::query()->create([
            'user_id' => $peer1->id,
            'latitude' => 22.3072,
            'longitude' => 73.1812,
            'is_visible' => true,
        ]);

        // 3. Peer 2: Delhi (28.7041, 77.1025) - ~775km (visible: true) -> EXCLUDED (too far)
        $peer2 = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Delhi Peer',
            'email' => 'delhi@example.com',
            'status' => 'active',
        ]);
        UserGeoLocation::query()->create([
            'user_id' => $peer2->id,
            'latitude' => 28.7041,
            'longitude' => 77.1025,
            'is_visible' => true,
        ]);

        // 4. Peer 3: Surat (21.1702, 72.8311) - ~224km (visible: false) -> EXCLUDED (invisible)
        $peer3 = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Surat Peer',
            'email' => 'surat@example.com',
            'status' => 'active',
        ]);
        UserGeoLocation::query()->create([
            'user_id' => $peer3->id,
            'latitude' => 21.1702,
            'longitude' => 72.8311,
            'is_visible' => false,
        ]);

        Sanctum::actingAs($authUser);

        $response = $this->getJson('/api/v1/geo/peers-count-500km');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Peers count fetched successfully within 500km radius.',
                'data' => [
                    'count' => 1,
                ],
            ]);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('user_geo_locations');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('first_name')->nullable();
            $table->string('email')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
        });

        Schema::create('user_geo_locations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->boolean('is_visible')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    private function registerSqliteMathFunctions(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $pdo = DB::connection()->getPdo();
            $pdo->sqliteCreateFunction('acos', 'acos', 1);
            $pdo->sqliteCreateFunction('cos', 'cos', 1);
            $pdo->sqliteCreateFunction('sin', 'sin', 1);
            $pdo->sqliteCreateFunction('radians', function ($degrees) {
                return deg2rad($degrees);
            }, 1);
            $pdo->sqliteCreateFunction('least', function (...$args) {
                return min($args);
            });
            $pdo->sqliteCreateFunction('greatest', function (...$args) {
                return max($args);
            });
        }
    }
}

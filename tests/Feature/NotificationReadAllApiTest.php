<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationReadAllApiTest extends TestCase
{
    public function test_read_all_marks_only_authenticated_users_unread_notifications_as_read(): void
    {
        $this->createSchema();

        $authUser = $this->createUser('auth@example.com');
        $otherUser = $this->createUser('other@example.com');

        $authUnreadOne = (string) Str::uuid();
        $authUnreadTwo = (string) Str::uuid();
        $authRead = (string) Str::uuid();
        $otherUnread = (string) Str::uuid();

        DB::table('notifications')->insert([
            $this->notificationRow($authUnreadOne, $authUser->id, false, null),
            $this->notificationRow($authUnreadTwo, $authUser->id, false, null),
            $this->notificationRow($authRead, $authUser->id, true, now()),
            $this->notificationRow($otherUnread, $otherUser->id, false, null),
        ]);

        Sanctum::actingAs($authUser);

        $this->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'All notifications marked as read.',
                'data' => [
                    'updated_count' => 2,
                ],
            ]);

        $this->assertDatabaseHas('notifications', [
            'id' => $authUnreadOne,
            'user_id' => $authUser->id,
            'is_read' => true,
        ]);
        $this->assertDatabaseHas('notifications', [
            'id' => $authUnreadTwo,
            'user_id' => $authUser->id,
            'is_read' => true,
        ]);
        $this->assertDatabaseHas('notifications', [
            'id' => $otherUnread,
            'user_id' => $otherUser->id,
            'is_read' => false,
            'read_at' => null,
        ]);
    }

    public function test_read_all_returns_zero_when_no_unread_notifications_exist(): void
    {
        $this->createSchema();

        $authUser = $this->createUser('auth@example.com');

        DB::table('notifications')->insert([
            $this->notificationRow((string) Str::uuid(), $authUser->id, true, now()),
        ]);

        Sanctum::actingAs($authUser);

        $this->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'No unread notifications found.',
                'data' => [
                    'updated_count' => 0,
                ],
            ]);
    }

    public function test_read_all_requires_authentication(): void
    {
        $this->createSchema();

        $this->postJson('/api/v1/notifications/read-all')
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('display_name')->nullable();
            $table->string('email')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('type');
            $table->json('payload')->nullable();
            $table->json('data')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('read_at')->nullable();
        });
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'id' => (string) Str::uuid(),
            'display_name' => 'Test User',
            'email' => $email,
            'status' => 'active',
        ]);
    }

    private function notificationRow(string $id, string $userId, bool $isRead, mixed $readAt): array
    {
        return [
            'id' => $id,
            'user_id' => $userId,
            'type' => 'test',
            'payload' => json_encode(['message' => 'Test notification']),
            'data' => null,
            'is_read' => $isRead,
            'created_at' => now(),
            'read_at' => $readAt,
        ];
    }
}

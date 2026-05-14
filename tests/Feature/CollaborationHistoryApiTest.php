<?php

namespace Tests\Feature;

use App\Models\CollaborationPost;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CollaborationHistoryApiTest extends TestCase
{
    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        $this->userA = $this->createUser('Alice', 'Creator');
        $this->userB = $this->createUser('Bob', 'Peer');
    }

    public function test_history_is_visible_to_every_authenticated_peer(): void
    {
        $firstPost = $this->createCollaborationPost($this->userA, [
            'title' => 'Alice incomplete collaboration',
            'completion_status' => CollaborationPost::COMPLETION_INCOMPLETE,
            'created_at' => Carbon::parse('2026-05-13 09:00:00'),
        ]);
        $secondPost = $this->createCollaborationPost($this->userB, [
            'title' => 'Bob completed collaboration',
            'completion_status' => CollaborationPost::COMPLETION_COMPLETED,
            'completed_at' => Carbon::parse('2026-05-13 11:00:00'),
            'accepted_by_user_id' => $this->userA->id,
            'accepted_at' => Carbon::parse('2026-05-13 11:00:00'),
            'created_at' => Carbon::parse('2026-05-13 10:00:00'),
        ]);

        Sanctum::actingAs($this->userA);
        $userAResponse = $this->getJson('/api/v1/collaborations/history');

        $userAResponse->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Collaboration history fetched successfully.')
            ->assertJsonCount(1, 'data.incomplete')
            ->assertJsonCount(1, 'data.completed')
            ->assertJsonPath('data.incomplete.0.id', $firstPost)
            ->assertJsonPath('data.incomplete.0.user.id', $this->userA->id)
            ->assertJsonPath('data.completed.0.id', $secondPost)
            ->assertJsonPath('data.completed.0.user.id', $this->userB->id)
            ->assertJsonPath('data.completed.0.accepted_by.id', $this->userA->id);

        Sanctum::actingAs($this->userB);
        $userBResponse = $this->getJson('/api/v1/collaborations/history');

        $userBResponse->assertOk()
            ->assertJsonCount(1, 'data.incomplete')
            ->assertJsonCount(1, 'data.completed')
            ->assertJsonPath('data.incomplete.0.id', $firstPost)
            ->assertJsonPath('data.completed.0.id', $secondPost);
    }

    public function test_history_status_filters_keep_same_response_shape(): void
    {
        $incompletePost = $this->createCollaborationPost($this->userA, [
            'title' => 'Incomplete collaboration',
            'completion_status' => null,
        ]);
        $completedPost = $this->createCollaborationPost($this->userB, [
            'title' => 'Completed collaboration',
            'completion_status' => CollaborationPost::COMPLETION_COMPLETED,
            'completed_at' => Carbon::parse('2026-05-13 12:00:00'),
        ]);

        Sanctum::actingAs($this->userB);

        $this->getJson('/api/v1/collaborations/history?status=incomplete')
            ->assertOk()
            ->assertJsonCount(1, 'data.incomplete')
            ->assertJsonCount(0, 'data.completed')
            ->assertJsonPath('data.incomplete.0.id', $incompletePost);

        $this->getJson('/api/v1/collaborations/history?status=completed')
            ->assertOk()
            ->assertJsonCount(0, 'data.incomplete')
            ->assertJsonCount(1, 'data.completed')
            ->assertJsonPath('data.completed.0.id', $completedPost);

        $this->getJson('/api/v1/collaborations/history?status=complete')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_my_history_only_returns_authenticated_users_collaborations(): void
    {
        $myPost = $this->createCollaborationPost($this->userA, [
            'title' => 'My collaboration',
            'completion_status' => CollaborationPost::COMPLETION_INCOMPLETE,
        ]);
        $this->createCollaborationPost($this->userB, [
            'title' => 'Other peer collaboration',
            'completion_status' => CollaborationPost::COMPLETION_INCOMPLETE,
        ]);

        Sanctum::actingAs($this->userA);

        $this->getJson('/api/v1/collaborations/my-history')
            ->assertOk()
            ->assertJsonCount(1, 'data.incomplete')
            ->assertJsonCount(0, 'data.completed')
            ->assertJsonPath('data.incomplete.0.id', $myPost)
            ->assertJsonPath('data.incomplete.0.user.id', $this->userA->id);
    }

    private function createTables(): void
    {
        Schema::dropIfExists('collaboration_posts');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('display_name', 150)->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('company_name')->nullable();
            $table->string('designation')->nullable();
            $table->string('city')->nullable();
            $table->string('membership_status', 50)->nullable();
            $table->uuid('profile_photo_file_id')->nullable();
            $table->string('profile_photo_url')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('collaboration_posts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('collaboration_type_id')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('scope')->nullable();
            $table->json('countries_of_interest')->nullable();
            $table->string('preferred_model')->nullable();
            $table->uuid('industry_id')->nullable();
            $table->string('business_stage')->nullable();
            $table->string('years_in_operation')->nullable();
            $table->string('urgency')->nullable();
            $table->string('status')->default(CollaborationPost::STATUS_ACTIVE);
            $table->string('completion_status')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->uuid('accepted_by_user_id')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    private function createUser(string $firstName, string $lastName): User
    {
        $id = (string) Str::uuid();

        DB::table('users')->insert([
            'id' => $id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => $firstName . ' ' . $lastName,
            'email' => Str::lower($firstName) . '@example.test',
            'membership_status' => User::STATUS_FREE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }

    private function createCollaborationPost(User $user, array $overrides = []): string
    {
        $id = (string) Str::uuid();
        $now = now();

        DB::table('collaboration_posts')->insert(array_merge([
            'id' => $id,
            'user_id' => $user->id,
            'title' => 'Collaboration post',
            'description' => 'Collaboration description',
            'status' => CollaborationPost::STATUS_ACTIVE,
            'completion_status' => CollaborationPost::COMPLETION_INCOMPLETE,
            'posted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        return $id;
    }
}

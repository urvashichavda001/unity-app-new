<?php

namespace Tests\Feature;

use App\Models\P2pMeeting;
use App\Models\User;
use App\Services\Coins\CoinsService;
use App\Services\Notifications\NotifyUserService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class P2pMeetingCoinsApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('notifications');
        Schema::dropIfExists('coins_ledger');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('p2p_meetings');
        Schema::dropIfExists('peer_blocks');
        Schema::dropIfExists('files');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('display_name')->nullable();
            $table->string('email')->nullable();
            $table->string('password_hash')->nullable();
            $table->bigInteger('coins_balance')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('files', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('uploader_user_id')->nullable();
            $table->string('s3_key')->nullable();
            $table->string('mime_type')->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->timestamps();
        });

        Schema::create('peer_blocks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('blocker_user_id');
            $table->uuid('blocked_user_id');
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('p2p_meetings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('initiator_user_id');
            $table->uuid('peer_user_id');
            $table->date('meeting_date');
            $table->string('meeting_place')->nullable();
            $table->text('remarks')->nullable();
            $table->json('media')->nullable();
            $table->boolean('is_deleted')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('circle_id')->nullable();
            $table->text('content_text')->nullable();
            $table->json('media')->nullable();
            $table->json('tags')->nullable();
            $table->string('visibility')->nullable();
            $table->string('moderation_status')->nullable();
            $table->boolean('sponsored')->default(false);
            $table->boolean('is_deleted')->default(false);
            $table->string('source_type')->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('source_event')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('coins_ledger', function (Blueprint $table): void {
            $table->uuid('transaction_id')->primary();
            $table->uuid('user_id');
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->uuid('activity_id')->nullable();
            $table->string('reference')->nullable();
            $table->string('source_type', 100)->nullable();
            $table->uuid('source_id')->nullable();
            $table->text('remark')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['user_id', 'source_type', 'source_id']);
        });

        $notify = Mockery::mock(NotifyUserService::class);
        $notify->shouldReceive('notifyUser')->andReturn(null);
        $this->app->instance(NotifyUserService::class, $notify);

        Event::fake();
    }

    public function test_creator_and_peer_both_receive_p2p_meeting_coins_once(): void
    {
        [$creator, $peer] = $this->users();

        $response = $this->actingAs($creator, 'sanctum')->postJson('/api/v1/activities/p2p-meetings', [
            'peer_user_id' => $peer->id,
            'meeting_place' => 'Vadodara office - P2P discussion room',
            'remarks' => 'We discussed Peers Global Unity roadmap and possible collaboration.',
            'meeting_date' => '2026-06-11',
            'duration_minutes' => 60,
            'media_file_ids' => [],
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.coins.earned', 3000);

        $meetingId = $response->json('data.id');

        $this->assertSame(3000, (int) $creator->fresh()->coins_balance);
        $this->assertSame(3000, (int) $peer->fresh()->coins_balance);

        foreach ([$creator, $peer] as $user) {
            $this->assertDatabaseHas('coins_ledger', [
                'user_id' => $user->id,
                'amount' => 3000,
                'reference' => 'P2P Meeting Completed',
                'source_type' => 'p2p_meeting',
                'source_id' => $meetingId,
                'created_by' => $creator->id,
            ]);
        }

        $this->assertSame(2, (int) DB::table('coins_ledger')
            ->where('source_type', 'p2p_meeting')
            ->where('source_id', $meetingId)
            ->count());

        $meeting = P2pMeeting::query()->findOrFail($meetingId);
        $coinService = app(CoinsService::class);

        $coinService->rewardForActivity(
            $creator->fresh(),
            'p2p_meeting',
            null,
            'P2P Meeting Completed',
            $creator->id,
            'p2p_meeting',
            $meeting->id
        );

        $coinService->rewardForActivity(
            $peer->fresh(),
            'p2p_meeting',
            null,
            'P2P Meeting Completed',
            $creator->id,
            'p2p_meeting',
            $meeting->id
        );

        $this->assertSame(3000, (int) $creator->fresh()->coins_balance);
        $this->assertSame(3000, (int) $peer->fresh()->coins_balance);
        $this->assertSame(2, (int) DB::table('coins_ledger')
            ->where('source_type', 'p2p_meeting')
            ->where('source_id', $meetingId)
            ->count());
    }

    private function users(): array
    {
        $creator = User::query()->create([
            'id' => (string) Str::uuid(),
            'display_name' => 'Jay',
            'email' => Str::uuid() . '@example.com',
            'coins_balance' => 0,
        ]);

        $peer = User::query()->create([
            'id' => (string) Str::uuid(),
            'display_name' => 'Mohit Chavda',
            'email' => Str::uuid() . '@example.com',
            'coins_balance' => 0,
        ]);

        return [$creator, $peer];
    }
}

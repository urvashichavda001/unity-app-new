<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\P2PMeetingRequestResource;
use App\Http\Resources\P2PMeetingRescheduleRequestResource;
use App\Mail\P2PMeetingWorkflowMail;
use App\Models\Notification;
use App\Models\P2PMeetingRequest;
use App\Models\P2PMeetingRescheduleRequest;
use App\Models\Post;
use App\Models\User;
use App\Services\Blocks\PeerBlockService;
use App\Services\Coins\CoinsService;
use App\Services\Notifications\NotifyUserService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class P2PMeetingRequestController extends BaseApiController
{
    public function store(Request $request, NotifyUserService $notifyUserService, PeerBlockService $peerBlockService)
    {
        $authUser = $request->user();

        $validated = $request->validate([
            'to_user_id' => [
                'required',
                'uuid',
                'exists:users,id',
                Rule::notIn([(string) $authUser->id]),
            ],
            'scheduled_at' => ['required', 'date'],
            'place' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
        ], [
            'to_user_id.not_in' => 'You cannot send a meeting request to yourself.',
        ]);


        if ($peerBlockService->isBlockedEitherWay((string) $authUser->id, (string) $validated['to_user_id'])) {
            return $this->error('You cannot interact with this peer.', 422);
        }

        $scheduledAt = Carbon::parse($validated['scheduled_at']);
        $duplicateExists = P2PMeetingRequest::query()
            ->where('requester_id', $authUser->id)
            ->where('invitee_id', $validated['to_user_id'])
            ->where('status', 'pending')
            ->whereBetween('scheduled_at', [
                $scheduledAt->copy()->subMinutes(15),
                $scheduledAt->copy()->addMinutes(15),
            ])
            ->exists();

        if ($duplicateExists) {
            return $this->error('A similar pending meeting request already exists near this schedule time.', 422);
        }

        $meetingRequest = DB::transaction(function () use ($authUser, $validated, $scheduledAt, $notifyUserService) {
            $meetingRequest = P2PMeetingRequest::create([
                'requester_id' => $authUser->id,
                'invitee_id' => $validated['to_user_id'],
                'scheduled_at' => $scheduledAt,
                'place' => $validated['place'],
                'message' => $validated['message'] ?? null,
                'status' => 'pending',
            ]);

            $invitee = User::query()->findOrFail($validated['to_user_id']);
            $this->createMeetingNotification($invitee, 'p2p_meeting_request', $meetingRequest, $authUser);
            $this->dispatchPushNotification($notifyUserService, $invitee, $authUser, 'p2p_meeting_request', $meetingRequest);

            return $meetingRequest;
        });

        $meetingRequest->load(['requester', 'invitee']);

        return $this->success(new P2PMeetingRequestResource($meetingRequest), 'P2P meeting request created.', 201);
    }

    public function inbox(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'accepted', 'scheduled', 'reschedule_requested', 'completed', 'rejected', 'cancelled'])],
        ]);

        $query = P2PMeetingRequest::query()
            ->with('requester')
            ->where('invitee_id', $request->user()->id)
            ->orderByDesc('created_at');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $items = $query->get();

        return $this->success([
            'total' => $items->count(),
            'items' => P2PMeetingRequestResource::collection($items),
        ]);
    }

    public function sent(Request $request)
    {
        $items = P2PMeetingRequest::query()
            ->with('invitee')
            ->where('requester_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return $this->success([
            'total' => $items->count(),
            'items' => P2PMeetingRequestResource::collection($items),
        ]);
    }

    public function show(Request $request, string $id)
    {
        $meetingRequest = P2PMeetingRequest::query()
            ->with(['requester', 'invitee'])
            ->find($id);

        if (! $meetingRequest) {
            return $this->error('Meeting request not found.', 404);
        }

        if (! $this->isParticipant($meetingRequest, (string) $request->user()->id)) {
            return $this->error('Forbidden.', 403);
        }

        return $this->success(new P2PMeetingRequestResource($meetingRequest));
    }

    public function accept(Request $request, string $id, NotifyUserService $notifyUserService, PeerBlockService $peerBlockService)
    {
        return $this->respondToRequest($request, $id, 'accepted', $notifyUserService, $peerBlockService);
    }

    public function reject(Request $request, string $id, NotifyUserService $notifyUserService, PeerBlockService $peerBlockService)
    {
        return $this->respondToRequest($request, $id, 'rejected', $notifyUserService, $peerBlockService);
    }

    public function cancel(Request $request, string $id, NotifyUserService $notifyUserService, PeerBlockService $peerBlockService)
    {
        $meetingRequest = P2PMeetingRequest::query()
            ->with(['requester', 'invitee'])
            ->find($id);

        if (! $meetingRequest) {
            return $this->error('Meeting request not found.', 404);
        }

        if ((string) $meetingRequest->requester_id !== (string) $request->user()->id) {
            return $this->error('Only requester can cancel this meeting request.', 403);
        }

        if ($meetingRequest->status !== 'pending') {
            return $this->error('Only pending requests can be cancelled.', 422);
        }


        if ($peerBlockService->isBlockedEitherWay((string) $request->user()->id, (string) $meetingRequest->invitee_id)) {
            return $this->error('You cannot interact with this peer.', 422);
        }

        DB::transaction(function () use ($meetingRequest, $request, $notifyUserService) {
            $meetingRequest->update([
                'status' => 'cancelled',
                'responded_at' => now(),
            ]);

            $meetingRequest->loadMissing(['requester', 'invitee']);
            $invitee = $meetingRequest->invitee;
            $actor = $request->user();

            if ($invitee) {
                $this->createMeetingNotification($invitee, 'p2p_meeting_cancelled', $meetingRequest, $actor);
                $this->dispatchPushNotification($notifyUserService, $invitee, $actor, 'p2p_meeting_cancelled', $meetingRequest);
            }
        });

        $meetingRequest->refresh()->load(['requester', 'invitee']);

        return $this->success(new P2PMeetingRequestResource($meetingRequest), 'Meeting request cancelled successfully.');
    }

    private function respondToRequest(Request $request, string $id, string $status, NotifyUserService $notifyUserService, PeerBlockService $peerBlockService)
    {
        $meetingRequest = P2PMeetingRequest::query()
            ->with(['requester', 'invitee'])
            ->find($id);

        if (! $meetingRequest) {
            return $this->error('Meeting request not found.', 404);
        }

        if ((string) $meetingRequest->invitee_id !== (string) $request->user()->id) {
            return $this->error('Only invitee can perform this action.', 403);
        }

        if ($meetingRequest->status !== 'pending') {
            return $this->error('Only pending requests can be updated.', 422);
        }


        if ($peerBlockService->isBlockedEitherWay((string) $request->user()->id, (string) $meetingRequest->requester_id)) {
            return $this->error('You cannot interact with this peer.', 422);
        }

        DB::transaction(function () use ($meetingRequest, $status, $request, $notifyUserService) {
            $updates = [
                'status' => $status,
                'responded_at' => now(),
            ];

            if ($status === 'accepted') {
                $updates['accepted_at'] = now();
            }

            $meetingRequest->update($updates);

            $meetingRequest->loadMissing(['requester', 'invitee']);
            $requester = $meetingRequest->requester;
            $actor = $request->user();

            if ($requester) {
                $notificationType = 'p2p_meeting_' . $status;
                $this->createMeetingNotification($requester, $notificationType, $meetingRequest, $actor);
                $this->dispatchPushNotification($notifyUserService, $requester, $actor, $notificationType, $meetingRequest);
            }
        });

        $meetingRequest->refresh()->load(['requester', 'invitee']);

        return $this->success(new P2PMeetingRequestResource($meetingRequest), 'Meeting request ' . $status . ' successfully.');
    }


    public function reschedule(Request $request, string $id, NotifyUserService $notifyUserService, PeerBlockService $peerBlockService)
    {
        $authUser = $request->user();
        $validated = $request->validate([
            'new_scheduled_at' => ['required', 'date'],
            'new_place' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $meetingRequest = P2PMeetingRequest::query()
            ->with(['requester', 'invitee'])
            ->find($id);

        if (! $meetingRequest) {
            return $this->error('Meeting request not found.', 404);
        }

        if (! $this->isParticipant($meetingRequest, (string) $authUser->id)) {
            return $this->error('Forbidden.', 403);
        }

        if (! $this->canRescheduleOrComplete($meetingRequest)) {
            return $this->error('This meeting cannot be rescheduled.', 422);
        }

        $otherUserId = $this->otherParticipantId($meetingRequest, (string) $authUser->id);
        if (! $otherUserId) {
            return $this->error('Other meeting participant not found.', 422);
        }

        if ($peerBlockService->isBlockedEitherWay((string) $authUser->id, $otherUserId)) {
            return $this->error('You cannot interact with this peer.', 422);
        }

        $pendingExists = P2PMeetingRescheduleRequest::query()
            ->where('p2p_meeting_request_id', $meetingRequest->id)
            ->where('status', 'pending')
            ->exists();

        if ($pendingExists) {
            return $this->error('A pending reschedule request already exists for this meeting.', 422);
        }

        $newScheduledAt = Carbon::parse($validated['new_scheduled_at']);

        $rescheduleRequest = DB::transaction(function () use ($meetingRequest, $authUser, $otherUserId, $validated, $newScheduledAt, $notifyUserService) {
            $rescheduleRequest = P2PMeetingRescheduleRequest::query()->create([
                'p2p_meeting_request_id' => $meetingRequest->id,
                'requested_by_user_id' => $authUser->id,
                'requested_to_user_id' => $otherUserId,
                'old_scheduled_at' => $meetingRequest->scheduled_at,
                'new_scheduled_at' => $newScheduledAt,
                'old_place' => $meetingRequest->place,
                'new_place' => $validated['new_place'] ?? null,
                'reason' => $validated['reason'] ?? null,
                'status' => 'pending',
            ]);

            $meetingRequest->update(['status' => 'reschedule_requested']);
            $meetingRequest->loadMissing(['requester', 'invitee']);
            $toUser = $this->participantById($meetingRequest, $otherUserId);

            if ($toUser) {
                $this->createMeetingNotification($toUser, 'p2p_reschedule_requested', $meetingRequest, $authUser, $rescheduleRequest);
                $this->dispatchPushNotification($notifyUserService, $toUser, $authUser, 'p2p_reschedule_requested', $meetingRequest);
                $this->sendWorkflowEmail($toUser, $authUser, 'p2p_reschedule_requested', $meetingRequest, $rescheduleRequest);
            }

            return $rescheduleRequest;
        });

        $rescheduleRequest->load(['requestedBy', 'requestedTo', 'meetingRequest.requester', 'meetingRequest.invitee']);

        return $this->success(new P2PMeetingRescheduleRequestResource($rescheduleRequest), 'P2P meeting reschedule request created.', 201);
    }

    public function done(Request $request, string $id, NotifyUserService $notifyUserService, PeerBlockService $peerBlockService)
    {
        $authUser = $request->user();
        $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $meetingRequest = P2PMeetingRequest::query()
            ->with(['requester', 'invitee'])
            ->find($id);

        if (! $meetingRequest) {
            return $this->error('Meeting request not found.', 404);
        }

        if (! $this->isParticipant($meetingRequest, (string) $authUser->id)) {
            return $this->error('Forbidden.', 403);
        }

        if (! $this->canRescheduleOrComplete($meetingRequest)) {
            return $this->error('This meeting cannot be marked completed.', 422);
        }

        $otherUserId = $this->otherParticipantId($meetingRequest, (string) $authUser->id);
        if ($otherUserId && $peerBlockService->isBlockedEitherWay((string) $authUser->id, $otherUserId)) {
            return $this->error('You cannot interact with this peer.', 422);
        }

        $result = DB::transaction(function () use ($meetingRequest, $authUser, $notifyUserService) {
            $lockedMeeting = P2PMeetingRequest::query()
                ->with(['requester', 'invitee'])
                ->lockForUpdate()
                ->findOrFail($meetingRequest->id);

            if (! $this->canRescheduleOrComplete($lockedMeeting)) {
                return ['error' => 'This meeting cannot be marked completed.', 'status' => 422];
            }

            $completionColumn = (string) $lockedMeeting->requester_id === (string) $authUser->id
                ? 'completed_by_from_user_at'
                : 'completed_by_to_user_at';

            if ($lockedMeeting->{$completionColumn}) {
                return ['error' => 'You have already marked this meeting as completed.', 'status' => 422];
            }

            $lockedMeeting->forceFill([$completionColumn => now()])->save();
            $lockedMeeting->refresh()->load(['requester', 'invitee']);

            if (! $lockedMeeting->completed_by_from_user_at || ! $lockedMeeting->completed_by_to_user_at) {
                return [
                    'meeting' => $lockedMeeting,
                    'message' => 'Your meeting completion has been marked. Waiting for the other peer to confirm.',
                ];
            }

            $post = $this->createCompletionPostOnce($lockedMeeting);
            $lockedMeeting->forceFill([
                'status' => 'completed',
                'completed_at' => $lockedMeeting->completed_at ?? now(),
                'completion_post_id' => $lockedMeeting->completion_post_id ?: $post?->id,
            ])->save();

            $this->rewardCompletionCoins($lockedMeeting, $authUser);
            $lockedMeeting->refresh()->load(['requester', 'invitee']);

            foreach ([$lockedMeeting->requester, $lockedMeeting->invitee] as $recipient) {
                if (! $recipient) {
                    continue;
                }

                $this->createMeetingNotification($recipient, 'p2p_meeting_completed', $lockedMeeting, $authUser);
                $this->dispatchPushNotification($notifyUserService, $recipient, $authUser, 'p2p_meeting_completed', $lockedMeeting);
                $this->sendWorkflowEmail($recipient, $authUser, 'p2p_meeting_completed', $lockedMeeting);
            }

            return [
                'meeting' => $lockedMeeting,
                'message' => 'P2P meeting completed successfully.',
            ];
        });

        if (isset($result['error'])) {
            return $this->error($result['error'], $result['status'] ?? 422);
        }

        return $this->success(new P2PMeetingRequestResource($result['meeting']), $result['message']);
    }

    private function createMeetingNotification(User $toUser, string $type, P2PMeetingRequest $meetingRequest, User $fromUser, ?P2PMeetingRescheduleRequest $rescheduleRequest = null): void
    {
        Notification::create([
            'user_id' => $toUser->id,
            'type' => $type,
            'payload' => [
                'notification_type' => $type,
                'meeting_request_id' => (string) $meetingRequest->id,
                'scheduled_at' => $meetingRequest->scheduled_at?->toIso8601String(),
                'place' => $meetingRequest->place,
                'message' => $meetingRequest->message,
                'from_user' => $fromUser->publicProfileArray(),
                'reschedule_request_id' => $rescheduleRequest ? (string) $rescheduleRequest->id : null,
                'new_scheduled_at' => $rescheduleRequest?->new_scheduled_at?->toIso8601String(),
                'new_place' => $rescheduleRequest?->new_place,
            ],
            'is_read' => false,
            'created_at' => now(),
            'read_at' => null,
        ]);
    }

    private function dispatchPushNotification(
        NotifyUserService $notifyUserService,
        User $toUser,
        User $fromUser,
        string $notificationType,
        P2PMeetingRequest $meetingRequest
    ): void {
        $notifyUserService->notifyUser(
            $toUser,
            $fromUser,
            $notificationType,
            [
                'title' => 'P2P Meeting Update',
                'body' => 'You have a new P2P meeting update.',
                'meeting_request_id' => (string) $meetingRequest->id,
                'scheduled_at' => $meetingRequest->scheduled_at?->toIso8601String(),
                'place' => $meetingRequest->place,
            ],
            $meetingRequest
        );
    }


    private function canRescheduleOrComplete(P2PMeetingRequest $meetingRequest): bool
    {
        return in_array((string) $meetingRequest->status, ['accepted', 'scheduled'], true)
            && ! $meetingRequest->completed_at;
    }

    private function otherParticipantId(P2PMeetingRequest $meetingRequest, string $authUserId): ?string
    {
        if ((string) $meetingRequest->requester_id === $authUserId) {
            return (string) $meetingRequest->invitee_id;
        }

        if ((string) $meetingRequest->invitee_id === $authUserId) {
            return (string) $meetingRequest->requester_id;
        }

        return null;
    }

    private function participantById(P2PMeetingRequest $meetingRequest, string $userId): ?User
    {
        $meetingRequest->loadMissing(['requester', 'invitee']);

        if ((string) $meetingRequest->requester_id === $userId) {
            return $meetingRequest->requester;
        }

        if ((string) $meetingRequest->invitee_id === $userId) {
            return $meetingRequest->invitee;
        }

        return null;
    }

    private function createCompletionPostOnce(P2PMeetingRequest $meetingRequest): ?Post
    {
        if ($meetingRequest->completion_post_id) {
            return Post::query()->find($meetingRequest->completion_post_id);
        }

        $existingPost = Post::query()
            ->where('source_type', 'p2p_meeting_completed')
            ->where('source_id', $meetingRequest->id)
            ->where('source_event', 'p2p_meeting_completed')
            ->first();

        if ($existingPost) {
            return $existingPost;
        }

        $meetingRequest->loadMissing(['requester', 'invitee']);
        $requesterName = $this->resolveDisplayName($meetingRequest->requester);
        $inviteeName = $this->resolveDisplayName($meetingRequest->invitee);

        return Post::query()->create([
            'user_id' => $meetingRequest->requester_id,
            'circle_id' => null,
            'content_text' => "{$requesterName} completed a P2P meeting with {$inviteeName}.",
            'media' => [],
            'tags' => ['p2p_meeting', 'p2p_meeting_completed'],
            'visibility' => 'public',
            'moderation_status' => 'pending',
            'sponsored' => false,
            'is_deleted' => false,
            'active' => true,
            'source_type' => 'p2p_meeting_completed',
            'source_id' => $meetingRequest->id,
            'source_event' => 'p2p_meeting_completed',
        ]);
    }

    private function rewardCompletionCoins(P2PMeetingRequest $meetingRequest, User $actor): void
    {
        $coinsService = app(CoinsService::class);

        foreach ([$meetingRequest->requester, $meetingRequest->invitee] as $user) {
            if (! $user) {
                continue;
            }

            $coinsService->rewardForActivity(
                $user,
                'p2p_meeting',
                null,
                'P2P Meeting Completed',
                $actor->id,
                'p2p_meeting_request',
                $meetingRequest->id
            );
        }
    }

    private function sendWorkflowEmail(User $recipient, User $actor, string $eventType, P2PMeetingRequest $meetingRequest, ?P2PMeetingRescheduleRequest $rescheduleRequest = null, ?string $responseReason = null): void
    {
        $email = trim((string) $recipient->email);

        if ($email === '') {
            return;
        }

        try {
            Mail::to($email)->send(new P2PMeetingWorkflowMail($eventType, $meetingRequest, $recipient, $actor, $rescheduleRequest, $responseReason));
        } catch (\Throwable $exception) {
            Log::error('P2P meeting workflow email failed', [
                'event_type' => $eventType,
                'meeting_request_id' => (string) $meetingRequest->id,
                'recipient_id' => (string) $recipient->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function isParticipant(P2PMeetingRequest $meetingRequest, string $authUserId): bool
    {
        return (string) $meetingRequest->requester_id === $authUserId
            || (string) $meetingRequest->invitee_id === $authUserId;
    }
}

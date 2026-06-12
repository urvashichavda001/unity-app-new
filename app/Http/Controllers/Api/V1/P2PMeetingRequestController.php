<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\P2PMeetingRequestResource;
use App\Mail\P2PMeetingWorkflowMail;
use App\Models\Notification;
use App\Models\P2PMeetingRequest;
use App\Models\P2PMeetingRescheduleRequest;
use App\Models\User;
use App\Services\Blocks\PeerBlockService;
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
            'status' => ['nullable', Rule::in(['pending', 'accepted', 'scheduled', 'reschedule_requested', 'rejected', 'cancelled'])],
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
            $meetingRequest->update([
                'status' => $status,
                'responded_at' => now(),
            ]);

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


    public function requestReschedule(Request $request, string $id, NotifyUserService $notifyUserService, PeerBlockService $peerBlockService)
    {
        $authUser = $request->user();
        $validated = $request->validate([
            'new_scheduled_at' => ['required', 'date', 'after:now'],
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
            return $this->error('You are not allowed to reschedule this meeting.', 403);
        }

        $status = $this->normalizedMeetingStatus($meetingRequest);

        if ($status === 'reschedule_requested') {
            return $this->error('A pending reschedule request already exists for this meeting.', 422);
        }

        $pendingExists = P2PMeetingRescheduleRequest::query()
            ->where('p2p_meeting_request_id', $meetingRequest->id)
            ->whereRaw('LOWER(status) = ?', ['pending'])
            ->exists();

        if ($pendingExists) {
            return $this->error('A pending reschedule request already exists for this meeting.', 422);
        }

        if ($status === 'pending' && (string) $meetingRequest->requester_id === (string) $authUser->id) {
            return $this->error('Only the invitee can request reschedule before accepting the meeting.', 422);
        }

        if (in_array($status, ['rejected', 'cancelled', 'completed'], true)) {
            return $this->error("This meeting cannot be rescheduled because it is already {$status}.", 422);
        }

        if (! $this->canRequestReschedule($meetingRequest, (string) $authUser->id, $status)) {
            return $this->error('This meeting cannot be rescheduled.', 422);
        }

        $otherUserId = $this->otherParticipantId($meetingRequest, (string) $authUser->id);
        if (! $otherUserId) {
            return $this->error('Other meeting participant not found.', 422);
        }

        if ($peerBlockService->isBlockedEitherWay((string) $authUser->id, $otherUserId)) {
            return $this->error('You cannot interact with this peer.', 422);
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

        return $this->success([
            'reschedule_request_id' => (string) $rescheduleRequest->id,
            'p2p_meeting_request_id' => (string) $rescheduleRequest->p2p_meeting_request_id,
            'old_scheduled_at' => $rescheduleRequest->old_scheduled_at?->toIso8601String(),
            'new_scheduled_at' => $rescheduleRequest->new_scheduled_at?->toIso8601String(),
            'old_place' => $rescheduleRequest->old_place,
            'new_place' => $rescheduleRequest->new_place,
            'reason' => $rescheduleRequest->reason,
            'status' => (string) $rescheduleRequest->status,
            'requested_by_user_id' => (string) $rescheduleRequest->requested_by_user_id,
            'requested_to_user_id' => (string) $rescheduleRequest->requested_to_user_id,
        ], 'P2P meeting reschedule request sent successfully.', 201);
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


    private function canRequestReschedule(P2PMeetingRequest $meetingRequest, string $authUserId, ?string $status = null): bool
    {
        $status ??= $this->normalizedMeetingStatus($meetingRequest);

        if (in_array($status, ['accepted', 'scheduled', 'reschedule_rejected'], true)) {
            return true;
        }

        return $status === 'pending'
            && (string) $meetingRequest->invitee_id === $authUserId;
    }

    private function normalizedMeetingStatus(P2PMeetingRequest $meetingRequest): string
    {
        return strtolower(trim((string) $meetingRequest->status));
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

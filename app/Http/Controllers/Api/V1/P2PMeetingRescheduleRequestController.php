<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\P2PMeetingRequestResource;
use App\Http\Resources\P2PMeetingRescheduleRequestResource;
use App\Mail\P2PMeetingWorkflowMail;
use App\Models\Notification;
use App\Models\P2PMeetingRequest;
use App\Models\P2PMeetingRescheduleRequest;
use App\Models\User;
use App\Services\Notifications\NotifyUserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class P2PMeetingRescheduleRequestController extends BaseApiController
{
    public function pendingReceived(Request $request)
    {
        $items = P2PMeetingRescheduleRequest::query()
            ->with(['requestedBy', 'requestedTo', 'meetingRequest.requester', 'meetingRequest.invitee'])
            ->where('requested_to_user_id', $request->user()->id)
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->get();

        return $this->success([
            'total' => $items->count(),
            'items' => P2PMeetingRescheduleRequestResource::collection($items),
        ]);
    }

    public function approve(Request $request, string $id, NotifyUserService $notifyUserService)
    {
        $authUser = $request->user();

        $result = DB::transaction(function () use ($id, $authUser, $notifyUserService) {
            $rescheduleRequest = P2PMeetingRescheduleRequest::query()
                ->with(['requestedBy', 'requestedTo', 'meetingRequest.requester', 'meetingRequest.invitee'])
                ->lockForUpdate()
                ->find($id);

            if (! $rescheduleRequest) {
                return ['error' => 'Reschedule request not found.', 'status' => 404];
            }

            if ((string) $rescheduleRequest->requested_to_user_id !== (string) $authUser->id) {
                return ['error' => 'Only the requested peer can approve this reschedule request.', 'status' => 403];
            }

            if ($rescheduleRequest->status !== 'pending') {
                return ['error' => 'Only pending reschedule requests can be approved.', 'status' => 422];
            }

            $meetingRequest = P2PMeetingRequest::query()
                ->with(['requester', 'invitee'])
                ->lockForUpdate()
                ->findOrFail($rescheduleRequest->p2p_meeting_request_id);

            if (in_array((string) $meetingRequest->status, ['completed', 'cancelled', 'rejected'], true)) {
                return ['error' => 'This meeting cannot be rescheduled.', 'status' => 422];
            }

            $rescheduleRequest->update([
                'status' => 'approved',
                'approved_at' => now(),
                'responded_by_user_id' => $authUser->id,
            ]);

            $meetingRequest->update([
                'scheduled_at' => $rescheduleRequest->new_scheduled_at,
                'place' => $rescheduleRequest->new_place ?? $meetingRequest->place,
                'status' => 'scheduled',
            ]);

            $meetingRequest->refresh()->load(['requester', 'invitee']);
            $rescheduleRequest->refresh()->load(['requestedBy', 'requestedTo', 'meetingRequest.requester', 'meetingRequest.invitee']);
            $requester = $rescheduleRequest->requestedBy;

            if ($requester) {
                $this->createMeetingNotification($requester, 'p2p_reschedule_approved', $meetingRequest, $authUser, $rescheduleRequest);
                $this->dispatchPushNotification($notifyUserService, $requester, $authUser, 'p2p_reschedule_approved', $meetingRequest);
                $this->sendWorkflowEmail($requester, $authUser, 'p2p_reschedule_approved', $meetingRequest, $rescheduleRequest);
            }

            return ['meeting' => $meetingRequest];
        });

        if (isset($result['error'])) {
            return $this->error($result['error'], $result['status'] ?? 422);
        }

        return $this->success(new P2PMeetingRequestResource($result['meeting']), 'P2P meeting reschedule request approved.');
    }

    public function reject(Request $request, string $id, NotifyUserService $notifyUserService)
    {
        $authUser = $request->user();
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = DB::transaction(function () use ($id, $authUser, $validated, $notifyUserService) {
            $rescheduleRequest = P2PMeetingRescheduleRequest::query()
                ->with(['requestedBy', 'requestedTo', 'meetingRequest.requester', 'meetingRequest.invitee'])
                ->lockForUpdate()
                ->find($id);

            if (! $rescheduleRequest) {
                return ['error' => 'Reschedule request not found.', 'status' => 404];
            }

            if ((string) $rescheduleRequest->requested_to_user_id !== (string) $authUser->id) {
                return ['error' => 'Only the requested peer can reject this reschedule request.', 'status' => 403];
            }

            if ($rescheduleRequest->status !== 'pending') {
                return ['error' => 'Only pending reschedule requests can be rejected.', 'status' => 422];
            }

            $meetingRequest = P2PMeetingRequest::query()
                ->with(['requester', 'invitee'])
                ->lockForUpdate()
                ->findOrFail($rescheduleRequest->p2p_meeting_request_id);

            $rescheduleRequest->update([
                'status' => 'rejected',
                'rejected_at' => now(),
                'responded_by_user_id' => $authUser->id,
            ]);

            if (! in_array((string) $meetingRequest->status, ['completed', 'cancelled', 'rejected'], true)) {
                $meetingRequest->update(['status' => 'scheduled']);
            }

            $meetingRequest->refresh()->load(['requester', 'invitee']);
            $rescheduleRequest->refresh()->load(['requestedBy', 'requestedTo', 'meetingRequest.requester', 'meetingRequest.invitee']);
            $requester = $rescheduleRequest->requestedBy;

            if ($requester) {
                $this->createMeetingNotification($requester, 'p2p_reschedule_rejected', $meetingRequest, $authUser, $rescheduleRequest, $validated['reason'] ?? null);
                $this->dispatchPushNotification($notifyUserService, $requester, $authUser, 'p2p_reschedule_rejected', $meetingRequest);
                $this->sendWorkflowEmail($requester, $authUser, 'p2p_reschedule_rejected', $meetingRequest, $rescheduleRequest, $validated['reason'] ?? null);
            }

            return ['reschedule_request' => $rescheduleRequest];
        });

        if (isset($result['error'])) {
            return $this->error($result['error'], $result['status'] ?? 422);
        }

        return $this->success(new P2PMeetingRescheduleRequestResource($result['reschedule_request']), 'P2P meeting reschedule request rejected.');
    }

    private function createMeetingNotification(User $toUser, string $type, P2PMeetingRequest $meetingRequest, User $fromUser, ?P2PMeetingRescheduleRequest $rescheduleRequest = null, ?string $responseReason = null): void
    {
        Notification::query()->create([
            'user_id' => $toUser->id,
            'type' => $type,
            'payload' => [
                'notification_type' => $type,
                'meeting_request_id' => (string) $meetingRequest->id,
                'reschedule_request_id' => $rescheduleRequest ? (string) $rescheduleRequest->id : null,
                'scheduled_at' => $meetingRequest->scheduled_at?->toIso8601String(),
                'place' => $meetingRequest->place,
                'new_scheduled_at' => $rescheduleRequest?->new_scheduled_at?->toIso8601String(),
                'new_place' => $rescheduleRequest?->new_place,
                'response_reason' => $responseReason,
                'from_user' => $fromUser->publicProfileArray(),
            ],
            'is_read' => false,
            'created_at' => now(),
            'read_at' => null,
        ]);
    }

    private function dispatchPushNotification(NotifyUserService $notifyUserService, User $toUser, User $fromUser, string $notificationType, P2PMeetingRequest $meetingRequest): void
    {
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
}

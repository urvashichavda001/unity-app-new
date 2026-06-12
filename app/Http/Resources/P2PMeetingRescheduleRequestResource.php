<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class P2PMeetingRescheduleRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['requestedBy', 'requestedTo', 'respondedBy', 'meetingRequest.requester', 'meetingRequest.invitee']);

        return [
            'id' => (string) $this->id,
            'p2p_meeting_request_id' => (string) $this->p2p_meeting_request_id,
            'status' => (string) $this->status,
            'old_scheduled_at' => $this->old_scheduled_at?->toIso8601String(),
            'new_scheduled_at' => $this->new_scheduled_at?->toIso8601String(),
            'old_place' => $this->old_place,
            'new_place' => $this->new_place,
            'reason' => $this->reason,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'requested_by' => $this->requestedBy?->publicProfileArray(),
            'requested_to' => $this->requestedTo?->publicProfileArray(),
            'responded_by' => $this->respondedBy?->publicProfileArray(),
            'meeting' => $this->meetingRequest ? new P2PMeetingRequestResource($this->meetingRequest) : null,
        ];
    }
}

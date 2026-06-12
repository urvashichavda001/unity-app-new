<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class P2PMeetingRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['requester', 'invitee']);

        return [
            'id' => (string) $this->id,
            'status' => (string) $this->status,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'place' => (string) $this->place,
            'message' => $this->message,
            'responded_at' => $this->responded_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'completed_by_from_user_at' => $this->completed_by_from_user_at?->toIso8601String(),
            'completed_by_to_user_at' => $this->completed_by_to_user_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'completion_post_id' => $this->completion_post_id ? (string) $this->completion_post_id : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'requester' => $this->requester?->publicProfileArray(),
            'invitee' => $this->invitee?->publicProfileArray(),
        ];
    }
}

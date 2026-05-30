<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    public function toArray($request)
    {
        $authUser = $request->user();

        $stats = [
            'going' => 0,
            'interested' => 0,
            'not_going' => 0,
            'waitlisted' => 0,
            'checked_in' => 0,
        ];

        $rsvpForMe = null;
        $isCheckedInForMe = false;

        if ($this->relationLoaded('rsvps')) {
            foreach ($this->rsvps as $rsvp) {
                $status = $rsvp->status ?? null;
                if ($status && array_key_exists($status, $stats)) {
                    $stats[$status]++;
                }

                if ($rsvp->checked_in) {
                    $stats['checked_in']++;
                }

                if ($authUser && $rsvp->user_id === $authUser->id) {
                    $rsvpForMe = $rsvp->status;
                    $isCheckedInForMe = (bool) $rsvp->checked_in;
                }
            }
        }

        $metadata = is_string($this->metadata) ? json_decode($this->metadata, true) : $this->metadata;
        $metadata = is_array($metadata) ? $metadata : [];

        return [
            'id' => $this->id,
            'circle_id' => $this->circle_id,
            'created_by_user_id' => $this->created_by_user_id,
            'title' => $this->title,
            'description' => $this->description,
            'start_at' => $this->start_at,
            'end_at' => $this->end_at,
            'is_virtual' => (bool) $this->is_virtual,
            'location_text' => $this->location_text,
            'agenda' => $this->agenda,
            'speakers' => $this->speakers,
            'banner_url' => $this->banner_url,
            'what_youll_gain' => array_values((array) data_get($metadata, 'what_youll_gain', [])),
            'organizer' => data_get($metadata, 'organizer'),
            'visibility' => $this->visibility,
            'is_paid' => (bool) $this->is_paid,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'circle' => $this->whenLoaded('circle', function () {
                return [
                    'id' => $this->circle->id,
                    'name' => $this->circle->name,
                    'slug' => $this->circle->slug,
                ];
            }),
            'created_by' => $this->whenLoaded('createdByUser', function () {
                return [
                    'id' => $this->createdByUser->id,
                    'display_name' => $this->createdByUser->display_name,
                    'first_name' => $this->createdByUser->first_name,
                    'last_name' => $this->createdByUser->last_name,
                    'profile_photo_url' => $this->createdByUser->profile_photo_url,
                ];
            }),
            'rsvp_status_for_me' => $this->when($authUser, $rsvpForMe),
            'is_checked_in_for_me' => $this->when($authUser, $isCheckedInForMe),
            'stats' => $stats,
        ];
    }
}

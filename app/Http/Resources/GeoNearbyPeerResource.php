<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class GeoNearbyPeerResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->display_name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'company_name' => $this->company_name,
            'designation' => $this->designation,
            'business_type' => $this->business_type,
            'profile_photo_url' => $this->resolveProfilePhotoUrl(),
            'city' => $this->resolveCity(),
            'location' => $this->resolveLocation(),
            'distance_km' => round((float) $this->distance_km, 2),
            'last_seen_at' => $this->geo_last_seen_at,
            'connection_status' => $this->connection_status,
            'can_send_connection_request' => (bool) ($this->can_send_connection_request ?? true),
        ];
    }

    private function resolveProfilePhotoUrl(): ?string
    {
        return $this->profile_photo_file_id
            ? url('/api/v1/files/' . $this->profile_photo_file_id)
            : null;
    }

    private function resolveLocation(): array
    {
        return [
            'latitude' => (float) $this->geo_latitude,
            'longitude' => (float) $this->geo_longitude,
        ];
    }

    private function resolveCity(): ?array
    {
        $city = $this->relationLoaded('cityRelation')
            ? $this->getRelationValue('cityRelation')
            : null;

        if ($city) {
            return [
                'id' => $city->id,
                'name' => $city->name,
            ];
        }

        $rawCity = $this->city;

        if (is_array($rawCity)) {
            return [
                'id' => $rawCity['id'] ?? null,
                'name' => $rawCity['name'] ?? null,
            ];
        }

        if (is_object($rawCity)) {
            return [
                'id' => $rawCity->id ?? null,
                'name' => $rawCity->name ?? null,
            ];
        }

        if (is_string($rawCity) && trim($rawCity) !== '') {
            return [
                'id' => null,
                'name' => $rawCity,
            ];
        }

        return null;
    }
}

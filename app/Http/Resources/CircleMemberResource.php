<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CircleMemberResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'circle_id' => $this->circle_id,
            'role' => $this->role,
            'status' => $this->status,
            'joined_at' => $this->joined_at,
            'left_at' => $this->left_at,
            'substitute_count' => $this->substitute_count,
            'role_id' => $this->role_id,

            'user' => $this->whenLoaded('user', function () {
                $user = $this->user;
                $city = $user?->relationLoaded('city')
                    ? $user?->city
                    : ($user?->relationLoaded('cityRelation') ? $user?->cityRelation : null);
                $businessCategory = $user?->relationLoaded('businessCategory')
                    ? $user?->businessCategory
                    : null;
                $photoFileId = data_get($user, 'profile_photo_file_id')
                    ?: data_get($user, 'image_file_id')
                    ?: data_get($user, 'avatar_file_id')
                    ?: data_get($user, 'profile_image_file_id')
                    ?: data_get($user, 'photo_file_id')
                    ?: data_get($user, 'profile_file_id');

                $name = $user?->name
                    ?? $user?->display_name
                    ?? trim(($user?->first_name ?? '') . ' ' . ($user?->last_name ?? ''))
                    ?: $user?->email;

                return [
                    'id' => $user?->id,
                    'name' => $name,
                    'email' => $user?->email,
                    'phone' => $user?->phone ?? null,
                    'country_code' => $user?->country_code ?? null,
                    'city_id' => $user?->city_id,
                    'city_name' => $city?->name,
                    'city' => $city?->name,
                    'business_category_id' => $user?->business_category_id,
                    'business_category_name' => $businessCategory?->name,
                    'business_category' => $businessCategory?->name,
                    'business_sub_category' => $user?->business_sub_category,
                    'membership_status' => $user?->membership_status ?? null,
                    'is_active' => $user?->is_active ?? null,
                    'profile_photo_file_id' => $photoFileId,
                    'profile_photo_url' => $photoFileId
                        ? url("/api/v1/files/{$photoFileId}")
                        : null,
                    'designation' => $user?->designation ?? null,
                    'company_name' => $user?->company_name ?? null,
                    'created_at' => optional($user?->created_at)->toISOString(),
                ];
            }),

            'role_details' => $this->whenLoaded('roleModel', function () {
                return [
                    'id' => $this->roleModel->id,
                    'name' => $this->roleModel->name ?? null,
                    'slug' => $this->roleModel->slug ?? null,
                ];
            }),
        ];
    }
}

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
                $cityName = $this->resolveCityName($user);
                $categoryName = $this->resolveBusinessCategoryName($user);
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
                    'city_name' => $cityName,
                    'city' => $cityName,
                    'business_category_id' => $user?->business_category_id,
                    'business_category_name' => $categoryName,
                    'business_category' => $categoryName,
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

    private function resolveCityName($user): ?string
    {
        if (! $user) {
            return null;
        }

        $cityRelation = $user->relationLoaded('city')
            ? $user->getRelationValue('city')
            : ($user->relationLoaded('cityRelation') ? $user->getRelationValue('cityRelation') : null);

        if (is_object($cityRelation)) {
            return $cityRelation->name ?? null;
        }

        $city = $user->getAttribute('city');

        if (is_object($city)) {
            return $city->name ?? null;
        }

        if (is_string($city) && $city !== '') {
            return $city;
        }

        $cityName = $user->getAttribute('city_name');

        return is_string($cityName) && $cityName !== '' ? $cityName : null;
    }

    private function resolveBusinessCategoryName($user): ?string
    {
        if (! $user) {
            return null;
        }

        $businessCategoryRelation = $user->relationLoaded('businessCategory')
            ? $user->getRelationValue('businessCategory')
            : null;

        if (is_object($businessCategoryRelation)) {
            return $businessCategoryRelation->name ?? null;
        }

        $businessCategory = $user->getAttribute('business_category');

        if (is_object($businessCategory)) {
            return $businessCategory->name ?? null;
        }

        if (is_string($businessCategory) && $businessCategory !== '') {
            return $businessCategory;
        }

        $businessCategoryName = $user->getAttribute('business_category_name');

        return is_string($businessCategoryName) && $businessCategoryName !== '' ? $businessCategoryName : null;
    }
}

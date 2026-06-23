<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\MyLeadershipCircleResource;
use App\Models\CircleMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CircleLeadershipController extends BaseApiController
{
    public function myLeadershipCircles(Request $request): JsonResponse
    {
        $allowedRoles = CircleMember::LEADERSHIP_ROLE_OPTIONS;

        $members = CircleMember::query()
            ->with(['circle.coverFile', 'roleModel'])
            ->where('user_id', $request->user()->id)
            ->whereNull('left_at')
            ->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhereIn(DB::raw('LOWER(circle_members.status::text)'), CircleMember::activeStatuses());
            })
            ->whereHas('circle')
            ->orderBy('joined_at')
            ->orderBy('created_at')
            ->get()
            ->map(function (CircleMember $member): CircleMember {
                $member->setAttribute('resolved_role_slug', $this->resolveRoleSlug($member));

                return $member;
            })
            ->filter(fn (CircleMember $member): bool => in_array($member->getAttribute('resolved_role_slug'), $allowedRoles, true))
            ->values();

        return $this->success([
            'total' => $members->count(),
            'items' => MyLeadershipCircleResource::collection($members),
        ], 'My leadership circles fetched successfully.');
    }

    private function resolveRoleSlug(CircleMember $member): ?string
    {
        $role = $member->relationLoaded('roleModel') ? $member->roleModel : null;

        return $this->normalizeRoleSlug(
            $role?->slug
            ?? $role?->name
            ?? $member->role
        );
    }

    private function normalizeRoleSlug(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $slug = Str::of($value)
            ->lower()
            ->trim()
            ->replace(['-', ' '], '_')
            ->replaceMatches('/_+/', '_')
            ->trim('_')
            ->toString();

        if ($slug === 'circle_member') {
            return 'member';
        }

        if (str_starts_with($slug, 'circle_')) {
            $withoutPrefix = Str::after($slug, 'circle_');

            return in_array($withoutPrefix, CircleMember::LEADERSHIP_ROLE_OPTIONS, true)
                ? $withoutPrefix
                : $slug;
        }

        return $slug;
    }
}

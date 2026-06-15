<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Circle;
use App\Models\CircleJoinRequest;
use App\Models\CircleMember;
use App\Services\Admin\AdminAuditService;
use App\Services\Admin\AdminScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CircleManagementController extends BaseApiController
{
    public function __construct(private readonly AdminScopeService $scope, private readonly AdminAuditService $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $q = Circle::query()->with('cityRef')->withCount('members');
        if ($request->filled('state_name')) {
            $state = $request->input('state_name');
            $q->where(function ($query) use ($state): void {
                $hasCircleState = Schema::hasColumn('circles', 'state');
                $hasCircleStateName = Schema::hasColumn('circles', 'state_name');
                $hasCityState = Schema::hasColumn('cities', 'state');
                $hasCityStateName = Schema::hasColumn('cities', 'state_name');
                if ($hasCircleState) {
                    $query->where('state', $state);
                }
                if ($hasCircleStateName) {
                    ($hasCircleState ? $query->orWhere('state_name', $state) : $query->where('state_name', $state));
                }
                if ($hasCityState || $hasCityStateName) {
                    $method = ($hasCircleState || $hasCircleStateName) ? 'orWhereHas' : 'whereHas';
                    $query->{$method}('cityRef', function ($cityQuery) use ($state, $hasCityState, $hasCityStateName): void {
                        if ($hasCityState) {
                            $cityQuery->where('state', $state);
                        }
                        if ($hasCityStateName) {
                            ($hasCityState ? $cityQuery->orWhere('state_name', $state) : $cityQuery->where('state_name', $state));
                        }
                    });
                }
            });
        }
        $this->scope->applyCircleScope($q, $request->user());
        if ($request->filled('state_name')) {
            return $this->success($q->orderBy('name')->get()->map(fn (Circle $circle) => [
                'id' => $circle->id,
                'name' => $circle->name,
                'state_name' => $circle->state_name ?? $circle->state ?? $circle->cityRef?->state_name ?? $circle->cityRef?->state ?? null,
            ])->values(), 'Circles fetched successfully.');
        }

        return $this->success($q->paginate((int) $request->input('per_page', 20)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string'], 'industry_id' => ['nullable', 'uuid'], 'founder_user_id' => ['nullable', 'uuid', 'exists:users,id'], 'status' => ['nullable', 'string']]);
        $circle = Circle::query()->create($data);
        return $this->success($circle);
    }

    public function show(string $id): JsonResponse { return $this->success(Circle::query()->with(['members'])->findOrFail($id)); }

    public function update(Request $request, string $id): JsonResponse
    {
        $circle = Circle::query()->findOrFail($id);
        $circle->fill($request->only(['name','description','industry_id','status','founder_user_id','director_user_id','industry_director_user_id','ded_user_id']))->save();
        return $this->success($circle);
    }

    public function patchStatus(Request $request, string $id): JsonResponse
    {
        $request->validate(['status' => ['required', 'string']]);
        return $this->update($request, $id);
    }

    public function assignFounder(Request $request, string $id): JsonResponse { $request->merge(['founder_user_id' => $request->validate(['user_id'=>'required|uuid|exists:users,id'])['user_id']]); return $this->update($request, $id); }
    public function assignDirector(Request $request, string $id): JsonResponse { $request->merge(['director_user_id' => $request->validate(['user_id'=>'required|uuid|exists:users,id'])['user_id']]); return $this->update($request, $id); }

    public function assignLeadershipTeam(Request $request, string $id): JsonResponse
    {
        $v = $request->validate([
            'chair_user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'vice_chair_user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'secretary_user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'powerhouse_user_id' => ['nullable', 'uuid', 'exists:users,id'],
        ]);

        foreach (['chair_user_id' => 'chair', 'vice_chair_user_id' => 'vice_chair', 'secretary_user_id' => 'secretary', 'powerhouse_user_id' => 'committee_leader'] as $key => $role) {
            if (! empty($v[$key])) {
                CircleMember::query()->updateOrCreate(['circle_id' => $id, 'user_id' => $v[$key]], ['role' => $role, 'status' => 'approved', 'joined_at' => now(), 'deleted_at' => null]);
            }
        }

        return $this->success(['assigned' => true]);
    }

    public function joinRequests(string $id): JsonResponse { return $this->success(CircleJoinRequest::query()->where('circle_id', $id)->paginate(20)); }
    public function members(string $id): JsonResponse { return $this->success(CircleMember::query()->with('user:id,display_name,membership_status,life_impacted_count,coins_balance')->where('circle_id',$id)->whereNull('deleted_at')->paginate(20)); }

    public function addMember(Request $request, string $id): JsonResponse
    {
        $v = $request->validate(['user_id' => ['required','uuid','exists:users,id'], 'role' => ['nullable','string']]);
        $member = CircleMember::query()->firstOrCreate(['circle_id' => $id, 'user_id' => $v['user_id']], ['id' => (string) Str::uuid(), 'status' => 'approved', 'joined_at' => now(), 'role' => $v['role'] ?? 'member']);
        if ($member->deleted_at) {
            $member->deleted_at = null;
            $member->status = 'approved';
            $member->save();
        }
        return $this->success($member);
    }

    public function removeMember(string $id, string $userId): JsonResponse
    {
        CircleMember::query()->where('circle_id', $id)->where('user_id', $userId)->update(['deleted_at' => now(), 'status' => 'inactive']);
        return $this->success(['removed' => true]);
    }

    public function health(string $id): JsonResponse
    {
        $memberCount = CircleMember::query()->where('circle_id', $id)->where('status', 'approved')->whereNull('deleted_at')->count();
        $impactTotal = \App\Models\Impact::query()->where('circle_id', $id)->where('status', 'approved')->sum(DB::raw('COALESCE(impact_score,impact_value,1)'));
        $revenue = \App\Models\Payment::query()->where('circle_id', $id)->where('status', 'paid')->sum('amount');

        return $this->success([
            'current_members' => $memberCount,
            'target_members' => null,
            'avg_attendance' => null,
            'visitor_count' => (int) Circle::query()->where('id', $id)->value('visitor_count'),
            'conversions' => null,
            'renewals' => null,
            'total_impact' => (int) $impactTotal,
            'revenue' => (float) $revenue,
            'latest_health_log' => null,
        ]);
    }

    public function performance(string $id): JsonResponse
    {
        return $this->success([
            'founder' => Circle::query()->where('id', $id)->value('founder_user_id'),
            'director' => Circle::query()->where('id', $id)->value('director_user_id'),
            'leadership' => CircleMember::query()->where('circle_id', $id)->whereIn(DB::raw('role::text'), ['chair', 'vice_chair', 'secretary', 'committee_leader'])->whereNull('deleted_at')->get(),
        ]);
    }

    public function patchPackage(Request $request, string $id): JsonResponse
    {
        $circle = Circle::query()->findOrFail($id);
        $circle->fill($request->only(['zoho_addon_code','zoho_addon_id','zoho_addon_name','circle_price_amount','circle_price_currency','circle_duration_months']))->save();
        return $this->success($circle);
    }
}

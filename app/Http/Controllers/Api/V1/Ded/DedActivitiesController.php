<?php

namespace App\Http\Controllers\Api\V1\Ded;

use App\Models\BusinessDeal;
use App\Models\LeaderInterestSubmission;
use App\Models\PeerRecommendation;
use App\Models\Referral;
use App\Models\Requirement;
use App\Models\Testimonial;
use App\Models\User;
use App\Models\VisitorRegistration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DedActivitiesController extends DedBaseController
{
    public function summary(Request $request)
    {
        $request->validate(['circle_id' => ['nullable', 'string'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date'], 'search' => ['nullable', 'string'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $admin = $this->admin($request);
        $users = $this->ded->usersQuery($admin)->select(['users.id', 'display_name', 'first_name', 'last_name', 'company_name', 'city']);
        $this->ded->applyCircleFilter($users, $admin, $request->query('circle_id'), ['users.id']);
        if ($request->filled('search')) {
            $like = '%'.$request->query('search').'%';
            $users->where(fn ($q) => $q->where('display_name', 'ILIKE', $like)->orWhere('company_name', 'ILIKE', $like)->orWhere('city', 'ILIKE', $like));
        }
        $items = $users->paginate($this->ded->perPage($request))->withQueryString();
        $from = $request->query('date_from'); $to = $request->query('date_to');
        $rows = collect($items->items())->map(function ($user) use ($from, $to) {
            $date = function ($q, $column = 'created_at') use ($from, $to) { if ($from) $q->whereDate($column, '>=', $from); if ($to) $q->whereDate($column, '<=', $to); };
            $testimonials = Testimonial::query()->where(fn ($q) => $q->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id)); $date($testimonials);
            $referrals = Referral::query()->where(fn ($q) => $q->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id)); $date($referrals, 'referral_date');
            $requirements = Requirement::query()->where('user_id', $user->id); $date($requirements);
            $deals = BusinessDeal::query()->where(fn ($q) => $q->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id)); $date($deals, 'deal_date');
            $p2p = \App\Models\P2PMeetingRequest::query()->where(fn ($q) => $q->where('requester_id', $user->id)->orWhere('invitee_id', $user->id)); $date($p2p, 'scheduled_at');
            $leader = LeaderInterestSubmission::query()->where('user_id', $user->id); $date($leader);
            $recommend = PeerRecommendation::query()->where('user_id', $user->id); $date($recommend);
            $visitors = VisitorRegistration::query()->where('user_id', $user->id); $date($visitors);
            $score = $testimonials->count() + $referrals->count() + $requirements->count() + $deals->count() + $p2p->count() + $leader->count() + $recommend->count() + $visitors->count();
            return ['peer' => $user, 'testimonials_count' => $testimonials->count(), 'referrals_count' => $referrals->count(), 'requirements_count' => $requirements->count(), 'business_deals_count' => $deals->count(), 'p2p_meetings_count' => $p2p->count(), 'become_a_leader_count' => $leader->count(), 'recommend_a_peer_count' => $recommend->count(), 'visitor_registrations_count' => $visitors->count(), 'performance_score' => $score];
        });
        return $this->success(['peer_activity_summary' => $rows, 'top_5_district_peers' => $rows->sortByDesc('performance_score')->take(5)->values()], 'DED activity summary fetched successfully.', $this->ded->serializePaginator($items));
    }

    public function index(Request $request, string $type)
    {
        [$model, $relations, $primary, $peer, $searchColumns, $dateColumn] = $this->ded->activityConfig($type);
        $request->validate(['search' => ['nullable', 'string'], 'circle_id' => ['nullable', 'string'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date'], 'status' => ['nullable', 'string'], 'category' => ['nullable', 'string'], 'referral_type' => ['nullable', 'string'], 'has_media' => ['nullable'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $admin = $this->admin($request);
        $query = $model::query()->with($relations);
        $this->ded->applyActivityScope($query, $admin, $primary, $peer);
        $this->ded->applyCircleFilter($query, $admin, $request->query('circle_id'), array_filter([$primary, $peer]));
        $this->ded->applyDates($query, $request, $dateColumn);
        if ($request->filled('search')) {
            $like = '%'.$request->query('search').'%';
            $query->where(function ($q) use ($searchColumns, $like, $query) {
                foreach ($searchColumns as $i => $column) {
                    if (Schema::hasColumn($query->getModel()->getTable(), $column)) {
                        ($i === 0 ? $q->where($column, 'ILIKE', $like) : $q->orWhere($column, 'ILIKE', $like));
                    }
                }
            });
        }
        if ($request->filled('status') && Schema::hasColumn($query->getModel()->getTable(), 'status')) $query->where('status', $request->query('status'));
        if ($request->filled('referral_type') && $type === 'referrals') $query->where('referral_type', $request->query('referral_type'));
        if ($request->filled('has_media') && $type === 'testimonials') $query->whereNotNull('media');
        $items = $query->latest($dateColumn)->paginate($this->ded->perPage($request))->withQueryString();
        return $this->success($items->items(), 'DED '.$type.' fetched successfully.', $this->ded->serializePaginator($items));
    }

    public function show(Request $request, string $type, string $id)
    {
        [$model, $relations, $primary, $peer] = $this->ded->activityConfig($type);
        $admin = $this->admin($request);
        $query = $model::query()->with($relations)->whereKey($id);
        $this->ded->applyActivityScope($query, $admin, $primary, $peer);
        $record = $query->firstOrFail();
        return $this->success($record, 'DED '.$type.' detail fetched successfully.');
    }
}

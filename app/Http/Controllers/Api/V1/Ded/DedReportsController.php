<?php

namespace App\Http\Controllers\Api\V1\Ded;

use App\Models\BusinessDeal;
use App\Models\CoinsLedger;
use App\Models\LifeImpactHistory;
use App\Models\P2PMeetingRequest;
use App\Models\Referral;
use App\Models\Requirement;
use App\Models\Testimonial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class DedReportsController extends DedBaseController
{
    public function referrals(Request $request)
    {
        $admin = $this->admin($request); $q = Referral::query()->with(['fromUser', 'toUser']); $this->ded->applyActivityScope($q, $admin, 'referrals.from_user_id', 'referrals.to_user_id'); $this->ded->applyDates($q, $request, 'referral_date');
        return $this->success(['total' => $q->count(), 'items' => $q->latest('referral_date')->paginate($this->ded->perPage($request))], 'DED referral report fetched successfully.');
    }

    public function activities(Request $request)
    {
        $admin = $this->admin($request);
        $queries = [
            'testimonials' => [Testimonial::query(), 'testimonials.from_user_id', 'testimonials.to_user_id', 'created_at'],
            'requirements' => [Requirement::query(), 'requirements.user_id', null, 'created_at'],
            'business_deals' => [BusinessDeal::query(), 'business_deals.from_user_id', 'business_deals.to_user_id', 'deal_date'],
            'p2p_meetings' => [P2PMeetingRequest::query(), 'p2p_meeting_requests.requester_id', 'p2p_meeting_requests.invitee_id', 'scheduled_at'],
        ];
        $data = [];
        foreach ($queries as $key => [$q, $primary, $peer, $date]) { $this->ded->applyActivityScope($q, $admin, $primary, $peer); $this->ded->applyDates($q, $request, $date); $data[$key] = $q->count(); }
        return $this->success($data, 'DED activity report fetched successfully.');
    }

    public function coins(Request $request)
    {
        $q = CoinsLedger::query(); $this->ded->applyActivityScope($q, $this->admin($request), 'coins_ledger.user_id'); $this->ded->applyDates($q, $request, 'created_at');
        return $this->success(['total_earned' => (int) (clone $q)->where('amount', '>', 0)->sum('amount'), 'total_debited' => (int) (clone $q)->where('amount', '<', 0)->sum('amount'), 'items' => $q->latest('created_at')->paginate($this->ded->perPage($request))], 'DED coin report fetched successfully.');
    }


    public function referralReport(Request $request)
    {
        return $this->referrals($request);
    }

    public function lifeImpact(Request $request)
    {
        $request->validate([
            'search' => ['nullable', 'string'],
            'circle_id' => ['nullable', 'string'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'activity_type' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $admin = $this->admin($request);
        $model = new LifeImpactHistory();
        $table = $model->getTable();
        $query = LifeImpactHistory::query()->with(['user', 'triggeredByUser']);

        $this->ded->applyActivityScope($query, $admin, $table.'.user_id', $table.'.triggered_by_user_id');
        $this->ded->applyCircleFilter($query, $admin, $request->query('circle_id'), [$table.'.user_id', $table.'.triggered_by_user_id']);
        $this->ded->applyDates($query, $request, $table.'.created_at');

        if ($request->filled('activity_type') && Schema::hasColumn($model->getTable(), 'activity_type')) {
            $query->where($table.'.activity_type', $request->query('activity_type'));
        }

        if ($request->filled('search')) {
            $like = '%'.$request->query('search').'%';
            $query->where(function ($inner) use ($like, $table): void {
                foreach (['activity_type', 'impact_category', 'action_label', 'remarks', 'title', 'description'] as $index => $column) {
                    if (Schema::hasColumn($table, $column)) {
                        ($index === 0 ? $inner->where($table.'.'.$column, 'ILIKE', $like) : $inner->orWhere($table.'.'.$column, 'ILIKE', $like));
                    }
                }
            });
        }

        $items = $query->latest($table.'.created_at')->paginate($this->ded->perPage($request))->withQueryString();

        return $this->success($items->items(), 'DED life impact fetched successfully.', $this->ded->serializePaginator($items));
    }

    public function pendingRequests(Request $request)
    {
        return $this->success($this->ded->pendingSummary($this->admin($request)), 'DED pending requests report fetched successfully.');
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Ded;

use App\Models\BusinessDeal;
use App\Models\CoinsLedger;
use App\Models\P2PMeetingRequest;
use App\Models\Referral;
use App\Models\Requirement;
use App\Models\Testimonial;
use Illuminate\Http\Request;

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

    public function pendingRequests(Request $request)
    {
        return $this->success($this->ded->pendingSummary($this->admin($request)), 'DED pending requests report fetched successfully.');
    }
}

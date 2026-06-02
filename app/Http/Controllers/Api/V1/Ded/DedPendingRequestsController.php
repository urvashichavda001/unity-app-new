<?php

namespace App\Http\Controllers\Api\V1\Ded;

use App\Models\CircleJoinRequest;
use App\Models\CoinClaimRequest;
use App\Models\Impact;
use App\Models\VisitorRegistration;
use App\Services\CoinClaims\CoinClaimEmailService;
use App\Services\Coins\CoinsService;
use App\Services\Impacts\ImpactService;
use App\Support\AdminCircleScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class DedPendingRequestsController extends DedBaseController
{
    public function __construct(\App\Services\Api\DedApiService $ded, private CoinsService $coins, private CoinClaimEmailService $coinEmail, private ImpactService $impacts)
    {
        parent::__construct($ded);
    }

    public function summary(Request $request)
    {
        return $this->success($this->ded->pendingSummary($this->admin($request)), 'DED pending request summary fetched successfully.');
    }

    public function visitorRegistrations(Request $request) { return $this->listSimple($request, 'visitor'); }
    public function visitorRegistrationShow(Request $request, string $id) { return $this->showSimple($request, 'visitor', $id); }
    public function coinClaims(Request $request) { return $this->listSimple($request, 'coin'); }
    public function coinClaimShow(Request $request, string $id) { return $this->showSimple($request, 'coin', $id); }
    public function pendingImpacts(Request $request) { return $this->listSimple($request, 'impact'); }
    public function pendingImpactShow(Request $request, string $id) { return $this->showSimple($request, 'impact', $id); }

    private function listSimple(Request $request, string $kind)
    {
        $request->validate(['search' => ['nullable', 'string'], 'status' => ['nullable', 'string'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $admin = $this->admin($request);
        [$query, $dateColumn] = $this->simpleQuery($kind, $admin);
        if ($request->filled('status')) $query->where('status', $request->query('status'));
        $this->ded->applyDates($query, $request, $dateColumn);
        if ($request->filled('search')) {
            $like = '%'.$request->query('search').'%';
            $query->whereRaw('COALESCE('.$query->getModel()->getTable().'.*::text, \'\') ILIKE ?', [$like]);
        }
        $items = $query->latest($dateColumn)->paginate($this->ded->perPage($request))->withQueryString();
        return $this->success($items->items(), 'DED pending requests fetched successfully.', $this->ded->serializePaginator($items));
    }

    private function showSimple(Request $request, string $kind, string $id)
    {
        [$query] = $this->simpleQuery($kind, $this->admin($request));
        return $this->success($query->whereKey($id)->firstOrFail(), 'DED pending request detail fetched successfully.');
    }

    private function simpleQuery(string $kind, $admin): array
    {
        if ($kind === 'visitor') { $q = VisitorRegistration::query()->with('user'); $this->ded->applyActivityScope($q, $admin, 'visitor_registrations.user_id'); return [$q, 'created_at']; }
        if ($kind === 'coin') { $q = CoinClaimRequest::query()->with('user'); $this->ded->applyActivityScope($q, $admin, 'coin_claim_requests.user_id'); return [$q, 'created_at']; }
        $q = Impact::query()->with(['user', 'impactedPeer'])->where('status', 'pending'); $this->ded->applyActivityScope($q, $admin, 'impacts.user_id', 'impacts.impacted_peer_id'); return [$q, 'created_at'];
    }

    public function approveVisitor(Request $request, string $id)
    {
        $admin = $this->admin($request);
        $record = VisitorRegistration::with('user')->whereKey($id)->firstOrFail();
        $this->ded->assertUserInDistrict($admin, $record->user_id);
        if ($record->status !== 'approved') {
            $record->forceFill(['status' => 'approved', 'reviewed_at' => now(), 'reviewed_by_admin_user_id' => $admin->id])->save();
            $amount = (int) config('coins.register_visitor', 0);
            if (! $record->coins_awarded && $record->user && $amount > 0 && $this->coins->reward($record->user, $amount, 'Register a Visitor (Approved)')) {
                $record->forceFill(['coins_awarded' => true, 'coins_awarded_at' => now()])->save();
            }
        }
        return $this->success($record->fresh('user'), 'Visitor registration approved successfully.');
    }

    public function rejectVisitor(Request $request, string $id)
    {
        $admin = $this->admin($request); $record = VisitorRegistration::with('user')->whereKey($id)->firstOrFail(); $this->ded->assertUserInDistrict($admin, $record->user_id);
        abort_if($record->status === 'approved', 422, 'Already approved.');
        $record->forceFill(['status' => 'rejected', 'reviewed_at' => now(), 'reviewed_by_admin_user_id' => $admin->id])->save();
        return $this->success($record->fresh('user'), 'Visitor registration rejected successfully.');
    }

    public function eventJoiningRequests(Request $request)
    {
        $request->validate(['search' => ['nullable', 'string'], 'status' => ['nullable', 'string'], 'event_id' => ['nullable', 'string'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = $this->ded->eventJoiningRequestQuery(); $this->ded->applyEventJoiningScope($query, $this->admin($request));
        if ($request->filled('status')) $query->where('status', $request->query('status'));
        if ($request->filled('event_id')) $query->where('event_id', $request->query('event_id'));
        if ($request->filled('search')) $this->ded->applyEventJoiningSearch($query, (string) $request->query('search'));
        $this->ded->applyDates($query, $request);
        $items = $query->latest('created_at')->paginate($this->ded->perPage($request))->withQueryString();
        return $this->success($items->items(), 'DED event joining requests fetched successfully.', $this->ded->serializePaginator($items));
    }

    public function eventJoiningRequestShow(Request $request, string $id) { $q = $this->ded->eventJoiningRequestQuery(); $this->ded->applyEventJoiningScope($q, $this->admin($request)); return $this->success($q->whereKey($id)->firstOrFail(), 'DED event joining request fetched successfully.'); }
    public function approveEventJoining(Request $request, string $id) { $q = $this->ded->eventJoiningRequestQuery(); $this->ded->applyEventJoiningScope($q, $this->admin($request)); $record = $q->whereKey($id)->firstOrFail(); abort_if($record->status !== 'pending', 422, 'Event joining request already reviewed.'); $this->ded->updateEventJoiningReview($record, ['status' => 'approved', 'admin_note' => $request->input('admin_note', 'Approved.'), 'approved_by_user_id' => $this->admin($request)->id, 'approved_at' => now()]); return $this->success($record->fresh(), 'Event joining request approved successfully.'); }
    public function rejectEventJoining(Request $request, string $id) { $data = $request->validate(['admin_note' => ['required', 'string', 'max:2000']]); $q = $this->ded->eventJoiningRequestQuery(); $this->ded->applyEventJoiningScope($q, $this->admin($request)); $record = $q->whereKey($id)->firstOrFail(); abort_if($record->status !== 'pending', 422, 'Event joining request already reviewed.'); $this->ded->updateEventJoiningReview($record, ['status' => 'rejected', 'admin_note' => $data['admin_note'], 'rejected_by_user_id' => $this->admin($request)->id, 'rejected_at' => now()]); return $this->success($record->fresh(), 'Event joining request rejected successfully.'); }

    public function approveCoinClaim(Request $request, string $id)
    {
        $admin = $this->admin($request); $claim = CoinClaimRequest::with('user')->whereKey($id)->firstOrFail(); $this->ded->assertUserInDistrict($admin, $claim->user_id); abort_if($claim->status !== 'pending', 422, 'Claim already reviewed.');
        $claim->forceFill(['status' => 'approved', 'approved_at' => now(), 'rejected_at' => null, 'admin_notes' => $request->input('admin_notes')])->save();
        return $this->success($claim->fresh('user'), 'Coin claim approved successfully.');
    }
    public function rejectCoinClaim(Request $request, string $id)
    {
        $data = $request->validate(['admin_notes' => ['nullable', 'string', 'max:1000']]); $admin = $this->admin($request); $claim = CoinClaimRequest::with('user')->whereKey($id)->firstOrFail(); $this->ded->assertUserInDistrict($admin, $claim->user_id); abort_if($claim->status !== 'pending', 422, 'Claim already reviewed.');
        $claim->forceFill(['status' => 'rejected', 'rejected_at' => now(), 'approved_at' => null, 'admin_notes' => $data['admin_notes'] ?? null])->save(); $this->coinEmail->sendRejected($claim->fresh('user'));
        return $this->success($claim->fresh('user'), 'Coin claim rejected successfully.');
    }

    public function circleJoinRequests(Request $request)
    {
        $request->validate(['search' => ['nullable', 'string'], 'status' => ['nullable', 'string'], 'circle_id' => ['nullable', 'string'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $admin = $this->admin($request); $query = CircleJoinRequest::query()->with(['user', 'circle'])->visibleToAdminUser($admin);
        if ($request->filled('status')) $query->where('status', $request->query('status'));
        if ($request->filled('circle_id')) { $this->ded->assertCircleInDistrict($admin, $request->query('circle_id')); $query->where('circle_id', $request->query('circle_id')); }
        if ($request->filled('search')) { $like = '%'.$request->query('search').'%'; $query->where(fn ($q) => $q->where('reason_for_joining', 'ILIKE', $like)->orWhere('status', 'ILIKE', $like)->orWhereHas('user', fn ($uq) => $uq->where('display_name', 'ILIKE', $like)->orWhere('email', 'ILIKE', $like))->orWhereHas('circle', fn ($cq) => $cq->where('name', 'ILIKE', $like))); }
        $this->ded->applyDates($query, $request, 'requested_at');
        $items = $query->latest('requested_at')->paginate($this->ded->perPage($request))->withQueryString();
        return $this->success($items->items(), 'DED circle joining requests fetched successfully.', $this->ded->serializePaginator($items));
    }
    public function circleJoinRequestShow(Request $request, string $id) { return $this->success(CircleJoinRequest::query()->with(['user', 'circle'])->visibleToAdminUser($this->admin($request))->whereKey($id)->firstOrFail(), 'DED circle joining request fetched successfully.'); }
    public function dedApproveCircleJoin(Request $request, string $id)
    {
        $admin = $this->admin($request);
        $actor = \App\Support\AdminAccess::resolveAppUser($admin);
        $record = CircleJoinRequest::query()->visibleToAdminUser($admin)->whereKey($id)->firstOrFail();
        $old = $record->status;

        if ($old === CircleJoinRequest::STATUS_PENDING_CD_APPROVAL) {
            $record->status = CircleJoinRequest::STATUS_PENDING_ID_APPROVAL;
            if (Schema::hasColumn('circle_join_requests', 'cd_approved_by') && $actor) $record->cd_approved_by = $actor->id;
            if (Schema::hasColumn('circle_join_requests', 'cd_approved_at')) $record->cd_approved_at = now();
        } elseif ($old === CircleJoinRequest::STATUS_PENDING_ID_APPROVAL) {
            $record->status = CircleJoinRequest::STATUS_PENDING_CIRCLE_FEE;
            if (Schema::hasColumn('circle_join_requests', 'id_approved_by') && $actor) $record->id_approved_by = $actor->id;
            if (Schema::hasColumn('circle_join_requests', 'id_approved_at')) $record->id_approved_at = now();
        } else {
            return $this->error('DED approval is not available for the current request status.', 422, ['status' => [$old]]);
        }

        if (Schema::hasColumn('circle_join_requests', 'ded_approved_by') && $actor) $record->ded_approved_by = $actor->id;
        if (Schema::hasColumn('circle_join_requests', 'ded_approved_admin_user_id')) $record->ded_approved_admin_user_id = $admin->id;
        if (Schema::hasColumn('circle_join_requests', 'ded_approved_at')) $record->ded_approved_at = now();
        if (Schema::hasColumn('circle_join_requests', 'ded_approval_status')) $record->ded_approval_status = 'approved';
        $record->save();

        Log::info('api.circle_join_request.ded_approved', ['request_id' => $record->id, 'admin_id' => $admin->id, 'old_status' => $old, 'new_status' => $record->status]);
        return $this->success($record->fresh(['user', 'circle']), 'Circle joining request DED approved successfully.');
    }
    public function rejectCircleJoin(Request $request, string $id) { $record = CircleJoinRequest::query()->visibleToAdminUser($this->admin($request))->whereKey($id)->firstOrFail(); $record->forceFill(['status' => CircleJoinRequest::STATUS_REJECTED_BY_CD])->save(); return $this->success($record->fresh(['user', 'circle']), 'Circle joining request rejected successfully.'); }
    public function approveImpact(Request $request, string $id) { $admin = $this->admin($request); $q = Impact::query()->whereKey($id); $this->ded->applyActivityScope($q, $admin, 'impacts.user_id', 'impacts.impacted_peer_id'); $record = $q->firstOrFail(); $this->impacts->approveImpact($id, (string) $admin->id, $request->input('review_remarks')); return $this->success($record->fresh(['user', 'impactedPeer']), 'Pending impact approved successfully.'); }
    public function rejectImpact(Request $request, string $id) { $admin = $this->admin($request); $q = Impact::query()->whereKey($id); $this->ded->applyActivityScope($q, $admin, 'impacts.user_id', 'impacts.impacted_peer_id'); $record = $q->firstOrFail(); $this->impacts->rejectImpact($id, (string) $admin->id, $request->input('review_remarks')); return $this->success($record->fresh(['user', 'impactedPeer']), 'Pending impact rejected successfully.'); }
}

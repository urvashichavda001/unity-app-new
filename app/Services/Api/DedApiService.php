<?php

namespace App\Services\Api;

use App\Models\AdminUser;
use App\Models\BusinessDeal;
use App\Models\Circle;
use App\Models\CircleJoinRequest;
use App\Models\CollaborationPost;
use App\Models\CoinClaimRequest;
use App\Models\CoinsLedger;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\EventRegistrationRequest;
use App\Models\Impact;
use App\Models\LeaderInterestSubmission;
use App\Models\P2PMeetingRequest;
use App\Models\PeerRecommendation;
use App\Models\Referral;
use App\Models\Requirement;
use App\Models\Testimonial;
use App\Models\User;
use App\Models\VisitorRegistration;
use App\Support\AdminAccess;
use App\Support\AdminCircleScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DedApiService
{
    public function admin(Request $request): AdminUser
    {
        return $request->attributes->get('ded_admin_user');
    }

    public function district(Request $request): array
    {
        return $request->attributes->get('ded_district') ?? AdminAccess::assignedDedDistrict($this->admin($request)) ?? [];
    }

    public function perPage(Request $request, int $default = 20): int
    {
        return max(1, min((int) $request->query('per_page', $default), 100));
    }

    public function usersQuery(AdminUser $admin): Builder
    {
        $query = User::query();
        AdminCircleScope::applyToUsersQuery($query, $admin);
        return $query;
    }

    public function circlesQuery(AdminUser $admin): Builder
    {
        $query = Circle::query();
        AdminCircleScope::applyDedDistrictCircleScope($query, $admin);
        return $query;
    }

    public function applyActivityScope(Builder $query, AdminUser $admin, string $primaryColumn, ?string $peerColumn = null): void
    {
        AdminCircleScope::applyToActivityQuery($query, $admin, $primaryColumn, $peerColumn);
    }

    public function applyCircleFilter(Builder $query, AdminUser $admin, ?string $circleId, array $userColumns): void
    {
        $circleId = trim((string) $circleId);
        if ($circleId === '' || $circleId === 'all') {
            return;
        }

        abort_unless(AdminCircleScope::circleBelongsToDedDistrict($admin, $circleId), 403);
        $userIds = AdminCircleScope::circleUserIdsSubquery($circleId);
        $query->where(function ($circleQuery) use ($userColumns, $userIds): void {
            foreach (array_values($userColumns) as $index => $column) {
                $method = $index === 0 ? 'whereIn' : 'orWhereIn';
                $circleQuery->{$method}($column, clone $userIds);
            }
        });
    }

    public function applyDates(Builder $query, Request $request, string $column = 'created_at'): void
    {
        if ($request->filled('date_from')) {
            $query->whereDate($column, '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate($column, '<=', $request->query('date_to'));
        }
    }

    public function assertUserInDistrict(AdminUser $admin, ?string $userId): void
    {
        abort_unless($userId && AdminCircleScope::userInScope($admin, $userId), 403);
    }

    public function assertCircleInDistrict(AdminUser $admin, ?string $circleId): void
    {
        abort_unless($circleId && AdminCircleScope::circleBelongsToDedDistrict($admin, $circleId), 403);
    }

    public function serializePaginator(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    public function eventJoiningRequestQuery()
    {
        $modelClass = $this->eventJoiningRequestModelClass();
        $query = $modelClass::query()->with(['user.circleMemberships.circle', 'event.circle', 'occurrence']);
        if ($modelClass === EventRegistrationRequest::class) {
            $query->with(['registration', 'approvedBy', 'rejectedBy']);
        }
        return $query;
    }

    public function eventJoiningRequestModelClass(): string
    {
        if (Schema::hasTable((new EventRegistrationRequest())->getTable())) {
            return EventRegistrationRequest::class;
        }
        if (Schema::hasTable((new EventRegistration())->getTable())) {
            return EventRegistration::class;
        }
        abort(500, 'Event joining request storage table is not available.');
    }

    public function applyEventJoiningScope(Builder $query, AdminUser $admin): void
    {
        $table = $query->getModel()->getTable();
        $userIds = $this->usersQuery($admin)->select('users.id');
        $query->where(function ($districtQuery) use ($admin, $table, $userIds): void {
            if (Schema::hasColumn($table, 'user_id')) {
                $districtQuery->whereIn($table.'.user_id', clone $userIds);
            } else {
                $districtQuery->whereRaw('1=0');
            }
            if (Schema::hasColumn($table, 'event_id')) {
                $districtQuery->orWhereExists(function ($subQuery) use ($admin, $table): void {
                    $subQuery->selectRaw('1')->from('events')
                        ->join('circles', 'circles.id', '=', 'events.circle_id')
                        ->whereColumn('events.id', $table.'.event_id');
                    AdminCircleScope::applyDedDistrictCircleScope($subQuery, $admin);
                });
            }
            if (Schema::hasColumn($table, 'event_circle_id')) {
                $districtQuery->orWhereExists(function ($subQuery) use ($admin, $table): void {
                    $subQuery->selectRaw('1')->from('circles')
                        ->whereColumn('circles.id', $table.'.event_circle_id');
                    AdminCircleScope::applyDedDistrictCircleScope($subQuery, $admin);
                });
            }
        });
    }


    public function applyEventJoiningSearch(Builder $query, string $term): void
    {
        $table = $query->getModel()->getTable();
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

        $query->where(function ($inner) use ($like, $table): void {
            $inner->whereRaw("CAST({$table}.id AS TEXT) ILIKE ?", [$like]);
            foreach (['request_reason', 'admin_note', 'source', 'registration_type', 'visitor_name', 'visitor_email', 'visitor_phone', 'visitor_company', 'visitor_city'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $inner->orWhere($table.'.'.$column, 'ILIKE', $like);
                }
            }
            if (Schema::hasColumn($table, 'metadata')) {
                $inner->orWhereRaw("COALESCE({$table}.metadata::text, '') ILIKE ?", [$like]);
            }
            $inner->orWhereHas('user', function ($userQuery) use ($like): void {
                $userQuery->where('display_name', 'ilike', $like)
                    ->orWhere('first_name', 'ilike', $like)
                    ->orWhere('last_name', 'ilike', $like)
                    ->orWhere('email', 'ilike', $like)
                    ->orWhere('phone', 'ilike', $like)
                    ->orWhere('company_name', 'ilike', $like);
            })->orWhereHas('event', fn ($eventQuery) => $eventQuery->where('title', 'ilike', $like));
        });
    }

    public function updateEventJoiningReview(Model $record, array $data): void
    {
        $table = $record->getTable();
        $updates = [];
        foreach ($data as $column => $value) {
            if (Schema::hasColumn($table, $column)) {
                $updates[$column] = $value;
            }
        }
        if (! Schema::hasColumn($table, 'admin_note') && Schema::hasColumn($table, 'metadata')) {
            $metadata = (array) ($record->metadata ?? []);
            foreach (['admin_note', 'approved_by_user_id', 'rejected_by_user_id'] as $key) {
                if (array_key_exists($key, $data)) {
                    $metadata[$key] = $data[$key];
                }
            }
            foreach (['approved_at', 'rejected_at'] as $key) {
                if (array_key_exists($key, $data)) {
                    $metadata[$key] = optional($data[$key])->toISOString();
                }
            }
            $updates['metadata'] = $metadata;
        }
        abort_if($updates === [], 500, 'Event joining request storage is missing review columns.');
        $record->forceFill($updates)->save();
    }

    public function activityConfig(string $type): array
    {
        return match ($type) {
            'testimonials' => [Testimonial::class, ['fromUser', 'toUser'], 'testimonials.from_user_id', 'testimonials.to_user_id', ['content'], 'created_at'],
            'requirements' => [Requirement::class, ['user'], 'requirements.user_id', null, ['subject', 'description', 'status'], 'created_at'],
            'referrals' => [Referral::class, ['fromUser', 'toUser'], 'referrals.from_user_id', 'referrals.to_user_id', ['referral_of', 'phone', 'email', 'remarks', 'referral_type'], 'referral_date'],
            'p2p-meetings' => [P2PMeetingRequest::class, ['requester', 'invitee'], 'p2p_meeting_requests.requester_id', 'p2p_meeting_requests.invitee_id', ['place', 'message', 'status'], 'scheduled_at'],
            'business-deals' => [BusinessDeal::class, ['fromUser', 'toUser'], 'business_deals.from_user_id', 'business_deals.to_user_id', ['business_type', 'comment'], 'deal_date'],
            'become-a-leader' => [LeaderInterestSubmission::class, ['user'], 'leader_interest_submissions.user_id', null, ['applying_for', 'referred_name', 'referred_mobile', 'primary_domain', 'contribute_city', 'message'], 'created_at'],
            'recommend-a-peer' => [PeerRecommendation::class, ['user'], 'peer_recommendations.user_id', null, ['peer_name', 'peer_mobile', 'peer_email', 'peer_city', 'peer_business', 'note'], 'created_at'],
            'find-build-collaborations' => [CollaborationPost::class, ['user', 'acceptedByUser', 'collaborationType', 'industry'], 'collaboration_posts.user_id', 'collaboration_posts.accepted_by_user_id', ['title', 'description', 'collaboration_type', 'scope', 'preferred_model', 'business_stage', 'urgency', 'status'], 'posted_at'],
            'register-a-visitor' => [VisitorRegistration::class, ['user'], 'visitor_registrations.user_id', null, ['event_name', 'visitor_full_name', 'visitor_mobile', 'visitor_email', 'visitor_city', 'visitor_business', 'status'], 'created_at'],
            default => abort(404, 'Unknown activity type.'),
        };
    }

    public function pendingSummary(AdminUser $admin): array
    {
        $visitor = VisitorRegistration::query();
        $this->applyActivityScope($visitor, $admin, 'visitor_registrations.user_id');
        $event = $this->eventJoiningRequestQuery();
        $this->applyEventJoiningScope($event, $admin);
        $claims = CoinClaimRequest::query();
        $this->applyActivityScope($claims, $admin, 'coin_claim_requests.user_id');
        $circleJoins = CircleJoinRequest::query()->visibleToAdminUser($admin);
        $impacts = Impact::query()->where('status', 'pending');
        $this->applyActivityScope($impacts, $admin, 'impacts.user_id', 'impacts.impacted_peer_id');

        return [
            'visitor_registrations' => $visitor->where('status', 'pending')->count(),
            'event_joining_requests' => $event->where('status', 'pending')->count(),
            'coin_claims' => $claims->where('status', 'pending')->count(),
            'circle_joining_requests' => $circleJoins->pending()->count(),
            'pending_impacts' => $impacts->count(),
        ];
    }

    public function dashboardStats(Request $request, AdminUser $admin): array
    {
        $circleId = $request->query('circle_id');
        $users = $this->usersQuery($admin)->with('circleMemberships.circle')->latest('created_at');
        $this->applyCircleFilter($users, $admin, $circleId, ['users.id']);
        $circles = $this->circlesQuery($admin);
        if ($circleId) {
            $this->assertCircleInDistrict($admin, $circleId);
            $circles->where('id', $circleId);
        }

        $referrals = Referral::query(); $this->applyActivityScope($referrals, $admin, 'referrals.from_user_id', 'referrals.to_user_id'); $this->applyCircleFilter($referrals, $admin, $circleId, ['referrals.from_user_id', 'referrals.to_user_id']); $this->applyDates($referrals, $request, 'referral_date');
        $requirements = Requirement::query(); $this->applyActivityScope($requirements, $admin, 'requirements.user_id'); $this->applyCircleFilter($requirements, $admin, $circleId, ['requirements.user_id']); $this->applyDates($requirements, $request);
        $testimonials = Testimonial::query(); $this->applyActivityScope($testimonials, $admin, 'testimonials.from_user_id', 'testimonials.to_user_id'); $this->applyCircleFilter($testimonials, $admin, $circleId, ['testimonials.from_user_id', 'testimonials.to_user_id']); $this->applyDates($testimonials, $request);
        $deals = BusinessDeal::query(); $this->applyActivityScope($deals, $admin, 'business_deals.from_user_id', 'business_deals.to_user_id'); $this->applyCircleFilter($deals, $admin, $circleId, ['business_deals.from_user_id', 'business_deals.to_user_id']); $this->applyDates($deals, $request, 'deal_date');
        $p2p = P2PMeetingRequest::query(); $this->applyActivityScope($p2p, $admin, 'p2p_meeting_requests.requester_id', 'p2p_meeting_requests.invitee_id'); $this->applyCircleFilter($p2p, $admin, $circleId, ['p2p_meeting_requests.requester_id', 'p2p_meeting_requests.invitee_id']); $this->applyDates($p2p, $request, 'scheduled_at');
        $ledger = CoinsLedger::query(); $this->applyActivityScope($ledger, $admin, 'coins_ledger.user_id'); $this->applyCircleFilter($ledger, $admin, $circleId, ['coins_ledger.user_id']); $this->applyDates($ledger, $request, 'created_at');

        return [
            'total_district_peers' => (clone $users)->count(),
            'total_district_circles' => $circles->count(),
            'total_referrals' => $referrals->count(),
            'total_requirements' => $requirements->count(),
            'total_testimonials' => $testimonials->count(),
            'total_business_deals' => $deals->count(),
            'total_p2p_meetings' => $p2p->count(),
            'total_coins_earned' => (int) $ledger->where('amount', '>', 0)->sum('amount'),
            'pending_requests' => $this->pendingSummary($admin),
            'latest_district_peers' => (clone $users)->limit(10)->get(['id', 'display_name', 'first_name', 'last_name', 'email', 'phone', 'company_name', 'city', 'created_at']),
        ];
    }
}

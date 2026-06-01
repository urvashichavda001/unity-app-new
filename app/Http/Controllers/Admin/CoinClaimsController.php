<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CoinClaims\RejectCoinClaimRequest;
use App\Models\CoinClaimRequest;
use App\Models\User;
use App\Services\CoinClaims\CoinClaimEmailService;
use App\Services\Coins\CoinsService;
use App\Support\AdminCircleScope;
use App\Support\CoinClaims\CoinClaimActivityRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CoinClaimsController extends Controller
{
    public function __construct(
        private readonly CoinClaimActivityRegistry $registry,
        private readonly CoinsService $coinsService,
        private readonly CoinClaimEmailService $emailService,
    ) {
    }

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', $request->query('search', '')));
        $status = (string) $request->query('status', 'all');
        $circleId = (string) $request->query('circle_id', 'all');

        $peerQ = trim((string) $request->query('peer_q', ''));
        $peerPhone = trim((string) $request->query('peer_phone', ''));
        $activity = trim((string) $request->query('activity', ''));
        $keyFields = trim((string) $request->query('key_fields', ''));

        $hasUsersName = Schema::hasColumn('users', 'name');
        $hasUsersCompany = Schema::hasColumn('users', 'company');
        $hasUsersBusinessName = Schema::hasColumn('users', 'business_name');

        $query = CoinClaimRequest::query()
            ->with([
                'user',
                'user.circleMembers' => function ($circleMembersQuery) {
                    $circleMembersQuery
                        ->where('status', 'approved')
                        ->whereNull('deleted_at')
                        ->orderByDesc('joined_at')
                        ->with(['circle:id,name']);
                },
            ]);

        if ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($circleId !== '' && $circleId !== 'all') {
            $query->whereHas('user.circleMembers', function ($circleMembersQuery) use ($circleId) {
                $circleMembersQuery
                    ->where('circle_id', $circleId)
                    ->where('status', 'approved')
                    ->whereNull('deleted_at');
            });
        }

        if ($q !== '') {
            $like = "%{$q}%";
            $query->where(function ($qQuery) use ($like, $hasUsersName, $hasUsersCompany, $hasUsersBusinessName) {
                $qQuery->where('activity_code', 'ILIKE', $like)
                    ->orWhere('status', 'ILIKE', $like)
                    ->orWhere('admin_notes', 'ILIKE', $like)
                    ->orWhereRaw("COALESCE(payload::text,'') ILIKE ?", [$like])
                    ->orWhereHas('user', function ($userQuery) use ($like, $hasUsersName, $hasUsersCompany, $hasUsersBusinessName) {
                        $userQuery->where(function ($uq) use ($like, $hasUsersName, $hasUsersCompany, $hasUsersBusinessName) {
                            $uq->where('display_name', 'ILIKE', $like)
                                ->orWhere('first_name', 'ILIKE', $like)
                                ->orWhere('last_name', 'ILIKE', $like)
                                ->orWhere('email', 'ILIKE', $like)
                                ->orWhere('phone', 'ILIKE', $like)
                                ->orWhere('company_name', 'ILIKE', $like)
                                ->orWhere('city', 'ILIKE', $like);

                            if ($hasUsersName) {
                                $uq->orWhere('name', 'ILIKE', $like);
                            }
                            if ($hasUsersCompany) {
                                $uq->orWhere('company', 'ILIKE', $like);
                            }
                            if ($hasUsersBusinessName) {
                                $uq->orWhere('business_name', 'ILIKE', $like);
                            }
                        })->orWhereHas('circleMembers.circle', function ($circleQuery) use ($like) {
                            $circleQuery->where('name', 'ILIKE', $like);
                        });
                    });
            });
        }

        if ($peerQ !== '') {
            $like = "%{$peerQ}%";
            $query->whereHas('user', function ($userQuery) use ($like, $hasUsersName, $hasUsersCompany, $hasUsersBusinessName) {
                $userQuery->where(function ($uq) use ($like, $hasUsersName, $hasUsersCompany, $hasUsersBusinessName) {
                    $uq->where('display_name', 'ILIKE', $like)
                        ->orWhere('first_name', 'ILIKE', $like)
                        ->orWhere('last_name', 'ILIKE', $like)
                        ->orWhere('email', 'ILIKE', $like)
                        ->orWhere('company_name', 'ILIKE', $like)
                        ->orWhere('city', 'ILIKE', $like);

                    if ($hasUsersName) {
                        $uq->orWhere('name', 'ILIKE', $like);
                    }
                    if ($hasUsersCompany) {
                        $uq->orWhere('company', 'ILIKE', $like);
                    }
                    if ($hasUsersBusinessName) {
                        $uq->orWhere('business_name', 'ILIKE', $like);
                    }
                });
            });
        }

        if ($peerPhone !== '') {
            $query->whereHas('user', fn ($userQuery) => $userQuery->where('phone', 'ILIKE', "%{$peerPhone}%"));
        }

        if ($activity !== '') {
            $query->where('activity_code', 'ILIKE', "%{$activity}%");
        }

        if ($keyFields !== '') {
            $like = "%{$keyFields}%";
            $query->where(function ($keyFieldsQuery) use ($like) {
                $keyFieldsQuery->where('activity_code', 'ILIKE', $like)
                    ->orWhere('admin_notes', 'ILIKE', $like)
                    ->orWhereRaw("COALESCE(payload::text,'') ILIKE ?", [$like]);
            });
        }

        AdminCircleScope::applyToActivityQuery($query, Auth::guard('admin')->user(), 'coin_claim_requests.user_id', null);

        $claims = $query->orderByDesc('created_at')->paginate(25)->appends($request->query());
        $circles = AdminCircleScope::circleOptions(auth('admin')->user());

        return view('admin.coin_claims.index', [
            'claims' => $claims,
            'registry' => $this->registry,
            'circles' => $circles,
            'filters' => [
                'q' => $q,
                'status' => in_array($status, ['all', 'pending', 'approved', 'rejected'], true) ? $status : 'all',
                'circle_id' => $circleId,
                'peer_q' => $peerQ,
                'peer_phone' => $peerPhone,
                'activity' => $activity,
                'key_fields' => $keyFields,
            ],
            'statuses' => ['pending', 'approved', 'rejected'],
        ]);
    }

    public function show(string $id): View
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $claim = CoinClaimRequest::with('user:id,display_name,first_name,last_name,phone,email')->findOrFail($id);

        if (! AdminCircleScope::userInScope(Auth::guard('admin')->user(), $claim->user_id)) {
            abort(403);
        }

        return view('admin.coin_claims.show', [
            'claim' => $claim,
            'activity' => $this->registry->get((string) $claim->activity_code),
        ]);
    }

    public function approve(string $id, Request $request): RedirectResponse
    {
        $requestId = (string) Str::uuid();
        Log::info('Coin claim approve requested', ['request_id' => $requestId, 'id' => $id]);

        $admin = Auth::guard('admin')->user();

        try {
            $message = DB::transaction(function () use ($id, $admin, $requestId, $request) {
                $claim = CoinClaimRequest::with('user')->where('id', $id)->lockForUpdate()->firstOrFail();

                if (! AdminCircleScope::userInScope($admin, $claim->user_id)) {
                    abort(403);
                }

                if ($claim->status !== 'pending') {
                    return 'Claim already reviewed.';
                }

                $activity = $this->registry->get((string) $claim->activity_code) ?? [];
                $coins = (int) ($activity['coins'] ?? 0);

                $claim->status = 'approved';
                $claim->approved_at = now();
                $claim->rejected_at = null;
                $claim->coins_awarded = $coins;
                $claim->admin_notes = $request->input('admin_notes');
                $claim->save();

                $isNewMemberAddition = Str::lower(trim((string) $claim->activity_code)) === 'new_member_addition';

                if ($isNewMemberAddition) {
                    $claimant = User::where('id', $claim->user_id)->lockForUpdate()->first();

                    if (! $claimant) {
                        Log::warning('coin_claim.new_member_addition.claimant_missing', [
                            'request_id' => $requestId,
                            'claim_id' => (string) $claim->id,
                            'user_id' => (string) $claim->user_id,
                        ]);
                    } else {
                        $oldIntroducedCount = (int) ($claimant->members_introduced_count ?? 0);
                        $claimant->members_introduced_count = $oldIntroducedCount + 1;

                        if (method_exists($claimant, 'syncContributionMilestoneAttributes')) {
                            $claimant->syncContributionMilestoneAttributes();
                        }

                        $claimant->save();

                        Log::info('coin_claim.new_member_addition.approved', [
                            'request_id' => $requestId,
                            'claim_id' => (string) $claim->id,
                            'user_id' => (string) $claimant->id,
                            'old_members_introduced_count' => $oldIntroducedCount,
                            'new_members_introduced_count' => (int) $claimant->members_introduced_count,
                            'contribution_award_name' => $claimant->contribution_award_name,
                        ]);
                    }
                }

                if ($coins > 0 && $claim->user) {
                    $this->coinsService->reward(
                        $claim->user,
                        $coins,
                        'Coin claim approved: ' . $claim->activity_code . ' #' . $claim->id,
                        [
                            'source' => 'coin_claim_approved',
                            'claim_id' => (string) $claim->id,
                            'activity_code' => (string) $claim->activity_code,
                            'approved_by_admin_id' => (string) ($admin?->id ?? ''),
                            'request_id' => $requestId,
                        ]
                    );
                }

                $this->emailService->sendApproved($claim->fresh('user'));

                return 'Coin claim approved.';
            });

            return redirect()->back()->with('success', $message);
        } catch (\Throwable $exception) {
            Log::error('Coin claim approve failed', [
                'request_id' => $requestId,
                'id' => $id,
                'admin_id' => $admin?->id,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return redirect()->back()->with('error', $exception->getMessage());
        }
    }

    public function reject(string $id, RejectCoinClaimRequest $request): RedirectResponse
    {
        $requestId = (string) Str::uuid();
        Log::info('Coin claim reject requested', ['request_id' => $requestId, 'id' => $id]);

        $admin = Auth::guard('admin')->user();

        try {
            $message = DB::transaction(function () use ($id, $admin, $request) {
                $claim = CoinClaimRequest::with('user')->where('id', $id)->lockForUpdate()->firstOrFail();

                if (! AdminCircleScope::userInScope($admin, $claim->user_id)) {
                    abort(403);
                }

                if ($claim->status !== 'pending') {
                    return 'Claim already reviewed.';
                }

                $claim->status = 'rejected';
                $claim->rejected_at = now();
                $claim->approved_at = null;
                $claim->admin_notes = $request->validated('admin_notes');
                $claim->save();

                $this->emailService->sendRejected($claim->fresh('user'));

                return 'Coin claim rejected.';
            });

            return redirect()->back()->with('success', $message);
        } catch (\Throwable $exception) {
            Log::error('Coin claim reject failed', [
                'request_id' => $requestId,
                'id' => $id,
                'admin_id' => $admin?->id,
                'error' => $exception->getMessage(),
            ]);

            return redirect()->back()->with('error', $exception->getMessage());
        }
    }
}

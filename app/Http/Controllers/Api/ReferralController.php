<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Activity\StoreReferralRequest;
use App\Events\ActivityCreated;
use App\Http\Requests\Api\GenerateReferralCodeRequest;
use App\Http\Resources\ReferralMemberResource;
use App\Models\Referral;
use App\Models\User;
use App\Services\Blocks\PeerBlockService;
use App\Services\Coins\CoinsService;
use App\Services\Notifications\NotifyUserService;
use App\Services\Referrals\ReferralService;
use App\Services\ActivityCreativeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ReferralController extends BaseApiController
{
    public function me(Request $request, ReferralService $referralService)
    {
        return $this->success($referralService->getMyReferralSummary($request->user()));
    }

    public function generate(GenerateReferralCodeRequest $request, ReferralService $referralService)
    {
        $user = $request->user();
        if (! $user) {
            return $this->error('Unauthorized', 401);
        }

        $payload = $referralService->generateOrGetReferral($user);
        $isExisting = (bool) ($payload['is_existing'] ?? false);
        unset($payload['is_existing']);

        return $this->success(
            $payload,
            $isExisting ? 'Referral code fetched successfully.' : 'Referral code generated successfully.'
        );
    }

    public function members(Request $request, ReferralService $referralService)
    {
        $paginator = $referralService->getReferralMembers($request->user(), (int) $request->input('per_page', 20));

        return $this->success([
            'items' => ReferralMemberResource::collection($paginator->items()),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function stats(Request $request, ReferralService $referralService)
    {
        return $this->success($referralService->getReferralStats($request->user()));
    }

    public function validateCode(string $code, ReferralService $referralService)
    {
        $row = $referralService->validateReferralCode($code);

        return $this->success([
            'valid' => $row !== null,
            'referrer_name' => $row['referrer_name'] ?? null,
        ]);
    }

    public function search(Request $request)
    {
        $queryText = trim((string) $request->query('q', ''));

        if ($queryText === '') {
            return $this->success([], 'Referral users fetched successfully.');
        }

        $referralCodeColumn = $this->referralLinksCodeColumn();
        $referralUserColumn = $this->referralLinksUserColumn();
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $queryText) . '%';

        $query = User::query()
            ->leftJoin('referral_links as rl', 'rl.' . $referralUserColumn, '=', 'users.id')
            ->select([
                'users.id as user_id',
                'users.display_name',
                'users.first_name',
                'users.last_name',
                'users.company_name',
                DB::raw('rl."' . $referralCodeColumn . '" as referral_code'),
            ])
            ->where(function ($query) use ($like, $referralCodeColumn): void {
                $query->where('users.display_name', 'ILIKE', $like)
                    ->orWhere('users.first_name', 'ILIKE', $like)
                    ->orWhere('users.last_name', 'ILIKE', $like)
                    ->orWhere('users.email', 'ILIKE', $like)
                    ->orWhere('users.phone', 'ILIKE', $like)
                    ->orWhere('rl.' . $referralCodeColumn, 'ILIKE', $like);
            })
            ->orderBy('users.display_name')
            ->limit(20);

        $items = $query->get()
            ->map(function ($row): array {
                $displayName = trim((string) (($row->display_name ?: '') ?: (($row->first_name ?? '') . ' ' . ($row->last_name ?? ''))));

                return [
                    'user_id' => (string) $row->user_id,
                    'display_name' => $displayName,
                    'company_name' => $row->company_name,
                    'referral_code' => $row->referral_code,
                ];
            })
            ->unique('user_id')
            ->values();

        return $this->success($items, 'Referral users fetched successfully.');
    }

    public function validateSelf(Request $request, ReferralService $referralService)
    {
        $user = $request->user();
        $payload = $referralService->generateOrGetReferral($user);
        $user->loadMissing(['activeCircle:id,name']);

        $resolvedName = trim((string) (($user->display_name ?: '') ?: (($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))));
        $resolvedCity = is_string($user->city)
            ? $user->city
            : data_get($user, 'city.name');

        $activeCircle = $user->activeCircle;
        $fallbackCircle = null;

        if (! $activeCircle && method_exists($user, 'circles')) {
            $fallbackCircle = $user->circles()
                ->select('circles.id', 'circles.name')
                ->first();
        }

        $resolvedCircle = $activeCircle ?: $fallbackCircle;

        return $this->success([
            'valid' => true,
            'referral_code' => $payload['referral_code'] ?? null,
            'referral_link' => $payload['referral_link'] ?? null,
            'referrer' => [
                'id' => (string) $user->id,
                'name' => $resolvedName !== '' ? $resolvedName : null,
                'email' => $user->email,
                'company_name' => $user->company_name ?? data_get($user, 'business_name'),
                'city' => $resolvedCity,
                'circle' => $resolvedCircle
                    ? [
                        'id' => (string) $resolvedCircle->id,
                        'name' => (string) $resolvedCircle->name,
                    ]
                    : null,
            ],
        ]);
    }


    private function referralLinksUserColumn(): string
    {
        if (Schema::hasTable('referral_links') && Schema::hasColumn('referral_links', 'user_id')) {
            return 'user_id';
        }

        return 'referrer_user_id';
    }

    private function referralLinksCodeColumn(): string
    {
        if (! Schema::hasTable('referral_links')) {
            return 'token';
        }

        foreach (['referral_code', 'token', 'code', 'ref_code', 'invite_code'] as $column) {
            if (Schema::hasColumn('referral_links', $column)) {
                return $column;
            }
        }

        return 'token';
    }

    public function index(Request $request)
    {
        $authUser = $request->user();
        $filter = $request->input('filter', 'given');
        $referralType = $request->input('referral_type');

        $query = Referral::query()
            ->where('is_deleted', false)
            ->whereNull('deleted_at');

        if ($filter === 'received') {
            $query->where('to_user_id', $authUser->id);
        } elseif ($filter === 'all') {
            $query->where(function ($q) use ($authUser) {
                $q->where('from_user_id', $authUser->id)
                    ->orWhere('to_user_id', $authUser->id);
            });
        } else {
            $query->where('from_user_id', $authUser->id);
        }

        if ($referralType) {
            $query->where('referral_type', $referralType);
        }

        $perPage = (int) $request->input('per_page', 20);
        $perPage = max(1, min($perPage, 100));

        $paginator = $query
            ->orderBy('referral_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->success([
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreReferralRequest $request, NotifyUserService $notifyUserService, PeerBlockService $peerBlockService, ActivityCreativeService $activityCreativeService)
    {
        $authUser = $request->user();
        $targetUserId = (string) $request->input('to_user_id');

        if ($peerBlockService->isBlockedEitherWay((string) $authUser->id, $targetUserId)) {
            return $this->error('You cannot interact with this peer.', 422);
        }

        try {
            $referral = Referral::create([
                'from_user_id' => $authUser->id,
                'to_user_id' => $request->input('to_user_id'),
                'referral_type' => $request->input('referral_type'),
                'referral_date' => $request->input('referral_date'),
                'referral_of' => $request->input('referral_of'),
                'phone' => $request->input('phone'),
                'email' => $request->input('email'),
                'address' => $request->input('address'),
                'hot_value' => $request->input('hot_value'),
                'remarks' => $request->input('remarks'),
                'is_deleted' => false,
            ]);

            $coinsLedger = app(CoinsService::class)->rewardForActivity(
                $authUser,
                'referral',
                null,
                'Activity: referral',
                $authUser->id
            );

            if ($coinsLedger) {
                $referral->setAttribute('coins', [
                    'earned' => $coinsLedger->amount,
                    'balance_after' => $coinsLedger->balance_after,
                ]);
            }

            event(new ActivityCreated(
                'Referral',
                $referral,
                (string) $authUser->id,
                $referral->to_user_id ? (string) $referral->to_user_id : null
            ));

            $targetUser = User::find($referral->to_user_id);

            if ($targetUser) {
                $notifyUserService->notifyUser(
                    $targetUser,
                    $authUser,
                    'activity_referral',
                    [
                        'activity_type' => 'referral',
                        'activity_id' => (string) $referral->id,
                        'title' => 'New Referral',
                        'body' => ($authUser->display_name ?? $authUser->name ?? 'A member') . ' sent you a referral',
                    ],
                    $referral
                );
            }

            $updatedLifeImpact = $this->increaseLifeImpact(
                (string) $authUser->id,
                1,
                'referral',
                'Gave a qualified business referral',
                (string) $authUser->id,
                (string) $referral->id,
                'Life impact added for referral activity.',
                [
                    'referral_type' => $referral->referral_type,
                    'referral_date' => $referral->referral_date,
                    'referral_of' => $referral->referral_of,
                    'phone' => $referral->phone,
                    'email' => $referral->email,
                    'address' => $referral->address,
                    'hot_value' => $referral->hot_value,
                    'remarks' => $referral->remarks,
                    'to_user_id' => $referral->to_user_id ? (string) $referral->to_user_id : null,
                ]
            );
            $referral->setAttribute('life_impacted_count', $updatedLifeImpact);

            // Postman example (referral create):
            // {
            //   "to_user_id": "<receiver-user-uuid>",
            //   "referral_type": "service",
            //   "referral_date": "2026-01-20",
            //   "referral_of": "Acme Corp",
            //   "phone": "+1234567890",
            //   "email": "lead@example.com",
            //   "address": "Downtown",
            //   "hot_value": "hot",
            //   "remarks": "High intent"
            // }
            // Verify SQL:
            // select * from notifications where user_id = '<receiver-user-uuid>' order by created_at desc limit 20;

            $activityCreativeService->createOrUpdateCreative('referral', (string) $referral->id, (string) $authUser->id, $activityCreativeService->buildCreativePayload('referral', $referral));
            $referral->setAttribute('creative', [
                'available' => true,
                'download_url' => $activityCreativeService->buildDownloadUrl('referral', (string) $referral->id),
            ]);

            return $this->success($referral, 'Referral saved successfully', 201);
        } catch (Throwable $e) {
            return $this->error('Something went wrong', 500);
        }
    }

    public function show(Request $request, string $id)
    {
        $authUser = $request->user();

        $referral = Referral::where('id', $id)
            ->where('is_deleted', false)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($authUser) {
                $q->where('from_user_id', $authUser->id)
                    ->orWhere('to_user_id', $authUser->id);
            })
            ->first();

        if (! $referral) {
            return $this->error('Referral not found', 404);
        }

        return $this->success($referral);
    }
}

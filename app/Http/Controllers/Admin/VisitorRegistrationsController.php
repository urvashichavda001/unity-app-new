<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VisitorRegistration;
use App\Services\Coins\CoinsService;
use App\Support\AdminCircleScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;

class VisitorRegistrationsController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->query('status', 'all');
        $search = trim((string) $request->query('search', ''));
        $circleId = (string) $request->query('circle_id', 'all');

        $peerQ = trim((string) $request->query('peer_q', ''));
        $peerPhone = trim((string) $request->query('peer_phone', ''));
        $eventType = trim((string) $request->query('event_type', ''));
        $eventName = trim((string) $request->query('event_name', ''));
        $eventDate = trim((string) $request->query('event_date', ''));
        $visitorName = trim((string) $request->query('visitor_name', ''));
        $visitorMobile = trim((string) $request->query('visitor_mobile', ''));
        $visitorCity = trim((string) $request->query('visitor_city', ''));
        $visitorBusiness = trim((string) $request->query('visitor_business', ''));

        $hasUsersName = Schema::hasColumn('users', 'name');
        $hasUsersCompany = Schema::hasColumn('users', 'company');
        $hasUsersBusinessName = Schema::hasColumn('users', 'business_name');

        $query = VisitorRegistration::query()
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

        if ($search !== '') {
            $like = "%{$search}%";
            $query->where(function ($q) use ($like, $hasUsersName, $hasUsersCompany, $hasUsersBusinessName) {
                $q->where('visitor_full_name', 'ILIKE', $like)
                    ->orWhere('visitor_mobile', 'ILIKE', $like)
                    ->orWhere('visitor_city', 'ILIKE', $like)
                    ->orWhere('visitor_business', 'ILIKE', $like)
                    ->orWhere('event_type', 'ILIKE', $like)
                    ->orWhere('event_name', 'ILIKE', $like)
                    ->orWhereHas('user', function ($userQuery) use ($like, $hasUsersName, $hasUsersCompany, $hasUsersBusinessName) {
                        $userQuery->where(function ($uq) use ($like, $hasUsersName, $hasUsersCompany, $hasUsersBusinessName) {
                            $uq->where('display_name', 'ILIKE', $like)
                                ->orWhere('first_name', 'ILIKE', $like)
                                ->orWhere('last_name', 'ILIKE', $like)
                                ->orWhere('phone', 'ILIKE', $like)
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

        if ($eventType !== '') {
            $query->where('event_type', 'ILIKE', "%{$eventType}%");
        }

        if ($eventName !== '') {
            $query->where('event_name', 'ILIKE', "%{$eventName}%");
        }

        if ($eventDate !== '') {
            $query->whereDate('event_date', $eventDate);
        }

        if ($visitorName !== '') {
            $query->where('visitor_full_name', 'ILIKE', "%{$visitorName}%");
        }

        if ($visitorMobile !== '') {
            $query->where('visitor_mobile', 'ILIKE', "%{$visitorMobile}%");
        }

        if ($visitorCity !== '') {
            $query->where('visitor_city', 'ILIKE', "%{$visitorCity}%");
        }

        if ($visitorBusiness !== '') {
            $query->where('visitor_business', 'ILIKE', "%{$visitorBusiness}%");
        }

        AdminCircleScope::applyToActivityQuery($query, Auth::guard('admin')->user(), 'visitor_registrations.user_id', null);

        $registrations = $query
            ->orderByDesc('created_at')
            ->paginate(25)
            ->appends($request->query());

        $circles = AdminCircleScope::circleOptions(auth('admin')->user());

        return view('admin.visitor_registrations.index', [
            'registrations' => $registrations,
            'circles' => $circles,
            'filters' => [
                'status' => in_array($status, ['all', 'pending', 'approved', 'rejected'], true) ? $status : 'all',
                'search' => $search,
                'circle_id' => $circleId,
                'peer_q' => $peerQ,
                'peer_phone' => $peerPhone,
                'event_type' => $eventType,
                'event_name' => $eventName,
                'event_date' => $eventDate,
                'visitor_name' => $visitorName,
                'visitor_mobile' => $visitorMobile,
                'visitor_city' => $visitorCity,
                'visitor_business' => $visitorBusiness,
            ],
            'statuses' => ['pending', 'approved', 'rejected'],
        ]);
    }

    public function approve(string $id, CoinsService $coinsService): RedirectResponse
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $admin = Auth::guard('admin')->user();
        $message = DB::transaction(function () use ($id, $admin, $coinsService) {
            $registration = VisitorRegistration::query()
                ->where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! AdminCircleScope::userInScope($admin, $registration->user_id)) {
                abort(403);
            }

            if ($registration->status === 'approved' || $registration->coins_awarded) {
                return 'Already approved.';
            }

            $registration->status = 'approved';
            $registration->reviewed_at = now();
            $registration->reviewed_by_admin_user_id = $admin?->id;
            $registration->save();

            $amount = (int) config('coins.register_visitor', 0);
            $ledger = $coinsService->reward($registration->user, $amount, 'Register a Visitor (Approved)');

            if ($ledger) {
                $registration->coins_awarded = true;
                $registration->coins_awarded_at = now();
                $registration->save();
            }

            return 'Visitor registration approved.';
        });

        return redirect()
            ->back()
            ->with('success', $message);
    }

    public function reject(string $id): RedirectResponse
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }

        $admin = Auth::guard('admin')->user();
        $message = DB::transaction(function () use ($id, $admin) {
            $registration = VisitorRegistration::query()
                ->where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! AdminCircleScope::userInScope($admin, $registration->user_id)) {
                abort(403);
            }

            if ($registration->status === 'approved') {
                return 'Already approved.';
            }

            $registration->status = 'rejected';
            $registration->reviewed_at = now();
            $registration->reviewed_by_admin_user_id = $admin?->id;
            $registration->save();

            return 'Visitor registration rejected.';
        });

        return redirect()
            ->back()
            ->with('success', $message);
    }
}

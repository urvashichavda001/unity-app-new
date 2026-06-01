<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\V1\Scanner\ScannerAuthController;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\EventScannerAuthorization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminEventScannerController extends Controller
{
    public function index(Request $request, Event $event): View
    {
        $search = trim((string) $request->input('search', ''));

        $scannerCandidates = User::query()
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
                $query->where(function ($inner) use ($like): void {
                    $inner->where('display_name', 'ilike', $like)
                        ->orWhere('first_name', 'ilike', $like)
                        ->orWhere('last_name', 'ilike', $like)
                        ->orWhere('email', 'ilike', $like)
                        ->orWhere('phone', 'ilike', $like)
                        ->orWhere('company_name', 'ilike', $like);
                });
            })
            ->orderBy('display_name')
            ->limit(300)
            ->get(['id', 'display_name', 'first_name', 'last_name', 'email', 'phone', 'company_name']);

        $scannerAuthorizations = EventScannerAuthorization::query()
            ->with('scanner')
            ->where('event_id', $event->id)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('assigned_at')
            ->get();

        return view('admin.events.scanners', compact('event', 'scannerCandidates', 'scannerAuthorizations', 'search'));
    }

    public function store(Request $request, Event $event): RedirectResponse
    {
        $data = $request->validate([
            'scanner_user_id' => ['required', 'uuid', 'exists:users,id'],
        ]);

        $authorization = EventScannerAuthorization::query()
            ->where('event_id', $event->id)
            ->where('scanner_user_id', $data['scanner_user_id'])
            ->first();

        if ($authorization && $authorization->status === EventScannerAuthorization::STATUS_ACTIVE && $authorization->revoked_at === null) {
            return back()->with('error', 'This user is already an active scanner for this event.');
        }

        $payload = [
            'assigned_by_user_id' => $this->assignedByUserId($request),
            'status' => EventScannerAuthorization::STATUS_ACTIVE,
            'assigned_at' => now(),
            'revoked_at' => null,
        ];

        if ($authorization) {
            $authorization->forceFill($payload)->save();
        } else {
            EventScannerAuthorization::query()->create($payload + [
                'event_id' => $event->id,
                'scanner_user_id' => $data['scanner_user_id'],
            ]);
        }

        return back()->with('success', 'Scanner assigned successfully.');
    }

    public function destroy(Event $event, string $scannerUserId): RedirectResponse
    {
        $scannerUser = User::query()->findOrFail($scannerUserId);

        $authorization = EventScannerAuthorization::query()
            ->where('event_id', $event->id)
            ->where('scanner_user_id', $scannerUserId)
            ->firstOrFail();

        $authorization->forceFill([
            'status' => EventScannerAuthorization::STATUS_REVOKED,
            'revoked_at' => now(),
        ])->save();

        $scannerUser->tokens()->where('name', ScannerAuthController::TOKEN_NAME)->delete();

        return back()->with('success', 'Scanner revoked successfully.');
    }

    private function assignedByUserId(Request $request): ?string
    {
        $webUser = $request->user();
        if ($webUser instanceof User) {
            return $webUser->id;
        }

        $adminUser = $request->user('admin');
        if ($adminUser instanceof AdminUser && User::query()->whereKey($adminUser->id)->exists()) {
            return $adminUser->id;
        }

        return null;
    }
}

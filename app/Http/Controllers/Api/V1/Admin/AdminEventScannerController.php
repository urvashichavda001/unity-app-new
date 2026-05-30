<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Event;
use App\Models\EventScannerAuthorization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminEventScannerController extends BaseApiController
{
    public function index(Event $event): JsonResponse
    {
        $items = EventScannerAuthorization::query()
            ->with('scanner')
            ->where('event_id', $event->id)
            ->orderByDesc('assigned_at')
            ->get()
            ->map(fn (EventScannerAuthorization $authorization) => $this->authorizationPayload($authorization))
            ->values();

        return $this->success(['items' => $items], 'Event scanners fetched successfully.');
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $validated = $request->validate([
            'scanner_user_id' => ['required', 'uuid', 'exists:users,id'],
        ]);

        $authorization = EventScannerAuthorization::query()->updateOrCreate(
            [
                'event_id' => $event->id,
                'scanner_user_id' => $validated['scanner_user_id'],
            ],
            [
                'assigned_by_user_id' => $request->user()?->id,
                'status' => EventScannerAuthorization::STATUS_ACTIVE,
                'assigned_at' => now(),
                'revoked_at' => null,
            ]
        );

        return $this->success($this->authorizationPayload($authorization->fresh('scanner')), 'Scanner authorized successfully.');
    }

    public function destroy(Event $event, string $scannerUserId): JsonResponse
    {
        User::query()->findOrFail($scannerUserId);

        $authorization = EventScannerAuthorization::query()
            ->where('event_id', $event->id)
            ->where('scanner_user_id', $scannerUserId)
            ->firstOrFail();

        $authorization->forceFill([
            'status' => EventScannerAuthorization::STATUS_REVOKED,
            'revoked_at' => now(),
        ])->save();

        return $this->success($this->authorizationPayload($authorization->fresh('scanner')), 'Scanner revoked successfully.');
    }

    private function authorizationPayload(EventScannerAuthorization $authorization): array
    {
        return [
            'scanner_user_id' => $authorization->scanner_user_id,
            'display_name' => $authorization->scanner?->display_name,
            'email' => $authorization->scanner?->email,
            'company_name' => $authorization->scanner?->company_name,
            'status' => $authorization->status,
            'assigned_at' => optional($authorization->assigned_at)->toISOString(),
            'revoked_at' => optional($authorization->revoked_at)->toISOString(),
        ];
    }
}

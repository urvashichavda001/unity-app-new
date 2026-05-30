<?php

namespace App\Http\Controllers\Api\V1\Scanner;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Event;
use App\Models\EventScannerAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScannerEventController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $items = EventScannerAuthorization::query()
            ->active()
            ->where('scanner_user_id', $request->user()->id)
            ->with(['event' => fn ($query) => $query
                ->withCount([
                    'registrations as total_registered' => fn ($registrationQuery) => $registrationQuery->where('status', '!=', 'cancelled'),
                    'attendances as total_checked_in',
                ])])
            ->get()
            ->filter(fn (EventScannerAuthorization $authorization) => $authorization->event !== null)
            ->map(fn (EventScannerAuthorization $authorization) => $this->eventPayload($authorization->event, $authorization->status))
            ->values();

        return $this->success(['items' => $items], 'Scanner events fetched successfully.');
    }

    public function summary(Request $request, Event $event): JsonResponse
    {
        if (! $this->isActiveScanner($event, $request->user()->id)) {
            return $this->unauthorizedScannerResponse();
        }

        $totalRegistered = $this->totalRegistered($event);
        $totalCheckedIn = $this->totalCheckedIn($event);

        return $this->success([
            'event_id' => $event->id,
            'title' => $event->title,
            'total_registered' => $totalRegistered,
            'total_checked_in' => $totalCheckedIn,
            'total_pending' => max($totalRegistered - $totalCheckedIn, 0),
            'event_date' => optional($event->start_at)->toISOString(),
            'start_at' => optional($event->start_at)->toISOString(),
            'location' => $event->location_text,
        ], 'Scanner event summary fetched successfully.');
    }

    private function eventPayload(Event $event, string $authorizationStatus): array
    {
        return [
            'event_id' => $event->id,
            'id' => $event->id,
            'title' => $event->title,
            'name' => $event->title,
            'event_date' => optional($event->start_at)->toISOString(),
            'start_at' => optional($event->start_at)->toISOString(),
            'location' => $event->location_text,
            'total_registered' => (int) ($event->total_registered ?? 0),
            'total_checked_in' => (int) ($event->total_checked_in ?? 0),
            'scanner_authorization_status' => $authorizationStatus,
        ];
    }

    private function isActiveScanner(Event $event, string $userId): bool
    {
        return EventScannerAuthorization::query()
            ->active()
            ->where('event_id', $event->id)
            ->where('scanner_user_id', $userId)
            ->exists();
    }

    private function totalRegistered(Event $event): int
    {
        return $event->registrations()->where('status', '!=', 'cancelled')->count();
    }

    private function totalCheckedIn(Event $event): int
    {
        return $event->attendances()->count();
    }

    private function unauthorizedScannerResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'You are not authorized to scan attendance for this event.',
            'data' => null,
        ], 403);
    }
}

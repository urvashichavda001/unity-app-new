<?php

namespace App\Http\Controllers\Api\V1\Scanner;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Event;
use App\Models\EventAttendance;
use App\Models\EventRegistration;
use App\Models\EventScannerAuthorization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ScannerAttendanceController extends BaseApiController
{
    public function scan(Request $request, Event $event): JsonResponse
    {
        $validated = $request->validate([
            'qr_token' => ['required', 'string', 'max:512'],
        ]);

        if (! $this->isActiveScanner($event, $request->user()->id)) {
            return $this->unauthorizedScannerResponse();
        }

        $result = DB::transaction(function () use ($validated, $event, $request): array {
            $registration = EventRegistration::query()
                ->with(['user', 'event'])
                ->where('qr_token', $validated['qr_token'])
                ->lockForUpdate()
                ->first();

            if (! $registration || $registration->event_id !== $event->id || ! $this->isScannableRegistration($registration)) {
                return ['type' => 'invalid'];
            }

            $existingAttendance = EventAttendance::query()
                ->with('checkedInBy')
                ->where('event_id', $event->id)
                ->where('event_registration_id', $registration->id)
                ->lockForUpdate()
                ->first();

            if ($existingAttendance || $registration->checkin_status === EventAttendance::STATUS_CHECKED_IN) {
                return [
                    'type' => 'already_checked_in',
                    'attendance' => $existingAttendance,
                    'registration' => $registration->fresh(['checkedInBy', 'user']),
                ];
            }

            try {
                $attendance = EventAttendance::query()->create([
                    'event_id' => $event->id,
                    'attendee_user_id' => $registration->user_id,
                    'event_registration_id' => $registration->id,
                    'qr_token' => $registration->qr_token,
                    'checked_in_by_user_id' => $request->user()->id,
                    'checked_in_at' => now(),
                    'status' => EventAttendance::STATUS_CHECKED_IN,
                    'scan_meta' => [
                        'source' => 'UnityEventScan',
                        'ip' => $request->ip(),
                        'user_agent' => (string) $request->userAgent(),
                    ],
                ]);
            } catch (QueryException) {
                $attendance = EventAttendance::query()
                    ->with('checkedInBy')
                    ->where('event_id', $event->id)
                    ->where('event_registration_id', $registration->id)
                    ->first();

                return [
                    'type' => 'already_checked_in',
                    'attendance' => $attendance,
                    'registration' => $registration->fresh(['checkedInBy', 'user']),
                ];
            }

            $updates = [
                'status' => 'attended',
                'checkin_status' => 'checked_in',
                'checked_in_at' => $attendance->checked_in_at,
                'checked_in_by_user_id' => $request->user()->id,
            ];

            if (Schema::hasColumn('event_registrations', 'last_qr_scan_at')) {
                $updates['last_qr_scan_at'] = $attendance->checked_in_at;
            }
            if (Schema::hasColumn('event_registrations', 'attendance_source')) {
                $updates['attendance_source'] = 'unity_event_scan';
            }
            if (Schema::hasColumn('event_registrations', 'scan_device_info')) {
                $updates['scan_device_info'] = (string) $request->userAgent();
            }

            $registration->forceFill($updates)->save();

            return [
                'type' => 'checked_in',
                'attendance' => $attendance->fresh(['checkedInBy']),
                'registration' => $registration->fresh(['user', 'event']),
            ];
        });

        if ($result['type'] === 'invalid') {
            return response()->json([
                'success' => false,
                'message' => 'Invalid QR code for this event.',
            ], 422);
        }

        if ($result['type'] === 'already_checked_in') {
            return $this->alreadyCheckedInResponse($result['attendance'] ?? null, $result['registration']);
        }

        $registration = $result['registration'];
        $attendance = $result['attendance'];

        return $this->success([
            'attendee' => $this->attendeePayload($registration),
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
            ],
            'checked_in_at' => optional($attendance->checked_in_at)->toISOString(),
            'status' => $attendance->status,
        ], 'Attendance marked successfully.');
    }

    public function attendances(Request $request, Event $event): JsonResponse
    {
        if (! $this->isActiveScanner($event, $request->user()->id)) {
            return $this->unauthorizedScannerResponse();
        }

        $items = EventAttendance::query()
            ->with(['registration.user', 'attendee', 'checkedInBy'])
            ->where('event_id', $event->id)
            ->latest('checked_in_at')
            ->get()
            ->map(fn (EventAttendance $attendance) => [
                'attendee' => $attendance->registration ? $this->attendeePayload($attendance->registration) : $this->userPayload($attendance->attendee),
                'checked_in_at' => optional($attendance->checked_in_at)->toISOString(),
                'scanned_by' => $this->scannerPayload($attendance->checkedInBy),
                'status' => $attendance->status,
            ])
            ->values();

        return $this->success(['items' => $items], 'Event attendances fetched successfully.');
    }

    private function isActiveScanner(Event $event, string $userId): bool
    {
        return EventScannerAuthorization::query()
            ->active()
            ->where('event_id', $event->id)
            ->where('scanner_user_id', $userId)
            ->exists();
    }

    private function isScannableRegistration(EventRegistration $registration): bool
    {
        if ($registration->status === 'cancelled') {
            return false;
        }

        if ($registration->status === 'pending_payment') {
            return false;
        }

        if (($registration->payment_required ?? false) && ($registration->payment_status ?? null) !== 'paid') {
            return false;
        }

        return true;
    }

    private function alreadyCheckedInResponse(?EventAttendance $attendance, EventRegistration $registration): JsonResponse
    {
        $scannedBy = $attendance?->checkedInBy ?? $registration->checkedInBy;
        $checkedInAt = $attendance?->checked_in_at ?? $registration->checked_in_at;

        return response()->json([
            'success' => false,
            'message' => 'This attendee is already checked in.',
            'data' => [
                'already_checked_in' => true,
                'checked_in_at' => optional($checkedInAt)->toISOString(),
                'scanned_by' => $this->scannerPayload($scannedBy),
            ],
        ], 200);
    }

    private function unauthorizedScannerResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'You are not authorized to scan attendance for this event.',
        ], 403);
    }

    private function attendeePayload(EventRegistration $registration): array
    {
        if ($registration->user) {
            return $this->userPayload($registration->user);
        }

        return [
            'id' => null,
            'display_name' => $registration->visitor_name,
            'company_name' => $registration->visitor_company,
            'profile_photo_url' => null,
            'email' => $registration->visitor_email,
        ];
    }

    private function userPayload(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'display_name' => $user->display_name,
            'company_name' => $user->company_name,
            'profile_photo_url' => $user->profile_photo_url,
        ];
    }

    private function scannerPayload(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'display_name' => $user->display_name,
        ];
    }
}

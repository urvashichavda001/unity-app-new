<?php

namespace App\Http\Controllers\Api\V1\Scanner;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Event;
use App\Models\EventAttendance;
use App\Models\EventRegistration;
use App\Models\EventScannerAuthorization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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
            $registration = $this->registrationQueryForEvent($event)
                ->with(['user', 'event'])
                ->where('qr_token', $validated['qr_token'])
                ->lockForUpdate()
                ->first();

            if (! $registration || ! $this->isScannableRegistration($registration)) {
                return ['type' => 'invalid'];
            }

            $existingAttendance = $this->attendanceForRegistration($event, $registration)
                ->with('checkedInBy')
                ->lockForUpdate()
                ->first();

            if ($existingAttendance) {
                return [
                    'type' => 'already_checked_in',
                    'attendance' => $existingAttendance,
                    'registration' => $registration->fresh(['user']),
                ];
            }

            try {
                $attendance = EventAttendance::query()->create([
                    'event_id' => $event->id,
                    'attendee_user_id' => $this->attendeeUserId($registration),
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
            } catch (QueryException $exception) {
                if (! $this->isDuplicateAttendanceException($exception)) {
                    throw $exception;
                }

                $attendance = $this->attendanceForRegistration($event, $registration)
                    ->with('checkedInBy')
                    ->first();

                return [
                    'type' => 'already_checked_in',
                    'attendance' => $attendance,
                    'registration' => $registration->fresh(['user']),
                ];
            }

            $this->syncOptionalRegistrationScanMetadata($registration, $attendance, $request);

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
                'data' => null,
            ], 422);
        }

        if ($result['type'] === 'already_checked_in') {
            return $this->alreadyCheckedInResponse($result['attendance'] ?? null);
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

    private function registrationQueryForEvent(Event $event): Builder
    {
        $query = EventRegistration::query();

        if (Schema::hasColumn('event_registrations', 'event_id')) {
            return $query->where('event_id', $event->id);
        }

        if (Schema::hasColumn('event_registrations', 'occurrence_id') && Schema::hasTable('event_occurrences')) {
            return $query->whereHas('occurrence', fn (Builder $occurrenceQuery) => $occurrenceQuery->where('event_id', $event->id));
        }

        return $query->whereRaw('1 = 0');
    }

    private function attendanceForRegistration(Event $event, EventRegistration $registration): Builder
    {
        return EventAttendance::query()
            ->where('event_id', $event->id)
            ->where('event_registration_id', $registration->id);
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

    private function attendeeUserId(EventRegistration $registration): ?string
    {
        if (! Schema::hasColumn('event_registrations', 'user_id')) {
            return null;
        }

        return $registration->user_id;
    }

    private function syncOptionalRegistrationScanMetadata(EventRegistration $registration, EventAttendance $attendance, Request $request): void
    {
        $updates = [];

        if (Schema::hasColumn('event_registrations', 'status')) {
            $updates['status'] = 'attended';
        }
        if (Schema::hasColumn('event_registrations', 'checkin_status')) {
            $updates['checkin_status'] = 'checked_in';
        }
        if (Schema::hasColumn('event_registrations', 'checked_in_at')) {
            $updates['checked_in_at'] = $attendance->checked_in_at;
        }
        if (Schema::hasColumn('event_registrations', 'last_qr_scan_at')) {
            $updates['last_qr_scan_at'] = $attendance->checked_in_at;
        }
        if (Schema::hasColumn('event_registrations', 'attendance_source')) {
            $updates['attendance_source'] = 'unity_event_scan';
        }
        if (Schema::hasColumn('event_registrations', 'scan_device_info')) {
            $updates['scan_device_info'] = (string) $request->userAgent();
        }

        if ($updates !== []) {
            $registration->forceFill($updates)->save();
        }
    }

    private function isDuplicateAttendanceException(QueryException $exception): bool
    {
        return (string) ($exception->errorInfo[0] ?? '') === '23505'
            || str_contains(strtolower($exception->getMessage()), 'uq_event_attendances_event_registration')
            || str_contains(strtolower($exception->getMessage()), 'unique');
    }

    private function alreadyCheckedInResponse(?EventAttendance $attendance): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'This attendee is already checked in.',
            'data' => [
                'already_checked_in' => true,
                'checked_in_at' => optional($attendance?->checked_in_at)->toISOString(),
                'scanned_by' => $this->scannerPayload($attendance?->checkedInBy),
            ],
        ], 200);
    }

    private function unauthorizedScannerResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'You are not authorized to scan attendance for this event.',
            'data' => null,
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

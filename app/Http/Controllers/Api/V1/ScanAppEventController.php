<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Event;
use App\Models\EventQrScanLog;
use App\Models\EventRegistration;
use App\Models\ScanAppUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ScanAppEventController extends BaseApiController
{
    public function index(Request $request)
    {
        $scanner = $this->activeScanner($request);
        if (! $scanner instanceof ScanAppUser) {
            return $scanner;
        }

        $events = Event::query()
            ->when($scanner->event_id, fn ($q) => $q->where('id', $scanner->event_id))
            ->when(! $scanner->event_id, fn ($q) => $q->where('start_at', '>=', now()->subDay())->orderBy('start_at'))
            ->get();

        return $this->success([
            'items' => $events->map(fn (Event $event) => $this->eventPayload($event))->values(),
        ], 'Events fetched successfully.');
    }

    public function scan(Request $request, Event $event)
    {
        $scanner = $this->activeScanner($request);
        if (! $scanner instanceof ScanAppUser) {
            return $scanner;
        }

        $data = $request->validate([
            'qr_token' => ['required', 'string', 'max:2048'],
            'device_info' => ['nullable', 'array'],
        ]);

        $qrToken = $this->normalizeQrToken($data['qr_token']);
        $deviceInfo = $data['device_info'] ?? null;
        $status = 'failed';
        $message = 'Unable to scan QR.';
        $registration = null;
        $checkedInAt = null;
        $meta = ['route_event_id' => $event->id];
        $httpStatus = 400;

        if ($scanner->event_id && $scanner->event_id !== $event->id) {
            $status = 'wrong_event';
            $message = 'Scanner is not assigned to this event.';
            $this->writeScanLog($event->id, null, $scanner->id, $qrToken, $status, $message, $deviceInfo, $meta + ['assigned_event_id' => $scanner->event_id]);

            return $this->error($message, 403);
        }

        try {
            DB::transaction(function () use ($qrToken, $event, $scanner, $deviceInfo, &$status, &$message, &$registration, &$checkedInAt, &$meta, &$httpStatus): void {
                $registration = EventRegistration::query()
                    ->with(['event.circle', 'occurrence', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub'])
                    ->where('qr_token', $qrToken)
                    ->lockForUpdate()
                    ->first();

                if (! $registration) {
                    $status = 'invalid_qr';
                    $message = 'QR token not found.';
                    $httpStatus = 404;
                    return;
                }

                $meta['registration_id'] = $registration->id;
                $meta['registration_event_id'] = $registration->event_id;
                $meta['registration_status'] = $registration->status;
                $meta['checkin_status'] = $registration->checkin_status;

                if ($registration->event_id !== $event->id) {
                    $status = 'wrong_event';
                    $message = 'QR code does not belong to this event.';
                    $httpStatus = 403;
                    return;
                }

                if ($registration->status === 'cancelled') {
                    $status = 'failed';
                    $message = 'Registration is cancelled.';
                    $httpStatus = 422;
                    return;
                }

                if ($registration->status === 'pending_payment' || (($registration->payment_required ?? false) && ($registration->payment_status ?? null) !== 'paid')) {
                    $status = 'failed';
                    $message = 'Payment is required before QR check-in.';
                    $httpStatus = 422;
                    return;
                }

                if (empty($registration->qr_code_path) && empty($registration->qr_code_url)) {
                    $status = 'invalid_qr';
                    $message = 'QR code has not been generated for this registration.';
                    $httpStatus = 422;
                    return;
                }

                if (! $registration->occurrence) {
                    $status = 'failed';
                    $message = 'Event occurrence not found.';
                    $httpStatus = 422;
                    return;
                }

                if (! $registration->event || ! $registration->event->qr_checkin_enabled) {
                    $status = 'failed';
                    $message = 'QR check-in is not enabled for this event.';
                    $httpStatus = 422;
                    return;
                }

                if ($registration->checkin_status === 'checked_in') {
                    $status = 'already_checked_in';
                    $message = 'User already checked in.';
                    $checkedInAt = $registration->checked_in_at;
                    $httpStatus = 200;
                    return;
                }

                $updates = [
                    'status' => 'attended',
                    'checkin_status' => 'checked_in',
                    'checked_in_at' => now(),
                ];

                if (Schema::hasColumn('event_registrations', 'last_qr_scan_at')) {
                    $updates['last_qr_scan_at'] = now();
                }
                if (Schema::hasColumn('event_registrations', 'attendance_source')) {
                    $updates['attendance_source'] = 'scan_app';
                }
                if (Schema::hasColumn('event_registrations', 'scan_device_info') && $deviceInfo) {
                    $updates['scan_device_info'] = json_encode($deviceInfo);
                }

                $registration->forceFill($updates)->save();
                $this->refreshOccurrenceCounts($registration);

                $registration = $registration->fresh(['event.circle', 'occurrence', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub']);
                $checkedInAt = $registration->checked_in_at;
                $status = 'success';
                $message = 'Attendance marked successfully.';
                $httpStatus = 200;
            });
        } catch (Throwable $exception) {
            Log::error('scan_app_qr_scan_failed', ['error' => $exception->getMessage(), 'event_id' => $event->id, 'scanner_id' => $scanner->id]);
            $status = 'failed';
            $message = 'Unable to scan QR. Please try again.';
            $httpStatus = 500;
            $meta['exception'] = $exception->getMessage();
        }

        $this->writeScanLog($event->id, $registration?->user_id, $scanner->id, $qrToken, $status, $message, $deviceInfo, $meta);

        if (in_array($status, ['success', 'already_checked_in'], true) && $registration) {
            return $this->success([
                'event_id' => $event->id,
                'checked_in_user' => $this->attendeePayload($registration),
                'scanner' => $this->scannerPayload($scanner),
                'checked_in_at' => optional($checkedInAt)->toISOString(),
            ], $message, $httpStatus);
        }

        return $this->error($message, $httpStatus, ['scan_status' => $status]);
    }

    public function attendanceHistory(Request $request, Event $event)
    {
        $scanner = $this->activeScanner($request);
        if (! $scanner instanceof ScanAppUser) {
            return $scanner;
        }

        if ($scanner->event_id && $scanner->event_id !== $event->id) {
            return $this->error('Scanner is not assigned to this event.', 403);
        }

        $registrations = EventRegistration::query()
            ->with(['user'])
            ->where('event_id', $event->id)
            ->where('status', '!=', 'cancelled')
            ->orderBy('registered_at')
            ->get();

        $scanLogs = EventQrScanLog::query()
            ->with('scanner')
            ->where('event_id', $event->id)
            ->whereIn('scan_status', ['success', 'already_checked_in'])
            ->latest('scanned_at')
            ->get()
            ->keyBy('qr_token');

        $checkedIn = $registrations->where('checkin_status', 'checked_in')->values();
        $pending = $registrations->reject(fn (EventRegistration $registration) => $registration->checkin_status === 'checked_in')->values();

        return $this->success([
            'event' => $this->eventPayload($event),
            'scanner' => $this->scannerPayload($scanner),
            'summary' => [
                'total_registered' => $registrations->count(),
                'checked_in' => $checkedIn->count(),
                'pending' => $pending->count(),
            ],
            'checked_in_users' => $checkedIn->map(fn (EventRegistration $registration) => $this->historyAttendeePayload($registration, $scanLogs->get($registration->qr_token)))->values(),
            'pending_users' => $pending->map(fn (EventRegistration $registration) => $this->historyAttendeePayload($registration))->values(),
        ], 'Attendance history fetched successfully.');
    }

    private function activeScanner(Request $request): ScanAppUser|\Illuminate\Http\JsonResponse
    {
        $scanner = $request->user();
        if (! $scanner instanceof ScanAppUser) {
            return $this->error('Scanner authentication required.', 401);
        }

        if (! $scanner->is_active) {
            return $this->error('Scanner account is inactive.', 403);
        }

        return $scanner;
    }

    private function eventPayload(Event $event): array
    {
        return [
            'id' => $event->id,
            'name' => $event->title,
            'title' => $event->title,
            'date' => optional($event->start_at)->toISOString(),
            'venue' => $event->location_text,
            'hotel' => $event->location_text,
            'location' => $event->location_text,
        ];
    }

    private function scannerPayload(ScanAppUser $scanner): array
    {
        return [
            'id' => $scanner->id,
            'name' => $scanner->name,
            'hotel_name' => $scanner->hotel_name,
        ];
    }

    private function attendeePayload(EventRegistration $registration): array
    {
        $user = $registration->user;

        return [
            'id' => $user?->id,
            'name' => $user?->display_name ?: trim(($user?->first_name ?? '').' '.($user?->last_name ?? '')) ?: $registration->visitor_name,
            'email' => $user?->email ?? $registration->visitor_email,
            'phone' => $user?->phone ?? $registration->visitor_phone,
        ];
    }

    private function historyAttendeePayload(EventRegistration $registration, ?EventQrScanLog $scanLog = null): array
    {
        $payload = $this->attendeePayload($registration);
        $payload['company_name'] = $registration->user?->company_name ?? $registration->visitor_company;
        $payload['checked_in_at'] = optional($registration->checked_in_at)->toISOString();
        $payload['scanned_by'] = $scanLog?->scanner?->name;
        $payload['hotel_name'] = $scanLog?->scanner?->hotel_name;

        return $payload;
    }

    private function normalizeQrToken(string $qrToken): string
    {
        $qrToken = trim($qrToken);
        $path = parse_url($qrToken, PHP_URL_PATH);
        if (is_string($path) && str_contains($path, '/events/checkin/qr/')) {
            return basename($path);
        }

        return $qrToken;
    }

    private function refreshOccurrenceCounts(EventRegistration $registration): void
    {
        $checkedInCount = EventRegistration::query()
            ->where('occurrence_id', $registration->occurrence_id)
            ->where('checkin_status', 'checked_in')
            ->whereNull('deleted_at')
            ->count();

        $registeredCount = EventRegistration::query()
            ->where('occurrence_id', $registration->occurrence_id)
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at')
            ->count();

        $updates = ['registered_count' => $registeredCount];
        if (Schema::hasColumn('event_occurrences', 'checked_in_count')) {
            $updates['checked_in_count'] = $checkedInCount;
        }

        DB::table('event_occurrences')->where('id', $registration->occurrence_id)->update($updates);
    }

    private function writeScanLog(string $eventId, ?string $userId, string $scannerId, string $qrToken, string $status, string $message, ?array $deviceInfo, array $meta): void
    {
        try {
            EventQrScanLog::query()->create([
                'event_id' => $eventId,
                'user_id' => $userId,
                'scanner_id' => $scannerId,
                'qr_token' => $qrToken,
                'scan_status' => $status,
                'scan_message' => $message,
                'scanned_at' => now(),
                'device_info' => $deviceInfo,
                'meta' => $meta,
            ]);
        } catch (Throwable $exception) {
            Log::error('event_qr_scan_log_write_failed', ['error' => $exception->getMessage(), 'event_id' => $eventId, 'scanner_id' => $scannerId, 'scan_status' => $status]);
        }
    }
}

<?php

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class EventRegistrationService
{
    public function __construct(
        private readonly EventService $events,
        private readonly EventQrService $qr,
    ) {}

    public function registerMember(Event $event, EventOccurrence $occurrence, User $user, string $source = 'app'): EventRegistration
    {
        if ($occurrence->event_id !== $event->id) {
            throw ValidationException::withMessages(['occurrence_id' => 'Occurrence does not belong to this event.']);
        }
        $canRegister = $this->events->canRegister($event, $user);
        if (! $canRegister['can_register']) {
            throw ValidationException::withMessages(['event_id' => $canRegister['reason']]);
        }

        return $this->createRegistration($event, $occurrence, ['user_id' => $user->id, 'source' => $source]);
    }

    public function registerVisitor(Event $event, EventOccurrence $occurrence, array $data): EventRegistration
    {
        if ($occurrence->event_id !== $event->id) {
            throw ValidationException::withMessages(['occurrence_id' => 'Occurrence does not belong to this event.']);
        }
        if (! $this->events->visitorRegistrationEnabled($event)) {
            throw ValidationException::withMessages(['event_id' => 'Visitor registration is not enabled for this event.']);
        }

        $source = $this->normalizeVisitorSource($data['source'] ?? 'visitor_app');
        unset($data['source']);

        return $this->createRegistration($event, $occurrence, $data + ['source' => $source]);
    }

    public function syncZohoVisitor(Event $event, EventOccurrence $occurrence, array $data): EventRegistration
    {
        if ($occurrence->event_id !== $event->id) {
            throw ValidationException::withMessages(['occurrence_id' => 'Occurrence does not belong to this event.']);
        }
        if (! $this->events->visitorRegistrationEnabled($event)) {
            throw ValidationException::withMessages(['event_id' => 'Visitor registration is not enabled for this event.']);
        }

        return DB::transaction(function () use ($event, $occurrence, $data): EventRegistration {
            $existing = $this->duplicateVisitorQuery($occurrence->id, $data)->lockForUpdate()->first();
            if ($existing) {
                $updates = $this->filterRegistrationColumns(array_filter([
                    'visitor_name' => $data['visitor_name'] ?? $existing->visitor_name,
                    'visitor_email' => $data['visitor_email'] ?? $existing->visitor_email,
                    'visitor_phone' => $data['visitor_phone'] ?? $existing->visitor_phone,
                    'visitor_company' => $data['visitor_company'] ?? $existing->visitor_company,
                    'visitor_city' => $data['visitor_city'] ?? $existing->visitor_city,
                    'zoho_form_entry_id' => $data['zoho_form_entry_id'] ?? $existing->zoho_form_entry_id,
                    'zoho_payment_id' => $data['zoho_payment_id'] ?? $existing->zoho_payment_id,
                    'zoho_payment_status' => $data['zoho_payment_status'] ?? $existing->zoho_payment_status,
                    'source' => 'zoho_form',
                ], fn ($value) => $value !== null));
                $existing->forceFill($updates)->save();

                if (empty($existing->qr_code_path) || empty($existing->qr_code_url)) {
                    $this->qr->generateAndStore($existing);
                }

                return $existing->fresh(['event.circle', 'occurrence', 'user']);
            }

            return $this->createRegistration($event, $occurrence, $data + ['source' => 'zoho_form']);
        });
    }

    public function qrDetails(EventRegistration $registration): array
    {
        return [
            'registration_id' => $registration->id,
            'event_id' => $registration->event_id,
            'occurrence_id' => $registration->occurrence_id,
            'qr_token' => $registration->qr_token,
            'qr_payload' => $this->qr->payload($registration->qr_token),
            'qr_code_url' => $registration->qr_code_url ?: $this->qr->url($registration->qr_code_path),
            'qr_code_svg' => $registration->qr_code_svg,
            'status' => $registration->status,
            'checkin_status' => $registration->checkin_status,
        ];
    }

    private function createRegistration(Event $event, EventOccurrence $occurrence, array $data): EventRegistration
    {
        return DB::transaction(function () use ($event, $occurrence, $data): EventRegistration {
            $lockedOccurrence = EventOccurrence::query()
                ->where('id', $occurrence->id)
                ->lockForUpdate()
                ->firstOrFail();

            $registeredCount = EventRegistration::query()
                ->where('occurrence_id', $lockedOccurrence->id)
                ->where('status', '!=', 'cancelled')
                ->whereNull('deleted_at')
                ->count();

            $this->assertCapacity($event, $lockedOccurrence, $registeredCount);

            $query = isset($data['user_id'])
                ? EventRegistration::query()->where('occurrence_id', $lockedOccurrence->id)->where('user_id', $data['user_id'])->where('status', '!=', 'cancelled')->whereNull('deleted_at')
                : $this->duplicateVisitorQuery($lockedOccurrence->id, $data);

            if ($query->exists()) {
                throw ValidationException::withMessages(['registration' => 'Already registered for this event occurrence.']);
            }

            $registration = EventRegistration::query()->create($this->filterRegistrationColumns(array_merge($data, [
                'event_id' => $event->id,
                'occurrence_id' => $lockedOccurrence->id,
                'qr_token' => $this->uniqueToken(),
                'status' => 'registered',
                'checkin_status' => 'pending',
                'registered_at' => now(),
            ])));

            $this->qr->generateAndStore($registration);

            $lockedOccurrence->forceFill(['registered_count' => $registeredCount + 1])->save();

            $this->notifySafely($registration);

            return $registration->fresh(['event.circle', 'occurrence', 'user']);
        });
    }

    private function duplicateVisitorQuery(string $occurrenceId, array $data)
    {
        return EventRegistration::query()
            ->where('occurrence_id', $occurrenceId)
            ->where('status', '!=', 'cancelled')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($data): void {
                $matched = false;
                foreach (['zoho_form_entry_id', 'visitor_phone', 'visitor_email'] as $field) {
                    if (! empty($data[$field])) {
                        $q->orWhere($field, $data[$field]);
                        $matched = true;
                    }
                }
                if (! $matched) {
                    $q->whereRaw('1 = 0');
                }
            });
    }

    private function assertCapacity(Event $event, EventOccurrence $occurrence, int $registeredCount): void
    {
        $registrationLimit = $occurrence->registration_limit ?? $event->registration_limit;

        if (! $registrationLimit) {
            return;
        }

        if ($registeredCount >= $registrationLimit) {
            throw ValidationException::withMessages(['registration_limit' => 'Registration limit has been reached.']);
        }
    }

    private function uniqueToken(): string
    {
        do {
            $token = $this->qr->generateToken();
        } while (EventRegistration::query()->where('qr_token', $token)->exists());

        return $token;
    }

    private function normalizeVisitorSource(string $source): string
    {
        return match ($source) {
            'zoho_form' => 'zoho_form',
            'admin' => 'admin',
            default => 'visitor_app',
        };
    }

    private function filterRegistrationColumns(array $data): array
    {
        return array_filter($data, fn ($value, $key) => Schema::hasColumn('event_registrations', $key), ARRAY_FILTER_USE_BOTH);
    }

    private function notifySafely(EventRegistration $registration): void
    {
        try {
            Log::info('Event registration notification queued placeholder.', ['event_registration_id' => $registration->id]);
        } catch (\Throwable $exception) {
            Log::error('Event registration notification failed.', ['event_registration_id' => $registration->id, 'error' => $exception->getMessage()]);
        }
    }
}

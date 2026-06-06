<?php

namespace App\Http\Controllers;

use App\Http\Requests\Event\VisitorEventRegistrationRequest;
use App\Models\CircleCategory;
use App\Models\CircleCategoryLevel4;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRegistration;
use App\Services\Events\EventPaymentService;
use App\Services\Events\EventPaymentSyncService;
use App\Services\Events\EventRegistrationService;
use App\Services\Events\EventRegistrationQrService;
use App\Services\Events\EventService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class PublicEventRegistrationFormController extends Controller
{
    public function __construct(
        private readonly EventService $events,
        private readonly EventRegistrationService $registrations,
        private readonly EventPaymentService $payments,
        private readonly EventPaymentSyncService $paymentSync,
        private readonly EventRegistrationQrService $registrationQr,
    ) {}

    public function show(Request $request, string $event, string $occurrence): View
    {
        [$event, $occurrence] = $this->publicEventAndOccurrence($event, $occurrence);

        $registration = null;
        $payment = null;
        $qr = null;

        if ($request->filled('registration_id')) {
            $registration = EventRegistration::query()
                ->where('event_id', $event->id)
                ->where('occurrence_id', $occurrence->id)
                ->findOrFail((string) $request->query('registration_id'));
            $registration = $this->prepareRegistrationForDisplay($registration);
            $payment = $this->payments->responsePayload($registration);
            $qr = $this->qrDetailsForDisplay($registration);
        }

        return view('events.visitor-register', [
            'event' => $event,
            'occurrence' => $occurrence,
            'categories' => $this->categories(),
            'payment' => $payment,
            'qr' => $qr,
            'registration' => $registration,
        ]);
    }

    public function submit(VisitorEventRegistrationRequest $request, string $event, string $occurrence): RedirectResponse
    {
        [$event, $occurrence] = $this->publicEventAndOccurrence($event, $occurrence);

        try {
            $registration = $this->registrations->registerVisitor(
                $event,
                $occurrence,
                $request->validated(),
                'web_form'
            );
        } catch (\Throwable $exception) {
            Log::error('public_event_registration_form_submit_failed', [
                'event_id' => $event->id,
                'occurrence_id' => $occurrence->id,
                'error' => $exception->getMessage(),
            ]);

            return back()
                ->withInput()
                ->withErrors(['registration' => $exception->getMessage() ?: 'Unable to complete registration. Please try again.']);
        }

        $registration = $this->registrations->ensureVisitorRegistrationFormUrl($registration);

        return redirect()->to($this->registrations->visitorRegistrationFormUrl($registration));
    }

    private function prepareRegistrationForDisplay(EventRegistration $registration): EventRegistration
    {
        $registration = $this->registrations->ensureVisitorRegistrationFormUrl($registration);

        if ($this->registrationUsesZohoPaymentLink($registration)
            && in_array(strtolower((string) ($registration->payment_status ?? '')), ['pending', 'processing', 'failed', 'expired'], true)) {
            try {
                $syncResult = $this->paymentSync->syncRegistrationPayment($registration, ['source' => 'visitor_form']);
                $registration = $syncResult['registration'];
            } catch (\Throwable $exception) {
                Log::warning('public_event_registration_form_payment_sync_failed', [
                    'registration_id' => (string) $registration->id,
                    'error' => $exception->getMessage(),
                ]);
                $registration = $registration->fresh() ?? $registration;
            }
        }

        if ($this->registrationIsConfirmed($registration)) {
            $registration = $this->registrationQr->ensureQrGenerated($registration);
        }

        return $registration->fresh(['event.circle', 'occurrence', 'user', 'invitedByUser', 'businessCategoryMain', 'businessCategorySub']) ?? $registration;
    }

    private function qrDetailsForDisplay(?EventRegistration $registration): ?array
    {
        if (! $registration || ! $this->registrationIsConfirmed($registration)) {
            return null;
        }

        return $this->registrations->qrDetails($registration);
    }

    private function registrationUsesZohoPaymentLink(EventRegistration $registration): bool
    {
        return ($registration->payment_gateway ?? '') === 'zoho_billing_payment_link'
            || ! empty($registration->zoho_payment_link_url)
            || ! empty($registration->zoho_checkout_url)
            || ! empty($registration->zoho_hosted_page_url);
    }

    private function registrationIsConfirmed(EventRegistration $registration): bool
    {
        if (! (bool) ($registration->payment_required ?? false)) {
            return true;
        }

        return in_array(strtolower((string) ($registration->payment_status ?? '')), ['paid', 'success', 'completed'], true);
    }

    private function publicEventAndOccurrence(string $eventId, string $occurrenceId): array
    {
        $occurrence = EventOccurrence::query()
            ->with(['event.circle'])
            ->where('event_id', $eventId)
            ->findOrFail($occurrenceId);
        $event = $occurrence->event ?: Event::query()->findOrFail($eventId);

        abort_unless(
            $event->is_public || $event->visibility === 'public' || $this->events->visitorRegistrationEnabled($event),
            403,
            'Event is not available for public registration.'
        );
        abort_unless(
            $this->events->visitorRegistrationEnabled($event),
            403,
            'Visitor registration is not enabled for this event.'
        );

        return [$event, $occurrence];
    }

    private function categories(): array
    {
        $main = Schema::hasTable('circle_categories')
            ? CircleCategory::query()->orderBy('name')->get(['id', 'name'])
            : collect();
        $sub = (Schema::hasTable('level4_categories') || Schema::hasTable('circle_category_level4'))
            ? CircleCategoryLevel4::query()->orderBy('name')->get(['id', 'name'])
            : collect();

        return [
            'main' => $main,
            'sub' => $sub,
        ];
    }
}

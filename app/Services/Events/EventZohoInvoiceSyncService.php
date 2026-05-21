<?php

namespace App\Services\Events;

use App\Models\EventRegistration;
use App\Models\User;
use App\Support\Zoho\ZohoBillingClient;
use App\Support\Zoho\ZohoBillingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class EventZohoInvoiceSyncService
{
    public function __construct(
        private readonly ZohoBillingService $zohoBillingService,
        private readonly ZohoBillingClient $zohoBillingClient,
    ) {}

    public function sync(EventRegistration $registration): EventRegistration
    {
        $registration->loadMissing(['event', 'occurrence', 'user']);

        try {
            Log::info('zoho_event_invoice_create_after_payment_start', [
                'event_registration_id' => (string) $registration->id,
                'existing_invoice_id' => $registration->zoho_invoice_id,
            ]);

            $customerPayload = $this->customerPayload($registration);
            $invoicePayload = $this->invoicePayload($registration);

            $invoice = ! empty($registration->zoho_invoice_id)
                ? $this->updateInvoiceForEventRegistration((string) $registration->zoho_invoice_id, $invoicePayload)
                : $this->zohoBillingService->createInvoiceForEventRegistration($customerPayload, $invoicePayload);

            $registration->forceFill($this->filterRegistrationColumns([
                'zoho_customer_id' => $invoice['customer_id'] ?? $registration->zoho_customer_id,
                'zoho_invoice_id' => $invoice['invoice_id'] ?? $registration->zoho_invoice_id,
                'zoho_invoice_number' => $invoice['invoice_number'] ?? $registration->zoho_invoice_number,
                'zoho_invoice_url' => $invoice['invoice_url'] ?? $registration->zoho_invoice_url,
                'zoho_invoice_pdf_url' => $invoice['invoice_pdf_url'] ?? $registration->zoho_invoice_pdf_url,
                'zoho_invoice_synced_at' => now(),
                'zoho_invoice_sync_error' => null,
            ]))->save();

            Log::info(! empty($registration->getOriginal('zoho_invoice_id')) ? 'zoho_event_invoice_updated_after_payment' : 'zoho_event_invoice_created_after_payment', [
                'event_registration_id' => (string) $registration->id,
                'zoho_invoice_id' => $registration->zoho_invoice_id,
            ]);

            try {
                if (! empty($registration->zoho_invoice_id)) {
                    $this->zohoBillingClient->request('POST', '/invoices/'.$registration->zoho_invoice_id.'/status/sent');
                    Log::info('zoho_event_invoice_mark_paid_success', [
                        'event_registration_id' => (string) $registration->id,
                        'zoho_invoice_id' => $registration->zoho_invoice_id,
                    ]);
                }
            } catch (\Throwable) {
                // non-fatal, keep paid state
            }
        } catch (\Throwable $exception) {
            Log::error('zoho_event_invoice_failed_after_payment', [
                'event_registration_id' => (string) $registration->id,
                'error' => $exception->getMessage(),
            ]);

            $registration->forceFill($this->filterRegistrationColumns([
                'zoho_invoice_sync_error' => $exception->getMessage(),
            ]))->save();
        }

        return $registration->fresh(['event.circle', 'occurrence', 'user']);
    }

    private function customerPayload(EventRegistration $registration): array
    {
        if ($registration->user instanceof User) {
            $user = $registration->user;

            return [
                'user' => $user,
                'name' => $user->display_name ?: trim(($user->first_name ?? '').' '.($user->last_name ?? '')),
                'email' => (string) $user->email,
                'phone' => (string) ($user->phone ?? $user->mobile ?? ''),
                'company' => (string) ($user->company_name ?? ''),
                'city' => (string) ($user->city ?? $user->business_city ?? ''),
            ];
        }

        return [
            'name' => (string) $registration->visitor_name,
            'email' => (string) $registration->visitor_email,
            'phone' => (string) $registration->visitor_phone,
            'company' => (string) $registration->visitor_company,
            'city' => (string) $registration->visitor_city,
        ];
    }

    private function invoicePayload(EventRegistration $registration): array
    {
        $attendeeName = $registration->user
            ? ($registration->user->display_name ?: trim(($registration->user->first_name ?? '').' '.($registration->user->last_name ?? '')))
            : (string) $registration->visitor_name;
        $attendeeEmail = $registration->user?->email ?: $registration->visitor_email;
        $eventTitle = (string) ($registration->event?->title ?? 'Unity Event');
        $occurrenceStart = optional($registration->occurrence?->start_at)->toDateTimeString();
        $modeOrVenue = $registration->event?->location_text ?: (($registration->event?->mode ?? '') === 'online' ? 'online' : 'N/A');
        $itemId = (string) config('services.zoho_event_ticket_item_id', '');

        $details = "Event: {$eventTitle}\n"
            ."Date: {$occurrenceStart}\n"
            ."Venue/Mode: {$modeOrVenue}\n"
            ."Occurrence ID: {$registration->occurrence_id}\n"
            ."Registration ID: {$registration->id}\n"
            ."Attendee: {$attendeeName}\n"
            ."Email: {$attendeeEmail}\n"
            ."Payment Link Ref: ".($registration->zoho_payment_link_id ?? '');

        $payload = [
            'registration_id' => (string) $registration->id,
            'event_title' => $eventTitle,
            'description' => $details,
            'amount' => (float) ($registration->amount ?? 0),
            'currency' => (string) ($registration->currency ?? 'INR'),
            'invoice_payload' => [
                'customer_id' => $registration->zoho_customer_id,
                'reference_number' => (string) $registration->id,
                'date' => now()->toDateString(),
                'notes' => $details,
                'terms' => 'Payment received via Zoho Payment Link.',
                'line_items' => [[
                    'item_id' => $itemId,
                    'rate' => (float) ($registration->amount ?? 0),
                    'quantity' => 1,
                    'description' => $details,
                ]],
            ],
        ];

        Log::info('zoho_event_invoice_payload', [
            'event_registration_id' => (string) $registration->id,
            'payload' => $payload['invoice_payload'],
        ]);

        return $payload;
    }

    private function updateInvoiceForEventRegistration(string $invoiceId, array $eventInvoice): array
    {
        $payload = (array) ($eventInvoice['invoice_payload'] ?? []);
        $response = $this->zohoBillingClient->request('PUT', '/invoices/'.$invoiceId, $payload);
        $invoice = is_array($response['invoice'] ?? null) ? $response['invoice'] : $response;

        return [
            'customer_id' => $invoice['customer_id'] ?? data_get($payload, 'customer_id'),
            'invoice_id' => (string) data_get($invoice, 'invoice_id', $invoiceId),
            'invoice_number' => (string) (data_get($invoice, 'invoice_number') ?? data_get($invoice, 'number') ?? ''),
            'invoice_url' => data_get($invoice, 'invoice_url') ?? data_get($invoice, 'url'),
            'invoice_pdf_url' => data_get($invoice, 'invoice_pdf_url') ?? data_get($invoice, 'pdf_url'),
            'raw' => $response,
        ];
    }

    private function filterRegistrationColumns(array $data): array
    {
        return array_filter($data, fn ($value, $key) => Schema::hasColumn('event_registrations', $key), ARRAY_FILTER_USE_BOTH);
    }
}

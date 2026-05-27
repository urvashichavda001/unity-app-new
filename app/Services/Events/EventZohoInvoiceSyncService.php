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
            Log::info('zoho_invoice_create_start', [
                'event_registration_id' => (string) $registration->id,
                'existing_invoice_id' => $registration->zoho_invoice_id,
            ]);

            $customerPayload = $this->customerPayload($registration);
            $invoicePayload = $this->invoicePayload($registration);

            $invoice = ! empty($registration->zoho_invoice_id)
                ? $this->updateInvoiceForEventRegistration((string) $registration->zoho_invoice_id, $invoicePayload)
                : $this->zohoBillingService->createInvoiceForEventRegistration($customerPayload, $invoicePayload);
            if (! empty($registration->zoho_invoice_id)) {
                Log::info('zoho_invoice_existing_found', [
                    'event_registration_id' => (string) $registration->id,
                    'zoho_invoice_id' => $registration->zoho_invoice_id,
                ]);
            }

            $registration->forceFill($this->filterRegistrationColumns([
                'zoho_customer_id' => $invoice['customer_id'] ?? $registration->zoho_customer_id,
                'zoho_invoice_id' => $invoice['invoice_id'] ?? $registration->zoho_invoice_id,
                'zoho_invoice_number' => $invoice['invoice_number'] ?? $registration->zoho_invoice_number,
                'zoho_invoice_url' => $invoice['invoice_url'] ?? $registration->zoho_invoice_url,
                'zoho_invoice_pdf_url' => $invoice['invoice_pdf_url'] ?? $registration->zoho_invoice_pdf_url,
                'zoho_invoice_synced_at' => now(),
                'zoho_invoice_sync_error' => null,
            ]))->save();

            Log::info(! empty($registration->getOriginal('zoho_invoice_id')) ? 'zoho_event_invoice_updated_after_payment' : 'zoho_invoice_created', [
                'event_registration_id' => (string) $registration->id,
                'zoho_invoice_id' => $registration->zoho_invoice_id,
            ]);

            // Invoice creation/update only; payment application is handled in finalizeAndApplyPaymentToEventInvoice().
        } catch (\Throwable $exception) {
            Log::error('zoho_invoice_create_failed', [
                'event_registration_id' => (string) $registration->id,
                'error' => $exception->getMessage(),
            ]);

            $registration->forceFill($this->filterRegistrationColumns([
                'zoho_invoice_sync_error' => $exception->getMessage(),
            ]))->save();
        }

        return $registration->fresh(['event.circle', 'occurrence', 'user']);
    }

    public function finalizeAndApplyPaymentToEventInvoice(EventRegistration $registration): array
    {
        $registration->refresh();
        $context = [
            'registration_id' => (string) $registration->id,
            'zoho_invoice_id' => $registration->zoho_invoice_id,
            'zoho_invoice_number' => $registration->zoho_invoice_number,
            'zoho_customer_id' => $registration->zoho_customer_id,
            'payment_amount' => (float) ($registration->payment_amount ?? $registration->amount ?? 0),
            'payment_link_id' => $registration->zoho_payment_link_id,
            'payment_id' => $registration->zoho_payment_id,
        ];
        Log::info('zoho_invoice_finalize_start', $context);

        try {
            if (empty($registration->zoho_invoice_id) || empty($registration->zoho_customer_id)) {
                throw new \RuntimeException('Missing Zoho invoice/customer details for sync.');
            }
            $invoiceResponse = $this->zohoBillingClient->request('GET', '/invoices/'.$registration->zoho_invoice_id);
            $invoice = is_array($invoiceResponse['invoice'] ?? null) ? $invoiceResponse['invoice'] : $invoiceResponse;
            Log::info('zoho_invoice_fetch_before_apply', $context + ['response' => $invoiceResponse]);

            $status = strtolower((string) data_get($invoice, 'status', ''));
            $balance = (float) (data_get($invoice, 'balance') ?? data_get($invoice, 'balance_due') ?? 0);
            if ($status === 'draft') {
                Log::info('zoho_invoice_convert_to_open_start', $context);
                try {
                    $this->convertInvoiceToOpen((string) $registration->zoho_invoice_id);
                    Log::info('zoho_invoice_convert_to_open_success', $context);
                } catch (\Throwable $e) {
                    if (! str_contains(strtolower($e->getMessage()), 'already') && ! str_contains(strtolower($e->getMessage()), 'not draft')) {
                        Log::error('zoho_invoice_convert_to_open_failed', $context + ['error' => $e->getMessage()]);
                        throw $e;
                    }
                }
                $invoiceResponse = $this->zohoBillingClient->request('GET', '/invoices/'.$registration->zoho_invoice_id);
                $invoice = is_array($invoiceResponse['invoice'] ?? null) ? $invoiceResponse['invoice'] : $invoiceResponse;
                $status = strtolower((string) data_get($invoice, 'status', ''));
                $balance = (float) (data_get($invoice, 'balance') ?? data_get($invoice, 'balance_due') ?? 0);
            }

            $paymentApplied = false;
            $matchedPaymentId = (string) ($registration->zoho_payment_id ?? '');
            if ($matchedPaymentId === '') {
                $linkResp = $this->zohoBillingClient->request('GET', '/paymentlinks/'.$registration->zoho_payment_link_id);
                $link = data_get($linkResp, 'payment_link') ?? data_get($linkResp, 'payment_links') ?? $linkResp;
                $matchedPaymentId = (string) (data_get($link, 'customer_payments.0.payment_id') ?? '');
                if ($matchedPaymentId !== '') {
                    $registration->forceFill($this->filterRegistrationColumns([
                        'zoho_payment_id' => $matchedPaymentId,
                    ]))->save();
                }
            }
            if ($balance > 0 && ! in_array($status, ['paid', 'closed'], true)) {
                if (! empty($matchedPaymentId)) {
                    $paymentApply = $this->applyPaymentToInvoice($matchedPaymentId, $registration, $invoice);
                    $paymentApplied = (bool) ($paymentApply['payment_applied'] ?? false);
                }
            }

            $finalInvoiceResponse = $this->zohoBillingClient->request('GET', '/invoices/'.$registration->zoho_invoice_id);
            Log::info('zoho_invoice_final_fetch', $context + ['response' => $finalInvoiceResponse]);
            $finalInvoice = is_array($finalInvoiceResponse['invoice'] ?? null) ? $finalInvoiceResponse['invoice'] : $finalInvoiceResponse;
            Log::info('zoho_invoice_finalize_success', $context + ['payment_id' => $matchedPaymentId]);

            return [
                'invoice_id' => (string) data_get($finalInvoice, 'invoice_id', $registration->zoho_invoice_id),
                'invoice_number' => (string) (data_get($finalInvoice, 'invoice_number') ?? $registration->zoho_invoice_number),
                'status' => (string) data_get($finalInvoice, 'status', ''),
                'balance' => (float) (data_get($finalInvoice, 'balance') ?? data_get($finalInvoice, 'balance_due') ?? 0),
                'total' => (float) (data_get($finalInvoice, 'total') ?? 0),
                'amount_paid' => (float) (data_get($finalInvoice, 'amount_paid') ?? 0),
                'invoice_url' => data_get($finalInvoice, 'invoice_url') ?? data_get($finalInvoice, 'url'),
                'invoice_pdf_url' => data_get($finalInvoice, 'invoice_pdf_url') ?? data_get($finalInvoice, 'pdf_url'),
                'payment_id' => $matchedPaymentId,
                'payment_applied' => $paymentApplied || ((float) (data_get($finalInvoice, 'balance') ?? 0) <= 0) || in_array(strtolower((string) data_get($finalInvoice, 'status', '')), ['paid', 'closed'], true),
                'sync_error' => null,
            ];
        } catch (\Throwable $e) {
            Log::error('zoho_invoice_finalize_failed', $context + ['error' => $e->getMessage()]);
            return ['sync_error' => $e->getMessage()];
        }
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
        $startAt = $registration->occurrence?->start_at;
        $eventDate = optional($startAt)->format('M d, Y') ?: 'TBD';
        $eventTime = optional($startAt)->format('h:i A') ?: 'TBD';
        $modeOrVenue = $registration->event?->location_text ?: (($registration->event?->mode ?? '') === 'online' ? 'Online' : 'TBD');
        $itemId = (string) config('services.zoho_event_ticket_item_id', '');

        $details = "Event Date: {$eventDate}\n"
            ."Event Time: {$eventTime}\n"
            ."Venue: {$modeOrVenue}\n"
            ."Attendee: {$attendeeName}";

        $shortRegistrationId = substr((string) $registration->id, 0, 8);

        $payload = [
            'registration_id' => (string) $registration->id,
            'event_title' => $eventTitle,
            'description' => $details,
            'amount' => (float) ($registration->payment_amount ?? $registration->amount ?? 0),
            'currency' => (string) ($registration->currency ?? 'INR'),
            'invoice_payload' => [
                'customer_id' => $registration->zoho_customer_id,
                'reference_number' => (string) $registration->id,
                'date' => now()->toDateString(),
                'notes' => 'Payment received via Zoho Payment Link.\nRegistration Ref: '.$shortRegistrationId,
                'terms' => 'Thank you for registering for this event.',
                'line_items' => [[
                    'item_id' => $itemId,
                    'name' => 'Event Registration - '.$eventTitle,
                    'rate' => (float) ($registration->amount ?? 0),
                    'quantity' => 1,
                    'description' => $details,
                ]],
            ],
        ];

        Log::info('zoho_invoice_payload', [
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


    private function convertInvoiceToOpen(string $invoiceId): void
    {
        $this->zohoBillingClient->request('POST', '/invoices/'.$invoiceId.'/converttoopen');
    }

    public function getPayment(string $paymentId): array
    {
        Log::info('zoho_payment_fetch_start', ['payment_id' => $paymentId]);
        $response = $this->zohoBillingClient->request('GET', '/payments/'.$paymentId);
        Log::info('zoho_payment_fetch_success', ['payment_id' => $paymentId, 'response' => $response]);
        return is_array($response['payment'] ?? null) ? $response['payment'] : $response;
    }

    public function applyPaymentToInvoice(string $paymentId, EventRegistration $registration, array $invoice): array
    {
        $payment = $this->getPayment($paymentId);
        $invoices = (array) data_get($payment, 'invoices', []);
        foreach ($invoices as $appliedInvoice) {
            if ((string) data_get($appliedInvoice, 'invoice_id') === (string) $registration->zoho_invoice_id) {
                Log::info('zoho_payment_already_applied', ['payment_id' => $paymentId, 'invoice_id' => $registration->zoho_invoice_id]);
                return ['payment_applied' => true, 'payment' => $payment];
            }
        }

        $amount = (float) (data_get($payment, 'amount') ?? $registration->payment_amount ?? $registration->amount ?? 0);
        $balance = (float) (data_get($invoice, 'balance') ?? data_get($invoice, 'balance_due') ?? $amount);
        $payload = [
            'customer_id' => (string) ($registration->zoho_customer_id ?? data_get($payment, 'customer_id')),
            'payment_mode' => (string) (data_get($payment, 'payment_mode') ?: 'others'),
            'amount' => $amount,
            'date' => (string) (data_get($payment, 'date') ?: optional($registration->payment_completed_at)->toDateString() ?: now()->toDateString()),
            'reference_number' => (string) (data_get($payment, 'reference_number') ?: $registration->zoho_payment_link_id ?: $registration->id),
            'description' => 'Event registration payment applied to invoice '.((string) ($registration->zoho_invoice_number ?? data_get($invoice, 'invoice_number', ''))),
            'invoices' => [[
                'invoice_id' => (string) $registration->zoho_invoice_id,
                'amount_applied' => $balance > 0 ? min($amount, $balance) : $amount,
            ]],
        ];
        Log::info('zoho_payment_apply_start', ['payment_id' => $paymentId, 'payload' => $payload]);
        $response = $this->zohoBillingClient->request('PUT', '/payments/'.$paymentId, $payload);
        Log::info('zoho_payment_apply_success', ['payment_id' => $paymentId, 'response' => $response]);
        return ['payment_applied' => true, 'payment' => is_array($response['payment'] ?? null) ? $response['payment'] : $response];
    }

    private function filterRegistrationColumns(array $data): array
    {
        return array_filter($data, fn ($value, $key) => Schema::hasColumn('event_registrations', $key), ARRAY_FILTER_USE_BOTH);
    }
}

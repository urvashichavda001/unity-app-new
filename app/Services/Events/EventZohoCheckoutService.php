<?php

namespace App\Services\Events;

use App\Models\EventRegistration;
use App\Models\User;
use App\Support\Zoho\ZohoBillingService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class EventZohoCheckoutService
{
    public function __construct(private readonly ZohoBillingService $zohoBillingService) {}

    public function createForRegistration(EventRegistration $registration): array
    {
        $registration->loadMissing(['event', 'occurrence', 'user']);

        $amount = (float) ($registration->amount ?? $registration->event?->ticket_price ?? 0);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Paid event amount must be greater than zero.']);
        }

        $customer = $this->customerPayload($registration);
        $metadata = [
            'type' => 'event_registration',
            'registration_id' => (string) $registration->id,
            'event_id' => (string) $registration->event_id,
            'occurrence_id' => (string) $registration->occurrence_id,
        ];

        Log::info('Creating Zoho checkout for event registration.', [
            'event_registration_id' => (string) $registration->id,
            'event_id' => (string) $registration->event_id,
            'occurrence_id' => (string) $registration->occurrence_id,
            'amount' => $amount,
        ]);

        return $this->zohoBillingService->createHostedInvoicePaymentForEventRegistration(
            $customer,
            [
                'registration_id' => (string) $registration->id,
                'event_title' => (string) ($registration->event?->title ?? 'Unity Event'),
                'event_description' => (string) ($registration->event?->description ?? ''),
                'occurrence_start_at' => optional($registration->occurrence?->start_at)->toDateTimeString(),
                'amount' => $amount,
                'currency' => (string) ($registration->currency ?? 'INR'),
                'redirect_url' => $this->redirectUrl($registration),
                'metadata' => $metadata,
            ],
            fn (array $invoiceMapping) => $this->storeInvoiceMapping($registration, $invoiceMapping)
        );
    }


    private function storeInvoiceMapping(EventRegistration $registration, array $invoiceMapping): void
    {
        $registration->forceFill($this->filterRegistrationColumns([
            'zoho_customer_id' => $invoiceMapping['customer_id'] ?? null,
            'zoho_invoice_id' => $invoiceMapping['invoice_id'] ?? null,
            'zoho_invoice_number' => $invoiceMapping['invoice_number'] ?? null,
        ]))->save();
    }

    private function filterRegistrationColumns(array $data): array
    {
        return array_filter($data, fn ($value, $key) => Schema::hasColumn('event_registrations', $key), ARRAY_FILTER_USE_BOTH);
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

    private function redirectUrl(EventRegistration $registration): string
    {
        return rtrim((string) config('app.url'), '/').'/events/registrations/'.$registration->id.'/payment-return';
    }
}

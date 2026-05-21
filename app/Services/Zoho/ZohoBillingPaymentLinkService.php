<?php

namespace App\Services\Zoho;

use App\Models\EventRegistration;
use App\Support\Zoho\ZohoBillingClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ZohoBillingPaymentLinkService
{
    public function __construct(private readonly ZohoBillingClient $client) {}


    public function findOrCreateCustomer(EventRegistration $registration): ?string
    {
        if (! empty($registration->zoho_customer_id)) {
            return (string) $registration->zoho_customer_id;
        }

        $registration->loadMissing(['user']);
        $email = (string) ($registration->user?->email ?: $registration->visitor_email ?: '');
        if ($email === '') {
            return null;
        }

        $phone = (string) ($registration->user?->phone ?: $registration->visitor_phone ?: '');
        $name = (string) ($registration->user?->name ?: $registration->visitor_name ?: 'Event Attendee');

        $search = $this->client->request('GET', '/customers', ['email' => $email], true);
        $customer = data_get($search, 'customers.0');
        $customerId = data_get($customer, 'customer_id');

        if (! $customerId) {
            $created = $this->client->request('POST', '/customers', [
                'display_name' => $name,
                'email' => $email,
                'phone' => $phone,
            ]);
            $customerId = data_get($created, 'customer.customer_id') ?? data_get($created, 'customer_id');
        }

        if ($customerId) {
            $registration->forceFill($this->filter(['zoho_customer_id' => $customerId]))->save();
        }

        return $customerId ? (string) $customerId : null;
    }

    public function createPaymentLink(EventRegistration $registration): EventRegistration
    {
        $registration->loadMissing(['event', 'user']);

        if (($registration->payment_status ?? null) === 'pending' && ! empty($registration->zoho_payment_link_url)) {
            return $registration;
        }

        $email = (string) ($registration->user?->email ?: $registration->visitor_email ?: '');
        $phone = (string) ($registration->user?->phone ?: $registration->visitor_phone ?: '');
        $eventTitle = (string) ($registration->event?->title ?? 'Event');
        $amountValue = (float) ($registration->payment_amount ?? $registration->amount ?? 0);
        if ($amountValue < 1) {
            throw ValidationException::withMessages([
                'ticket_price' => 'Paid event ticket price must be at least ₹1 for Zoho payment link.',
            ]);
        }

        $customerId = $this->findOrCreateCustomer($registration);
        if (empty($customerId)) {
            throw ValidationException::withMessages([
                'customer' => 'Unable to create or match Zoho customer for payment link.',
            ]);
        }

        $amount = number_format((float) ($registration->payment_amount ?? $registration->amount ?? 0), 2, '.', '');

        $payload = [
            'amount' => $amount,
            'currency' => 'INR',
            'customer_id' => $customerId,
            'email' => $email,
            'phone' => $phone,
            'reference_id' => (string) $registration->id,
            'description' => 'Event Registration - '.$eventTitle,
            'return_url' => rtrim((string) config('app.url'), '/').'/api/v1/events/registrations/'.$registration->id.'/payment-return',
            'notify_customer' => [
                'email' => true,
                'sms' => false,
            ],
        ];

        Log::info('zoho_billing_payment_link_create_request', [
            'registration_id' => (string) $registration->id,
            'payload' => $payload,
        ]);
        Log::info('Zoho Billing request path=/paymentlinks', ['registration_id' => (string) $registration->id]);

        try {
            $response = $this->client->request('POST', '/paymentlinks', $payload);
            $link = data_get($response, 'payment_links') ?? data_get($response, 'payment_link') ?? $response;
            $url = data_get($link, 'url');

            if (empty($url)) {
                Log::error('zoho_billing_payment_link_error', [
                    'registration_id' => (string) $registration->id,
                    'response' => $response,
                    'error' => 'Zoho Billing payment link URL missing.',
                ]);

                throw new \RuntimeException('Zoho Billing payment link URL missing.');
            }

            $registration->forceFill($this->filter([
                'zoho_payment_link_id' => data_get($link, 'payment_link_id') ?? data_get($link, 'id') ?? null,
                'zoho_payment_link_url' => $url,
                'payment_url' => $url,
                'checkout_url' => $url,
                'zoho_payment_status' => data_get($link, 'status', 'active'),
                'payment_gateway' => 'zoho_billing_payment_link',
                'payment_status' => 'pending',
                'status' => 'pending_payment',
            ]))->save();

            Log::info('zoho_billing_payment_link_created', [
                'registration_id' => (string) $registration->id,
                'zoho_payment_link_id' => $registration->zoho_payment_link_id,
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $registration->forceFill($this->filter([
                'zoho_invoice_sync_error' => $e->getMessage(),
                'payment_status' => 'pending',
                'status' => 'pending_payment',
            ]))->save();
        }

        return $registration->fresh(['event', 'occurrence', 'user']);
    }

    private function filter(array $data): array
    {
        return array_filter($data, fn ($value, $key) => Schema::hasColumn('event_registrations', $key), ARRAY_FILTER_USE_BOTH);
    }
}

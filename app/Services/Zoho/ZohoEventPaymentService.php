<?php

namespace App\Services\Zoho;

use App\Models\EventRegistration;
use App\Services\Events\EventQrService;
use App\Support\Zoho\ZohoBillingClient;
use App\Support\Zoho\ZohoBillingTokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ZohoEventPaymentService
{
    public function __construct(private readonly ZohoBillingClient $client, private readonly ZohoBillingTokenService $tokenService, private readonly EventQrService $qrService) {}
    public function getAccessToken(): string { return $this->tokenService->getAccessToken(); }

    public function findOrCreateCustomer(EventRegistration $registration): EventRegistration
    {
        if (! empty($registration->zoho_customer_id)) return $registration;
        $registration->loadMissing('user');
        $email = strtolower((string) ($registration->user?->email ?: $registration->visitor_email));
        $found = $email !== '' ? collect($this->client->request('GET', '/customers', ['email' => $email], true)['customers'] ?? [])->first() : null;
        if ($found) {
            $registration->forceFill($this->f(['zoho_customer_id' => (string) ($found['customer_id'] ?? '')]))->save();
            Log::info('zoho_event_payment_customer_matched', ['event_registration_id' => $registration->id]);
            return $registration->fresh(['event','occurrence','user']);
        }
        $created = $this->client->request('POST', '/customers', ['display_name' => (string)($registration->user?->display_name ?: $registration->visitor_name ?: 'Event Attendee'), 'email' => $email]);
        $registration->forceFill($this->f(['zoho_customer_id' => data_get($created,'customer.customer_id',data_get($created,'customer_id'))]))->save();
        Log::info('zoho_event_payment_customer_created', ['event_registration_id' => $registration->id]);
        return $registration->fresh(['event','occurrence','user']);
    }

    public function createEventInvoice(EventRegistration $registration): EventRegistration
    {
        if (! empty($registration->zoho_invoice_id)) return $registration;
        $registration = $this->findOrCreateCustomer($registration->fresh(['event','occurrence','user']));
        $invoice = $this->client->request('POST', '/invoices', [
            'customer_id' => $registration->zoho_customer_id,
            'line_items' => [[
                'name' => 'Event Ticket - '.(string)($registration->event?->title ?? 'Event'),
                'description' => 'Occurrence: '.(optional($registration->occurrence?->start_at)->toDateTimeString() ?? '').', Registration: '.$registration->id,
                'quantity' => 1,
                'rate' => (float)($registration->amount ?? 0),
            ]],
            'reference_number' => (string)$registration->id,
        ]);
        $registration->forceFill($this->f([
            'zoho_invoice_id' => data_get($invoice,'invoice.invoice_id',data_get($invoice,'invoice_id')),
            'zoho_invoice_number' => data_get($invoice,'invoice.invoice_number',data_get($invoice,'invoice_number')),
            'zoho_invoice_url' => data_get($invoice,'invoice.invoice_url',data_get($invoice,'invoice_url')),
            'zoho_invoice_pdf_url' => data_get($invoice,'invoice.invoice_pdf_url',data_get($invoice,'invoice_pdf_url')),
            'zoho_invoice_synced_at' => now(),
            'zoho_invoice_sync_error' => null,
        ]))->save();
        Log::info('zoho_event_invoice_created', ['event_registration_id' => $registration->id]);
        return $registration->fresh(['event','occurrence','user']);
    }

    public function createHostedPaymentPage(EventRegistration $registration): EventRegistration
    {
        if (! empty($registration->payment_url)) return $registration;
        try {
            $registration = $this->createEventInvoice($registration);
            $hosted = $this->client->request('POST', '/hostedpages/invoice', ['invoice_id' => $registration->zoho_invoice_id, 'reference_id' => (string)$registration->id]);
            $url = data_get($hosted,'hostedpage.url',data_get($hosted,'url'));
            $registration->forceFill($this->f([
                'zoho_hosted_page_id' => data_get($hosted,'hostedpage.hostedpage_id',data_get($hosted,'hostedpage_id')),
                'zoho_hosted_page_url' => $url,
                'zoho_payment_link_id' => data_get($hosted,'hostedpage.payment_link_id',data_get($hosted,'payment_link_id')),
                'payment_url' => $url,
            ]))->save();
            Log::info('zoho_event_hosted_page_created', ['event_registration_id' => $registration->id]);
        } catch (\Throwable $e) {
            Log::error('zoho_event_payment_error', ['event_registration_id' => $registration->id, 'error' => $e->getMessage()]);
            $registration->forceFill($this->f(['zoho_invoice_sync_error' => $e->getMessage()]))->save();
        }
        return $registration->fresh(['event','occurrence','user']);
    }

    public function syncPaidPaymentFromWebhook(array $payload): ?EventRegistration
    {
        Log::info('zoho_event_webhook_received', ['keys' => array_keys($payload)]);
        $registration = $this->resolve($payload); if (! $registration) return null;
        $raw = strtolower((string)(data_get($payload,'payment.status') ?? data_get($payload,'invoice.status') ?? data_get($payload,'status') ?? ''));
        if (str_contains($raw,'paid') || str_contains($raw,'success')) return $this->markRegistrationPaid($registration,$payload);
        if (str_contains($raw,'fail') || str_contains($raw,'cancel')) {
            $registration->forceFill($this->f(['status'=>'payment_failed','payment_status'=>'failed','zoho_payment_status'=>'failed','payment_failed_reason'=>(string)(data_get($payload,'message') ?? data_get($payload,'reason') ?? 'Payment failed'),'zoho_payment_webhook_payload'=>$payload,'webhook_payload'=>$payload]))->save();
            Log::error('zoho_event_payment_failed', ['event_registration_id' => $registration->id]);
        }
        return $registration->fresh(['event','occurrence','user']);
    }

    public function markRegistrationPaid(EventRegistration $registration, array $payload): EventRegistration
    {
        return DB::transaction(function () use ($registration, $payload) {
            $locked = EventRegistration::query()->lockForUpdate()->findOrFail($registration->id);
            if (($locked->payment_status ?? null) !== 'paid') {
                $locked->forceFill($this->f(['status'=>'registered','payment_status'=>'paid','zoho_payment_status'=>'paid','payment_completed_at'=>now(),'zoho_payment_id'=>data_get($payload,'payment.payment_id',data_get($payload,'payment_id')),'zoho_payment_webhook_payload'=>$payload,'webhook_payload'=>$payload]))->save();
            }
            return $this->generateQrAfterPayment($locked);
        });
    }

    public function generateQrAfterPayment(EventRegistration $registration): EventRegistration
    {
        if (empty($registration->qr_code_path) && empty($registration->qr_code_url)) { $this->qrService->generateAndStore($registration); Log::info('zoho_event_qr_generated', ['event_registration_id' => $registration->id]); }
        Log::info('zoho_event_payment_success', ['event_registration_id' => $registration->id]);
        return $registration->fresh(['event','occurrence','user']);
    }

    private function resolve(array $payload): ?EventRegistration
    {
        foreach ([data_get($payload,'registration_id'),data_get($payload,'reference_id'),data_get($payload,'invoice.reference_number')] as $id) if ($id) { $r = EventRegistration::query()->find($id); if ($r) return $r; }
        foreach (['zoho_invoice_id'=>[data_get($payload,'invoice.invoice_id'),data_get($payload,'invoice_id')],'zoho_payment_id'=>[data_get($payload,'payment.payment_id'),data_get($payload,'payment_id')],'zoho_hosted_page_id'=>[data_get($payload,'hostedpage.hostedpage_id'),data_get($payload,'hostedpage_id')]] as $c=>$vals) foreach ($vals as $v) if ($v) { $r = EventRegistration::query()->where($c,$v)->first(); if ($r) return $r; }
        return null;
    }

    private function f(array $data): array { return array_filter($data, fn ($value, $key) => Schema::hasColumn('event_registrations', $key), ARRAY_FILTER_USE_BOTH); }
}

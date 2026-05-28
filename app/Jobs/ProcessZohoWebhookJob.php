<?php

namespace App\Jobs;

use App\Models\ZohoWebhookLog;
use App\Services\Zoho\ZohoEventPaymentService;
use App\Support\Zoho\ZohoBillingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessZohoWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $webhookLogId) {}

    public function handle(ZohoBillingService $zohoBillingService, ZohoEventPaymentService $zohoEventPaymentService): void
    {
        $log = ZohoWebhookLog::query()->find($this->webhookLogId);
        if (! $log) {
            return;
        }

        $log->update(['status' => 'processing', 'attempts' => (int) $log->attempts + 1, 'error_message' => null]);

        $payload = is_array($log->payload) ? $log->payload : [];
        $ids = [
            'subscription_id' => data_get($payload, 'subscription.subscription_id') ?? data_get($payload, 'subscription_id'),
            'subscription_number' => data_get($payload, 'subscription.subscription_number') ?? data_get($payload, 'subscription_number'),
            'invoice_id' => data_get($payload, 'invoice.invoice_id') ?? data_get($payload, 'invoice_id'),
            'invoice_status' => data_get($payload, 'invoice.status') ?? data_get($payload, 'status'),
            'payment_id' => data_get($payload, 'payment.payment_id') ?? data_get($payload, 'payment_id'),
            'hostedpage_id' => data_get($payload, 'hostedpage.hostedpage_id') ?? data_get($payload, 'hostedpage_id'),
            'hostedpage_status' => data_get($payload, 'hostedpage.status'),
            'customer_id' => data_get($payload, 'customer.customer_id') ?? data_get($payload, 'customer_id'),
        ];

        Log::info('Zoho webhook job started', ['webhook_log_id' => $log->id]);
        Log::info('Zoho webhook parsed ids', array_merge(['webhook_log_id' => $log->id], $ids));

        try {
            $eventMatched = (bool) $zohoEventPaymentService->syncPaidPaymentFromWebhook($payload);
            $billingMatched = $zohoBillingService->applyWebhookEvent($payload);

            if (! $eventMatched && ! $billingMatched) {
                $message = 'No matching local record found for Zoho webhook';
                $log->update(['status' => 'failed', 'error_message' => $message]);
                Log::warning('Zoho webhook no local record matched', ['webhook_log_id' => $log->id] + $ids);

                return;
            }

            $log->update(['status' => 'processed', 'processed_at' => now()]);
            Log::info('Zoho membership/payment status updated', ['webhook_log_id' => $log->id, 'event_matched' => $eventMatched, 'billing_matched' => $billingMatched]);
        } catch (\Throwable $e) {
            $log->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            Log::error('Zoho webhook job failed', ['webhook_log_id' => $log->id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}

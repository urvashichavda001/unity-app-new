<?php

namespace App\Console\Commands;

use App\Services\Zoho\ZohoPaymentWebhookService;
use App\Support\Zoho\ZohoBillingClient;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class SyncZohoSubscriptionPayment extends Command
{
    protected $signature = 'zoho:subscriptions:sync-payment {--payment_id=} {--invoice_id=}';

    protected $description = 'Manually sync a paid Zoho Billing subscription/member payment by payment ID or invoice ID.';

    public function handle(ZohoBillingClient $client, ZohoPaymentWebhookService $webhooks): int
    {
        $paymentId = trim((string) $this->option('payment_id'));
        $invoiceId = trim((string) $this->option('invoice_id'));

        if ($paymentId === '' && $invoiceId === '') {
            $this->error('Provide --payment_id or --invoice_id.');
            return self::FAILURE;
        }

        try {
            $payload = $paymentId !== ''
                ? $this->payloadFromPayment($client, $paymentId)
                : $this->payloadFromInvoice($client, $invoiceId);

            $request = Request::create('/internal/zoho/subscriptions/sync-payment', 'POST', [], [], [], [], json_encode($payload));
            $request->headers->set('Content-Type', 'application/json');

            $result = $webhooks->handle($request);

            $this->info($result['message'] ?? 'Sync attempted.');
            if (! empty($result['webhook_event_id'])) {
                $this->line('webhook_event_id='.$result['webhook_event_id']);
            }
            if (! empty($result['error'])) {
                $this->warn('error='.$result['error']);
            }

            return self::SUCCESS;
        } catch (\Throwable $throwable) {
            $this->error($throwable->getMessage());
            return self::FAILURE;
        }
    }

    private function payloadFromPayment(ZohoBillingClient $client, string $paymentId): array
    {
        $response = $client->request('GET', '/payments/'.$paymentId);
        $payment = data_get($response, 'payment') ?? data_get($response, 'customerpayment') ?? $response;

        return ['payment' => is_array($payment) ? $payment : []];
    }

    private function payloadFromInvoice(ZohoBillingClient $client, string $invoiceId): array
    {
        $response = $client->request('GET', '/invoices/'.$invoiceId);
        $invoice = data_get($response, 'invoice') ?? $response;
        $subscriptionId = data_get($invoice, 'subscription_id') ?? data_get($invoice, 'subscriptions.0.subscription_id');

        return [
            'payment' => [
                'date' => data_get($invoice, 'date') ?? now()->toDateString(),
                'payment_link_id' => '',
                'amount' => data_get($invoice, 'total') ?? data_get($invoice, 'balance') ?? data_get($invoice, 'sub_total'),
                'payment_status' => strtolower((string) data_get($invoice, 'status')) === 'paid' ? 'paid' : data_get($invoice, 'status'),
                'status' => strtolower((string) data_get($invoice, 'status')) === 'paid' ? 'success' : data_get($invoice, 'status'),
                'customer_id' => data_get($invoice, 'customer_id'),
                'customer_name' => data_get($invoice, 'customer_name'),
                'email' => data_get($invoice, 'email') ?? data_get($invoice, 'contact_persons.0.email'),
                'invoices' => [[
                    'invoice_id' => data_get($invoice, 'invoice_id') ?? $invoiceId,
                    'invoice_number' => data_get($invoice, 'invoice_number'),
                    'invoice_amount' => data_get($invoice, 'total'),
                    'amount_applied' => data_get($invoice, 'total') ?? 1,
                    'balance_amount' => data_get($invoice, 'balance') ?? 0,
                    'hosted_page_id' => data_get($invoice, 'hosted_page_id') ?? data_get($invoice, 'hostedpage_id'),
                    'subscription_ids' => $subscriptionId ? [$subscriptionId] : [],
                    'transaction_type' => 'manual_sync',
                ]],
            ],
        ];
    }
}

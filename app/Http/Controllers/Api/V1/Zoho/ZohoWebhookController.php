<?php

namespace App\Http\Controllers\Api\V1\Zoho;

use App\Http\Controllers\Controller;
use App\Services\Zoho\ZohoEventPaymentService;
use App\Support\Zoho\ZohoBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ZohoWebhookController extends Controller
{
    public function __construct(private readonly ZohoBillingService $zohoBillingService, private readonly ZohoEventPaymentService $zohoEventPaymentService)
    {
    }

    public function handle(Request $request)
    {
        $token = $request->header('X-Webhook-Token');
        $configuredSecret = (string) (config('services.zoho.webhook_secret') ?: config('zoho_billing.webhook_secret') ?: env('ZOHO_WEBHOOK_SECRET', ''));

        if ($configuredSecret === '' || ! is_string($token) || ! hash_equals($configuredSecret, $token)) {
            Log::warning('Zoho webhook unauthorized token mismatch', [
                'ip' => $request->ip(),
                'headers' => [
                    'has_x_webhook_token' => $request->header('X-Webhook-Token') !== null,
                    'user-agent' => $request->userAgent(),
                    'content-type' => $request->header('Content-Type'),
                ],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unauthorized webhook request.',
            ], 401);
        }

        $raw = $request->getContent();
        $event = $request->all();

        if ($event === [] && $raw !== '') {
            $decoded = json_decode($raw, true);
            $event = is_array($decoded) ? $decoded : $event;
        }

        if (! is_array($event) || $event === []) {
            Log::error('Zoho webhook invalid payload, skipping', [
                'ip' => $request->ip(),
                'raw_preview' => mb_substr((string) $raw, 0, 1000),
            ]);

            return response()->json([
                'success' => true,
                'handled' => false,
            ], 200);
        }

        Log::info('Zoho webhook received', [
            'event_type' => data_get($event, 'event_type') ?? data_get($event, 'event.type') ?? data_get($event, 'event') ?? data_get($event, 'eventType'),
            'payload' => $event,
        ]);

        $ok = false;

        try {
            $this->zohoEventPaymentService->syncPaidPaymentFromWebhook($event);
            $ok = $this->zohoBillingService->applyWebhookEvent($event);
        } catch (Throwable $throwable) {
            Log::error('Zoho webhook processing failed', [
                'event_type' => data_get($event, 'event_type') ?? data_get($event, 'event.type') ?? data_get($event, 'event') ?? data_get($event, 'eventType'),
                'message' => $throwable->getMessage(),
            ]);
        }

        Log::info('Zoho webhook handled', [
            'event_type' => data_get($event, 'event_type') ?? data_get($event, 'event.type') ?? data_get($event, 'event') ?? data_get($event, 'eventType'),
            'ok' => $ok,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Webhook processed successfully',
        ], 200);
    }
}

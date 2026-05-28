<?php

namespace App\Http\Controllers\Api\V1\Zoho;

use App\Http\Controllers\Controller;
use App\Services\Zoho\ZohoEventPaymentService;
use App\Support\Zoho\ZohoBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ZohoWebhookController extends Controller
{
    public function __construct(private readonly ZohoBillingService $zohoBillingService, private readonly ZohoEventPaymentService $zohoEventPaymentService)
    {
    }

    /**
     * Zoho Billing may perform reachability checks during webhook save using methods
     * different from actual POST webhook delivery. POST remains secured by token.
     */
    public function verify(Request $request)
    {
        $headerToken = (string) ($request->header('X-Webhook-Token') ?? $request->header('x-webhook-token') ?? '');
        $queryToken = (string) ($request->query('token') ?? '');

        Log::info('Zoho webhook verification hit', [
            'method' => $request->getMethod(),
            'endpoint' => $request->path(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'content_type' => $request->header('Content-Type'),
            'has_header_token' => trim($headerToken) !== '',
            'has_query_token' => trim($queryToken) !== '',
            'header_token_length' => strlen(trim($headerToken)),
            'query_token_length' => strlen(trim($queryToken)),
        ]);

        if ($request->isMethod('HEAD')) {
            return response('', Response::HTTP_OK);
        }

        return response()->json([
            'success' => true,
            'message' => 'Zoho webhook endpoint reachable.',
        ], Response::HTTP_OK);
    }

    public function health()
    {
        $configuredSecret = trim((string) (
            config('services.zoho.webhook_token')
            ?: config('zoho_billing.webhook_secret')
            ?: env('ZOHO_WEBHOOK_TOKEN')
            ?: ''
        ));

        return response()->json([
            'success' => true,
            'message' => 'Zoho webhook health OK',
            'token_configured' => $configuredSecret !== '',
            'token_length' => strlen($configuredSecret),
        ], Response::HTTP_OK);
    }

    public function handle(Request $request)
    {
        $receivedToken = trim((string) (
            $request->header('X-Webhook-Token')
            ?: $request->header('x-webhook-token')
            ?: $request->query('token')
            ?: ''
        ));

        $configuredSecret = trim((string) (
            config('services.zoho.webhook_token')
            ?: config('zoho_billing.webhook_secret')
            ?: env('ZOHO_WEBHOOK_TOKEN')
            ?: ''
        ));

        $raw = $request->getContent();
        $event = $request->all();

        if ($event === [] && $raw !== '') {
            $decoded = json_decode($raw, true);
            $event = is_array($decoded) ? $decoded : $event;
        }

        Log::info('Zoho webhook request received', [
            'method' => $request->getMethod(),
            'endpoint' => $request->path(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'content_type' => $request->header('Content-Type'),
            'has_header_token' => trim((string) ($request->header('X-Webhook-Token') ?? $request->header('x-webhook-token') ?? '')) !== '',
            'has_query_token' => trim((string) ($request->query('token') ?? '')) !== '',
            'received_token_length' => strlen($receivedToken),
            'configured_token_length' => strlen($configuredSecret),
            'raw_payload_preview' => mb_substr((string) $raw, 0, 1000),
            'parsed_payload_keys' => is_array($event) ? array_keys($event) : [],
        ]);

        if ($configuredSecret === '') {
            Log::error('Zoho webhook configured secret missing', [
                'endpoint' => $request->path(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Zoho webhook secret is not configured.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if ($receivedToken === '' || ! hash_equals($configuredSecret, $receivedToken)) {
            Log::warning('Zoho webhook unauthorized token mismatch', [
                'ip' => $request->ip(),
                'received_token_length' => strlen($receivedToken),
                'configured_token_length' => strlen($configuredSecret),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unauthorized webhook request.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (! is_array($event) || $event === []) {
            Log::info('Zoho webhook empty payload, acknowledged', [
                'endpoint' => $request->path(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Webhook received',
                'handled' => false,
            ], Response::HTTP_OK);
        }

        Log::info('Zoho webhook received', [
            'event_type' => $event['event_type'] ?? ($event['eventType'] ?? null),
            'event_id' => $event['event_id'] ?? ($event['eventId'] ?? null),
            'keys' => array_keys($event),
            'raw_preview' => mb_substr((string) $raw, 0, 1000),
        ]);

        $ok = false;

        try {
            $this->zohoEventPaymentService->syncPaidPaymentFromWebhook($event);
            $ok = $this->zohoBillingService->applyWebhookEvent($event);
        } catch (Throwable $throwable) {
            Log::error('Zoho webhook processing failed', [
                'event_type' => $event['event_type'] ?? ($event['eventType'] ?? null),
                'message' => $throwable->getMessage(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Webhook received',
            ], Response::HTTP_OK);
        }

        Log::info('Zoho webhook handled', [
            'event_type' => $event['event_type'] ?? ($event['eventType'] ?? null),
            'ok' => $ok,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Webhook received',
            'handled' => $ok,
        ], Response::HTTP_OK);
    }
}

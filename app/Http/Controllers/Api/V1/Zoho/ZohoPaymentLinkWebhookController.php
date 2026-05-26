<?php

namespace App\Http\Controllers\Api\V1\Zoho;

use App\Http\Controllers\Controller;
use App\Models\EventRegistration;
use App\Services\Zoho\ZohoBillingPaymentLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ZohoPaymentLinkWebhookController extends Controller
{
    public function __construct(private readonly ZohoBillingPaymentLinkService $service) {}

    public function handle(Request $request)
    {
        $payload = $request->all();
        $registration = null;

        foreach ([
            data_get($payload, 'reference_id'),
            data_get($payload, 'registration_id'),
        ] as $id) {
            if (! empty($id)) {
                $registration = EventRegistration::query()->find($id);
                if ($registration) {
                    break;
                }
            }
        }

        if (! $registration) {
            $paymentLinkId = data_get($payload, 'zoho_payment_link_id') ?? data_get($payload, 'payment_link_id');
            if ($paymentLinkId) {
                $registration = EventRegistration::query()->where('zoho_payment_link_id', $paymentLinkId)->first();
            }
        }

        if (! $registration) {
            return response()->json(['success' => true]);
        }

        $registration->forceFill(array_filter([
            'webhook_payload' => $payload,
            'zoho_payment_webhook_payload' => Schema::hasColumn('event_registrations', 'zoho_payment_webhook_payload') ? $payload : null,
        ], fn ($v) => $v !== null))->save();

        $status = strtolower((string) (data_get($payload, 'status') ?? data_get($payload, 'payment.status') ?? ''));
        if (in_array($status, ['paid', 'success', 'succeeded'], true)) {
            $this->service->markRegistrationPaid($registration, $payload, $payload);
        }

        return response()->json(['success' => true]);
    }
}

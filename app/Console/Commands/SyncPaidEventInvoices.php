<?php

namespace App\Console\Commands;

use App\Models\EventRegistration;
use App\Services\Events\EventZohoInvoiceSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class SyncPaidEventInvoices extends Command
{
    protected $signature = 'zoho:event-invoices:sync-paid {--registration_id=}';

    protected $description = 'Sync paid event registrations with Zoho invoice finalization/payment application.';

    public function handle(EventZohoInvoiceSyncService $service): int
    {
        $query = EventRegistration::query()->where('payment_status', 'paid')->whereNotNull('zoho_invoice_id');

        if (Schema::hasColumn('event_registrations', 'zoho_invoice_status')) {
            $query->where(function ($q): void {
                $q->whereNull('zoho_invoice_status')->orWhereIn('zoho_invoice_status', ['draft', 'unpaid', 'sent']);
            })->orWhereNotNull('zoho_invoice_sync_error');
        }

        if ($registrationId = $this->option('registration_id')) {
            $query->where('id', $registrationId);
        }

        $items = $query->get();
        foreach ($items as $registration) {
            $oldStatus = (string) ($registration->zoho_invoice_status ?? '');
            $result = $service->finalizeAndApplyPaymentToEventInvoice($registration);
            $registration->forceFill(array_filter([
                'zoho_invoice_status' => $result['status'] ?? $registration->zoho_invoice_status,
                'zoho_invoice_synced_at' => now(),
                'zoho_invoice_sync_error' => $result['sync_error'] ?? null,
                'zoho_payment_id' => $result['payment_id'] ?? $registration->zoho_payment_id,
                'zoho_invoice_url' => $result['invoice_url'] ?? $registration->zoho_invoice_url,
                'zoho_invoice_pdf_url' => $result['invoice_pdf_url'] ?? $registration->zoho_invoice_pdf_url,
            ], fn ($value, $key) => Schema::hasColumn('event_registrations', $key), ARRAY_FILTER_USE_BOTH))->save();

            if (empty($result['sync_error'])) {
                $this->info(sprintf(
                    'OK registration_id=%s invoice_id=%s payment_id=%s old_status=%s final_status=%s payment_applied=%s',
                    (string) $registration->id,
                    (string) ($result['invoice_id'] ?? $registration->zoho_invoice_id),
                    (string) ($result['payment_id'] ?? $registration->zoho_payment_id),
                    $oldStatus,
                    (string) ($result['status'] ?? 'unknown'),
                    ($result['payment_applied'] ?? false) ? 'true' : 'false'
                ));
            } else {
                $this->error(sprintf(
                    'FAIL registration_id=%s invoice_id=%s payment_id=%s old_status=%s final_status=%s payment_applied=%s error=%s',
                    (string) $registration->id,
                    (string) ($result['invoice_id'] ?? $registration->zoho_invoice_id),
                    (string) ($result['payment_id'] ?? $registration->zoho_payment_id),
                    $oldStatus,
                    (string) ($result['status'] ?? 'unknown'),
                    ($result['payment_applied'] ?? false) ? 'true' : 'false',
                    (string) $result['sync_error']
                ));
            }
        }

        $this->line('Processed: '.$items->count());

        return self::SUCCESS;
    }
}

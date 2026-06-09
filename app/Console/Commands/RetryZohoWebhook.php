<?php

namespace App\Console\Commands;

use App\Models\WebhookEvent;
use App\Services\Zoho\ZohoPaymentWebhookService;
use Illuminate\Console\Command;

class RetryZohoWebhook extends Command
{
    protected $signature = 'zoho:webhooks:retry {--id=} {--payment_id=} {--registration_id=} {--limit=50}';

    protected $description = 'Retry ignored or failed Zoho webhook events by id, payment id, or registration id.';

    public function handle(ZohoPaymentWebhookService $service): int
    {
        $query = WebhookEvent::query()
            ->where('provider', 'zoho')
            ->whereIn('status', ['ignored', 'failed']);

        if ($this->option('id')) {
            $query->where('id', $this->option('id'));
        }
        if ($this->option('payment_id')) {
            $query->where('payment_id', $this->option('payment_id'));
        }
        if ($this->option('registration_id')) {
            $query->where('registration_id', $this->option('registration_id'));
        }

        $events = $query->oldest()->limit((int) $this->option('limit'))->get();

        foreach ($events as $event) {
            $this->line('Retrying webhook '.$event->id.' payment='.(string) $event->payment_id.' registration='.(string) $event->registration_id);
            $event->forceFill(['status' => 'received', 'processed_at' => null, 'error' => null])->save();
            $service->processStored($event);
            $event->refresh();
            $this->line('Result '.$event->id.' status='.$event->status.' error='.(string) $event->error);
        }

        $this->info('Processed: '.$events->count());

        return self::SUCCESS;
    }
}

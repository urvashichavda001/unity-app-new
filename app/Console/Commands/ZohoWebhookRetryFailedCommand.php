<?php

namespace App\Console\Commands;

use App\Jobs\ProcessZohoWebhookJob;
use App\Models\ZohoWebhookLog;
use Illuminate\Console\Command;

class ZohoWebhookRetryFailedCommand extends Command
{
    protected $signature = 'zoho:webhooks:retry-failed {--limit=100}';
    protected $description = 'Retry failed/pending Zoho webhook logs';

    public function handle(): int
    {
        $logs = ZohoWebhookLog::query()->whereIn('status', ['failed', 'pending'])->orderBy('created_at')->limit((int) $this->option('limit'))->get();
        foreach ($logs as $log) {
            ProcessZohoWebhookJob::dispatch($log->id);
        }
        $this->info('Dispatched '.$logs->count().' webhook logs.');

        return self::SUCCESS;
    }
}

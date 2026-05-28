<?php

namespace App\Console\Commands;

use App\Jobs\ProcessZohoWebhookJob;
use Illuminate\Console\Command;

class ZohoWebhookProcessCommand extends Command
{
    protected $signature = 'zoho:webhooks:process {id}';
    protected $description = 'Process a single Zoho webhook log by id';

    public function handle(): int
    {
        ProcessZohoWebhookJob::dispatchSync((string) $this->argument('id'));
        $this->info('Webhook processed.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\CircleSubscription;
use App\Models\User;
use App\Models\ZohoWebhookLog;
use Illuminate\Console\Command;

class ZohoSubscriptionStatusCommand extends Command
{
    protected $signature = 'zoho:subscription-status {subscription_id}';
    protected $description = 'Show webhook logs and local status for a Zoho subscription';

    public function handle(): int
    {
        $sid = (string) $this->argument('subscription_id');
        $logs = ZohoWebhookLog::query()->where('subscription_id', $sid)->latest()->limit(20)->get(['id','status','attempts','error_message','created_at','processed_at']);
        $this->info('Webhook logs:');
        $this->table(['id','status','attempts','error','created_at','processed_at'], $logs->map(fn($l)=>[$l->id,$l->status,$l->attempts,$l->error_message,$l->created_at,$l->processed_at])->all());

        $subs = CircleSubscription::query()->where('zoho_subscription_id', $sid)->orWhere('subscription_id', $sid)->get();
        $this->info('Local subscriptions:');
        $this->table(['id','user_id','status','payment_status','zoho_subscription_id','updated_at'], $subs->map(fn($s)=>[$s->id,$s->user_id,$s->status,$s->payment_status,$s->zoho_subscription_id,$s->updated_at])->all());

        $userIds = $subs->pluck('user_id')->filter()->unique()->values();
        if ($userIds->isNotEmpty()) {
            $users = User::query()->whereIn('id', $userIds)->get(['id','membership_status','payment_status','membership_ends_at']);
            $this->info('Local users/memberships:');
            $this->table(['id','membership_status','payment_status','membership_ends_at'], $users->map(fn($u)=>[$u->id,$u->membership_status,$u->payment_status,$u->membership_ends_at])->all());
        }

        return self::SUCCESS;
    }
}

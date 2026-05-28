<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ZohoWebhookLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'event_type',
        'module',
        'zoho_record_id',
        'subscription_id',
        'hostedpage_id',
        'invoice_id',
        'payment_id',
        'payload',
        'status',
        'attempts',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];
}

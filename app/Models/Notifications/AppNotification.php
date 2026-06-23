<?php

namespace App\Models\Notifications;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppNotification extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id', 'campaign_id', 'type', 'category', 'title', 'body', 'channel', 'priority',
        'reference_type', 'reference_id', 'screen', 'data', 'dedupe_key', 'status', 'sent_at',
        'read_at', 'clicked_at', 'failed_at', 'failure_reason',
    ];

    protected $casts = [
        'data' => 'array',
        'sent_at' => 'datetime',
        'read_at' => 'datetime',
        'clicked_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function dataPayload(): array
    {
        return array_merge((array) ($this->data ?? []), [
            'notification_id' => (string) $this->id,
            'type' => (string) $this->type,
            'category' => (string) ($this->category ?? ''),
            'screen' => (string) ($this->screen ?? 'home'),
            'tap_destination' => (string) (($this->data['tap_destination'] ?? null) ?: ($this->screen ?? 'home')),
            'reference_type' => (string) $this->reference_type,
            'reference_id' => (string) $this->reference_id,
            'campaign_id' => (string) $this->campaign_id,
            'title' => (string) $this->title,
            'body' => (string) $this->body,
            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(NotificationCampaign::class, 'campaign_id');
    }

    public function deliveryLogs(): HasMany
    {
        return $this->hasMany(NotificationDeliveryLog::class, 'notification_id');
    }
}

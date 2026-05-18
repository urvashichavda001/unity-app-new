<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AdminCampaignRecipient extends Model
{
    use HasFactory;

    protected $table = 'admin_campaign_recipients';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'campaign_id', 'user_id', 'email', 'email_status', 'notification_status', 'email_sent',
        'notification_sent', 'error_message', 'sent_at',
    ];

    protected $casts = [
        'email_sent' => 'boolean',
        'notification_sent' => 'boolean',
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (AdminCampaignRecipient $recipient): void {
            if (empty($recipient->id)) {
                $recipient->id = (string) Str::uuid();
            }
        });
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(AdminCampaign::class, 'campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

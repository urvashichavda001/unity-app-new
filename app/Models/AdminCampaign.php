<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AdminCampaign extends Model
{
    use HasFactory;

    public const DEFAULT_SENDER_EMAIL = 'noreply@peersunity.com';

    public const SENDER_EMAILS = [
        'hello@peersunity.com',
        self::DEFAULT_SENDER_EMAIL,
        'support@peersunity.com',
    ];

    public const TYPE_EMAIL_ONLY = 'email_only';
    public const TYPE_NOTIFICATION_ONLY = 'notification_only';
    public const TYPE_EMAIL_AND_NOTIFICATION = 'email_and_notification';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PARTIALLY_SENT = 'partially_sent';

    protected $table = 'admin_campaigns';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'title', 'campaign_type', 'subject', 'email_body', 'notification_title', 'notification_message',
        'audience_type', 'filters', 'total_recipients', 'total_email_sent', 'total_notification_sent',
        'total_failed', 'status', 'sent_at', 'pamphlet_id', 'pamphlet_snapshot', 'email_template_id',
        'email_template_snapshot', 'sender_email', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'filters' => 'array',
        'pamphlet_snapshot' => 'array',
        'email_template_snapshot' => 'array',
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (AdminCampaign $campaign): void {
            if (empty($campaign->id)) {
                $campaign->id = (string) Str::uuid();
            }
        });
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(AdminCampaignRecipient::class, 'campaign_id');
    }

    public function pamphlet(): BelongsTo
    {
        return $this->belongsTo(CampaignPamphlet::class, 'pamphlet_id');
    }

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(CampaignEmailTemplate::class, 'email_template_id');
    }

    public function includesEmail(): bool
    {
        return in_array($this->campaign_type, [self::TYPE_EMAIL_ONLY, self::TYPE_EMAIL_AND_NOTIFICATION], true);
    }

    public function includesNotification(): bool
    {
        return in_array($this->campaign_type, [self::TYPE_NOTIFICATION_ONLY, self::TYPE_EMAIL_AND_NOTIFICATION], true);
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function getSenderEmailAttribute(): string
    {
        $senderEmail = data_get($this->filters, '_campaign_meta.sender_email');

        return in_array($senderEmail, self::SENDER_EMAILS, true)
            ? $senderEmail
            : self::DEFAULT_SENDER_EMAIL;
    }

    public function setSenderEmailAttribute(?string $value): void
    {
        $senderEmail = in_array($value, self::SENDER_EMAILS, true)
            ? $value
            : self::DEFAULT_SENDER_EMAIL;
        $filters = $this->filters ?: [];

        data_set($filters, '_campaign_meta.sender_email', $senderEmail);

        $this->attributes['filters'] = json_encode($filters);
    }
}

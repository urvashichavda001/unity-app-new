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
        $payload = array_merge((array) ($this->data ?? []), [
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

        return $this->normalizePushPayload($payload);
    }

    public function normalizePushPayload(array $data): array
    {
        // 1. For Circle Details: Ensure payload maps "type" to "circle_update", "notification_type" to "circle_details", and includes "circle_id" (UUID).
        $isCircle = false;
        if (
            ($data['notification_type'] ?? '') === 'circle_details' ||
            ($data['type'] ?? '') === 'circle_details' ||
            ($data['type'] ?? '') === 'circle_update' ||
            ($data['screen'] ?? '') === 'circle_details' ||
            ($data['tap_destination'] ?? '') === 'circle_details' ||
            (str_contains(strtolower($data['reference_type'] ?? ''), 'circle'))
        ) {
            $isCircle = true;
        }

        if ($isCircle) {
            $data['type'] = 'circle_update';
            $data['notification_type'] = 'circle_details';
            $circleId = $data['circle_id'] ?? $data['reference_id'] ?? null;
            if ($circleId) {
                $data['circle_id'] = (string) $circleId;
            }
        }

        // 2. For Leader Profile: Map "type" to "member_profile", "notification_type" to "leader_profile", and include "member_id" (UUID).
        $isLeader = false;
        if (
            ($data['notification_type'] ?? '') === 'leader_profile' ||
            ($data['type'] ?? '') === 'leader_profile' ||
            ($data['type'] ?? '') === 'member_profile' ||
            ($data['screen'] ?? '') === 'member_profile' ||
            ($data['tap_destination'] ?? '') === 'member_profile'
        ) {
            $isLeader = true;
        }

        if ($isLeader) {
            $data['type'] = 'member_profile';
            $data['notification_type'] = 'leader_profile';
            $memberId = $data['member_id'] ?? $data['user_id'] ?? $data['actor_id'] ?? $data['reference_id'] ?? null;
            if ($memberId) {
                $data['member_id'] = (string) $memberId;
            }
        }

        // 3. For Business Deals: Map "type" to "business_deal_finalized", "notification_type" to "business_deal", and include "deal_id" (UUID).
        $isDeal = false;
        if (
            ($data['notification_type'] ?? '') === 'business_deal' ||
            ($data['type'] ?? '') === 'business_deal' ||
            ($data['type'] ?? '') === 'business_deal_finalized' ||
            ($data['screen'] ?? '') === 'business_deal' ||
            ($data['tap_destination'] ?? '') === 'business_deal'
        ) {
            $isDeal = true;
        }

        if ($isDeal) {
            $data['type'] = 'business_deal_finalized';
            $data['notification_type'] = 'business_deal';
            $dealId = $data['deal_id'] ?? $data['business_deal_id'] ?? $data['reference_id'] ?? null;
            if ($dealId) {
                $data['deal_id'] = (string) $dealId;
            }
        }

        // 4. For Advertiser Ads / External Promo: Update the route destination keyword logic from '/deals' to '/external-promo'. Map "type" to "external_promo", "notification_type" to "marketing_ad", and include a valid "ad_url" parameter.
        $isPromo = false;
        if (isset($data['tap_destination']) && str_contains($data['tap_destination'], '/deals')) {
            $data['tap_destination'] = str_replace('/deals', '/external-promo', $data['tap_destination']);
            $isPromo = true;
        }
        if (isset($data['screen']) && str_contains($data['screen'], '/deals')) {
            $data['screen'] = str_replace('/deals', '/external-promo', $data['screen']);
            $isPromo = true;
        }
        if (
            ($data['notification_type'] ?? '') === 'marketing_ad' ||
            ($data['type'] ?? '') === 'external_promo' ||
            ($data['type'] ?? '') === 'external_promo' ||
            ($data['screen'] ?? '') === '/external-promo' ||
            ($data['tap_destination'] ?? '') === '/external-promo'
        ) {
            $isPromo = true;
        }

        if ($isPromo) {
            $data['type'] = 'external_promo';
            $data['notification_type'] = 'marketing_ad';
            $data['ad_url'] = (string) ($data['ad_url'] ?? $data['url'] ?? $data['promo_url'] ?? 'https://peersunity.com/assets/rewards/external-promo');
        }

        return $data;
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

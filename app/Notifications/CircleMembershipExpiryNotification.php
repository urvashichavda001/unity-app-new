<?php

namespace App\Notifications;

use App\Models\CircleMember;
use Illuminate\Notifications\Notification;

class CircleMembershipExpiryNotification extends Notification
{
    public CircleMember $circleMember;

    public function __construct(CircleMember $circleMember)
    {
        $this->circleMember = $circleMember;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toArray($notifiable)
    {
        return [
            'notification_type' => 'circle_membership_expiry_reminder',
            'title' => 'Circle Membership Expiring Soon',
            'body' => 'Your Circle Membership will expire soon. Please renew before the expiry date to continue enjoying your Circle benefits and access.',
            'expires_at' => $this->circleMember->expires_at ? $this->circleMember->expires_at->toIso8601String() : null,
        ];
    }
}

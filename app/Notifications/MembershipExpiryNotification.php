<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

class MembershipExpiryNotification extends Notification
{
    public User $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toArray($notifiable)
    {
        $formattedDate = $this->user->membership_ends_at
            ? $this->user->membership_ends_at->format('d M Y')
            : '';

        return [
            'notification_type' => 'membership_expired',
            'title' => 'Membership Expired – Action Required',
            'body' => "Dear {$this->user->display_name}, your membership with Peers Global Unity has expired as of {$formattedDate}. Please renew your membership.",
            'membership_ends_at' => $this->user->membership_ends_at ? $this->user->membership_ends_at->toIso8601String() : null,
        ];
    }
}

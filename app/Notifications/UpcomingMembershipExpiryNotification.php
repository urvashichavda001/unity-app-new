<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

class UpcomingMembershipExpiryNotification extends Notification
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
            'notification_type' => 'upcoming_membership_expired',
            'title' => 'Upcoming Membership Expiry – Renewal Reminder',
            'body' => "Dear {$this->user->display_name}, your membership with Peers Global Unity is approaching its expiry date on {$formattedDate}. Please renew your membership.",
            'membership_ends_at' => $this->user->membership_ends_at ? $this->user->membership_ends_at->toIso8601String() : null,
        ];
    }
}

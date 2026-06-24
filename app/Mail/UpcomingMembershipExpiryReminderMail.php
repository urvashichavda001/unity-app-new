<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class UpcomingMembershipExpiryReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public User $user;
    public string $formattedExpiryDate;
    public string $support_email;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->formattedExpiryDate = $user->membership_ends_at
            ? $user->membership_ends_at->format('d M Y')
            : '';
        $this->support_email = config('mail.from.address') ?: 'support@peersglobal.com';
    }

    public function build()
    {
        return $this->subject('Upcoming Membership Expiry – Renewal Reminder')
            ->view('emails.membership.upcoming_expiry_reminder')
            ->with([
                'user' => $this->user,
                'membership_ends_at' => $this->formattedExpiryDate,
                'support_email' => $this->support_email,
            ]);
    }
}

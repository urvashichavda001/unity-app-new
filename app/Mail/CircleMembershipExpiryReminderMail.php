<?php

namespace App\Mail;

use App\Models\User;
use App\Models\CircleMember;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class CircleMembershipExpiryReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public User $user;
    public string $formattedExpiryDate;
    public string $support_email;

    public function __construct(User $user, CircleMember $circleMember)
    {
        $this->user = $user;
        $this->formattedExpiryDate = $circleMember->expires_at
            ? $circleMember->expires_at->format('d M Y')
            : '';
        $this->support_email = config('mail.from.address') ?: 'support@peersglobal.com';
    }

    public function build()
    {
        return $this->subject('Your Circle Membership Is Expiring Soon')
            ->view('emails.membership.circle_expiry_reminder')
            ->with([
                'user' => $this->user,
                'expires_at' => $this->formattedExpiryDate,
                'support_email' => $this->support_email,
            ]);
    }
}

<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MembershipUpdatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public array $details,
    ) {
    }

    public function build(): self
    {
        return $this->from('pravin@peersunity.com', 'Peers Global')
            ->subject('Membership Updated - Peers Global Unity')
            ->view('emails.membership.membership_updated')
            ->with([
                'user' => $this->user,
                'details' => $this->details,
            ]);
    }
}

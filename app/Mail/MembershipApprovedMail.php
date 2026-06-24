<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class MembershipApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Carbon $membershipStartsAt,
        public Carbon $membershipEndsAt,
    ) {
    }

    private function logoUrl(): ?string
    {
        foreach ([
            'assets/images/peers-global-logo.png',
            'assets/images/logo.png',
            'assets/img/logo.png',
            'images/peers-global-logo.png',
            'images/logo.png',
            'admin/images/logo.png',
            'img/logo.png',
            'logo.png',
        ] as $path) {
            if (file_exists(public_path($path))) {
                return asset($path);
            }
        }

        return 'https://unity.peersglobal.com/wp-content/uploads/2025/08/peersglobal_white-removebg-preview.png';
    }

    public function build()
    {
        return $this->subject('Your PeersGlobal Membership Has Been Approved')
            ->view('emails.membership-approved')
            ->with([
                'user' => $this->user,
                'userName' => $this->user->name ?: trim((string) (($this->user->first_name ?? '') . ' ' . ($this->user->last_name ?? ''))) ?: ($this->user->display_name ?: 'Peer'),
                'membershipStartsAt' => $this->membershipStartsAt->format('d M Y'),
                'membershipEndsAt' => $this->membershipEndsAt->format('d M Y'),
                'currentYear' => now()->year,
                'logoUrl' => $this->logoUrl(),
            ]);
    }
}

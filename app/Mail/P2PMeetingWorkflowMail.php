<?php

namespace App\Mail;

use App\Models\P2PMeetingRequest;
use App\Models\P2PMeetingRescheduleRequest;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class P2PMeetingWorkflowMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $eventType,
        public P2PMeetingRequest $meetingRequest,
        public User $recipient,
        public ?User $actor = null,
        public ?P2PMeetingRescheduleRequest $rescheduleRequest = null,
        public ?string $responseReason = null,
    ) {
    }

    public function build(): self
    {
        return $this->subject($this->subjectLine())
            ->view('emails.p2p_meeting_workflow');
    }

    public function subjectLine(): string
    {
        return match ($this->eventType) {
            'p2p_reschedule_requested' => 'P2P meeting reschedule requested',
            'p2p_reschedule_approved' => 'P2P meeting reschedule approved',
            'p2p_reschedule_rejected' => 'P2P meeting reschedule rejected',
            'p2p_meeting_completed' => 'P2P meeting completed',
            default => 'P2P meeting update',
        };
    }
}

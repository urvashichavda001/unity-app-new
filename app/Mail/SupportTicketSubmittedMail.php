<?php

namespace App\Mail;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SupportTicketSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public SupportTicket $ticket)
    {
    }

    public function build(): self
    {
        return $this->subject('Support Ticket Received - ' . $this->ticket->ticket_number)
            ->view('emails.support_ticket_submitted');
    }
}

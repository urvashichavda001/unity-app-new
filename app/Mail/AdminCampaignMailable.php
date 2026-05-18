<?php

namespace App\Mail;

use App\Models\AdminCampaign;
use App\Models\User;
use App\Services\AdminCampaigns\CampaignEmailTemplateRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdminCampaignMailable extends Mailable
{
    use Queueable;
    use SerializesModels;

    public string $subjectLine;

    public function __construct(public AdminCampaign $campaign, public User $recipient)
    {
        $this->subjectLine = (string) $campaign->subject;
    }

    public function build(): self
    {
        return $this->subject($this->subjectLine)
            ->view('emails.admin.campaign')
            ->with([
                'campaign' => $this->campaign,
                'user' => $this->recipient,
                'bodyHtml' => app(CampaignEmailTemplateRenderer::class)->render($this->campaign),
            ]);
    }
}

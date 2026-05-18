<?php

namespace App\Services\AdminCampaigns;

use App\Mail\AdminCampaignMailable;
use App\Models\AdminCampaign;
use App\Models\AdminCampaignRecipient;
use App\Models\Notification;
use App\Models\User;
use App\Services\EmailLogs\EmailLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CampaignSendService
{
    private ?string $notificationType = null;

    public function __construct(
        private readonly CampaignRecipientResolverService $resolver,
        private readonly EmailLogService $emailLogService,
        private readonly CampaignEmailTemplateRenderer $emailTemplateRenderer,
    ) {
    }

    public function send(AdminCampaign $campaign): AdminCampaign
    {
        if ($campaign->status !== AdminCampaign::STATUS_DRAFT) {
            throw new RuntimeException('Campaign has already been sent or is not editable.');
        }

        $recipientCount = $this->resolver->count($campaign->audience_type, $campaign->filters, $campaign->includesEmail());
        if ($recipientCount < 1) {
            throw new RuntimeException('Campaign has no eligible recipients.');
        }

        $campaign->forceFill([
            'total_recipients' => $recipientCount,
            'total_email_sent' => 0,
            'total_notification_sent' => 0,
            'total_failed' => 0,
            'email_template_snapshot' => $campaign->includesEmail() ? $this->emailTemplateRenderer->snapshotForCampaign($campaign, true) : $campaign->email_template_snapshot,
        ])->save();

        $stats = ['email_sent' => 0, 'notification_sent' => 0, 'failed' => 0];

        $this->resolver->query($campaign->audience_type, $campaign->filters, $campaign->includesEmail())
            ->chunk(100, function ($users) use ($campaign, &$stats): void {
                foreach ($users as $user) {
                    $this->sendToUser($campaign, $user, $stats);
                }
            });

        $campaign->forceFill([
            'total_email_sent' => $stats['email_sent'],
            'total_notification_sent' => $stats['notification_sent'],
            'total_failed' => $stats['failed'],
            'status' => $stats['failed'] === 0 ? AdminCampaign::STATUS_SENT : ($stats['failed'] >= $campaign->total_recipients ? AdminCampaign::STATUS_FAILED : AdminCampaign::STATUS_PARTIALLY_SENT),
            'sent_at' => now(),
        ])->save();

        return $campaign->refresh();
    }

    private function sendToUser(AdminCampaign $campaign, User $user, array &$stats): void
    {
        $recipient = AdminCampaignRecipient::query()->firstOrCreate([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
        ], [
            'email' => $user->email,
            'email_status' => $campaign->includesEmail() ? 'pending' : 'skipped',
            'notification_status' => $campaign->includesNotification() ? 'pending' : 'skipped',
        ]);

        $errors = [];
        $emailSent = (bool) $recipient->email_sent;
        $notificationSent = (bool) $recipient->notification_sent;
        $emailStatus = $campaign->includesEmail() ? (string) ($recipient->email_status ?: 'pending') : 'skipped';
        $notificationStatus = $campaign->includesNotification() ? (string) ($recipient->notification_status ?: 'pending') : 'skipped';

        if ($campaign->includesEmail()) {
            $email = trim((string) $user->email);
            if ($email === '') {
                $emailStatus = 'skipped';
                $emailSent = false;
            } elseif ($emailSent || $emailStatus === 'sent') {
                $emailSent = true;
                $emailStatus = 'sent';
                $stats['email_sent']++;
            } else {
                try {
                    $mailable = new AdminCampaignMailable($campaign, $user);
                    Mail::to($email)->send($mailable);
                    $emailSent = true;
                    $emailStatus = 'sent';
                    $stats['email_sent']++;
                    $this->emailLogService->logMailableSent($mailable, $this->emailLogData($campaign, $user, $email));
                } catch (Throwable $exception) {
                    $emailStatus = 'failed';
                    $errors[] = $exception->getMessage();
                    $this->emailLogService->logMailableFailed(new AdminCampaignMailable($campaign, $user), $this->emailLogData($campaign, $user, $email), $exception);
                }
            }
        }

        if ($campaign->includesNotification()) {
            if ($notificationSent || $notificationStatus === 'sent') {
                $notificationSent = true;
                $notificationStatus = 'sent';
                $stats['notification_sent']++;
            } else {
                try {
                    Notification::query()->create($this->notificationRow($campaign, $user));
                    $notificationSent = true;
                    $notificationStatus = 'sent';
                    $stats['notification_sent']++;
                } catch (Throwable $exception) {
                    $notificationStatus = 'failed';
                    $errors[] = $exception->getMessage();
                }
            }
        }

        if ($errors !== []) {
            $stats['failed']++;
        }

        $recipient->forceFill([
            'email' => $user->email,
            'email_status' => $emailStatus,
            'notification_status' => $notificationStatus,
            'email_sent' => $emailSent,
            'notification_sent' => $notificationSent,
            'error_message' => $errors === [] ? null : Str::limit(implode(' | ', $errors), 5000, ''),
            'sent_at' => now(),
        ])->save();
    }

    private function emailLogData(AdminCampaign $campaign, User $user, string $email): array
    {
        return [
            'user_id' => $user->id,
            'to_email' => $email,
            'to_name' => $user->adminDisplayName(),
            'template_key' => 'admin_campaign',
            'subject' => $campaign->subject,
            'source_module' => 'admin_campaigns',
            'related_type' => AdminCampaign::class,
            'related_id' => $campaign->id,
            'source_type' => 'admin_campaign',
            'source_id' => $campaign->id,
            'source_event' => 'campaign_send',
            'payload' => ['campaign_id' => $campaign->id, 'campaign_title' => $campaign->title],
        ];
    }

    private function resolveNotificationType(): string
    {
        if ($this->notificationType !== null) {
            return $this->notificationType;
        }

        try {
            $allowedTypes = DB::table('pg_enum')
                ->join('pg_type', 'pg_type.oid', '=', 'pg_enum.enumtypid')
                ->where('pg_type.typname', 'notification_type_enum')
                ->pluck('pg_enum.enumlabel')
                ->map(fn ($type) => (string) $type)
                ->all();

            foreach (['general', 'system', 'activity_update'] as $type) {
                if (in_array($type, $allowedTypes, true)) {
                    return $this->notificationType = $type;
                }
            }
        } catch (Throwable) {
            // Fall back to the base schema's enum-safe system type when enum introspection is unavailable.
        }

        return $this->notificationType = 'system';
    }

    private function notificationRow(AdminCampaign $campaign, User $user): array
    {
        $row = [
            'user_id' => $user->id,
            'type' => $this->resolveNotificationType(),
            'payload' => [
                'notification_type' => 'admin_campaign',
                'title' => $campaign->notification_title,
                'body' => $campaign->notification_message,
                'campaign_id' => $campaign->id,
                'pamphlet_id' => $campaign->pamphlet_id,
                'pamphlet_image_url' => $campaign->pamphlet_snapshot['image_url'] ?? null,
                'data' => [
                    'campaign_id' => $campaign->id,
                    'pamphlet_id' => $campaign->pamphlet_id,
                    'pamphlet_image_url' => $campaign->pamphlet_snapshot['image_url'] ?? null,
                ],
            ],
            'is_read' => false,
            'created_at' => now(),
            'read_at' => null,
        ];

        foreach (['title' => $campaign->notification_title, 'message' => $campaign->notification_message, 'source_type' => 'admin_campaign', 'source_id' => $campaign->id, 'source_event' => 'campaign_send'] as $column => $value) {
            if (Schema::hasColumn('notifications', $column)) {
                $row[$column] = $value;
            }
        }

        return $row;
    }
}

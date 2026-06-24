<?php

namespace App\Console\Commands;

use App\Events\UserNotificationCreated;
use App\Jobs\SendFcmNotificationJob;
use App\Mail\CircleMembershipExpiryReminderMail;
use App\Models\Notification;
use App\Models\CircleMember;
use App\Models\User;
use App\Notifications\CircleMembershipExpiryNotification;
use App\Services\EmailLogs\EmailLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class SendCircleMembershipExpiryReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'memberships:send-circle-expiry-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send daily upcoming circle membership expiry reminder emails and notifications to members approaching expiry.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('Starting circle membership expiry reminder process...');

        // Fetch circle members whose membership is active and approaching expiry (expires_at in next 30 days)
        $circleMembers = CircleMember::query()
            ->whereHas('user', function ($query) {
                $query->where('membership_status', User::STATUS_FREE);
            })
            ->with('user')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>=', now())
            ->where('expires_at', '<=', now()->addDays(30))
            ->get();

        $this->info('Found ' . $circleMembers->count() . ' circle member records approaching expiry.');

        $sentCount = 0;
        $failedCount = 0;
        $skippedCount = 0;
        $sentEmails = [];

        foreach ($circleMembers as $circleMember) {
            $user = $circleMember->user;
            if (! $user) {
                $skippedCount++;
                continue;
            }

            $email = strtolower(trim((string) $user->email));

            // Prevent duplicate emails/notifications within the same scheduled execution
            if ($email === '' || in_array($email, $sentEmails, true)) {
                $skippedCount++;
                continue;
            }

            // Create Mailable instance
            $mailable = new CircleMembershipExpiryReminderMail($user, $circleMember);
            $emailSent = false;
            $emailError = null;

            try {
                // Queue email with a progressive delay to avoid rate limiting
                $delay = now()->addSeconds($sentCount * 2);
                Mail::to($user->email)->later($delay, $mailable);
                $emailSent = true;
            } catch (Throwable $exception) {
                $emailError = $exception;
            }

            try {
                // Create and trigger circle membership expiry notification
                $notificationObj = new CircleMembershipExpiryNotification($circleMember);
                $notifPayload = $notificationObj->toArray($user);

                $dbNotification = Notification::forceCreate([
                    'id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'type' => 'circle_update',
                    'payload' => $notifPayload,
                    'is_read' => false,
                    'created_at' => now(),
                    'read_at' => null,
                ]);

                event(new UserNotificationCreated((string) $user->id, [
                    'id' => (string) $dbNotification->id,
                    'type' => (string) $dbNotification->type,
                    'payload' => $dbNotification->payload,
                    'created_at' => optional($dbNotification->created_at)->toISOString(),
                ]));

                SendFcmNotificationJob::dispatch(
                    (string) $user->id,
                    (string) $notifPayload['title'],
                    (string) $notifPayload['body'],
                    [
                        'notification_type' => 'circle_membership_expiry_reminder',
                        'notification_id' => (string) $dbNotification->id,
                    ]
                );

                if ($emailSent) {
                    // Log to EmailLogService
                    app(EmailLogService::class)->logMailableSent($mailable, [
                        'user_id' => (string) $user->id,
                        'to_email' => (string) $user->email,
                        'to_name' => (string) $user->display_name,
                        'template_key' => 'circle_membership_expiry_reminder',
                        'source_module' => 'membership',
                        'related_type' => 'circle_member',
                        'related_id' => (string) $circleMember->id,
                        'payload' => [
                            'flow' => 'circle_membership_expiry_reminder_command',
                            'expires_at' => optional($circleMember->expires_at)->toDateTimeString(),
                            'notification_id' => (string) $dbNotification->id,
                        ],
                    ]);

                    // Log using the project's standard logging system
                    Log::info('membership.circle_expiry_reminder_email_sent', [
                        'user_id' => (string) $user->id,
                        'display_name' => (string) $user->display_name,
                        'email' => (string) $user->email,
                        'expires_at' => optional($circleMember->expires_at)->toDateString(),
                        'sent_at' => now()->toDateTimeString(),
                        'status' => 'sent',
                        'notification_id' => (string) $dbNotification->id,
                        'user_email' => (string) $user->email,
                        'membership_end_date' => optional($circleMember->expires_at)->toDateString(),
                        'user_status' => (string) $user->membership_status,
                        'reminder_type' => 'circle_expiry',
                        'email_status' => 'sent',
                        'notification_status' => 'sent',
                    ]);

                    $sentEmails[] = $email;
                    $sentCount++;
                } else {
                    throw $emailError ?: new \Exception('Email sending failed');
                }
            } catch (Throwable $exception) {
                $failedCount++;

                // Log failure to EmailLogService
                app(EmailLogService::class)->logMailableFailed($mailable, [
                    'user_id' => (string) $user->id,
                    'to_email' => (string) $user->email,
                    'to_name' => (string) $user->display_name,
                    'template_key' => 'circle_membership_expiry_reminder',
                    'source_module' => 'membership',
                    'related_type' => 'circle_member',
                    'related_id' => (string) $circleMember->id,
                    'payload' => [
                        'flow' => 'circle_membership_expiry_reminder_command',
                        'expires_at' => optional($circleMember->expires_at)->toDateTimeString(),
                    ],
                ], $exception);

                Log::warning('membership.circle_expiry_reminder_email_failed', [
                    'user_id' => (string) $user->id,
                    'display_name' => (string) $user->display_name,
                    'email' => (string) $user->email,
                    'expires_at' => optional($circleMember->expires_at)->toDateString(),
                    'sent_at' => now()->toDateTimeString(),
                    'status' => 'failed',
                    'error_message' => $exception->getMessage(),
                    'user_email' => (string) $user->email,
                    'membership_end_date' => optional($circleMember->expires_at)->toDateString(),
                    'user_status' => (string) $user->membership_status,
                    'reminder_type' => 'circle_expiry',
                    'email_status' => $emailSent ? 'sent' : 'failed',
                    'notification_status' => 'failed',
                ]);
            }
        }

        $this->info("Execution summary: Sent: {$sentCount}, Failed: {$failedCount}, Skipped: {$skippedCount}");

        return self::SUCCESS;
    }
}
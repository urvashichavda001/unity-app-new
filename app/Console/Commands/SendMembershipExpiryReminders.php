<?php

namespace App\Console\Commands;

use App\Events\UserNotificationCreated;
use App\Jobs\SendFcmNotificationJob;
use App\Mail\MembershipExpiryReminderMail;
use App\Models\Notification;
use App\Models\User;
use App\Notifications\MembershipExpiryNotification;
use App\Services\EmailLogs\EmailLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class SendMembershipExpiryReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'memberships:send-expiry-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send daily membership expiry reminder emails to expired members.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('Starting membership expiry reminder email process...');

        // Fetch all users whose membership has expired (membership_ends_at is in the past, i.e., less than now)
        $expiredUsers = User::query()
            ->whereIn('membership_status', [User::STATUS_FREE, 'free_peer'])
            ->whereNotNull('membership_ends_at')
            ->where('membership_ends_at', '<', now())
            ->get();

        $this->info('Found ' . $expiredUsers->count() . ' expired users.');

        $sentCount = 0;
        $failedCount = 0;
        $skippedCount = 0;
        $sentEmails = [];

        foreach ($expiredUsers as $user) {
            $email = strtolower(trim((string) $user->email));

            // Prevent duplicate emails within the same scheduled execution
            if ($email === '' || in_array($email, $sentEmails, true)) {
                $skippedCount++;
                continue;
            }

            // Create Mailable instance
            $mailable = new MembershipExpiryReminderMail($user);
            $emailSent = false;
            $emailError = null;

            try {
                Mail::to($user->email)->sendNow($mailable);
                $emailSent = true;
            } catch (Throwable $exception) {
                $emailError = $exception;
            }

            try {
                // Create and trigger membership expiry notification
                $notificationObj = new MembershipExpiryNotification($user);
                $notifPayload = $notificationObj->toArray($user);

                $dbNotification = Notification::forceCreate([
                    'id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'type' => 'system',
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
                        'notification_type' => 'membership_expired',
                        'notification_id' => (string) $dbNotification->id,
                    ]
                );

                if ($emailSent) {
                    // Log to EmailLogService (database)
                    app(EmailLogService::class)->logMailableSent($mailable, [
                        'user_id' => (string) $user->id,
                        'to_email' => (string) $user->email,
                        'to_name' => (string) $user->display_name,
                        'template_key' => 'membership_expiry_reminder',
                        'source_module' => 'membership',
                        'related_type' => 'user',
                        'related_id' => (string) $user->id,
                        'payload' => [
                            'flow' => 'membership_expiry_reminder_command',
                            'membership_ends_at' => optional($user->membership_ends_at)->toDateTimeString(),
                            'notification_id' => (string) $dbNotification->id,
                        ],
                    ]);

                    // Log using the project's standard logging system
                    Log::info('membership.expiry_reminder_email_sent', [
                        'user_id' => (string) $user->id,
                        'display_name' => (string) $user->display_name,
                        'email' => (string) $user->email,
                        'membership_ends_at' => optional($user->membership_ends_at)->toDateString(),
                        'sent_at' => now()->toDateTimeString(),
                        'status' => 'sent',
                        'notification_id' => (string) $dbNotification->id,
                        'user_email' => (string) $user->email,
                        'membership_end_date' => optional($user->membership_ends_at)->toDateString(),
                        'user_status' => (string) $user->membership_status,
                        'reminder_type' => 'expired_membership',
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
                    'template_key' => 'membership_expiry_reminder',
                    'source_module' => 'membership',
                    'related_type' => 'user',
                    'related_id' => (string) $user->id,
                    'payload' => [
                        'flow' => 'membership_expiry_reminder_command',
                        'membership_ends_at' => optional($user->membership_ends_at)->toDateTimeString(),
                    ],
                ], $exception);

                // Log failure using the project's standard logging system
                Log::warning('membership.expiry_reminder_email_failed', [
                    'user_id' => (string) $user->id,
                    'display_name' => (string) $user->display_name,
                    'email' => (string) $user->email,
                    'membership_ends_at' => optional($user->membership_ends_at)->toDateString(),
                    'sent_at' => now()->toDateTimeString(),
                    'status' => 'failed',
                    'error_message' => $exception->getMessage(),
                    'user_email' => (string) $user->email,
                    'membership_end_date' => optional($user->membership_ends_at)->toDateString(),
                    'user_status' => (string) $user->membership_status,
                    'reminder_type' => 'expired_membership',
                    'email_status' => $emailSent ? 'sent' : 'failed',
                    'notification_status' => 'failed',
                ]);
            }
        }

        $this->info("Execution summary: Sent: {$sentCount}, Failed: {$failedCount}, Skipped: {$skippedCount}");

        return self::SUCCESS;
    }
}

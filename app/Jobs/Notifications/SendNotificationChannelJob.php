<?php

namespace App\Jobs\Notifications;

use App\Models\Notifications\AppNotification;
use App\Models\Notifications\NotificationDeliveryLog;
use App\Services\Notifications\FcmService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendNotificationChannelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $notificationId, public string $channel)
    {
    }

    public function handle(FcmService $fcm): void
    {
        $notification = AppNotification::with('user')->find($this->notificationId);

        if (! $notification || ! $notification->user) {
            return;
        }

        if ($this->channel === 'push') {
            $result = $fcm->sendToUser($notification->user, $notification->title, $notification->body, $notification->dataPayload(), $notification);
            $notification->update([
                'status' => $result['success'] ? 'sent' : (($result['error'] ?? null) === 'No active push token' ? 'skipped' : 'failed'),
                'sent_at' => $result['success'] ? now() : $notification->sent_at,
                'failed_at' => $result['success'] ? null : now(),
                'failure_reason' => $result['success'] ? null : (string) ($result['error'] ?? 'Push delivery failed or no active FCM token accepted the message.'),
            ]);
            return;
        }

        if ($this->channel === 'email') {
            $log = NotificationDeliveryLog::create([
                'notification_id' => $notification->id,
                'user_id' => $notification->user_id,
                'channel' => 'email',
                'provider' => 'mail',
                'status' => 'pending',
                'request_payload' => ['to' => $notification->user->email, 'subject' => $notification->title],
                'attempted_at' => now(),
            ]);

            try {
                Mail::raw($notification->body, fn ($message) => $message->to($notification->user->email)->subject($notification->title));
                $log->update(['status' => 'sent', 'delivered_at' => now()]);
                $notification->update(['status' => 'sent', 'sent_at' => now()]);
            } catch (Throwable $throwable) {
                report($throwable);
                $log->update(['status' => 'failed', 'error_message' => $throwable->getMessage()]);
                $notification->update(['status' => 'failed', 'failed_at' => now(), 'failure_reason' => $throwable->getMessage()]);
            }
        }
    }
}

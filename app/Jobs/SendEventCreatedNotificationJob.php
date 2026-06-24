<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\User;
use App\Models\EventNotificationLog;
use App\Models\Notifications\AppNotification;
use App\Models\Notifications\NotificationDeliveryLog;
use App\Services\Notifications\FcmService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SendEventCreatedNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $eventId)
    {
    }

    public function handle(FcmService $fcmService): void
    {
        $event = Event::find($this->eventId);
        if (!$event) {
            Log::error("SendEventCreatedNotificationJob failed: Event not found.", ['event_id' => $this->eventId]);
            return;
        }

        Log::info("SendEventCreatedNotificationJob started for event: " . $event->id);

        // Create the event notification log record in 'processing' status
        $logRecord = EventNotificationLog::create([
            'event_id' => $event->id,
            'notification_type' => 'event_created',
            'status' => 'processing',
            'total_users' => 0,
            'in_app_notifications_created' => 0,
            'active_push_tokens' => 0,
            'push_sent_successfully' => 0,
            'push_failed' => 0,
            'failed_details' => [],
            'started_at' => now(),
        ]);

        try {
            $userQuery = User::query()
                ->when(Schema::hasColumn('users', 'deleted_at'), fn ($query) => $query->whereNull('users.deleted_at'))
                ->when(Schema::hasColumn('users', 'gdpr_deleted_at'), fn ($query) => $query->whereNull('users.gdpr_deleted_at'))
                ->when(Schema::hasColumn('users', 'status'), fn ($query) => $query->where(fn ($userQuery) => $userQuery->whereNull('users.status')->orWhereRaw("LOWER(users.status::text) NOT IN ('inactive', 'suspended', 'blocked', 'banned', 'deleted', 'rejected')")))
                ->when(Schema::hasColumn('users', 'membership_status'), fn ($query) => $query->where(fn ($userQuery) => $userQuery->whereNull('users.membership_status')->orWhere('users.membership_status', '!=', 'suspended')));

            $totalUsersTargeted = 0;
            $totalInAppCreated = 0;
            $totalPushTokensFound = 0;
            $totalPushSentSuccess = 0;
            $totalPushFailed = 0;
            $failedDetails = [];

            // Resolve absolute banner image URL
            $bannerUrl = $event->banner_url;
            if (is_string($bannerUrl) && trim($bannerUrl) !== '') {
                $bannerUrl = trim($bannerUrl);
                if (!str_starts_with($bannerUrl, 'http://') && !str_starts_with($bannerUrl, 'https://') && !str_starts_with($bannerUrl, '/')) {
                    $bannerUrl = url('/api/v1/files/' . $bannerUrl);
                }
            } else {
                $bannerUrl = null;
            }

            $notificationData = [
                'type' => 'event',
                'event_id' => (string) $event->id,
                'event_title' => (string) $event->title,
                'event_date' => $event->start_at ? $event->start_at->toDateString() : '',
                'event_banner' => $bannerUrl,
                'screen' => 'event_detail',
                'tap_destination' => 'event_detail',
                'reference_type' => 'event',
                'reference_id' => (string) $event->id,
            ];

            $title = 'New Event Created';
            $body = 'A new event has been added. Tap to view event details.';

            // Chunk users to prevent timeout and memory overflow
            $userQuery->chunkById(100, function ($users) use (
                $event,
                $title,
                $body,
                $notificationData,
                $fcmService,
                &$totalUsersTargeted,
                &$totalInAppCreated,
                &$totalPushTokensFound,
                &$totalPushSentSuccess,
                &$totalPushFailed,
                &$failedDetails
            ) {
                foreach ($users as $user) {
                    $totalUsersTargeted++;

                    try {
                        // Check duplicate in-app notification in app_notifications table
                        $exists = AppNotification::where('user_id', $user->id)
                            ->whereIn('type', ['event', 'event_created'])
                            ->where(function ($q) use ($event) {
                                $q->whereJsonContains('data->event_id', (string) $event->id)
                                  ->orWhere('reference_id', (string) $event->id);
                            })
                            ->exists();

                        if ($exists) {
                            continue;
                        }

                        // Create in-app AppNotification
                        $notification = AppNotification::create([
                            'user_id' => $user->id,
                            'type' => 'event',
                            'category' => 'event',
                            'title' => $title,
                            'body' => $body,
                            'channel' => 'push',
                            'priority' => 'high',
                            'reference_type' => 'event',
                            'reference_id' => $event->id,
                            'screen' => 'event_detail',
                            'data' => $notificationData,
                            'status' => 'pending',
                        ]);

                        $totalInAppCreated++;

                        // For legacy notifications support
                        try {
                            if (Schema::hasTable('notifications')) {
                                \App\Models\Notification::create([
                                    'user_id' => $user->id,
                                    'type' => 'activity_update',
                                    'payload' => $notificationData,
                                    'is_read' => false,
                                    'created_at' => now(),
                                    'read_at' => null,
                                    'title' => $title,
                                    'message' => $body,
                                    'source_type' => 'event',
                                    'source_id' => $event->id,
                                ]);
                            }
                        } catch (Throwable $e) {
                            Log::error("Failed to create legacy notification for user {$user->id}", ['error' => $e->getMessage()]);
                        }

                        // Create NotificationDeliveryLog for in_app
                        NotificationDeliveryLog::create([
                            'notification_id' => $notification->id,
                            'user_id' => $user->id,
                            'channel' => 'in_app',
                            'provider' => 'database',
                            'status' => 'sent',
                            'request_payload' => $notification->dataPayload(),
                            'response_payload' => ['notification_id' => (string) $notification->id],
                            'attempted_at' => now(),
                            'delivered_at' => now(),
                        ]);

                        // Send FCM push notifications
                        $tokens = $fcmService->activeTokensForUser($user->id);
                        if ($tokens->isNotEmpty()) {
                            $totalPushTokensFound += $tokens->count();

                            $userPushSuccess = false;
                            foreach ($tokens as $token) {
                                try {
                                    $result = $fcmService->sendToToken($token, $title, $body, $notification->dataPayload(), $notification);
                                    if ($result['success'] ?? false) {
                                        $totalPushSentSuccess++;
                                        $userPushSuccess = true;
                                    } else {
                                        $totalPushFailed++;
                                        $reason = $result['error'] ?? 'Unknown FCM error';
                                        $failedDetails[] = [
                                            'user_id' => $user->id,
                                            'name' => trim((string) ($user->display_name ?? '')) ?: trim(((string) ($user->first_name ?? '')) . ' ' . ((string) ($user->last_name ?? ''))),
                                            'error' => $reason
                                        ];
                                    }
                                } catch (Throwable $e) {
                                    $totalPushFailed++;
                                    $failedDetails[] = [
                                        'user_id' => $user->id,
                                        'name' => trim((string) ($user->display_name ?? '')) ?: trim(((string) ($user->first_name ?? '')) . ' ' . ((string) ($user->last_name ?? ''))),
                                        'error' => $e->getMessage()
                                    ];
                                }
                            }

                            // Update notification status based on token push results
                            $notification->update([
                                'status' => $userPushSuccess ? 'sent' : 'failed',
                                'sent_at' => $userPushSuccess ? now() : null,
                                'failed_at' => $userPushSuccess ? null : now(),
                                'failure_reason' => $userPushSuccess ? null : 'Push notification failed to deliver to FCM tokens.',
                            ]);
                        } else {
                            // Mark push channel delivery log as skipped
                            NotificationDeliveryLog::create([
                                'notification_id' => $notification->id,
                                'user_id' => $user->id,
                                'channel' => 'push',
                                'provider' => 'firebase',
                                'status' => 'skipped',
                                'request_payload' => ['title' => $title, 'body' => $body, 'data' => $notificationData],
                                'error_message' => 'No active push token',
                                'attempted_at' => now(),
                            ]);

                            $notification->update([
                                'status' => 'skipped',
                                'failure_reason' => 'No active push token',
                            ]);
                        }

                    } catch (Throwable $e) {
                        Log::error("Failed to process event notification for user {$user->id}", [
                            'event_id' => $event->id,
                            'user_id' => $user->id,
                            'error' => $e->getMessage(),
                        ]);
                        $failedDetails[] = [
                            'user_id' => $user->id,
                            'name' => trim((string) ($user->display_name ?? '')) ?: trim(((string) ($user->first_name ?? '')) . ' ' . ((string) ($user->last_name ?? ''))),
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            });

            // Update the log record in 'completed' status
            $logRecord->update([
                'status' => 'completed',
                'total_users' => $totalUsersTargeted,
                'in_app_notifications_created' => $totalInAppCreated,
                'active_push_tokens' => $totalPushTokensFound,
                'push_sent_successfully' => $totalPushSentSuccess,
                'push_failed' => $totalPushFailed,
                'failed_details' => $failedDetails,
                'completed_at' => now(),
            ]);

            $mainFailureReason = null;
            if (!empty($failedDetails)) {
                $errors = array_column($failedDetails, 'error');
                $counts = array_count_values($errors);
                arsort($counts);
                $mainFailureReason = array_key_first($counts);
            }

            // Summary log
            Log::info("SendEventCreatedNotificationJob finished for event: " . $event->id, [
                'event_id' => (string) $event->id,
                'total_users_targeted' => $totalUsersTargeted,
                'total_in_app_notifications_created' => $totalInAppCreated,
                'total_active_push_tokens_found' => $totalPushTokensFound,
                'total_push_sent_success' => $totalPushSentSuccess,
                'total_push_failed' => $totalPushFailed,
                'failure_reason' => $mainFailureReason,
            ]);

        } catch (Throwable $throwable) {
            Log::error("SendEventCreatedNotificationJob failed catastrophically.", [
                'event_id' => $event->id,
                'error' => $throwable->getMessage()
            ]);

            $logRecord->update([
                'status' => 'failed',
                'failed_details' => [['error' => $throwable->getMessage()]],
                'completed_at' => now(),
            ]);

            throw $throwable;
        }
    }
}

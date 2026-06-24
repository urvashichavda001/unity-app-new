<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Event;
use App\Models\EventNotificationLog;
use App\Models\Notifications\AppNotification;
use App\Models\Notifications\NotificationDeliveryLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminEventNotificationStatusController extends BaseApiController
{
    public function show(string $eventId): JsonResponse
    {
        $event = Event::find($eventId);
        if (!$event) {
            return $this->error('Event not found', 404);
        }

        $log = EventNotificationLog::where('event_id', $event->id)
            ->latest('created_at')
            ->first();

        if ($log) {
            $data = [
                'event_id' => (string) $event->id,
                'event_title' => (string) $event->title,
                'notification_type' => $log->notification_type,
                'status' => $log->status,
                'total_users' => (int) $log->total_users,
                'in_app_notifications_created' => (int) $log->in_app_notifications_created,
                'active_push_tokens' => (int) $log->active_push_tokens,
                'push_sent_successfully' => (int) $log->push_sent_successfully,
                'push_failed' => (int) $log->push_failed,
                'pending' => $log->status === 'processing' 
                    ? max(0, (int) $log->total_users - ((int) $log->push_sent_successfully + (int) $log->push_failed)) 
                    : 0,
                'last_sent_at' => $log->completed_at 
                    ? $log->completed_at->format('Y-m-d H:i:s') 
                    : ($log->started_at ? $log->started_at->format('Y-m-d H:i:s') : null),
                'failed_tokens' => $log->failed_details ?? [],
            ];
        } else {
            // Fallback calculation from database tables if no direct log exists
            $notificationQuery = fn ($q) => $q
                ->whereIn('type', ['event', 'event_created'])
                ->where(function ($query) use ($event) {
                    $query->whereJsonContains('data->event_id', (string) $event->id)
                        ->orWhere('reference_id', (string) $event->id);
                });

            $inAppCreated = AppNotification::where($notificationQuery)->count();

            $activePushTokens = NotificationDeliveryLog::whereHas('notification', $notificationQuery)
                ->where('channel', 'push')
                ->count();

            $pushSentSuccess = NotificationDeliveryLog::whereHas('notification', $notificationQuery)
                ->where('channel', 'push')
                ->whereIn('status', ['sent', 'delivered'])
                ->count();

            $pushFailed = NotificationDeliveryLog::whereHas('notification', $notificationQuery)
                ->where('channel', 'push')
                ->where('status', 'failed')
                ->count();

            $failedDeliveryLogs = NotificationDeliveryLog::with('user')
                ->whereHas('notification', $notificationQuery)
                ->where('channel', 'push')
                ->where('status', 'failed')
                ->get();

            $failedTokens = $failedDeliveryLogs->map(fn ($item) => [
                'user_id' => $item->user_id,
                'name' => $item->user 
                    ? trim((string) ($item->user->display_name ?? '')) ?: trim(((string) ($item->user->first_name ?? '')) . ' ' . ((string) ($item->user->last_name ?? '')))
                    : 'Unknown',
                'error' => $item->error_message ?: 'Push notification failed',
            ])->values()->all();

            $lastSentAtRaw = AppNotification::where($notificationQuery)
                ->latest('created_at')
                ->value('created_at');

            $lastSentAt = null;
            if ($lastSentAtRaw) {
                $lastSentAt = \Illuminate\Support\Carbon::parse($lastSentAtRaw)->format('Y-m-d H:i:s');
            }

            $status = $inAppCreated > 0 ? 'completed' : 'pending';

            $data = [
                'event_id' => (string) $event->id,
                'event_title' => (string) $event->title,
                'notification_type' => 'event_created',
                'status' => $status,
                'total_users' => $inAppCreated,
                'in_app_notifications_created' => $inAppCreated,
                'active_push_tokens' => $activePushTokens,
                'push_sent_successfully' => $pushSentSuccess,
                'push_failed' => $pushFailed,
                'pending' => 0,
                'last_sent_at' => $lastSentAt,
                'failed_tokens' => $failedTokens,
            ];
        }

        return $this->success($data, 'Event notification status fetched successfully.');
    }
}

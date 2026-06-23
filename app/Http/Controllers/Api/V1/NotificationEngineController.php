<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Notifications\AppNotification;
use App\Models\Notifications\NotificationDeliveryLog;
use App\Models\Notifications\NotificationPreference;
use App\Models\Post;
use App\Models\User;
use App\Models\UserPushToken;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class NotificationEngineController extends BaseApiController
{
    public function pushToken(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'platform' => ['nullable', 'in:android,ios,web'],
            'device_id' => ['nullable', 'string'],
            'app_version' => ['nullable', 'string'],
        ]);

        $attributes = [
            'user_id' => $request->user()->id,
            'platform' => $validated['platform'] ?? null,
            'device_id' => $validated['device_id'] ?? null,
            'app_version' => $validated['app_version'] ?? null,
            'is_active' => true,
            'last_used_at' => now(),
        ];

        if (Schema::hasColumn('user_push_tokens', 'last_seen_at')) {
            $attributes['last_seen_at'] = now();
        }

        $pushToken = UserPushToken::where('token', $validated['token'])->first();

        if (! $pushToken && ! empty($validated['device_id'])) {
            $pushToken = UserPushToken::where('user_id', $request->user()->id)
                ->where('device_id', $validated['device_id'])
                ->first();
        }

        if ($pushToken) {
            $pushToken->update(array_merge($attributes, ['token' => $validated['token']]));
        } else {
            $pushToken = UserPushToken::create(array_merge($attributes, ['token' => $validated['token']]));
        }

        return $this->success([
            'id' => (string) $pushToken->id,
            'user_id' => (string) $pushToken->user_id,
            'platform' => $pushToken->platform,
            'is_active' => (bool) $pushToken->is_active,
            'last_used_at' => $pushToken->last_used_at,
            'flutter_note' => 'After login, request notification permission, get the FirebaseMessaging FCM token, call this endpoint with the bearer token, and call it again from FirebaseMessaging.onTokenRefresh. Ensure android/app/google-services.json uses project_id peers-global-app.',
        ], 'Push token saved successfully.');
    }

    public function index(Request $request)
    {
        $query = AppNotification::where('user_id', $request->user()->id);

        if (Schema::hasColumn('app_notifications', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);
        $paginator = $query->latest()->paginate($perPage);

        $unreadQuery = AppNotification::where('user_id', $request->user()->id)->whereNull('read_at');
        if (Schema::hasColumn('app_notifications', 'deleted_at')) {
            $unreadQuery->whereNull('deleted_at');
        }

        return $this->success([
            'notifications' => collect($paginator->items())->map(fn (AppNotification $notification): array => [
                'id' => (string) $notification->id,
                'type' => $notification->type,
                'category' => $notification->category,
                'title' => $notification->title,
                'body' => $notification->body,
                'message' => $notification->body,
                'channel' => $notification->channel,
                'priority' => $notification->priority,
                'screen' => $notification->screen,
                'tap_destination' => $notification->data['tap_destination'] ?? $notification->screen,
                'reference_type' => $notification->reference_type,
                'reference_id' => $notification->reference_id,
                'data' => $notification->data ?? [],
                'status' => $notification->status,
                'is_read' => $notification->read_at !== null,
                'sent_at' => $notification->sent_at,
                'read_at' => $notification->read_at,
                'clicked_at' => $notification->clicked_at,
                'created_at' => $notification->created_at,
            ])->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'unread_count' => $unreadQuery->count(),
        ], 'Notifications fetched successfully.');
    }

    public function read(Request $request, string $id, NotificationService $service)
    {
        return $this->success($service->markAsRead($request->user(), $id), 'Notification marked as read.');
    }

    public function readAll(Request $request, NotificationService $service)
    {
        $updated = $service->markAllAsRead($request->user());
        return $this->success(['updated_count' => $updated, 'updated' => $updated], $updated > 0 ? 'All notifications marked as read.' : 'No unread notifications found.');
    }

    public function click(Request $request, string $id, NotificationService $service)
    {
        return $this->success($service->recordClick($request->user(), $id), 'Notification click recorded.');
    }


    public function check(Request $request)
    {
        try {
        $validated = $request->validate([
            'reference_type' => ['required', 'string', 'max:100'],
            'reference_id' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'uuid'],
        ]);

        $notifications = AppNotification::with('user')
            ->where(function ($query) use ($validated): void {
                $query->where(function ($referenceQuery) use ($validated): void {
                    $referenceQuery->where('reference_type', $validated['reference_type'])
                        ->where('reference_id', $validated['reference_id']);
                })
                    ->orWhere('data->post_id', $validated['reference_id'])
                    ->orWhere('data->reference_id', $validated['reference_id']);
            })
            ->when($validated['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($validated['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            ->latest()
            ->limit(100)
            ->get();

        $notificationIds = $notifications->pluck('id');

        $deliveryLogs = NotificationDeliveryLog::with(['notification', 'user'])
            ->where(function ($query) use ($notificationIds, $validated): void {
                if ($notificationIds->isNotEmpty()) {
                    $query->whereIn('notification_id', $notificationIds);
                }

                $query->orWhereHas('notification', function ($notificationQuery) use ($validated): void {
                    $notificationQuery->where(function ($referenceQuery) use ($validated): void {
                        $referenceQuery->where('reference_type', $validated['reference_type'])
                            ->where('reference_id', $validated['reference_id']);
                    })
                        ->orWhere('data->post_id', $validated['reference_id'])
                        ->orWhere('data->reference_id', $validated['reference_id']);
                });
            })
            ->when($validated['type'] ?? null, fn ($query, $type) => $query->whereHas('notification', fn ($notificationQuery) => $notificationQuery->where('type', $type)))
            ->when($validated['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            ->latest()
            ->limit(200)
            ->get();

        $debug = $this->notificationCheckDebug($validated, $notifications);

        return $this->success([
            'reference_type' => $validated['reference_type'],
            'reference_id' => $validated['reference_id'],
            'total_notifications' => $notifications->count(),
            'total_delivery_logs' => $deliveryLogs->count(),
            'post_found' => $debug['post_found'],
            'post_status' => $debug['post_status'],
            'moderation_status' => $debug['moderation_status'],
            'post_user_id' => $debug['post_user_id'],
            'is_post_visible' => $debug['is_post_visible'],
            'expected_notification_trigger' => $debug['expected_notification_trigger'],
            'reason' => $debug['reason'],
            'notifications' => $notifications->map(fn (AppNotification $notification): array => [
                'id' => (string) $notification->id,
                'recipient_id' => (string) $notification->user_id,
                'recipient_name' => $this->debugUserName($notification->user),
                'actor_id' => (string) data_get($notification->data, 'actor_id', ''),
                'type' => $notification->type,
                'title' => $notification->title,
                'body' => $notification->body,
                'read_at' => $notification->read_at,
                'clicked_at' => $notification->clicked_at,
                'created_at' => $notification->created_at,
            ])->values(),
            'delivery_logs' => $deliveryLogs->map(fn (NotificationDeliveryLog $log): array => [
                'notification_id' => (string) $log->notification_id,
                'recipient_id' => (string) $log->user_id,
                'channel' => $log->channel,
                'provider' => $log->provider,
                'status' => $log->status,
                'error_message' => $log->error_message,
                'created_at' => $log->created_at,
            ])->values(),
        ], 'Notification check fetched successfully');
            } catch (\Throwable $throwable) {
            report($throwable);

            return response()->json([
                'success' => false,
                'message' => 'Unable to check notifications',
                'data' => null,
                'meta' => [
                    'error' => config('app.debug') ? $throwable->getMessage() : 'Unable to check notifications right now.',
                ],
            ], 500);
        }
    }

    public function checkPost(Request $request, string $postId)
    {
        $request->merge(['reference_type' => 'post', 'reference_id' => $postId]);

        return $this->check($request);
    }

    public function checkUser(string $userId)
    {
        $notifications = AppNotification::with('user')->where('user_id', $userId)->latest()->limit(100)->get();
        $deliveryLogs = NotificationDeliveryLog::where('user_id', $userId)->latest()->limit(200)->get();

        return $this->success([
            'user_id' => $userId,
            'total_notifications' => $notifications->count(),
            'total_delivery_logs' => $deliveryLogs->count(),
            'post_found' => $debug['post_found'],
            'post_status' => $debug['post_status'],
            'moderation_status' => $debug['moderation_status'],
            'post_user_id' => $debug['post_user_id'],
            'is_post_visible' => $debug['is_post_visible'],
            'expected_notification_trigger' => $debug['expected_notification_trigger'],
            'reason' => $debug['reason'],
            'notifications' => $notifications->map(fn (AppNotification $notification): array => [
                'id' => (string) $notification->id,
                'recipient_id' => (string) $notification->user_id,
                'recipient_name' => $this->debugUserName($notification->user),
                'actor_id' => (string) data_get($notification->data, 'actor_id', ''),
                'type' => $notification->type,
                'title' => $notification->title,
                'body' => $notification->body,
                'reference_type' => $notification->reference_type,
                'reference_id' => $notification->reference_id,
                'read_at' => $notification->read_at,
                'clicked_at' => $notification->clicked_at,
                'created_at' => $notification->created_at,
            ])->values(),
            'delivery_logs' => $deliveryLogs->map(fn (NotificationDeliveryLog $log): array => [
                'notification_id' => (string) $log->notification_id,
                'recipient_id' => (string) $log->user_id,
                'channel' => $log->channel,
                'provider' => $log->provider,
                'status' => $log->status,
                'error_message' => $log->error_message,
                'created_at' => $log->created_at,
            ])->values(),
        ], 'Notification check fetched successfully');
    }


    public function sendPostTest(Request $request, string $postId, NotificationService $service)
    {
        $validated = $request->validate([
            'force' => ['nullable', 'boolean'],
        ]);

        $post = Post::with('user')->findOrFail($postId);
        $summary = $service->sendPostPublishedNotification($post, (bool) ($validated['force'] ?? false));

        return $this->success($summary, 'Post notification test send completed.');
    }

    public function preferences(Request $request)
    {
        return $this->success(NotificationPreference::firstOrCreate(['user_id' => $request->user()->id]), 'Notification preferences fetched successfully.');
    }

    public function updatePreferences(Request $request)
    {
        $validated = $request->validate([
            'push_enabled' => ['boolean'],
            'email_enabled' => ['boolean'],
            'chat_enabled' => ['boolean'],
            'event_enabled' => ['boolean'],
            'circle_enabled' => ['boolean'],
            'business_enabled' => ['boolean'],
            'campaign_enabled' => ['boolean'],
            'quiet_hours_start' => ['nullable', 'date_format:H:i'],
            'quiet_hours_end' => ['nullable', 'date_format:H:i'],
        ]);

        $preference = NotificationPreference::firstOrCreate(['user_id' => $request->user()->id]);
        $preference->update($validated);

        return $this->success($preference, 'Notification preferences updated successfully.');
    }

    private function notificationCheckDebug(array $validated, $notifications): array
    {
        $post = $validated['reference_type'] === 'post'
            ? Post::query()->withTrashed()->find($validated['reference_id'])
            : null;

        if (! $post) {
            return [
                'post_found' => false,
                'post_status' => null,
                'moderation_status' => null,
                'post_user_id' => null,
                'is_post_visible' => false,
                'expected_notification_trigger' => 'none',
                'reason' => 'Post not found',
            ];
        }

        $status = strtolower((string) ($post->moderation_status ?? ''));
        $isVisible = in_array($status, ['approved', 'published', 'visible'], true);
        $existing = $notifications->isNotEmpty();
        $recipientCount = app(NotificationService::class)->postNotificationRecipients($post)->count();

        if ($existing) {
            $reason = 'Notification already exists';
        } elseif (! $isVisible) {
            $reason = 'Post is pending, notification will send after approval';
        } elseif ($recipientCount === 0) {
            $reason = 'No eligible recipients found';
        } else {
            $reason = 'Notification trigger missing';
        }

        return [
            'post_found' => true,
            'post_status' => $status,
            'moderation_status' => $post->moderation_status,
            'post_user_id' => (string) $post->user_id,
            'is_post_visible' => $isVisible,
            'expected_notification_trigger' => $isVisible ? 'approval' : 'approval',
            'reason' => $reason,
        ];
    }

    private function debugUserName(?User $user): string
    {
        if (! $user) {
            return '—';
        }

        return trim((string) ($user->display_name ?? ''))
            ?: trim(((string) ($user->first_name ?? '')) . ' ' . ((string) ($user->last_name ?? '')))
            ?: (string) ($user->name ?? $user->email ?? $user->id);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Notifications\AppNotification;
use App\Models\Notifications\NotificationPreference;
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
}

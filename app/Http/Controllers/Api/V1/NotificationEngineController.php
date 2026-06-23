<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\Notifications\AppNotification;
use App\Models\Notifications\NotificationDeliveryLog;
use App\Models\Notifications\NotificationPreference;
use App\Models\Post;
use App\Models\User;
use App\Models\UserPushToken;
use App\Services\Notifications\FcmService;
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
        $validated = $request->validate([
            'reference_type' => ['required', 'string', 'max:100'],
            'reference_id' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string', 'max:50'],
            'channel' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'show_all' => ['nullable', 'boolean'],
        ]);

        $showAll = $request->boolean('show_all');
        $perPage = min(max((int) ($validated['per_page'] ?? 100), 1), 500);
        $page = max((int) ($validated['page'] ?? 1), 1);
        $notificationQuery = $this->referenceNotificationQuery($validated)
            ->when($validated['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($validated['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId));

        $totalNotifications = (clone $notificationQuery)->count();
        $totalDeliveryLogs = NotificationDeliveryLog::query()
            ->whereHas('notification', fn ($query) => $this->applyReferenceFilters($query, $validated))
            ->when($validated['type'] ?? null, fn ($query, $type) => $query->whereHas('notification', fn ($notificationQuery) => $notificationQuery->where('type', $type)))
            ->when($validated['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['channel'] ?? null, fn ($query, $channel) => $query->where('channel', $channel))
            ->count();

        $notificationDisplayQuery = (clone $notificationQuery)->with('user')->latest();
        $notifications = $showAll
            ? $notificationDisplayQuery->get()
            : $notificationDisplayQuery->forPage($page, $perPage)->get();
        $deliveryLogs = NotificationDeliveryLog::with(['notification', 'user'])
            ->whereHas('notification', fn ($query) => $this->applyReferenceFilters($query, $validated))
            ->when($validated['type'] ?? null, fn ($query, $type) => $query->whereHas('notification', fn ($notificationQuery) => $notificationQuery->where('type', $type)))
            ->when($validated['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['channel'] ?? null, fn ($query, $channel) => $query->where('channel', $channel))
            ->latest();
        $deliveryLogs = $showAll
            ? $deliveryLogs->get()
            : $deliveryLogs->forPage($page, $perPage)->get();

        $debug = $this->notificationCheckDebug($validated, $totalNotifications);
        $missingDebug = $this->missingRecipientsDebug($validated);

        return $this->success([
            'reference_type' => $validated['reference_type'],
            'reference_id' => $validated['reference_id'],
            'post_found' => $debug['post_found'],
            'post_user_id' => $debug['post_user_id'],
            'circle_id' => $debug['circle_id'],
            'eligible_recipient_count' => $debug['eligible_recipient_count'],
            'excluded_actor_id' => $debug['excluded_actor_id'],
            'expected_recipient_count' => $debug['expected_recipient_count'],
            'total_notifications_in_db' => $totalNotifications,
            'total_delivery_logs_in_db' => $totalDeliveryLogs,
            'missing_recipient_count' => $missingDebug['missing_recipient_count'],
            'missing_recipients' => $missingDebug['missing_recipients'],
            'displayed_notifications_count' => $notifications->count(),
            'displayed_delivery_logs_count' => $deliveryLogs->count(),
            'is_paginated' => ! $showAll && ($totalNotifications > $perPage || $totalDeliveryLogs > $perPage),
            'per_page' => $showAll ? null : $perPage,
            'current_page' => $showAll ? null : $page,
            'last_page' => $showAll ? 1 : (int) max(ceil(max($totalNotifications, $totalDeliveryLogs) / $perPage), 1),
            'show_all' => $showAll,
            'trigger_connected' => $debug['trigger_connected'],
            'reason' => $debug['reason'],
            'notifications' => $notifications->map(fn (AppNotification $notification): array => $this->notificationDebugPayload($notification))->values(),
            'delivery_logs' => $deliveryLogs->map(fn (NotificationDeliveryLog $log): array => $this->deliveryLogDebugPayload($log))->values(),
        ], 'Notification check fetched successfully');
    }

    public function checkPost(Request $request, string $postId)
    {
        $request->merge(['reference_type' => 'post', 'reference_id' => $postId]);

        return $this->check($request);
    }

    public function checkUserForReference(Request $request)
    {
        $validated = $request->validate([
            'reference_type' => ['required', 'string', 'max:100'],
            'reference_id' => ['required', 'string', 'max:255'],
            'user_id' => ['required', 'uuid'],
        ]);

        $user = User::findOrFail($validated['user_id']);
        $notification = $this->referenceNotificationQuery($validated)
            ->where('user_id', $user->id)
            ->latest()
            ->first();
        $logs = $notification
            ? NotificationDeliveryLog::where('notification_id', $notification->id)->latest()->get()
            : collect();
        $pushLogs = $logs->where('channel', 'push')->values();
        $pushLog = $pushLogs->first();
        $allPushTokensCount = UserPushToken::where('user_id', $user->id)->whereNotNull('token')->where('token', '!=', '')->count();
        $activeTokens = app(FcmService::class)->activeTokensForUser($user->id);
        $activeTokenCount = $activeTokens->count();
        $recipientDebug = $this->userRecipientDebug($validated, $user);
        $excludedReason = $recipientDebug['excluded_reason'];
        $fix = $recipientDebug['fix'];
        if ($recipientDebug['is_expected_recipient'] && ! $notification && $this->userHasAdminRole($user)) {
            $excludedReason = 'Previously excluded by admin role filter';
            $fix = 'Remove admin role exclusion for active Unity peers';
        }

        $reason = 'Notification and delivery state fetched';
        if (! $notification) {
            $reason = 'No in-app notification found for this user and reference.';
        } elseif ($activeTokenCount > 0 && $pushLogs->contains(fn (NotificationDeliveryLog $log): bool => $log->status === 'skipped')) {
            $reason = 'Bug: active token exists but notification service did not select it';
        } elseif ($activeTokenCount > 0 && $pushLogs->isEmpty()) {
            $reason = 'Active token exists but push send was not attempted. Fix token selection or push dispatch logic.';
        } elseif ($pushLogs->contains(fn (NotificationDeliveryLog $log): bool => $log->status === 'sent')) {
            $reason = 'Push sent successfully';
        } elseif ($pushLogs->contains(fn (NotificationDeliveryLog $log): bool => $log->status === 'failed')) {
            $reason = $pushLogs->firstWhere('status', 'failed')?->error_message ?: 'Push failed';
        } elseif ($pushLogs->contains(fn (NotificationDeliveryLog $log): bool => $log->status === 'skipped')) {
            $reason = $pushLogs->firstWhere('status', 'skipped')?->error_message ?: 'Push skipped';
        }

        return $this->success([
            'post_id' => $validated['reference_type'] === 'post' ? $validated['reference_id'] : null,
            'reference_type' => $validated['reference_type'],
            'reference_id' => $validated['reference_id'],
            'user_id' => (string) $user->id,
            'user_name' => $this->debugUserName($user),
            'is_expected_recipient' => $recipientDebug['is_expected_recipient'],
            'has_active_push_token' => $activeTokenCount > 0,
            'excluded_reason' => $excludedReason,
            'fix' => $fix,
            'user_push_tokens_count' => $allPushTokensCount,
            'user_has_active_token' => $activeTokenCount > 0,
            'active_push_tokens_count' => $activeTokenCount,
            'active_token_count' => $activeTokenCount,
            'active_tokens' => $activeTokens->map(fn (UserPushToken $token): array => $this->pushTokenDebugPayload($token))->values(),
            'notification_found' => (bool) $notification,
            'notification_id' => $notification ? (string) $notification->id : null,
            'in_app_status' => $logs->firstWhere('channel', 'in_app')?->status,
            'push_status' => $pushLog?->status,
            'push_error' => $pushLog?->error_message,
            'push_logs' => $pushLogs->map(fn (NotificationDeliveryLog $log): array => $this->deliveryLogDebugPayload($log))->values(),
            'token_used' => $pushLogs->isNotEmpty(),
            'latest_push_response' => $pushLog?->response_payload,
            'reason' => $reason,
        ], 'User notification check fetched successfully');
    }

    public function postSummary(string $postId, NotificationService $service)
    {
        $post = Post::query()->withTrashed()->findOrFail($postId);
        $eligibleIds = $service->postNotificationRecipients($post)->pluck('id')->map(fn ($id) => (string) $id)->values();
        $notifications = AppNotification::query()
            ->where('type', 'new_post')
            ->where('reference_type', 'post')
            ->where('reference_id', (string) $post->id)
            ->get();
        $notificationIds = $notifications->pluck('id');
        $notifiedIds = $notifications->pluck('user_id')->map(fn ($id) => (string) $id)->unique()->values();
        $logs = NotificationDeliveryLog::query()->whereIn('notification_id', $notificationIds)->get();
        $missingIds = $eligibleIds->diff($notifiedIds)->values();
        $pushLogs = $logs->where('channel', 'push');
        $failedUserIds = $pushLogs->where('status', 'failed')->pluck('user_id')->map(fn ($id) => (string) $id)->unique()->values();
        $activeTokenUserIds = $eligibleIds
            ->filter(fn (string $userId): bool => app(FcmService::class)->activeTokensForUser($userId)->isNotEmpty())
            ->values();
        $skippedUserIds = $pushLogs->where('status', 'skipped')->pluck('user_id')->map(fn ($id) => (string) $id)->unique()->values();
        $activeTokenButSkippedIds = $activeTokenUserIds->intersect($skippedUserIds)->values();
        $duplicatePushLogCount = $pushLogs
            ->groupBy(fn (NotificationDeliveryLog $log): string => implode('|', [
                (string) $log->notification_id,
                (string) $log->user_id,
                (string) ($log->provider ?: 'firebase'),
                (string) data_get($log->request_payload, 'token_id', data_get($log->request_payload, 'token_preview', 'no-token')),
            ]))
            ->sum(fn ($group): int => max($group->count() - 1, 0));
        $firebaseAuthErrorLogs = $pushLogs->filter(fn (NotificationDeliveryLog $log): bool => str_contains(strtolower((string) $log->error_message), 'firebase authentication')
            || strtoupper((string) data_get($log->response_payload, 'firebase_status')) === 'UNAUTHENTICATED'
            || strtoupper((string) data_get($log->response_payload, 'firebase_error_code')) === 'THIRD_PARTY_AUTH_ERROR');

        return $this->success([
            'post_id' => (string) $post->id,
            'eligible_recipient_count' => $eligibleIds->count(),
            'expected_recipient_count' => $eligibleIds->count(),
            'total_notifications_in_db' => $notifications->count(),
            'notification_created_count' => $notifications->count(),
            'missing_notification_count' => $missingIds->count(),
            'in_app_sent_count' => $logs->where('channel', 'in_app')->where('status', 'sent')->count(),
            'active_token_users_count' => $activeTokenUserIds->count(),
            'push_sent_count' => $pushLogs->where('status', 'sent')->count(),
            'push_failed_count' => $pushLogs->where('status', 'failed')->count(),
            'push_skipped_count' => $pushLogs->where('status', 'skipped')->count(),
            'users_with_active_token_but_skipped_count' => $activeTokenButSkippedIds->count(),
            'duplicate_push_log_count' => $duplicatePushLogCount,
            'firebase_auth_error_count' => $firebaseAuthErrorLogs->count(),
            'no_token_users_count' => $eligibleIds->diff($activeTokenUserIds)->count(),
            'sample_missing_users' => $this->usersPreview($missingIds),
            'missing_users' => $this->usersPreview($missingIds),
            'sample_active_token_but_skipped_users' => $this->usersPreview($activeTokenButSkippedIds),
            'sample_failed_users' => $this->usersPreview($failedUserIds),
            'failed_users' => $this->usersPreview($failedUserIds),
        ], 'Post notification summary fetched successfully');
    }

    public function checkMissing(Request $request)
    {
        $validated = $request->validate([
            'reference_type' => ['required', 'string', 'max:100'],
            'reference_id' => ['required', 'string', 'max:255'],
        ]);

        $debug = $this->missingRecipientsDebug($validated, false);

        return $this->success($debug, 'Missing notification recipients fetched successfully');
    }

    public function checkUser(string $userId)
    {
        $notifications = AppNotification::with('user')->where('user_id', $userId)->latest()->limit(100)->get();
        $deliveryLogs = NotificationDeliveryLog::where('user_id', $userId)->latest()->limit(100)->get();

        return $this->success([
            'user_id' => $userId,
            'displayed_notifications_count' => $notifications->count(),
            'displayed_delivery_logs_count' => $deliveryLogs->count(),
            'is_paginated' => true,
            'per_page' => 100,
            'notifications' => $notifications->map(fn (AppNotification $notification): array => $this->notificationDebugPayload($notification))->values(),
            'delivery_logs' => $deliveryLogs->map(fn (NotificationDeliveryLog $log): array => $this->deliveryLogDebugPayload($log))->values(),
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

    private function notificationCheckDebug(array $validated, int $totalNotifications): array
    {
        $post = $validated['reference_type'] === 'post'
            ? Post::query()->withTrashed()->find($validated['reference_id'])
            : null;

        if (! $post) {
            return [
                'post_found' => false,
                'post_user_id' => null,
                'circle_id' => null,
                'eligible_recipient_count' => 0,
                'expected_recipient_count' => 0,
                'excluded_actor_id' => null,
                'trigger_connected' => false,
                'reason' => 'Post not found',
            ];
        }

        $recipientCount = app(NotificationService::class)->postNotificationRecipientQuery($post)->count();
        $reason = $totalNotifications > 0
            ? 'Notifications found for this post'
            : 'No notifications found. Post notification trigger may not be connected or no eligible recipients found.';

        return [
            'post_found' => true,
            'post_user_id' => (string) $post->user_id,
            'circle_id' => $post->circle_id ? (string) $post->circle_id : null,
            'eligible_recipient_count' => $recipientCount,
            'expected_recipient_count' => $recipientCount,
            'excluded_actor_id' => (string) $post->user_id,
            'trigger_connected' => true,
            'reason' => $reason,
        ];
    }

    private function referenceNotificationQuery(array $validated)
    {
        return AppNotification::query()->where(fn ($query) => $this->applyReferenceFilters($query, $validated));
    }

    private function applyReferenceFilters($query, array $validated): void
    {
        $query->where(function ($referenceQuery) use ($validated): void {
            $referenceQuery->where('reference_type', $validated['reference_type'])
                ->where('reference_id', $validated['reference_id']);
        })
            ->orWhere('data->post_id', $validated['reference_id'])
            ->orWhere('data->reference_id', $validated['reference_id']);
    }

    private function missingRecipientsDebug(array $validated, bool $limit = true): array
    {
        if (($validated['reference_type'] ?? null) !== 'post') {
            return [
                'post_id' => null,
                'expected_recipient_count' => 0,
                'notification_created_count' => 0,
                'missing_recipient_count' => 0,
                'missing_recipients' => [],
            ];
        }

        $post = Post::query()->withTrashed()->find($validated['reference_id']);
        if (! $post) {
            return [
                'post_id' => $validated['reference_id'],
                'expected_recipient_count' => 0,
                'notification_created_count' => 0,
                'missing_recipient_count' => 0,
                'missing_recipients' => [],
            ];
        }

        $expectedIds = app(NotificationService::class)
            ->postNotificationRecipientQuery($post)
            ->pluck('users.id')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();
        $notifiedIds = AppNotification::query()
            ->where('type', 'new_post')
            ->where('reference_type', 'post')
            ->where('reference_id', (string) $post->id)
            ->pluck('user_id')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();
        $missingIds = $expectedIds->diff($notifiedIds)->values();

        return [
            'post_id' => (string) $post->id,
            'expected_recipient_count' => $expectedIds->count(),
            'notification_created_count' => $notifiedIds->count(),
            'missing_recipient_count' => $missingIds->count(),
            'missing_recipients' => $this->missingRecipientPayloads($missingIds, $post, $limit ? 50 : null),
        ];
    }

    private function missingRecipientPayloads($userIds, Post $post, ?int $limit = 50): array
    {
        $ids = collect($userIds);
        if ($limit !== null) {
            $ids = $ids->take($limit);
        }

        return User::query()
            ->with('roles')
            ->whereIn('id', $ids)
            ->get()
            ->map(fn (User $user): array => $this->missingRecipientPayload($user, $post))
            ->values()
            ->all();
    }

    private function missingRecipientPayload(User $user, Post $post): array
    {
        $hasAdminRole = $this->userHasAdminRole($user);

        return [
            'user_id' => (string) $user->id,
            'name' => $this->debugUserName($user),
            'email' => $user->email,
            'status' => $user->getAttribute('status'),
            'membership_status' => $user->getAttribute('membership_status'),
            'membership_label' => $this->membershipLabel($user),
            'circle_id' => $post->circle_id ? (string) $post->circle_id : null,
            'has_admin_role' => $hasAdminRole,
            'has_active_push_token' => app(FcmService::class)->activeTokensForUser((string) $user->id)->isNotEmpty(),
            'excluded_reason' => $hasAdminRole ? 'Previously excluded by admin role filter' : 'Expected recipient missing notification row',
        ];
    }

    private function userRecipientDebug(array $validated, User $user): array
    {
        if (($validated['reference_type'] ?? null) !== 'post') {
            return ['is_expected_recipient' => false, 'excluded_reason' => 'Reference is not a post', 'fix' => null];
        }

        $post = Post::query()->withTrashed()->find($validated['reference_id']);
        if (! $post) {
            return ['is_expected_recipient' => false, 'excluded_reason' => 'Post not found', 'fix' => null];
        }

        $isExpected = app(NotificationService::class)
            ->postNotificationRecipientQuery($post)
            ->where('users.id', $user->id)
            ->exists();

        if ($isExpected) {
            return ['is_expected_recipient' => true, 'excluded_reason' => null, 'fix' => null];
        }

        $reason = $this->recipientExclusionReason($user, $post);

        return [
            'is_expected_recipient' => false,
            'excluded_reason' => $reason,
            'fix' => $reason === 'Excluded by admin role filter' ? 'Remove admin role exclusion for active Unity peers' : null,
        ];
    }

    private function recipientExclusionReason(User $user, Post $post): string
    {
        if ((string) $user->id === (string) $post->user_id) {
            return 'Post creator excluded';
        }

        if (Schema::hasColumn('users', 'deleted_at') && $user->getAttribute('deleted_at') !== null) {
            return 'User deleted';
        }

        if (Schema::hasColumn('users', 'gdpr_deleted_at') && $user->getAttribute('gdpr_deleted_at') !== null) {
            return 'User GDPR deleted';
        }

        $status = strtolower((string) $user->getAttribute('status'));
        if (in_array($status, ['inactive', 'suspended', 'blocked', 'banned', 'deleted', 'rejected'], true)) {
            return 'User status is not active';
        }

        if ((string) $user->getAttribute('membership_status') === 'suspended') {
            return 'Membership suspended';
        }

        if (! empty($post->circle_id)) {
            return 'Not an active member of the post circle';
        }

        return $this->userHasAdminRole($user) ? 'Excluded by admin role filter' : 'No matching recipient rule';
    }

    private function userHasAdminRole(User $user): bool
    {
        return $user->relationLoaded('roles')
            ? $user->roles->isNotEmpty()
            : $user->roles()->exists();
    }

    private function membershipLabel(User $user): ?string
    {
        foreach (['membership_label', 'membership_type', 'member_type', 'user_type'] as $column) {
            if (Schema::hasColumn('users', $column) && filled($user->getAttribute($column))) {
                return (string) $user->getAttribute($column);
            }
        }

        return null;
    }

    private function activePushTokenQuery(string $userId)
    {
        return UserPushToken::query()
            ->whereIn('id', app(FcmService::class)->activeTokensForUser($userId)->pluck('id'));
    }

    private function pushTokenDebugPayload(UserPushToken $token): array
    {
        $lastUsedColumn = collect(['last_used_at', 'last_used', 'last_seen_at', 'updated_at', 'created_at'])
            ->first(fn (string $column): bool => Schema::hasColumn('user_push_tokens', $column));

        return [
            'id' => (string) $token->id,
            'platform' => $token->platform,
            'app_version' => $token->app_version,
            'status' => $this->pushTokenStatus($token),
            'last_used' => $lastUsedColumn ? $token->{$lastUsedColumn} : null,
        ];
    }

    private function pushTokenStatus(UserPushToken $token): string
    {
        if (Schema::hasColumn('user_push_tokens', 'status') && $token->getAttribute('status')) {
            return (string) $token->getAttribute('status');
        }

        if (Schema::hasColumn('user_push_tokens', 'token_status') && $token->getAttribute('token_status')) {
            return (string) $token->getAttribute('token_status');
        }

        if (Schema::hasColumn('user_push_tokens', 'is_active')) {
            return $token->is_active ? 'active' : 'deactivated';
        }

        return 'unknown';
    }

    private function usersPreview($userIds): array
    {
        return User::query()
            ->whereIn('id', collect($userIds)->take(50))
            ->get(['id', 'display_name', 'first_name', 'last_name', 'name', 'email'])
            ->map(fn (User $user): array => ['id' => (string) $user->id, 'name' => $this->debugUserName($user)])
            ->values()
            ->all();
    }

    private function notificationDebugPayload(AppNotification $notification): array
    {
        return [
            'id' => (string) $notification->id,
            'recipient_id' => (string) $notification->user_id,
            'recipient_name' => $this->debugUserName($notification->user),
            'actor_id' => (string) data_get($notification->data, 'actor_id', ''),
            'type' => $notification->type,
            'title' => $notification->title,
            'body' => $notification->body,
            'reference_type' => $notification->reference_type,
            'reference_id' => $notification->reference_id,
            'status' => $notification->status,
            'read_at' => $notification->read_at,
            'clicked_at' => $notification->clicked_at,
            'created_at' => $notification->created_at,
        ];
    }

    private function deliveryLogDebugPayload(NotificationDeliveryLog $log): array
    {
        return [
            'notification_id' => (string) $log->notification_id,
            'recipient_id' => (string) $log->user_id,
            'channel' => $log->channel,
            'provider' => $log->provider,
            'status' => $log->status,
            'error_message' => $log->error_message,
            'response_payload' => $log->response_payload,
            'created_at' => $log->created_at,
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

<?php

namespace App\Services\Notifications;

use App\Models\Notifications\AppNotification;
use App\Models\Notifications\NotificationDeliveryLog;
use App\Models\User;
use App\Models\UserPushToken;
use App\Services\Firebase\FcmService as FirebaseFcmService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FcmService
{
    public function __construct(private FirebaseFcmService $firebase)
    {
    }

    public function sendToToken(UserPushToken|string $token, string $title, string $body, array $data, ?AppNotification $notification = null, ?string $imageUrl = null): array
    {
        $pushToken = $token instanceof UserPushToken ? $token : null;
        $tokenValue = $pushToken?->token ?: (string) $token;

        // Resolve image URL from data payload if not explicitly provided
        if ($imageUrl === null) {
            $imageUrl = $data['image_url'] ?? $data['event_banner'] ?? null;
            if (!is_string($imageUrl) || trim($imageUrl) === '') {
                $imageUrl = null;
            }
        }

        $tokenRequestPayload = [
            'token_id' => $pushToken ? (string) $pushToken->id : null,
            'token_preview' => Str::limit($tokenValue, 18, '...'),
            'platform' => $pushToken?->platform,
            'app_version' => $pushToken?->app_version,
            'device_id' => $pushToken?->device_id,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ];

        $log = null;
        if ($notification) {
            $existingLogQuery = NotificationDeliveryLog::query()
                ->where('notification_id', $notification->id)
                ->where('user_id', $notification->user_id)
                ->where('channel', 'push')
                ->where('provider', 'firebase');

            if ($pushToken) {
                $existingLogQuery->where('request_payload->token_id', (string) $pushToken->id);
            } else {
                $existingLogQuery->where('request_payload->token_preview', Str::limit($tokenValue, 18, '...'));
            }

            $log = $existingLogQuery->first();

            if ($log && in_array($log->status, ['sent', 'failed'], true)) {
                return [
                    'success' => $log->status === 'sent',
                    'error' => $log->error_message,
                    'duplicate_skipped' => true,
                    'delivery_log_id' => (string) $log->id,
                ];
            }

            $log ??= NotificationDeliveryLog::create([
                'notification_id' => $notification->id,
                'campaign_id' => $notification->campaign_id,
                'user_id' => $notification->user_id,
                'channel' => 'push',
                'provider' => 'firebase',
                'status' => 'pending',
                'request_payload' => $tokenRequestPayload,
                'attempted_at' => now(),
            ]);
        }

        $result = $this->firebase->sendToDevice($tokenValue, $title, $body, $data, null, 1, [
            'user_id' => $notification?->user_id,
            'notification_type' => $data['type'] ?? null,
            'push_token_id' => $pushToken?->id,
            'device_id' => $pushToken?->device_id,
            'platform' => $pushToken?->platform,
        ], $imageUrl);

        if (! ($result['success'] ?? false) && $this->isInvalidTokenError((string) ($result['error'] ?? ''))) {
            $this->deactivateInvalidToken($tokenValue, (string) ($result['error'] ?? ''));
            $result['error'] = $this->friendlyError((string) ($result['error'] ?? 'Invalid Firebase token'));
        }

        if ($log) {
            $log->update([
                'status' => ($result['success'] ?? false) ? 'sent' : 'failed',
                'request_payload' => $tokenRequestPayload,
                'response_payload' => $result,
                'error_message' => ($result['success'] ?? false) ? null : $this->friendlyError((string) ($result['error'] ?? 'Unknown Firebase error')),
                'provider_message_id' => $result['message_id'] ?? data_get($result, 'firebase_response.name'),
                'delivered_at' => ($result['success'] ?? false) ? now() : null,
            ]);
        }

        return $result;
    }

    public function sendToUser(User $user, string $title, string $body, array $data, ?AppNotification $notification = null): array
    {
        $tokens = $this->activeTokensForUser($user->id);

        if ($tokens->isEmpty()) {
            if ($notification) {
                NotificationDeliveryLog::firstOrCreate(
                    [
                        'notification_id' => $notification->id,
                        'user_id' => $notification->user_id,
                        'channel' => 'push',
                        'provider' => 'firebase',
                        'status' => 'skipped',
                    ],
                    [
                        'campaign_id' => $notification->campaign_id,
                        'request_payload' => ['title' => $title, 'body' => $body, 'data' => $data],
                        'error_message' => 'No active push token',
                        'attempted_at' => now(),
                    ]
                );
            }

            return ['success' => false, 'error' => 'No active push token', 'results' => []];
        }

        if ($notification) {
            NotificationDeliveryLog::query()
                ->where('notification_id', $notification->id)
                ->where('user_id', $notification->user_id)
                ->where('channel', 'push')
                ->where('provider', 'firebase')
                ->where('status', 'skipped')
                ->where('error_message', 'No active push token')
                ->delete();
        }

        $results = [];
        foreach ($tokens as $token) {
            $results[] = $this->sendToToken($token, $title, $body, $data, $notification);
        }

        return ['success' => collect($results)->contains('success', true), 'results' => $results];
    }

    public function activeTokensForUser(string $userId): Collection
    {
        $query = UserPushToken::query()
            ->where(UserPushToken::getUserIdColumn(), $userId)
            ->whereNotNull('token')
            ->where('token', '!=', '');

        if (Schema::hasColumn('user_push_tokens', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        if (Schema::hasColumn('user_push_tokens', 'status')) {
            $query->where('status', 'active');
        }

        if (Schema::hasColumn('user_push_tokens', 'token_status')) {
            $query->where('token_status', 'active');
        }

        if (Schema::hasColumn('user_push_tokens', 'is_active')) {
            $query->where('is_active', true);
        }

        if (Schema::hasColumn('user_push_tokens', 'platform')) {
            $query->where(function ($platformQuery): void {
                $platformQuery->whereNull('platform')
                    ->orWhereRaw("LOWER(platform::text) IN ('android', 'ios')");
            });
        }

        $latestColumn = collect(['last_used_at', 'last_used', 'last_seen_at', 'updated_at', 'created_at'])
            ->first(fn (string $column): bool => Schema::hasColumn('user_push_tokens', $column));

        if ($latestColumn) {
            $query->latest($latestColumn);
        }

        return $query->get()
            ->unique(fn (UserPushToken $token) => $token->device_id ?: $token->token)
            ->values();
    }

    public function deactivateInvalidToken(string $token, ?string $reason = null): void
    {
        $updates = [];

        if (Schema::hasColumn('user_push_tokens', 'is_active')) {
            $updates['is_active'] = false;
        }

        if (Schema::hasColumn('user_push_tokens', 'status')) {
            $updates['status'] = 'deactivated';
        }

        if (Schema::hasColumn('user_push_tokens', 'token_status')) {
            $updates['token_status'] = 'deactivated';
        }

        if (Schema::hasColumn('user_push_tokens', 'failed_at')) {
            $updates['failed_at'] = now();
        }

        if (Schema::hasColumn('user_push_tokens', 'failure_reason')) {
            $updates['failure_reason'] = $reason ?: 'Invalid Firebase token';
        }

        if ($updates !== []) {
            UserPushToken::where('token', $token)->update($updates);
        }
    }

    private function friendlyError(string $error): string
    {
        $lower = strtolower($error);

        if ($this->isInvalidTokenError($error)) {
            return 'Invalid Firebase token';
        }

        if (str_contains($lower, 'third_party_auth_error') || str_contains($lower, 'unauthenticated') || str_contains($lower, 'http status 401') || str_contains($lower, 'auth') || str_contains($lower, 'credential') || str_contains($lower, 'permission')) {
            return 'Firebase authentication error';
        }

        if (str_contains($lower, 'quota') || str_contains($lower, 'rate') || str_contains($lower, 'too many')) {
            return 'Firebase quota/rate limit';
        }

        return $error !== '' ? $error : 'Unknown Firebase error';
    }

    private function isInvalidTokenError(string $error): bool
    {
        $error = strtolower($error);
        if (str_contains($error, 'third_party_auth_error') || str_contains($error, 'unauthenticated') || str_contains($error, 'http status 401')) {
            return false;
        }

        return str_contains($error, 'invalid')
            || str_contains($error, 'unregistered')
            || str_contains($error, 'not registered')
            || str_contains($error, 'registration-token-not-registered')
            || str_contains($error, 'not found')
            || str_contains($error, 'invalid-argument');
    }
}

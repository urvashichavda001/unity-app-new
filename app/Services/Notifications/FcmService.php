<?php

namespace App\Services\Notifications;

use App\Models\Notifications\AppNotification;
use App\Models\Notifications\NotificationDeliveryLog;
use App\Models\User;
use App\Models\UserPushToken;
use App\Services\Firebase\FcmService as FirebaseFcmService;
use Illuminate\Support\Str;

class FcmService
{
    public function __construct(private FirebaseFcmService $firebase)
    {
    }

    public function sendToToken(string $token, string $title, string $body, array $data, ?AppNotification $notification = null): array
    {
        $log = null;
        if ($notification) {
            $log = NotificationDeliveryLog::create([
                'notification_id' => $notification->id,
                'campaign_id' => $notification->campaign_id,
                'user_id' => $notification->user_id,
                'channel' => 'push',
                'provider' => 'firebase',
                'status' => 'pending',
                'request_payload' => ['token_preview' => Str::limit($token, 18, '...'), 'title' => $title, 'body' => $body, 'data' => $data],
                'attempted_at' => now(),
            ]);
        }

        $result = $this->firebase->sendToDevice($token, $title, $body, $data, null, 1, [
            'user_id' => $notification?->user_id,
            'notification_type' => $data['type'] ?? null,
        ]);

        if (! ($result['success'] ?? false) && $this->isInvalidTokenError((string) ($result['error'] ?? ''))) {
            $this->deactivateInvalidToken($token);
        }

        if ($log) {
            $log->update([
                'status' => ($result['success'] ?? false) ? 'sent' : 'failed',
                'response_payload' => $result,
                'error_message' => $result['error'] ?? null,
                'provider_message_id' => $result['message_id'] ?? null,
                'delivered_at' => ($result['success'] ?? false) ? now() : null,
            ]);
        }

        return $result;
    }

    public function sendToUser(User $user, string $title, string $body, array $data, ?AppNotification $notification = null): array
    {
        $tokens = $user->pushTokens()->where('is_active', true)->get();

        if ($tokens->isEmpty()) {
            if ($notification) {
                NotificationDeliveryLog::create([
                    'notification_id' => $notification->id,
                    'campaign_id' => $notification->campaign_id,
                    'user_id' => $notification->user_id,
                    'channel' => 'push',
                    'provider' => 'firebase',
                    'status' => 'skipped',
                    'request_payload' => ['title' => $title, 'body' => $body, 'data' => $data],
                    'error_message' => 'No active push token found.',
                    'attempted_at' => now(),
                ]);
            }

            return ['success' => false, 'error' => 'No active push token found.', 'results' => []];
        }

        $results = [];
        foreach ($tokens as $token) {
            $results[] = $this->sendToToken($token->token, $title, $body, $data, $notification);
        }

        return ['success' => collect($results)->contains('success', true), 'results' => $results];
    }

    public function deactivateInvalidToken(string $token): void
    {
        UserPushToken::where('token', $token)->update(['is_active' => false]);
    }

    private function isInvalidTokenError(string $error): bool
    {
        $error = strtolower($error);
        return str_contains($error, 'invalid') || str_contains($error, 'unregistered') || str_contains($error, 'not registered');
    }
}

<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Firebase\FcmService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendPushNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $user,
        public string $title,
        public string $body,
        public array $data = []
    ) {
    }

    public function handle(FcmService $fcmService): void
    {
        try {
            $imageUrl = $this->data['image_url'] ?? null;
            $hasImage = is_string($imageUrl) && $imageUrl !== '';

            Log::info('SendPushNotificationJob started', [
                'user_id' => $this->user->id,
            ]);

            Log::info('Push payload meta', [
                'has_image' => $hasImage,
                'image_url' => $imageUrl,
            ]);

            if (($this->user->status ?? null) !== 'active') {
                return;
            }

            $tokens = $this->user->pushTokens()->get();

            foreach ($tokens as $token) {
                try {
                    Log::info('Sending push to token', [
                        'token_masked' => substr((string) $token->token, 0, 8) . '****',
                        'has_image' => $hasImage,
                        'image_url_prefix' => $hasImage ? substr((string) $imageUrl, 0, 40) . '...' : null,
                    ]);

                    $result = $fcmService->sendToDevice(
                        (string) $token->token,
                        $this->title,
                        $this->body,
                        $this->data,
                        null,
                        1,
                        [
                            'user_id' => (string) $this->user->id,
                            'device_id' => $token->device_id,
                            'platform' => $token->platform,
                            'device_type' => $token->platform,
                            'notification_type' => $this->data['notification_type'] ?? ($this->data['type'] ?? null),
                        ],
                        $hasImage ? (string) $imageUrl : null,
                    );

                    if ($result['success'] ?? false) {
                        Log::info('Push sent successfully', [
                            'user_id' => (string) $this->user->id,
                            'device_id' => $token->device_id,
                            'notification_type' => $this->data['notification_type'] ?? ($this->data['type'] ?? null),
                        ]);
                    } else {
                        Log::warning('Push send returned failure', [
                            'user_id' => (string) $this->user->id,
                            'device_id' => $token->device_id,
                            'platform' => $token->platform,
                            'notification_type' => $this->data['notification_type'] ?? ($this->data['type'] ?? null),
                            'error' => $result['error'] ?? null,
                        ]);
                    }
                } catch (Throwable $e) {
                    Log::error('Push send failed', [
                        'error' => $e->getMessage(),
                        'token_masked' => substr((string) $token->token, 0, 8) . '****',
                        'user_id' => (string) $this->user->id,
                        'platform' => $token->platform,
                        'device_type' => $token->platform,
                        'notification_type' => $this->data['notification_type'] ?? null,
                    ]);

                    report($e);
                }
            }
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }
}

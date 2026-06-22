<?php

namespace App\Services\Firebase;

use App\Models\UserPushToken;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class FcmService
{
    public function sendToDevice(
        string $deviceToken,
        string $title,
        string $body,
        array $data = [],
        ?string $channelId = null,
        int $badge = 1,
        array $context = [],
        ?string $imageUrl = null,
    ): array {
        $notificationType = $context['notification_type'] ?? ($data['notification_type'] ?? ($data['type'] ?? null));

        try {
            $projectId = $this->projectId();

            if ($projectId === '') {
                throw new RuntimeException('Firebase project id is not configured.');
            }

            $accessToken = $this->getAccessToken();
            $endpoint = sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', $projectId);
            $payload = $this->buildMessagePayload($deviceToken, $title, $body, $data, $channelId, $badge, $imageUrl);

            Log::info('Sending FCM HTTP v1 request', [
                'token_masked' => $this->maskToken($deviceToken),
                'title' => $title,
                'has_image' => isset($payload['message']['notification']['image']),
                'user_id' => $context['user_id'] ?? null,
                'device_id' => $context['device_id'] ?? null,
                'platform' => $context['platform'] ?? null,
                'device_type' => $context['device_type'] ?? ($context['platform'] ?? null),
                'notification_type' => $notificationType,
            ]);

            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->post($endpoint, $payload);

            $firebaseResponse = $response->json();
            if (! is_array($firebaseResponse)) {
                $firebaseResponse = ['raw_body' => $response->body()];
            }

            if ($response->successful()) {
                Log::info('FCM HTTP v1 send succeeded', [
                    'token_masked' => $this->maskToken($deviceToken),
                    'user_id' => $context['user_id'] ?? null,
                    'device_id' => $context['device_id'] ?? null,
                    'platform' => $context['platform'] ?? null,
                    'notification_type' => $notificationType,
                    'firebase_message_name' => $firebaseResponse['name'] ?? null,
                ]);

                return [
                    'success' => true,
                    'firebase_response' => $firebaseResponse,
                    'error' => null,
                ];
            }

            if ($this->isInvalidTokenResponse($firebaseResponse)) {
                if (Schema::hasColumn('user_push_tokens', 'is_active')) {
                    UserPushToken::where('token', $deviceToken)->update(['is_active' => false]);
                }

                Log::warning('FCM token removed after invalid token response', [
                    'token_masked' => $this->maskToken($deviceToken),
                    'user_id' => $context['user_id'] ?? null,
                    'device_id' => $context['device_id'] ?? null,
                    'platform' => $context['platform'] ?? null,
                    'device_type' => $context['device_type'] ?? ($context['platform'] ?? null),
                    'notification_type' => $notificationType,
                    'firebase_error' => $response->body(),
                ]);

                return [
                    'success' => false,
                    'firebase_response' => $firebaseResponse,
                    'error' => 'Invalid or unregistered Firebase device token.',
                ];
            }

            Log::error('FCM HTTP v1 send failed', [
                'token_masked' => $this->maskToken($deviceToken),
                'user_id' => $context['user_id'] ?? null,
                'device_id' => $context['device_id'] ?? null,
                'platform' => $context['platform'] ?? null,
                'device_type' => $context['device_type'] ?? ($context['platform'] ?? null),
                'notification_type' => $notificationType,
                'firebase_error' => $response->body(),
            ]);

            return [
                'success' => false,
                'firebase_response' => $firebaseResponse,
                'error' => 'FCM send failed with HTTP status '.$response->status().'.',
            ];
        } catch (Throwable $throwable) {
            report($throwable);

            Log::error('FCM HTTP v1 send exception', [
                'token_masked' => $this->maskToken($deviceToken),
                'user_id' => $context['user_id'] ?? null,
                'device_id' => $context['device_id'] ?? null,
                'platform' => $context['platform'] ?? null,
                'device_type' => $context['device_type'] ?? ($context['platform'] ?? null),
                'notification_type' => $notificationType,
                'error' => $throwable->getMessage(),
            ]);

            return [
                'success' => false,
                'firebase_response' => null,
                'error' => $throwable->getMessage(),
            ];
        }
    }

    public function sendToToken(string $token, string $title, string $body, array $data = [], ?string $imageUrl = null, array $context = []): array
    {
        return $this->sendToDevice($token, $title, $body, $data, null, 1, $context, $imageUrl);
    }

    public function buildMessagePayload(
        string $deviceToken,
        string $title,
        string $body,
        array $data = [],
        ?string $channelId = null,
        int $badge = 1,
        ?string $imageUrl = null,
    ): array {
        $resolvedImageUrl = $imageUrl;

        if ($resolvedImageUrl === null) {
            $candidateImageUrl = $data['image_url'] ?? null;
            if (is_string($candidateImageUrl) && $candidateImageUrl !== '') {
                $resolvedImageUrl = $candidateImageUrl;
            }
        }

        if ($resolvedImageUrl !== null) {
            $data['image_url'] = $resolvedImageUrl;
        }

        $notification = [
            'title' => $title,
            'body' => $body,
        ];

        $androidNotification = [
            'title' => $title,
            'body' => $body,
            'sound' => 'default',
            'channel_id' => $channelId ?: (string) config('firebase.default_android_channel_id', 'default'),
            'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            'notification_priority' => 'PRIORITY_HIGH',
            'visibility' => 'PUBLIC',
        ];

        if ($resolvedImageUrl !== null) {
            $notification['image'] = $resolvedImageUrl;
            $androidNotification['image'] = $resolvedImageUrl;
        }

        $normalizedData = $this->normalizeData($data);

        return [
            'message' => [
                'token' => $deviceToken,
                'notification' => $notification,
                'data' => $normalizedData === [] ? (object) [] : $normalizedData,
                'android' => [
                    'priority' => 'high',
                    'ttl' => '86400s',
                    'notification' => $androidNotification,
                ],
                'apns' => [
                    'headers' => [
                        'apns-priority' => '10',
                        'apns-push-type' => 'alert',
                    ],
                    'payload' => [
                        'aps' => [
                            'alert' => [
                                'title' => $title,
                                'body' => $body,
                            ],
                            'sound' => 'default',
                            'badge' => $badge,
                            'mutable-content' => 1,
                            'content-available' => 1,
                        ],
                    ],
                ],
            ],
        ];
    }


    public function credentialsAvailable(): bool
    {
        return $this->resolveFirebaseCredentialsPath() !== null;
    }

    public function diagnostics(): array
    {
        $configuredPath = $this->configuredCredentialsPath();
        $candidates = $configuredPath ? $this->credentialPathCandidates($configuredPath) : [];
        $resolvedPath = $this->resolveFirebaseCredentialsPath() ?: ($candidates[0] ?? null);

        return [
            'project_id' => $this->projectId(),
            'credentials_configured' => $configuredPath !== null,
            'resolved_credentials_path' => $resolvedPath,
            'file_exists' => $resolvedPath !== null && file_exists($resolvedPath),
            'file_readable' => $resolvedPath !== null && is_readable($resolvedPath),
        ];
    }

    private function projectId(): string
    {
        return (string) (config('services.firebase.project_id') ?: config('firebase.project_id') ?: env('FIREBASE_PROJECT_ID', ''));
    }

    private function configuredCredentialsPath(): ?string
    {
        $path = config('services.firebase.credentials') ?: config('firebase.credentials_path') ?: config('firebase.credentials') ?: env('FIREBASE_CREDENTIALS');

        if (! $path) {
            return null;
        }

        $path = trim((string) $path);
        $path = trim($path, "\"'");

        return $path !== '' ? $path : null;
    }

    private function credentialPathCandidates(string $path): array
    {
        $candidates = [];

        if (str_starts_with($path, '/') || (strlen($path) > 2 && ctype_alpha($path[0]) && $path[1] === ':' && in_array($path[2], ['/', '\\'], true))) {
            $candidates[] = $path;
        }

        $candidates[] = base_path($path);
        $candidates[] = storage_path($path);
        $candidates[] = storage_path('app/' . ltrim($path, '/\\'));

        return array_values(array_unique($candidates));
    }

    private function resolveFirebaseCredentialsPath(): ?string
    {
        $path = $this->configuredCredentialsPath();

        if ($path === null) {
            return null;
        }

        foreach ($this->credentialPathCandidates($path) as $candidate) {
            if ($candidate && file_exists($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function getAccessToken(): string
    {
        return Cache::remember('firebase.fcm.access_token', now()->addMinutes(50), function (): string {
            $credentialsPath = $this->resolveFirebaseCredentialsPath();

            if ($credentialsPath === null) {
                throw new RuntimeException('Firebase credentials file is not available.');
            }

            $credentials = json_decode((string) file_get_contents($credentialsPath), true);

            if (! is_array($credentials)) {
                throw new RuntimeException('Firebase credentials are invalid JSON.');
            }

            $clientEmail = (string) ($credentials['client_email'] ?? '');
            $privateKey = (string) ($credentials['private_key'] ?? '');
            $tokenUri = (string) ($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token');

            if ($clientEmail === '' || $privateKey === '') {
                throw new RuntimeException('Firebase credentials are incomplete.');
            }

            $jwt = $this->buildJwt($clientEmail, $privateKey, $tokenUri);

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if (! $response->successful()) {
                throw new RuntimeException('Unable to fetch Firebase OAuth token.');
            }

            $accessToken = (string) $response->json('access_token');

            if ($accessToken === '') {
                throw new RuntimeException('Firebase OAuth token missing in response.');
            }

            return $accessToken;
        });
    }

    private function buildJwt(string $clientEmail, string $privateKey, string $audience): string
    {
        $now = now()->timestamp;

        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];

        $payload = [
            'iss' => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => $audience,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));

        $signatureInput = $encodedHeader . '.' . $encodedPayload;

        $signature = '';
        $signed = openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (! $signed) {
            throw new RuntimeException('Unable to sign Firebase JWT.');
        }

        return $signatureInput . '.' . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function normalizeData(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                $normalized[(string) $key] = '';
                continue;
            }

            if (is_bool($value)) {
                $normalized[(string) $key] = $value ? 'true' : 'false';
                continue;
            }

            $normalized[(string) $key] = is_scalar($value)
                ? (string) $value
                : (json_encode($value) ?: '');
        }

        return $normalized;
    }

    private function maskToken(string $token): string
    {
        return substr($token, 0, 8).'****';
    }

    private function isInvalidTokenResponse(mixed $response): bool
    {
        if (! is_array($response)) {
            return false;
        }

        $errorCode = Arr::get($response, 'error.details.0.errorCode');
        $message = strtolower((string) Arr::get($response, 'error.message', ''));

        return in_array($errorCode, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)
            || str_contains($message, 'registration token is not a valid fcm registration token')
            || str_contains($message, 'requested entity was not found');
    }
}

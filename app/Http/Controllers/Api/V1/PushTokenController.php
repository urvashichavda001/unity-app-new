<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\UserPushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PushTokenController extends BaseApiController
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['nullable', 'required_without:fcm_token', 'string'],
            'fcm_token' => ['nullable', 'required_without:token', 'string'],
            'platform' => ['required', 'string', 'in:android,ios,web'],
            'device_id' => ['nullable', 'string'],
            'app_version' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $user = $request->user();
            $token = (string) ($validated['fcm_token'] ?? $validated['token']);

            UserPushToken::where('token', $token)
                ->where('user_id', '!=', $user->id)
                ->delete();

            $updates = [
                'platform' => $validated['platform'],
                'last_seen_at' => now(),
            ];

            if (array_key_exists('device_id', $validated)) {
                $updates['device_id'] = $validated['device_id'];
            }

            if (array_key_exists('app_version', $validated)) {
                $updates['app_version'] = $validated['app_version'];
            }

            $pushToken = UserPushToken::updateOrCreate(
                [
                    'token' => $token,
                ],
                array_merge($updates, [
                    'user_id' => $user->id,
                ])
            );

            return $this->success([
                'id' => $pushToken->id,
                'token' => $pushToken->token,
                'fcm_token' => $pushToken->token,
                'platform' => $pushToken->platform,
                'device_id' => $pushToken->device_id,
                'app_version' => $pushToken->app_version,
                'last_seen_at' => $pushToken->last_seen_at,
            ], 'Push token saved successfully');
        } catch (Throwable $throwable) {
            report($throwable);

            return $this->error('Unable to save push token', 500);
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['nullable', 'required_without:fcm_token', 'string'],
            'fcm_token' => ['nullable', 'required_without:token', 'string'],
        ]);

        try {
            $token = (string) ($validated['fcm_token'] ?? $validated['token']);

            $deleted = UserPushToken::where('user_id', $request->user()->id)
                ->where('token', $token)
                ->delete();

            return $this->success([
                'deleted' => $deleted > 0,
            ], 'Push token deleted successfully');
        } catch (Throwable $throwable) {
            report($throwable);

            return $this->error('Unable to delete push token', 500);
        }
    }
}

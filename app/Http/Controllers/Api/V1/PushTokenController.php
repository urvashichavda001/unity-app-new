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
                ->where(UserPushToken::getUserIdColumn(), '!=', $user->id)
                ->delete();

            if (filled($validated['device_id'] ?? null)) {
                UserPushToken::where('device_id', $validated['device_id'])
                    ->where(UserPushToken::getUserIdColumn(), $user->id)
                    ->where('token', '!=', $token)
                    ->delete();
            }

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

            // Always activate/reset states when registered explicitly by the client device
            if (\Illuminate\Support\Facades\Schema::hasColumn('user_push_tokens', 'is_active')) {
                $updates['is_active'] = true;
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('user_push_tokens', 'status')) {
                $updates['status'] = 'active';
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('user_push_tokens', 'token_status')) {
                $updates['token_status'] = 'active';
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('user_push_tokens', 'failed_at')) {
                $updates['failed_at'] = null;
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('user_push_tokens', 'failure_reason')) {
                $updates['failure_reason'] = null;
            }

            $pushToken = UserPushToken::updateOrCreate(
                [
                    'token' => $token,
                ],
                array_merge($updates, [
                    UserPushToken::getUserIdColumn() => $user->id,
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

            $deleted = UserPushToken::where(UserPushToken::getUserIdColumn(), $request->user()->id)
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

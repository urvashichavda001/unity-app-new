<?php

namespace App\Http\Controllers\Api\V1\Scanner;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\EventScannerAuthorization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ScannerAuthController extends BaseApiController
{
    public const TOKEN_NAME = 'unity-event-scanner-token';

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = trim((string) $credentials['email']);
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
            ->first();

        if (! $user || ! $this->passwordMatchesUser($credentials['password'], $user)) {
            Log::warning('scanner.login.invalid_credentials', [
                'email' => Str::lower($email),
                'user_found' => (bool) $user,
            ]);

            return $this->invalidCredentialsResponse();
        }

        if (! $this->hasActiveScannerAuthorization($user)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to use the scanner app.',
                'data' => null,
            ], 403);
        }

        $user->expireFreeTrialIfNeeded();
        $user->refresh();

        if ($user->membership_status === 'suspended') {
            return $this->error('Account is suspended', 403);
        }

        if (($user->status ?? 'active') !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Your account is inactive. Please contact support.',
                'data' => null,
            ], 403);
        }

        $user->last_login_at = now();
        $user->save();
        $user->refresh();

        $token = $user->createToken(self::TOKEN_NAME)->plainTextToken;

        return $this->success([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->userPayload($user),
        ], 'Scanner login successful.');
    }

    private function hasActiveScannerAuthorization(User $user): bool
    {
        return EventScannerAuthorization::query()
            ->active()
            ->where('scanner_user_id', $user->id)
            ->exists();
    }

    private function passwordMatchesUser(string $plainPassword, User $user): bool
    {
        foreach ($this->loginPasswordHashes($user) as $hash) {
            if (Hash::check($plainPassword, $hash)) {
                return true;
            }
        }

        return false;
    }

    private function loginPasswordHashes(User $user): array
    {
        $hashes = [];

        $passwordHash = (string) ($user->password_hash ?? '');
        if ($passwordHash !== '') {
            $hashes[] = $passwordHash;
        }

        if (Schema::hasColumn('users', 'password')) {
            $password = (string) ($user->getAttribute('password') ?? '');
            if ($password !== '' && ! in_array($password, $hashes, true)) {
                $hashes[] = $password;
            }
        }

        return $hashes;
    }

    private function invalidCredentialsResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Invalid credentials.',
            'data' => null,
        ], 401);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'display_name' => $user->display_name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'profile_photo_url' => $user->profile_photo_url ?? $user->getRawOriginal('profile_photo_url'),
        ];
    }
}

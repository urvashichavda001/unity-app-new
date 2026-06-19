<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserContactsController extends BaseApiController
{
    public function permission(Request $request): JsonResponse
    {
        $user = $request->user();
        $allowed = (bool) $user->contacts_allowed;

        return response()->json([
            'success' => true,
            'message' => 'User contacts permission fetched successfully.',
            'data' => [
                'user_id' => $user->id,
                'contacts_allowed' => $allowed,
                'android_contacts_permission' => $allowed ? 'yes' : 'no',
                'ios_contacts_permission' => $allowed ? 'yes' : 'no',
            ],
        ]);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use App\Support\AdminAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDedApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $authUser = $request->user();
        $admin = $authUser instanceof AdminUser ? $authUser : null;

        if (! $admin && $authUser?->email) {
            $admin = AdminUser::query()
                ->whereRaw('LOWER(email) = ?', [strtolower((string) $authUser->email)])
                ->first();
        }

        if (! $admin || ! AdminAccess::isDed($admin)) {
            return response()->json([
                'success' => false,
                'message' => 'DED access is required.',
                'errors' => ['role' => ['Authenticated user is not assigned the DED role.']],
            ], 403);
        }

        $district = AdminAccess::assignedDedDistrict($admin);
        if (! $district || trim((string) ($district['name'] ?? '')) === '') {
            return response()->json([
                'success' => false,
                'message' => 'No district assigned. Please contact Global Admin.',
                'errors' => ['district' => ['No DED district assignment was found for this account.']],
            ], 403);
        }

        $request->attributes->set('ded_admin_user', $admin);
        $request->attributes->set('ded_district', $district);

        return $next($request);
    }
}

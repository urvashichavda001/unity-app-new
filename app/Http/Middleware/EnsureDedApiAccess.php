<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use App\Support\AdminAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDedApiAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $this->error('Unauthenticated.', 401);
        }

        $admin = AdminUser::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $user->email))])
            ->first();

        if (! $admin || ! AdminAccess::isDed($admin)) {
            return $this->error('Only DED users can access this API.', 403);
        }

        $location = AdminAccess::assignedDedLocation($admin);
        if (empty($location['district_name'])) {
            return $this->error('DED district assignment is missing.', 403);
        }

        $request->attributes->set('ded_admin', $admin);
        $request->attributes->set('ded_actor', $user);
        $request->attributes->set('ded_location', $location);

        return $next($request);
    }

    private function error(string $message, int $status): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => [],
        ], $status);
    }
}

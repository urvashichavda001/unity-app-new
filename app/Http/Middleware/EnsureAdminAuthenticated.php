<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('admin')->check()) {
            $adminId = $request->session()->get('admin_user_id');
            if ($adminId) {
                $admin = User::find($adminId);
                if ($admin) {
                    Auth::guard('admin')->login($admin);
                }
            }
        }

        if (! Auth::guard('admin')->check()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthenticated',
                ], 401);
            }

            return redirect()->route('admin.login');
        }

        return $next($request);
    }
}

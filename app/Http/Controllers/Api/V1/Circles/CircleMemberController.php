<?php

namespace App\Http\Controllers\Api\V1\Circles;

use App\Http\Controllers\Controller;
use App\Models\Circle;
use Illuminate\Http\Request;

class CircleMemberController extends Controller
{
    public function index(Request $request, Circle $circle)
    {
        $query = \App\Models\CircleMember::query()
            ->where('circle_id', $circle->id)
            ->whereNull('deleted_at')
            ->with([
                'user',
                'user.businessCategory',
            ]);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('role')) {
            $query->where('role', $request->string('role'));
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();

            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        $members = $query->orderByDesc('created_at')->paginate(20);

        return response()->json([
            'success' => true,
            'message' => null,
            'data' => \App\Http\Resources\CircleMemberResource::collection($members),
        ]);
    }
}

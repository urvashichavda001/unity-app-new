<?php

namespace App\Http\Controllers\Api\V1\Ded;

use App\Models\User;
use App\Support\AdminCircleScope;
use Illuminate\Http\Request;

class DedPeersController extends DedBaseController
{
    public function index(Request $request)
    {
        $request->validate(['search' => ['nullable', 'string'], 'circle_id' => ['nullable', 'string'], 'membership_status' => ['nullable', 'string'], 'status' => ['nullable', 'string'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $admin = $this->admin($request);
        $query = $this->ded->usersQuery($admin)->with('circleMemberships.circle')
            ->when($request->filled('search'), function ($q) use ($request) {
                $like = '%'.$request->query('search').'%';
                $q->where(fn ($inner) => $inner->where('display_name', 'ILIKE', $like)->orWhere('first_name', 'ILIKE', $like)->orWhere('last_name', 'ILIKE', $like)->orWhere('email', 'ILIKE', $like)->orWhere('phone', 'ILIKE', $like)->orWhere('company_name', 'ILIKE', $like)->orWhere('city', 'ILIKE', $like));
            })
            ->when($request->filled('membership_status'), fn ($q) => $q->where('membership_status', $request->query('membership_status')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')));
        $this->ded->applyCircleFilter($query, $admin, $request->query('circle_id'), ['users.id']);
        $items = $query->latest('created_at')->paginate($this->ded->perPage($request))->withQueryString();
        return $this->success($items->items(), 'DED peers fetched successfully.', $this->ded->serializePaginator($items));
    }

    public function show(Request $request, string $id)
    {
        $admin = $this->admin($request);
        $this->ded->assertUserInDistrict($admin, $id);
        $peer = User::query()->with('circleMemberships.circle')->findOrFail($id);
        return $this->success($peer, 'DED peer fetched successfully.');
    }
}

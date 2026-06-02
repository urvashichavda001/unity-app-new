<?php

namespace App\Http\Controllers\Api\V1\Ded;

use App\Models\Circle;
use App\Support\AdminAccess;
use App\Support\AdminCircleScope;
use Illuminate\Http\Request;

class DedDashboardController extends DedBaseController
{
    public function me(Request $request)
    {
        $admin = $this->admin($request);
        $district = $this->ded->district($request);

        return $this->success([
            'admin' => $admin->only(['id', 'name', 'email']),
            'role' => 'ded',
            'assigned_state' => ['id' => $district['state_id'] ?? null, 'name' => $district['state_name'] ?? null],
            'assigned_district' => ['id' => $district['id'] ?? null, 'name' => $district['name'] ?? null],
            'permissions' => ['district_scope' => true, 'approve_pending_requests' => true],
            'available_modules' => ['dashboard', 'activities', 'referral_report', 'pending_requests', 'peers', 'coins', 'life_impact'],
        ], 'DED context fetched successfully.');
    }

    public function dashboard(Request $request)
    {
        $request->validate(['circle_id' => ['nullable', 'string'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date']]);
        return $this->success($this->ded->dashboardStats($request, $this->admin($request)), 'DED dashboard fetched successfully.');
    }

    public function circles(Request $request)
    {
        $request->validate(['search' => ['nullable', 'string'], 'status' => ['nullable', 'string'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = $this->ded->circlesQuery($this->admin($request))->withCount('members')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'ILIKE', '%'.$request->query('search').'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderBy('name');
        $items = $query->paginate($this->ded->perPage($request))->withQueryString();
        return $this->success($items->items(), 'DED circles fetched successfully.', $this->ded->serializePaginator($items));
    }
}

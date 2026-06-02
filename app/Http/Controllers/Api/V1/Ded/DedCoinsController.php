<?php

namespace App\Http\Controllers\Api\V1\Ded;

use App\Models\CoinsLedger;
use Illuminate\Http\Request;

class DedCoinsController extends DedBaseController
{
    public function index(Request $request)
    {
        $request->validate(['search' => ['nullable', 'string'], 'circle_id' => ['nullable', 'string'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $admin = $this->admin($request);
        $query = $this->ded->usersQuery($admin)->select(['users.id', 'display_name', 'first_name', 'last_name', 'email', 'company_name', 'city', 'coins_balance']);
        $this->ded->applyCircleFilter($query, $admin, $request->query('circle_id'), ['users.id']);
        if ($request->filled('search')) {
            $like = '%'.$request->query('search').'%';
            $query->where(fn ($q) => $q->where('display_name', 'ILIKE', $like)->orWhere('email', 'ILIKE', $like)->orWhere('company_name', 'ILIKE', $like)->orWhere('city', 'ILIKE', $like));
        }
        $items = $query->orderByDesc('coins_balance')->paginate($this->ded->perPage($request))->withQueryString();
        return $this->success($items->items(), 'DED coin summary fetched successfully.', $this->ded->serializePaginator($items));
    }

    public function history(Request $request)
    {
        $request->validate(['user_id' => ['nullable', 'string'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $admin = $this->admin($request);
        if ($request->filled('user_id')) $this->ded->assertUserInDistrict($admin, $request->query('user_id'));
        $query = CoinsLedger::query()->with('user');
        $this->ded->applyActivityScope($query, $admin, 'coins_ledger.user_id');
        if ($request->filled('user_id')) $query->where('user_id', $request->query('user_id'));
        $this->ded->applyDates($query, $request, 'created_at');
        $items = $query->orderByDesc('created_at')->paginate($this->ded->perPage($request))->withQueryString();
        return $this->success($items->items(), 'DED coin history fetched successfully.', $this->ded->serializePaginator($items));
    }
}

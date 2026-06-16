<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactPost;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContactController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->only(['search', 'from_date', 'to_date']);

        $contactPosts = ContactPost::query()
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    foreach ([
                        'full_name',
                        'first_name',
                        'middle_name',
                        'last_name',
                        'email',
                        'phone',
                        'company',
                        'job_title',
                        'nickname',
                    ] as $field) {
                        $query->orWhere($field, 'like', "%{$search}%");
                    }
                });
            })
            ->when($filters['from_date'] ?? null, fn ($query, string $fromDate) => $query->whereDate('created_at', '>=', $fromDate))
            ->when($filters['to_date'] ?? null, fn ($query, string $toDate) => $query->whereDate('created_at', '<=', $toDate))
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.contacts.index', [
            'contactPosts' => $contactPosts,
            'filters' => $filters,
        ]);
    }

    public function show(string $id): View
    {
        $contactPost = ContactPost::query()->findOrFail($id);

        return view('admin.contacts.show', [
            'contactPost' => $contactPost,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CertificationSubmission;
use App\Services\Certifications\CertificateGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CertificationSubmissionsController extends Controller
{
    public function __construct(private readonly CertificateGeneratorService $certificateGenerator)
    {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['new', 'approved', 'rejected'])],
            'type' => ['nullable', Rule::in(['leadership', 'entrepreneur'])],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        $query = CertificationSubmission::query();

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['type'])) {
            $query->where('certification_type', $filters['type']);
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search) {
                foreach (['full_name', 'business_name', 'email', 'contact_no'] as $column) {
                    $q->orWhereRaw('LOWER(' . $column . ') LIKE ?', ['%' . strtolower($search) . '%']);
                }
            });
        }

        $items = $query->latest()->paginate(15)->withQueryString();

        return view('admin.certifications.index', [
            'items' => $items,
            'filters' => $filters,
        ]);
    }

    public function approve(Request $request, string $id)
    {
        $data = $request->validate([
            'admin_note' => ['nullable', 'string'],
        ]);

        $submission = CertificationSubmission::findOrFail($id);

        $message = $submission->status === CertificationSubmission::STATUS_APPROVED
            ? 'Certification submission is already approved.'
            : 'Certification submission approved successfully.';

        $submission = $this->certificateGenerator->approveSubmission(
            $submission,
            $data['admin_note'] ?? $submission->admin_note,
            auth('admin')->id(),
        );

        return redirect()
            ->route('admin.certifications.index', ['search' => $submission->email])
            ->with('success', $message);
    }

    public function reject(Request $request, string $id)
    {
        $data = $request->validate([
            'admin_note' => ['required', 'string'],
        ]);

        $submission = CertificationSubmission::findOrFail($id);

        $submission->forceFill([
            'status' => CertificationSubmission::STATUS_REJECTED,
            'admin_note' => $data['admin_note'],
            'approved_by' => null,
            'approved_at' => null,
            'rejected_by' => auth('admin')->id(),
            'rejected_at' => now(),
        ])->save();

        return back()->with('success', 'Certification submission rejected successfully.');
    }
}

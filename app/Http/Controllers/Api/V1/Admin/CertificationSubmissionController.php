<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\AdminUser;
use App\Models\CertificationSubmission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\Certifications\CertificateGeneratorService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CertificationSubmissionController extends BaseApiController
{
    public function __construct(private readonly CertificateGeneratorService $certificateGenerator)
    {
    }

    public function index(Request $request): JsonResponse
    {
        if (! $this->canReview($request->user())) {
            return $this->unauthorizedResponse();
        }

        $validator = Validator::make($request->query(), [
            'status' => ['nullable', Rule::in(['new', 'approved', 'rejected'])],
            'type' => ['nullable', Rule::in(['leadership', 'entrepreneur'])],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'data' => null,
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = CertificationSubmission::query();
        $this->applyFilters($query, $request);

        $items = $query->latest()->paginate($this->resolvePerPage($request));

        return response()->json([
            'status' => true,
            'message' => 'Certification submissions fetched successfully.',
            'data' => collect($items->items())->map(fn (CertificationSubmission $item) => $this->mapSubmission($item))->values(),
            'meta' => $this->paginationMeta($items),
        ]);
    }

    public function counts(Request $request): JsonResponse
    {
        if (! $this->canReview($request->user())) {
            return $this->unauthorizedResponse();
        }

        return response()->json([
            'status' => true,
            'message' => 'Certification counts fetched successfully.',
            'data' => [
                'total' => CertificationSubmission::query()->count(),
                'new' => CertificationSubmission::query()->where('status', CertificationSubmission::STATUS_NEW)->count(),
                'approved' => CertificationSubmission::query()->where('status', CertificationSubmission::STATUS_APPROVED)->count(),
                'rejected' => CertificationSubmission::query()->where('status', CertificationSubmission::STATUS_REJECTED)->count(),
                'leadership' => CertificationSubmission::query()->where('certification_type', CertificationSubmission::TYPE_LEADERSHIP)->count(),
                'entrepreneur' => CertificationSubmission::query()->where('certification_type', CertificationSubmission::TYPE_ENTREPRENEUR)->count(),
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        if (! $this->canReview($request->user())) {
            return $this->unauthorizedResponse();
        }

        $submission = CertificationSubmission::find($id);

        if (! $submission) {
            return response()->json([
                'status' => false,
                'message' => 'Certification submission not found.',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Certification submission fetched successfully.',
            'data' => $this->mapSubmission($submission, true),
        ]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        if (! $this->canReview($request->user())) {
            return $this->unauthorizedResponse();
        }

        $validator = Validator::make($request->all(), [
            'admin_note' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'data' => null,
                'errors' => $validator->errors(),
            ], 422);
        }

        if (! preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $id)) {
            return response()->json([
                'status' => false,
                'message' => 'Certification submission not found. Use a real certification UUID in the URL, not the literal {id} placeholder.',
                'data' => null,
            ], 404);
        }

        $submission = CertificationSubmission::find($id);

        if (! $submission) {
            return response()->json([
                'status' => false,
                'message' => 'Certification submission not found.',
                'data' => null,
            ], 404);
        }

        $message = $submission->status === CertificationSubmission::STATUS_APPROVED
            ? 'Certification submission is already approved.'
            : 'Certification submission approved successfully.';

        $submission = $this->certificateGenerator->approveSubmission(
            $submission,
            $request->input('admin_note', $submission->admin_note),
            $request->user()?->id,
        );

        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => $this->mapSubmission($submission, true),
        ]);
    }

    public function download(Request $request, string $id)
    {
        if (! $this->canReview($request->user())) {
            return $this->unauthorizedResponse();
        }

        $submission = CertificationSubmission::find($id);

        if (! $submission) {
            return response()->json([
                'status' => false,
                'message' => 'Certification submission not found.',
                'data' => null,
            ], 404);
        }

        Log::info('Certification certificate download requested', [
            'certification_submission_id' => (string) $submission->id,
            'certificate_file_path' => $submission->certificate_file_path,
            'file_exists' => $submission->certificate_file_path
                ? Storage::disk('public')->exists($submission->certificate_file_path)
                : false,
        ]);

        if ($submission->status !== CertificationSubmission::STATUS_APPROVED) {
            return response()->json([
                'status' => false,
                'message' => 'Certificate is not available because this submission is not approved.',
                'data' => null,
            ], 422);
        }

        if ($request->boolean('regenerate')) {
            $submission = $this->certificateGenerator->regeneratePdf($submission);
        } elseif (! $submission->certificate_file_path || ! Storage::disk('public')->exists($submission->certificate_file_path)) {
            $submission = $this->certificateGenerator->ensureCertificate($submission);
        }

        if (! $submission->certificate_file_path || ! Storage::disk('public')->exists($submission->certificate_file_path)) {
            return response()->json([
                'status' => false,
                'message' => 'Certificate is not available.',
                'data' => null,
            ], 404);
        }

        return Storage::disk('public')->download(
            $submission->certificate_file_path,
            basename($submission->certificate_file_path),
            ['Content-Type' => 'application/pdf']
        );
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        if (! $this->canReview($request->user())) {
            return $this->unauthorizedResponse();
        }

        $validator = Validator::make($request->all(), [
            'admin_note' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed.',
                'data' => null,
                'errors' => $validator->errors(),
            ], 422);
        }

        $submission = CertificationSubmission::find($id);

        if (! $submission) {
            return response()->json([
                'status' => false,
                'message' => 'Certification submission not found.',
                'data' => null,
            ], 404);
        }

        $submission->forceFill([
            'status' => CertificationSubmission::STATUS_REJECTED,
            'admin_note' => $request->input('admin_note'),
            'approved_by' => null,
            'approved_at' => null,
            'rejected_by' => $request->user()?->id,
            'rejected_at' => now(),
        ])->save();

        return response()->json([
            'status' => true,
            'message' => 'Certification submission rejected successfully.',
            'data' => $this->mapSubmission($submission->refresh(), true),
        ]);
    }

    private function applyFilters($query, Request $request): void
    {
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('type')) {
            $query->where('certification_type', $request->query('type'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->query('search'));
            $query->where(function ($q) use ($search) {
                foreach (['full_name', 'business_name', 'email', 'contact_no'] as $column) {
                    $q->orWhereRaw('LOWER(' . $column . ') LIKE ?', ['%' . strtolower($search) . '%']);
                }
            });
        }
    }

    private function canReview($user): bool
    {
        $user ??= Auth::guard('admin')->user();

        if ($user instanceof AdminUser) {
            return true;
        }

        if ($user instanceof User) {
            $roleIds = Role::query()->whereIn('key', ['global_admin', 'industry_director', 'ded'])->pluck('id');

            return $user->roles()->whereIn('roles.id', $roleIds)->exists();
        }

        return false;
    }

    private function unauthorizedResponse(): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => 'You are not authorized to review certification submissions.',
            'data' => null,
        ], 403);
    }

    private function certificateDownloadUrl(CertificationSubmission $item): ?string
    {
        if ($item->status === CertificationSubmission::STATUS_APPROVED
            && ($item->certificate_file_path || $item->certificate_download_url || $item->certificate_number)
        ) {
            return url('/api/v1/admin/certifications/' . $item->id . '/download');
        }

        return $item->certificate_download_url;
    }

    private function mapSubmission(CertificationSubmission $item, bool $includeAnswers = false): array
    {
        $data = [
            'id' => (string) $item->id,
            'certification_type' => $item->certification_type,
            'user_id' => $item->user_id,
            'full_name' => $item->full_name,
            'business_name' => $item->business_name,
            'email' => $item->email,
            'contact_no' => $item->contact_no,
            'total_score' => (int) $item->total_score,
            'percentage' => (int) $item->percentage,
            'certification_level' => $item->certification_level,
            'certification_title' => $item->certification_title,
            'certificate_number' => $item->certificate_number,
            'certificate_file_path' => $item->certificate_file_path,
            'certificate_download_url' => $this->certificateDownloadUrl($item),
            'certificate_generated_at' => optional($item->certificate_generated_at)?->toISOString(),
            'issued_at' => optional($item->issued_at)?->toISOString(),
            'status' => $item->status,
            'admin_note' => $item->admin_note,
            'approved_by' => $item->approved_by,
            'rejected_by' => $item->rejected_by,
            'approved_at' => optional($item->approved_at)?->toISOString(),
            'rejected_at' => optional($item->rejected_at)?->toISOString(),
            'created_at' => optional($item->created_at)?->toISOString(),
            'updated_at' => optional($item->updated_at)?->toISOString(),
        ];

        if ($includeAnswers) {
            $data['answers'] = $item->answers ?? [];
        }

        return $data;
    }

    private function resolvePerPage(Request $request): int
    {
        return min(max((int) $request->query('per_page', 15), 1), 100);
    }

    private function paginationMeta($items): array
    {
        return [
            'current_page' => $items->currentPage(),
            'last_page' => $items->lastPage(),
            'per_page' => $items->perPage(),
            'total' => $items->total(),
        ];
    }
}

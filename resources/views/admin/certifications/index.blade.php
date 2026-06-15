@extends('admin.layouts.app')

@section('title', 'Certification Submissions')

@section('content')
    @php
        $statusBadgeClass = static function (?string $status): string {
            return match (strtolower((string) $status)) {
                'approved' => 'bg-success-subtle text-success border border-success-subtle',
                'rejected' => 'bg-danger-subtle text-danger border border-danger-subtle',
                'new' => 'bg-info-subtle text-info border border-info-subtle',
                default => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
            };
        };

        $formatLabel = static fn (string $value): string => str($value)->replace('_', ' ')->title()->toString();
        $formatDate = static fn ($value): string => $value ? $value->format('d M Y, h:i A') : '—';
    @endphp

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div>
            <h1 class="h4 mb-1">Certification Submissions</h1>
            <div class="text-muted small">Review Leadership and Entrepreneur certification requests.</div>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <div class="fw-semibold mb-1">Please fix the following:</div>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.certifications.index') }}" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small text-muted">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        @foreach (['new' => 'New', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted">Type</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="">All</option>
                        @foreach (['leadership' => 'Leadership', 'entrepreneur' => 'Entrepreneur'] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm" value="{{ $filters['search'] ?? '' }}" placeholder="Name, business, email, or contact no">
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary flex-fill">Apply</button>
                    <a href="{{ route('admin.certifications.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Type</th>
                        <th>Name</th>
                        <th>Business Name</th>
                        <th>Email</th>
                        <th>Contact No</th>
                        <th>Score</th>
                        <th>Percentage</th>
                        <th>Level</th>
                        <th>Status</th>
                        <th>Submitted Date</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $item)
                        @php
                            $downloadUrl = $item->status === \App\Models\CertificationSubmission::STATUS_APPROVED
                                ? ((is_string($item->certificate_download_url) && str_contains($item->certificate_download_url, '/admin/certificates/') && str_contains($item->certificate_download_url, '/view')) ? $item->certificate_download_url : url('/admin/certificates/' . $item->id . '/view'))
                                : null;
                        @endphp
                        <tr>
                            <td>{{ $formatLabel($item->certification_type) }}</td>
                            <td>{{ $item->full_name }}</td>
                            <td>{{ $item->business_name ?: '—' }}</td>
                            <td>{{ $item->email }}</td>
                            <td>{{ $item->contact_no ?: '—' }}</td>
                            <td>{{ $item->total_score }}</td>
                            <td>{{ $item->percentage }}%</td>
                            <td>{{ $item->certification_level ?: '—' }}</td>
                            <td><span class="badge {{ $statusBadgeClass($item->status) }}">{{ $formatLabel($item->status) }}</span></td>
                            <td>{{ $formatDate($item->created_at) }}</td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#viewCertification{{ $item->id }}">View</button>
                                    @if ($item->status === \App\Models\CertificationSubmission::STATUS_NEW)
                                        <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#approveCertification{{ $item->id }}">Approve</button>
                                        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectCertification{{ $item->id }}">Reject</button>
                                    @endif
                                    @if ($item->status === \App\Models\CertificationSubmission::STATUS_APPROVED && $downloadUrl)
                                        <a href="{{ $downloadUrl }}" target="_blank" rel="noopener" class="btn btn-outline-secondary">Open Certificate</a>
                                    @elseif ($item->status === \App\Models\CertificationSubmission::STATUS_APPROVED)
                                        <form method="POST" action="{{ route('admin.certifications.approve', $item->id) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-outline-warning">Refresh Certificate Link</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="text-center text-muted py-4">No certification submissions found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $items->links() }}
    </div>

    @foreach ($items as $item)
        @php
            $downloadUrl = $item->status === \App\Models\CertificationSubmission::STATUS_APPROVED
                ? ((is_string($item->certificate_download_url) && str_contains($item->certificate_download_url, '/admin/certificates/') && str_contains($item->certificate_download_url, '/view')) ? $item->certificate_download_url : url('/admin/certificates/' . $item->id . '/view'))
                : null;
        @endphp
        <div class="modal fade" id="viewCertification{{ $item->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Certification Details</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3 mb-3">
                            <div class="col-md-4"><div class="small text-muted">Type</div><div class="fw-semibold">{{ $formatLabel($item->certification_type) }}</div></div>
                            <div class="col-md-4"><div class="small text-muted">Name</div><div class="fw-semibold">{{ $item->full_name }}</div></div>
                            <div class="col-md-4"><div class="small text-muted">Business Name</div><div>{{ $item->business_name ?: '—' }}</div></div>
                            <div class="col-md-4"><div class="small text-muted">Email</div><div>{{ $item->email }}</div></div>
                            <div class="col-md-4"><div class="small text-muted">Contact No</div><div>{{ $item->contact_no ?: '—' }}</div></div>
                            <div class="col-md-4"><div class="small text-muted">Submitted Date</div><div>{{ $formatDate($item->created_at) }}</div></div>
                            <div class="col-md-3"><div class="small text-muted">Score</div><div>{{ $item->total_score }}</div></div>
                            <div class="col-md-3"><div class="small text-muted">Percentage</div><div>{{ $item->percentage }}%</div></div>
                            <div class="col-md-3"><div class="small text-muted">Level</div><div>{{ $item->certification_level ?: '—' }}</div></div>
                            <div class="col-md-3"><div class="small text-muted">Status</div><span class="badge {{ $statusBadgeClass($item->status) }}">{{ $formatLabel($item->status) }}</span></div>
                            <div class="col-12"><div class="small text-muted">Admin Note</div><div class="border rounded p-2 bg-light" style="white-space: pre-wrap;">{{ $item->admin_note ?: '—' }}</div></div>
                        </div>

                        @if ($item->status === \App\Models\CertificationSubmission::STATUS_APPROVED)
                            <div class="border rounded p-3 bg-light mb-3">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                    <div>
                                        <h6 class="mb-2">Certificate Details</h6>
                                        <div class="small text-muted">Certificate Number</div>
                                        <div class="fw-semibold">{{ $item->certificate_number ?: '—' }}</div>
                                        <div class="small text-muted mt-2">Issued Date</div>
                                        <div>{{ $formatDate($item->issued_at) }}</div>
                                    </div>
                                    @if ($downloadUrl)
                                        <a href="{{ $downloadUrl }}" target="_blank" rel="noopener" class="btn btn-primary">
                                            Open Certificate
                                        </a>
                                    @endif
                                </div>
                            </div>
                        @endif

                        <h6 class="mb-2">Answers</h6>
                        <div class="table-responsive border rounded">
                            <table class="table table-sm mb-0">
                                <tbody>
                                    @forelse (($item->answers ?? []) as $question => $answer)
                                        <tr>
                                            <th class="bg-light" style="width: 34%;">{{ $formatLabel($question) }}</th>
                                            <td>{{ is_array($answer) ? json_encode($answer) : ($answer ?: '—') }}</td>
                                        </tr>
                                    @empty
                                        <tr><td class="text-muted">No answers stored.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="approveCertification{{ $item->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('admin.certifications.approve', $item->id) }}" class="modal-content">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">Approve Certification</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Approve the {{ $formatLabel($item->certification_type) }} certification submission for <strong>{{ $item->full_name }}</strong>?</p>
                        <label class="form-label">Admin Note <span class="text-muted">(optional)</span></label>
                        <textarea name="admin_note" class="form-control" rows="4" placeholder="Certification approved after review.">{{ old('admin_note') }}</textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Approve</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal fade" id="rejectCertification{{ $item->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('admin.certifications.reject', $item->id) }}" class="modal-content">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">Reject Certification</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Reject the {{ $formatLabel($item->certification_type) }} certification submission for <strong>{{ $item->full_name }}</strong>?</p>
                        <label class="form-label">Admin Note <span class="text-danger">*</span></label>
                        <textarea name="admin_note" class="form-control" rows="4" required placeholder="Reason for rejection">{{ old('admin_note') }}</textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Reject</button>
                    </div>
                </form>
            </div>
        </div>
    @endforeach
@endsection

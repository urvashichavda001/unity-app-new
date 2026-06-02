@extends('admin.layouts.app')

@section('title', 'Event Joining Requests')

@section('content')
@php
    $statusClasses = [
        'pending' => 'warning text-dark',
        'pending_payment' => 'warning text-dark',
        'registered' => 'success',
        'approved' => 'success',
        'rejected' => 'danger',
        'cancelled' => 'secondary',
    ];
    $requestReason = fn ($request) => $request->request_reason
        ?? data_get($request->metadata, 'request_reason')
        ?? data_get($request->metadata, 'reason')
        ?? $request->registration_type
        ?? $request->source
        ?? null;
    $adminNote = fn ($request) => $request->admin_note
        ?? data_get($request->metadata, 'admin_note')
        ?? null;
    $approvedAt = fn ($request) => $request->approved_at
        ?? data_get($request->metadata, 'approved_at')
        ?? null;
    $rejectedAt = fn ($request) => $request->rejected_at
        ?? data_get($request->metadata, 'rejected_at')
        ?? null;
    $approvedByName = fn ($request) => method_exists($request, 'approvedBy')
        ? ($request->approvedBy?->display_name ?? '-')
        : (data_get($request->metadata, 'approved_by_user_id') ?: '-');
    $rejectedByName = fn ($request) => method_exists($request, 'rejectedBy')
        ? ($request->rejectedBy?->display_name ?? '-')
        : (data_get($request->metadata, 'rejected_by_user_id') ?: '-');
@endphp
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h1 class="h4 mb-1">Event Joining Requests</h1>
            <p class="text-muted mb-0">Manage requests from members who want to join events outside their circle.</p>
        </div>
        <a href="{{ route('admin.events.index') }}" class="btn btn-outline-secondary">Events</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="card border-warning"><div class="card-body"><div class="text-muted small">Pending Requests</div><div class="h3 mb-0">{{ $summary['pending'] ?? 0 }}</div></div></div></div>
        <div class="col-md-3"><div class="card border-success"><div class="card-body"><div class="text-muted small">Approved Requests</div><div class="h3 mb-0">{{ $summary['approved'] ?? 0 }}</div></div></div></div>
        <div class="col-md-3"><div class="card border-danger"><div class="card-body"><div class="text-muted small">Rejected Requests</div><div class="h3 mb-0">{{ $summary['rejected'] ?? 0 }}</div></div></div></div>
        <div class="col-md-3"><div class="card border-primary"><div class="card-body"><div class="text-muted small">Total Requests</div><div class="h3 mb-0">{{ $summary['total'] ?? 0 }}</div></div></div></div>
    </div>

    <form class="card card-body mb-3" method="GET">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small text-muted">Status</label>
                <select class="form-select" name="status">
                    @foreach(['all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'] as $value => $label)
                        <option value="{{ $value }}" @selected(($status ?? request('status', 'pending')) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted">Event</label>
                <select class="form-select" name="event_id">
                    <option value="">All Events</option>
                    @foreach($events as $event)
                        <option value="{{ $event->id }}" @selected(request('event_id') === $event->id)>{{ $event->title }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted">Member / Event Search</label>
                <input class="form-control" name="search" value="{{ request('search') }}" placeholder="Name, email, phone, company, event">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">From</label>
                <input class="form-control" type="date" name="date_from" value="{{ request('date_from') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">To</label>
                <input class="form-control" type="date" name="date_to" value="{{ request('date_to') }}">
            </div>
            <div class="col-12 d-flex gap-2">
                <button class="btn btn-outline-primary">Filter</button>
                <a class="btn btn-link" href="{{ route('admin.event-joining-requests.index') }}">Reset filters</a>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Requested By</th>
                        <th>Event</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Requested At</th>
                        <th>Admin Note</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($requests as $joinRequest)
                    @php
                        $user = $joinRequest->user;
                        $userCircle = $user?->circleMemberships?->first()?->circle;
                        $event = $joinRequest->event;
                        $occurrence = $joinRequest->occurrence;
                        $registration = $joinRequest->registration;
                        $badge = $statusClasses[$joinRequest->status] ?? 'secondary';
                        $modalId = 'joiningRequestModal'.$joinRequest->id;
                    @endphp
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $user?->display_name ?: trim(($user?->first_name ?? '').' '.($user?->last_name ?? '')) ?: '-' }}</div>
                            <div class="small text-muted">{{ $user?->email ?? '-' }}</div>
                            <div class="small text-muted">{{ $user?->phone ?? '-' }}</div>
                            @if($user?->company_name)<div class="small text-muted">{{ $user->company_name }}</div>@endif
                            @if($userCircle)<span class="badge bg-light text-dark border mt-1">{{ $userCircle->name }}</span>@endif
                        </td>
                        <td>
                            <div class="fw-semibold">{{ $event?->title ?? '-' }}</div>
                            <div class="small text-muted">{{ $event?->event_type ?? '-' }} · {{ $event?->circle?->name ?? 'No event circle' }}</div>
                            <div class="small text-muted">{{ optional($occurrence?->start_at)->format('d M Y h:i A') }} @if($occurrence?->end_at) - {{ optional($occurrence?->end_at)->format('h:i A') }} @endif</div>
                            <div class="small text-muted">{{ ucfirst((string) ($event?->mode ?? '-')) }} @if($event?->location_text) · {{ $event->location_text }} @endif</div>
                        </td>
                        <td style="max-width:260px;">
                            <span class="d-inline-block text-truncate" style="max-width:230px;">{{ $requestReason($joinRequest) ?: '-' }}</span>
                        </td>
                        <td><span class="badge bg-{{ $badge }}">{{ ucfirst($joinRequest->status) }}</span></td>
                        <td>{{ optional($joinRequest->created_at)->format('d M Y h:i A') }}</td>
                        <td style="max-width:220px;"><span class="d-inline-block text-truncate" style="max-width:200px;">{{ $adminNote($joinRequest) ?: '-' }}</span></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#{{ $modalId }}">View</button>
                            @if($joinRequest->status === 'pending')
                                <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#approve{{ $joinRequest->id }}">Approve</button>
                                <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#reject{{ $joinRequest->id }}">Reject</button>
                            @else
                                <div class="small text-muted mt-1">
                                    @if($joinRequest->status === 'approved') Approved {{ $approvedAt($joinRequest) ? \Illuminate\Support\Carbon::parse($approvedAt($joinRequest))->diffForHumans() : '' }} @endif
                                    @if($joinRequest->status === 'rejected') Rejected {{ $rejectedAt($joinRequest) ? \Illuminate\Support\Carbon::parse($rejectedAt($joinRequest))->diffForHumans() : '' }} @endif
                                </div>
                            @endif
                        </td>
                    </tr>

                    <div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
                            <div class="modal-header"><h5 class="modal-title">Event Joining Request Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <h6>Request Details</h6>
                                        <dl class="row small mb-0">
                                            <dt class="col-5">Request ID</dt><dd class="col-7">{{ $joinRequest->id }}</dd>
                                            <dt class="col-5">Status</dt><dd class="col-7"><span class="badge bg-{{ $badge }}">{{ ucfirst($joinRequest->status) }}</span></dd>
                                            <dt class="col-5">Reason</dt><dd class="col-7">{{ $requestReason($joinRequest) ?: '-' }}</dd>
                                            <dt class="col-5">Admin Note</dt><dd class="col-7">{{ $adminNote($joinRequest) ?: '-' }}</dd>
                                            <dt class="col-5">Requested At</dt><dd class="col-7">{{ optional($joinRequest->created_at)->format('d M Y h:i A') }}</dd>
                                            <dt class="col-5">Approved At</dt><dd class="col-7">{{ $approvedAt($joinRequest) ? optional(\Illuminate\Support\Carbon::parse($approvedAt($joinRequest)))->format('d M Y h:i A') : '-' }}</dd>
                                            <dt class="col-5">Rejected At</dt><dd class="col-7">{{ $rejectedAt($joinRequest) ? optional(\Illuminate\Support\Carbon::parse($rejectedAt($joinRequest)))->format('d M Y h:i A') : '-' }}</dd>
                                            <dt class="col-5">Approved By</dt><dd class="col-7">{{ $approvedByName($joinRequest) }}</dd>
                                            <dt class="col-5">Rejected By</dt><dd class="col-7">{{ $rejectedByName($joinRequest) }}</dd>
                                        </dl>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>User Details</h6>
                                        <dl class="row small mb-0">
                                            <dt class="col-5">Name</dt><dd class="col-7">{{ $user?->display_name ?: trim(($user?->first_name ?? '').' '.($user?->last_name ?? '')) ?: '-' }}</dd>
                                            <dt class="col-5">Email</dt><dd class="col-7">{{ $user?->email ?? '-' }}</dd>
                                            <dt class="col-5">Phone</dt><dd class="col-7">{{ $user?->phone ?? '-' }}</dd>
                                            <dt class="col-5">Company</dt><dd class="col-7">{{ $user?->company_name ?? '-' }}</dd>
                                            <dt class="col-5">City</dt><dd class="col-7">{{ $user?->city ?? $user?->city_of_residence ?? '-' }}</dd>
                                            <dt class="col-5">User Circle</dt><dd class="col-7">{{ $userCircle?->name ?? '-' }}</dd>
                                        </dl>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Event Details</h6>
                                        <dl class="row small mb-0">
                                            <dt class="col-5">Title</dt><dd class="col-7">{{ $event?->title ?? '-' }}</dd>
                                            <dt class="col-5">Type</dt><dd class="col-7">{{ $event?->event_type ?? '-' }}</dd>
                                            <dt class="col-5">Event Circle</dt><dd class="col-7">{{ $event?->circle?->name ?? '-' }}</dd>
                                            <dt class="col-5">Date/Time</dt><dd class="col-7">{{ optional($occurrence?->start_at)->format('d M Y h:i A') }} @if($occurrence?->end_at) - {{ optional($occurrence?->end_at)->format('h:i A') }} @endif</dd>
                                            <dt class="col-5">Location/Mode</dt><dd class="col-7">{{ ucfirst((string) ($event?->mode ?? '-')) }} @if($event?->location_text) · {{ $event->location_text }} @endif</dd>
                                        </dl>
                                    </div>
                                    <div class="col-md-6">
                                        <h6>Registration Details</h6>
                                        <dl class="row small mb-0">
                                            <dt class="col-5">Registration ID</dt><dd class="col-7">{{ $registration?->id ?? '-' }}</dd>
                                            <dt class="col-5">Type</dt><dd class="col-7">{{ $registration?->registration_type ?? '-' }}</dd>
                                            <dt class="col-5">Payment Status</dt><dd class="col-7">{{ $registration?->payment_status ?? '-' }}</dd>
                                            <dt class="col-5">QR</dt><dd class="col-7">@if($registration?->qr_code_url)<a href="{{ $registration->qr_code_url }}" target="_blank">Open QR</a>@else - @endif</dd>
                                            <dt class="col-5">Check-in</dt><dd class="col-7">{{ $registration?->checkin_status ?? '-' }}</dd>
                                        </dl>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
                        </div></div>
                    </div>

                    @if($joinRequest->status === 'pending')
                        <div class="modal fade" id="approve{{ $joinRequest->id }}" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog"><form class="modal-content" method="POST" action="{{ route('admin.event-joining-requests.approve', $joinRequest->id) }}">
                                @csrf
                                <div class="modal-header"><h5 class="modal-title">Approve Event Joining Request</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                <div class="modal-body">
                                    <p>Are you sure you want to approve this member to register for this event?</p>
                                    <label class="form-label">Admin Note</label>
                                    <textarea class="form-control" name="admin_note" rows="3">Approved for cross-circle event registration.</textarea>
                                </div>
                                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success">Approve</button></div>
                            </form></div>
                        </div>
                        <div class="modal fade" id="reject{{ $joinRequest->id }}" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog"><form class="modal-content" method="POST" action="{{ route('admin.event-joining-requests.reject', $joinRequest->id) }}">
                                @csrf
                                <div class="modal-header"><h5 class="modal-title">Reject Event Joining Request</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                <div class="modal-body">
                                    <p>Please add a reason for rejection.</p>
                                    <label class="form-label">Admin Note <span class="text-danger">*</span></label>
                                    <textarea class="form-control" name="admin_note" rows="3" required></textarea>
                                </div>
                                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Reject</button></div>
                            </form></div>
                        </div>
                    @endif
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No event joining requests found.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $requests->links() }}</div>
    </div>
</div>
@endsection

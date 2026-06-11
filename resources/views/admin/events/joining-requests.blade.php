@extends('admin.layouts.app')

@section('title', 'Event Joining Requests')


@push('styles')
<style>
    .event-request-decision-modal {
        --bs-modal-width: 560px;
        padding-left: 0.75rem;
        padding-right: 0.75rem;
    }

    .event-request-decision-modal .modal-dialog {
        margin-left: auto;
        margin-right: auto;
        max-width: min(560px, calc(100vw - 1.5rem));
        width: 100%;
    }

    .event-request-decision-modal .event-request-decision-card {
        background: #fff;
        border: 0;
        border-radius: 20px;
        box-shadow: 0 24px 70px rgba(15, 23, 42, 0.24);
        overflow: hidden;
    }

    .event-request-decision-modal .event-request-decision-header {
        align-items: flex-start;
        border-bottom: 1px solid #eef2f7;
        padding: 1.35rem 1.5rem 1rem;
    }

    .event-request-decision-modal .event-request-title-wrap {
        align-items: center;
        display: flex;
        gap: 0.8rem;
        min-width: 0;
    }

    .event-request-decision-modal .event-request-icon {
        align-items: center;
        border-radius: 14px;
        display: inline-flex;
        flex: 0 0 42px;
        height: 42px;
        justify-content: center;
        width: 42px;
    }

    .event-request-decision-modal .event-request-icon-success {
        background: #dcfce7;
        color: #15803d;
    }

    .event-request-decision-modal .event-request-icon-danger {
        background: #fee2e2;
        color: #b91c1c;
    }

    .event-request-decision-modal .event-request-decision-header .modal-title {
        color: #0f172a;
        font-size: 1.12rem;
        font-weight: 700;
        line-height: 1.3;
        margin: 0;
    }

    .event-request-decision-modal .event-request-decision-body {
        padding: 1.25rem 1.5rem 1.35rem;
    }

    .event-request-decision-modal .event-request-description {
        color: #64748b;
        font-size: 0.95rem;
        line-height: 1.6;
        margin-bottom: 1.15rem;
    }

    .event-request-decision-modal .event-request-note-label {
        color: #334155;
        display: block;
        font-size: 0.86rem;
        font-weight: 700;
        letter-spacing: 0.01em;
        margin-bottom: 0.5rem;
    }

    .event-request-decision-modal .event-request-note-field {
        background-color: #fff;
        border: 1px solid #cbd5e1;
        border-radius: 14px;
        box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.04);
        color: #0f172a;
        box-sizing: border-box;
        display: block;
        font-size: 0.95rem;
        line-height: 1.55;
        min-height: 118px;
        padding: 0.85rem 0.95rem;
        resize: vertical;
        width: 100%;
    }

    .event-request-decision-modal .event-request-note-field:focus {
        border-color: #86b7fe;
        box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.16);
    }

    .event-request-decision-modal .event-request-decision-footer {
        background: #f8fafc;
        border-top: 1px solid #eef2f7;
        gap: 0.75rem;
        justify-content: flex-end;
        padding: 1rem 1.5rem 1.25rem;
    }

    .event-request-decision-modal .event-request-decision-footer .btn {
        align-items: center;
        border-radius: 10px;
        display: inline-flex;
        font-weight: 600;
        justify-content: center;
        min-width: 104px;
        padding: 0.58rem 1rem;
    }

    .modal-backdrop.show {
        background-color: #0f172a;
        opacity: 0.62;
    }

    @media (max-width: 575.98px) {
        .event-request-decision-modal {
            --bs-modal-width: calc(100vw - 1.5rem);
        }

        .event-request-decision-modal .event-request-decision-header,
        .event-request-decision-modal .event-request-decision-body,
        .event-request-decision-modal .event-request-decision-footer {
            padding-left: 1rem;
            padding-right: 1rem;
        }

        .event-request-decision-modal .event-request-decision-footer {
            flex-direction: column-reverse;
        }

        .event-request-decision-modal .event-request-decision-footer .btn {
            width: 100%;
        }
    }
</style>
@endpush

@section('content')
@php
    $statusClasses = [
        'pending' => 'warning text-dark',
        'approved' => 'success',
        'rejected' => 'danger',
        'cancelled' => 'secondary',
    ];
@endphp
<div class="container-fluid py-3 event-joining-requests-page">
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
                            <span class="d-inline-block text-truncate" style="max-width:230px;">{{ $joinRequest->request_reason ?: '-' }}</span>
                        </td>
                        <td><span class="badge bg-{{ $badge }}">{{ ucfirst($joinRequest->status) }}</span></td>
                        <td>{{ optional($joinRequest->created_at)->format('d M Y h:i A') }}</td>
                        <td style="max-width:220px;"><span class="d-inline-block text-truncate" style="max-width:200px;">{{ $joinRequest->admin_note ?: '-' }}</span></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#{{ $modalId }}">View</button>
                            @if($joinRequest->status === 'pending')
                                <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#approve{{ $joinRequest->id }}">Approve</button>
                                <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#reject{{ $joinRequest->id }}">Reject</button>
                            @else
                                <div class="small text-muted mt-1">
                                    @if($joinRequest->status === 'approved') Approved {{ optional($joinRequest->approved_at)->diffForHumans() }} @endif
                                    @if($joinRequest->status === 'rejected') Rejected {{ optional($joinRequest->rejected_at)->diffForHumans() }} @endif
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
                                            <dt class="col-5">Reason</dt><dd class="col-7">{{ $joinRequest->request_reason ?: '-' }}</dd>
                                            <dt class="col-5">Admin Note</dt><dd class="col-7">{{ $joinRequest->admin_note ?: '-' }}</dd>
                                            <dt class="col-5">Requested At</dt><dd class="col-7">{{ optional($joinRequest->created_at)->format('d M Y h:i A') }}</dd>
                                            <dt class="col-5">Approved At</dt><dd class="col-7">{{ optional($joinRequest->approved_at)->format('d M Y h:i A') ?: '-' }}</dd>
                                            <dt class="col-5">Rejected At</dt><dd class="col-7">{{ optional($joinRequest->rejected_at)->format('d M Y h:i A') ?: '-' }}</dd>
                                            <dt class="col-5">Approved By</dt><dd class="col-7">{{ $joinRequest->approvedBy?->display_name ?? '-' }}</dd>
                                            <dt class="col-5">Rejected By</dt><dd class="col-7">{{ $joinRequest->rejectedBy?->display_name ?? '-' }}</dd>
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
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No event joining requests found.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $requests->links() }}</div>
    </div>

    @foreach($requests as $joinRequest)
        @if($joinRequest->status === 'pending')
            <div class="modal fade event-request-decision-modal" id="approve{{ $joinRequest->id }}" tabindex="-1" aria-labelledby="approve{{ $joinRequest->id }}Label" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <form class="modal-content event-request-decision-card" method="POST" action="{{ route('admin.event-joining-requests.approve', $joinRequest->id) }}">
                        @csrf
                        <div class="modal-header event-request-decision-header">
                            <div class="event-request-title-wrap">
                                <span class="event-request-icon event-request-icon-success" aria-hidden="true"><i class="bi bi-check2-circle"></i></span>
                                <h5 class="modal-title" id="approve{{ $joinRequest->id }}Label">Approve Event Joining Request</h5>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body event-request-decision-body">
                            <p class="event-request-description">Are you sure you want to approve this member to register for this event?</p>
                            <label class="event-request-note-label" for="approveNote{{ $joinRequest->id }}">Admin Note</label>
                            <textarea class="form-control event-request-note-field" id="approveNote{{ $joinRequest->id }}" name="admin_note" rows="4">Approved for cross-circle event registration.</textarea>
                        </div>
                        <div class="modal-footer event-request-decision-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-success">Approve</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="modal fade event-request-decision-modal" id="reject{{ $joinRequest->id }}" tabindex="-1" aria-labelledby="reject{{ $joinRequest->id }}Label" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <form class="modal-content event-request-decision-card" method="POST" action="{{ route('admin.event-joining-requests.reject', $joinRequest->id) }}">
                        @csrf
                        <div class="modal-header event-request-decision-header">
                            <div class="event-request-title-wrap">
                                <span class="event-request-icon event-request-icon-danger" aria-hidden="true"><i class="bi bi-x-circle"></i></span>
                                <h5 class="modal-title" id="reject{{ $joinRequest->id }}Label">Reject Event Joining Request</h5>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body event-request-decision-body">
                            <p class="event-request-description">Please add a reason for rejection.</p>
                            <label class="event-request-note-label" for="rejectNote{{ $joinRequest->id }}">Admin Note <span class="text-danger">*</span></label>
                            <textarea class="form-control event-request-note-field" id="rejectNote{{ $joinRequest->id }}" name="admin_note" rows="4" required></textarea>
                        </div>
                        <div class="modal-footer event-request-decision-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-danger">Reject</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @endforeach
</div>
@endsection

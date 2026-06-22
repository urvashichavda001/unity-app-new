@extends('admin.layouts.app')
@section('title','Delivery Logs')
@section('content')
@include('admin.notifications._helpers')
@include('admin.notifications._styles')
@include('admin.notifications._flash')
<div class="notification-page">
    <div class="notification-hero mb-4 d-flex flex-wrap justify-content-between gap-3">
        <div>
            <h1 class="h3 mb-1">Delivery Logs</h1>
            <p class="text-muted mb-0">Track every notification attempt, including failed and skipped Firebase pushes.</p>
        </div>
        <a href="{{ route('admin.notifications.send-test') }}" class="btn btn-primary"><i class="bi bi-send me-1"></i>Send Notification</a>
    </div>

    <div class="row g-3 mb-4">
        @foreach([['Total Attempts','total','bi-list-check','primary'],['Sent','sent','bi-check-circle','success'],['Failed','failed','bi-x-circle','danger'],['Skipped','skipped','bi-skip-forward','secondary'],['Pending','pending','bi-hourglass','warning'],['Firebase Push','firebase','bi-phone','info']] as [$label,$key,$icon,$color])
            <div class="col-6 col-xl-2"><div class="notification-card notification-stat"><div class="d-flex justify-content-between"><div><div class="h3 mb-0 text-{{ $color }}">{{ number_format($summary[$key] ?? 0) }}</div><div class="small fw-semibold">{{ $label }}</div></div><div class="notification-stat-icon"><i class="bi {{ $icon }}"></i></div></div></div></div>
        @endforeach
    </div>

    <div class="notification-card p-3 mb-4">
        <form class="row g-3 align-items-end" method="GET">
            <div class="col-6 col-lg-2"><label class="form-label small fw-semibold">Status</label><select class="form-select" name="status"><option value="">All</option>@foreach(['sent','delivered','failed','skipped','pending','read','clicked'] as $status)<option value="{{ $status }}" @selected(request('status')===$status)>{{ Str::headline($status) }}</option>@endforeach</select></div>
            <div class="col-6 col-lg-2"><label class="form-label small fw-semibold">Channel</label><select class="form-select" name="channel"><option value="">All</option>@foreach(['in_app','push','email'] as $channel)<option value="{{ $channel }}" @selected(request('channel')===$channel)>{{ notification_admin_channel_label($channel) }}</option>@endforeach</select></div>
            <div class="col-6 col-lg-2"><label class="form-label small fw-semibold">Type</label><input class="form-control" name="type" value="{{ request('type') }}" placeholder="post_like"></div>
            <div class="col-6 col-lg-2"><label class="form-label small fw-semibold">Error reason</label><input class="form-control" name="error_reason" value="{{ request('error_reason') }}" placeholder="No active push token"></div>
            <div class="col-12 col-lg-3"><label class="form-label small fw-semibold">User / notification</label><input class="form-control" name="user_search" value="{{ request('user_search') }}" placeholder="Name, email, title, campaign"></div>
            <div class="col-6 col-lg-2"><label class="form-label small fw-semibold">Campaign</label><select class="form-select" name="campaign_id"><option value="">All campaigns</option>@foreach($campaigns as $c)<option value="{{ $c->id }}" @selected(request('campaign_id')===$c->id)>{{ $c->name }}</option>@endforeach</select></div>
            <div class="col-6 col-lg-2"><label class="form-label small fw-semibold">From</label><input type="date" class="form-control" name="date_from" value="{{ request('date_from') }}"></div>
            <div class="col-6 col-lg-2"><label class="form-label small fw-semibold">To</label><input type="date" class="form-control" name="date_to" value="{{ request('date_to') }}"></div>
            <div class="col-12 col-lg-auto d-flex gap-2"><a href="{{ route('admin.notifications.logs') }}" class="btn btn-outline-secondary flex-fill">Clear</a><button class="btn btn-primary flex-fill">Filter</button></div>
        </form>
    </div>

    <div class="notification-card overflow-hidden"><div class="table-responsive"><table class="table table-hover align-middle notification-admin-table mb-0">
        <thead class="table-light"><tr><th>Date</th><th>User</th><th>Notification title/message</th><th>Notification type</th><th>Channel</th><th>In-app status</th><th>Push status</th><th>Error</th><th>Token status</th><th>Reference type</th><th>Reference ID</th><th class="action-cell">Action</th></tr></thead>
        <tbody>
        @forelse($logs as $log)
            @php($user=$log->notification?->user ?? $log->user)
            @php($notification=$log->notification)
            @php($pushStatus=$log->channel === 'push' ? $log->status : optional($notification?->deliveryLogs->where('channel','push')->sortByDesc('created_at')->first())->status)
            <tr>
                <td>{{ optional($log->created_at)->format('d M Y H:i') ?: '—' }}</td>
                <td><div class="fw-semibold">{{ notification_admin_user_name($user) }}</div><div class="small text-muted">{{ $user?->email ?? $user?->mobile ?? $user?->phone ?? '—' }}</div></td>
                <td><div class="fw-semibold">{{ Str::limit($notification?->title ?: '—', 42) }}</div><div class="small text-muted">{{ Str::limit($notification?->body ?: '—', 64) }}</div></td>
                <td><span class="notification-mono">{{ $notification?->type ?: '—' }}</span></td>
                <td>{{ notification_admin_channel_label($log->channel) }}</td>
                <td>{{ $notification?->read_at ? 'Read' : ($notification?->status ? Str::headline($notification->status) : '—') }}</td>
                <td><span class="badge bg-{{ notification_admin_status_badge($pushStatus ?: $log->status) }} notification-soft-badge">{{ notification_admin_label($pushStatus ?: $log->status) }}</span></td>
                <td title="{{ $log->error_message }}">{{ $log->error_message ? Str::limit(notification_admin_error_summary($log->error_message), 45) : '—' }}</td>
                <td>{{ data_get($log->request_payload, 'token_preview') ? 'Active at attempt' : ($log->channel === 'push' && $log->status === 'skipped' ? 'No active token' : '—') }}</td>
                <td>{{ $notification?->reference_type ?: '—' }}</td>
                <td><span class="notification-mono">{{ $notification?->reference_id ?: '—' }}</span></td>
                <td><button class="btn btn-sm btn-outline-primary action-btn" data-bs-toggle="modal" data-bs-target="#payload-{{ $log->id }}">View</button></td>
            </tr>
            <div class="modal fade" id="payload-{{ $log->id }}" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Delivery Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><dl class="row"><dt class="col-sm-3">Notification</dt><dd class="col-sm-9"><strong>{{ $notification?->title ?: '—' }}</strong><br>{{ $notification?->body ?: '—' }}</dd><dt class="col-sm-3">User</dt><dd class="col-sm-9">{{ notification_admin_user_name($user) }}</dd><dt class="col-sm-3">Channel / Provider</dt><dd class="col-sm-9">{{ notification_admin_channel_label($log->channel) }} / {{ notification_admin_label($log->provider ?: '—') }}</dd><dt class="col-sm-3">Status</dt><dd class="col-sm-9">{{ notification_admin_label($log->status) }}</dd><dt class="col-sm-3">Error</dt><dd class="col-sm-9">{{ $log->error_message ? notification_admin_error_summary($log->error_message) : '—' }}</dd><dt class="col-sm-3">Reference</dt><dd class="col-sm-9">{{ $notification?->reference_type ?: '—' }} / {{ $notification?->reference_id ?: '—' }}</dd></dl><details open><summary class="fw-semibold">Request payload</summary><pre class="bg-light p-3 rounded mt-2">{{ notification_admin_json($log->request_payload) }}</pre></details><details><summary class="fw-semibold">Provider response</summary><pre class="bg-light p-3 rounded mt-2">{{ notification_admin_json($log->response_payload) }}</pre></details></div></div></div></div>
        @empty
            <tr><td colspan="12"><div class="notification-empty">No delivery logs found for selected filters.</div></td></tr>
        @endforelse
        </tbody>
    </table></div></div>
    <div class="mt-3">{{ $logs->links() }}</div>
</div>
@endsection

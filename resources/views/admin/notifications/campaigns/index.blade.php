@extends('admin.layouts.app')
@section('title', 'Notification Campaigns')

@section('content')
@include('admin.notifications._helpers')
@include('admin.notifications._styles')
@include('admin.notifications._flash')

@php
    $human = fn ($value) => filled($value) ? Str::of($value)->replace(['_', '-'], ' ')->title() : '—';
    $categoryLabel = fn ($value) => $categoryTabs[$value] ?? (string) $human($value);
    $frequencyLabels = [
        'immediate' => 'Immediate', 'realtime' => 'Immediate', 'real_time' => 'Immediate', 'daily' => 'Daily', 'weekly' => 'Weekly',
        'every_3_5_days' => 'Every 3–5 days', 'event_reminder' => '24h / 1h before', 'post_event' => '1h after event',
        'sunday_evening' => 'Sunday evening', 'real_time_or_digest' => 'Real-time or digest', 'hourly' => 'Hourly', 'every-five-minutes' => 'Every 5 minutes',
    ];
    $audienceLabels = [
        'matching_industry_tags_city_circle' => 'Members matching industry, city, circle, or tags',
        'registered_or_rsvp_users' => 'Registered / RSVP users',
        'circle_members_and_connections' => 'Circle members & accepted peers',
        'inactive_users' => 'Members who have not opened the app recently',
        'incomplete_profile' => 'Members with incomplete profiles',
        'non_pro_users' => 'Non-PRO members',
        'subscription_expiring' => 'Members whose PRO plan is expiring',
        'unclaimed_coins' => 'Members with unclaimed coins',
        'all_users' => 'All members',
    ];
    $triggerLabels = [
        'scheduled_pending_requirement' => 'Sent when a requirement remains pending',
        'new_requirement_lead_available' => 'Sent when a new matching requirement or lead is available',
        'new_post' => 'Sent when a relevant new post is published',
        'mention' => 'Sent when a member is mentioned',
        'event_starting_now' => 'Sent when an event is starting now',
        'upcoming_event_reminder' => 'Sent before an upcoming event',
        'post_event_feedback' => 'Sent after an event to request feedback',
    ];
    $freq = fn ($value) => filled($value) ? ($frequencyLabels[$value] ?? (string) $human($value)) : 'Immediate';
    $audience = fn ($value) => filled($value) ? ($audienceLabels[$value] ?? (string) $human($value)) : 'General members';
    $trigger = fn ($value) => filled($value) ? ($triggerLabels[$value] ?? (string) $human($value)) : 'Manual or admin-triggered';
    $channel = fn ($value) => match((string) $value) { 'push' => 'Push', 'email' => 'Email', 'push_email', 'both' => 'Push + Email', 'in_app_only' => 'In-app only', default => (string) $human($value) };
    $statusLabel = fn ($campaign) => $campaign->is_active ? (filled($campaign->frequency) && $campaign->frequency !== 'immediate' ? 'Scheduled' : 'Active') : 'Inactive';
    $statusClass = fn ($campaign) => $campaign->is_active ? (filled($campaign->frequency) && $campaign->frequency !== 'immediate' ? 'primary' : 'success') : 'secondary';
    $activeFilters = request()->except('page');
@endphp

<style>
    .campaign-dashboard { background:#f8fafc; margin:-1rem; padding:1.25rem; border-radius:18px; }
    .campaign-hero, .campaign-card, .campaign-filter, .campaign-table-card { background:#fff; border:1px solid #e5e7eb; border-radius:18px; box-shadow:0 10px 25px rgba(15,23,42,.04); }
    .campaign-hero { padding:22px; }
    .summary-card { padding:18px; height:100%; }
    .summary-icon { width:42px; height:42px; border-radius:14px; display:grid; place-items:center; background:#eef2ff; color:#4f46e5; }
    .category-pills { gap:.5rem; overflow-x:auto; padding-bottom:.25rem; }
    .category-pills .btn { border-radius:999px; white-space:nowrap; }
    .campaign-title-preview { max-width:310px; color:#64748b; }
    .mono-code { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size:.76rem; color:#64748b; }
    .soft-badge { border-radius:999px; padding:.42rem .65rem; font-weight:600; }
    .push-preview { background:linear-gradient(145deg,#111827,#334155); border-radius:24px; padding:16px; color:#fff; }
    .push-bubble { background:rgba(255,255,255,.94); color:#111827; border-radius:18px; padding:14px; box-shadow:0 16px 35px rgba(0,0,0,.18); }
    .push-app { width:30px; height:30px; border-radius:9px; display:grid; place-items:center; background:#2563eb; color:#fff; font-weight:700; }
    .detail-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:1rem; }
    .detail-box { border:1px solid #eef2f7; border-radius:14px; padding:14px; background:#fbfdff; }
    .empty-state { padding:64px 20px; text-align:center; }
    @media (max-width: 768px) { .campaign-dashboard { margin:-.75rem; padding:.75rem; } .detail-grid { grid-template-columns:1fr; } .campaign-actions { width:100%; } .campaign-actions .btn, .campaign-actions form { flex:1 1 auto; } }
</style>

<div class="campaign-dashboard">
    <div class="campaign-hero mb-4 d-flex flex-wrap gap-3 justify-content-between align-items-start">
        <div>
            <div class="text-uppercase text-primary fw-bold small mb-1">Notification Center</div>
            <h1 class="h3 mb-1">Notification Campaigns</h1>
            <p class="text-muted mb-0">Manage automated, scheduled, and manual notification campaigns.</p>
        </div>
        <div class="d-flex flex-wrap gap-2 campaign-actions">
            <form method="POST" action="{{ route('admin.notifications.campaigns.seed-defaults') }}" class="m-0">
                @csrf
                <button class="btn btn-outline-success" onclick="return confirm('Seed/update the default notification campaigns?')"><i class="bi bi-database-add me-1"></i>Seed Default Campaigns</button>
            </form>
            <a class="btn btn-primary" href="{{ route('admin.notifications.campaigns.create') }}"><i class="bi bi-plus-lg me-1"></i>Create Campaign</a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        @foreach ([['Total Campaigns','total','bi-collection','Active automation rules'],['Active Campaigns','active','bi-check-circle','Currently enabled'],['Scheduled Campaigns','scheduled','bi-calendar-event','Runs on a cadence'],['Immediate Campaigns','immediate','bi-lightning-charge','Manual or real-time'],['Push Campaigns','push','bi-phone','Mobile push enabled'],['High/Urgent Priority','high_urgent','bi-exclamation-triangle','Needs faster attention']] as [$label,$key,$icon,$help])
            <div class="col-12 col-sm-6 col-xl-2"><div class="campaign-card summary-card"><div class="d-flex justify-content-between align-items-start"><div><div class="h3 mb-0">{{ $summary[$key] ?? 0 }}</div><div class="fw-semibold small">{{ $label }}</div></div><div class="summary-icon"><i class="bi {{ $icon }}"></i></div></div><div class="text-muted small mt-2">{{ $help }}</div></div></div>
        @endforeach
    </div>

    <div class="d-flex category-pills mb-3">
        @foreach($categoryTabs as $value => $label)
            <a class="btn btn-sm {{ request('category', '') === $value ? 'btn-primary' : 'btn-outline-secondary bg-white' }}" href="{{ route('admin.notifications.campaigns', array_filter(array_merge(request()->except(['page','category']), ['category' => $value]), fn($v) => $v !== null && $v !== '')) }}">{{ $label }}</a>
        @endforeach
    </div>

    <div class="campaign-filter p-3 mb-4">
        <form class="row g-3 align-items-end">
            <div class="col-12 col-lg-3"><label class="form-label small fw-semibold">Search</label><input class="form-control" name="search" value="{{ request('search') }}" placeholder="Name, code, title, audience, trigger"></div>
            <div class="col-6 col-lg"><label class="form-label small fw-semibold">Category</label><select class="form-select" name="category"><option value="">All categories</option>@foreach($filters['categories'] as $category)<option value="{{ $category }}" @selected(request('category') === $category)>{{ $categoryLabel($category) }}</option>@endforeach</select></div>
            <div class="col-6 col-lg"><label class="form-label small fw-semibold">Channel</label><select class="form-select" name="channel"><option value="">All channels</option>@foreach($filters['channels'] as $ch)<option value="{{ $ch }}" @selected(request('channel') === $ch)>{{ $channel($ch) }}</option>@endforeach</select></div>
            <div class="col-6 col-lg"><label class="form-label small fw-semibold">Priority</label><select class="form-select" name="priority"><option value="">Any priority</option>@foreach($filters['priorities'] as $priority)<option value="{{ $priority }}" @selected(request('priority') === $priority)>{{ $human($priority) }}</option>@endforeach</select></div>
            <div class="col-6 col-lg"><label class="form-label small fw-semibold">Status</label><select class="form-select" name="status"><option value="">Any status</option><option value="active" @selected(request('status') === 'active')>Active</option><option value="scheduled" @selected(request('status') === 'scheduled')>Scheduled</option><option value="inactive" @selected(request('status') === 'inactive')>Inactive</option></select></div>
            <div class="col-6 col-lg"><label class="form-label small fw-semibold">Frequency</label><select class="form-select" name="frequency"><option value="">Any frequency</option>@foreach($filters['frequencies'] as $frequency)<option value="{{ $frequency }}" @selected(request('frequency') === $frequency)>{{ $freq($frequency) }}</option>@endforeach</select></div>
            <div class="col-12 col-lg-auto d-flex gap-2"><a href="{{ route('admin.notifications.campaigns') }}" class="btn btn-outline-secondary flex-fill">Clear</a><button class="btn btn-primary flex-fill"><i class="bi bi-funnel me-1"></i>Filter</button></div>
        </form>
    </div>

    <div class="campaign-table-card overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle notification-admin-table mb-0">
                <thead class="table-light"><tr><th>Campaign</th><th>Category</th><th>Trigger</th><th>Audience</th><th>Frequency</th><th>Priority</th><th>Status</th><th>Daily Limit</th><th class="action-cell">Actions</th></tr></thead>
                <tbody>
                @forelse($campaigns as $campaign)
                    <tr>
                        <td><div class="fw-semibold">{{ $campaign->name }}</div><div class="mono-code">{{ $campaign->code }}</div>@if($campaign->title_template)<div class="small text-truncate campaign-title-preview" title="{{ $campaign->title_template }}">{{ $campaign->title_template }}</div>@endif</td>
                        <td><span class="badge text-bg-light border soft-badge">{{ $categoryLabel($campaign->category) }}</span></td>
                        <td><span title="{{ $trigger($campaign->trigger_type) }}">{{ Str::limit($trigger($campaign->trigger_type), 46) }}</span></td>
                        <td>{{ Str::limit($audience($campaign->audience_type), 52) }}</td>
                        <td><span class="badge text-bg-info soft-badge">{{ $freq($campaign->frequency) }}</span></td>
                        <td><span class="badge bg-{{ notification_admin_priority_badge($campaign->priority) }} soft-badge">{{ $human($campaign->priority) }}</span></td>
                        <td><span class="badge bg-{{ $statusClass($campaign) }} soft-badge">{{ $statusLabel($campaign) }}</span></td>
                        <td>{{ $campaign->daily_limit ?? '—' }}</td>
                        <td class="text-nowrap action-cell">
                            <div class="d-flex align-items-center gap-1 flex-wrap">
                                <button type="button" class="btn btn-sm btn-outline-primary action-btn" data-bs-toggle="modal" data-bs-target="#details-{{ $campaign->id }}">View Details</button>
                                <a href="{{ route('admin.notifications.campaigns.edit', $campaign->id) }}" class="btn btn-sm btn-outline-secondary action-btn">Edit</a>
                                <form method="POST" action="{{ route('admin.notifications.campaigns.toggle', $campaign->id) }}" class="d-inline m-0">@csrf @method('PATCH')<button type="submit" class="btn btn-sm {{ $campaign->is_active ? 'btn-outline-warning' : 'btn-outline-success' }} action-btn">{{ $campaign->is_active ? 'Disable' : 'Enable' }}</button></form>
                                <form method="POST" action="{{ route('admin.notifications.campaigns.run', $campaign->id) }}" class="d-inline m-0" onsubmit="return confirm('Run this campaign now?');">@csrf<button type="submit" class="btn btn-sm btn-outline-success action-btn">Run</button></form>
                                <a href="{{ route('admin.notifications.send-test', ['type' => $campaign->code, 'title' => $campaign->title_template, 'body' => $campaign->body_template, 'screen' => $campaign->tap_screen]) }}" class="btn btn-sm btn-outline-info action-btn">Test Send</a>
                            </div>
                            <div class="modal fade" id="details-{{ $campaign->id }}" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content border-0"><div class="modal-header"><div><h5 class="modal-title">{{ $campaign->name }}</h5><div class="mono-code">{{ $campaign->code }}</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-4"><div class="col-lg-4"><div class="push-preview"><div class="small mb-2 opacity-75">Message Preview</div><div class="push-bubble"><div class="d-flex gap-2 align-items-center mb-2"><div class="push-app">PG</div><div><div class="fw-bold">PeersGlobal</div><div class="small text-muted">now</div></div></div><div class="fw-semibold">{{ $campaign->title_template ?: 'Notification title' }}</div><div class="small text-muted mt-1">{{ $campaign->body_template ?: 'Notification body will appear here.' }}</div><div class="small text-primary mt-2">Tap opens: {{ $campaign->tap_screen ?: '—' }}</div></div></div></div><div class="col-lg-8"><div class="detail-grid"><div class="detail-box"><h6>A. Message Preview</h6><div class="fw-semibold">{{ $campaign->title_template ?: '—' }}</div><p class="text-muted mb-0">{{ $campaign->body_template ?: '—' }}</p></div><div class="detail-box"><h6>B. Automation Rule</h6><p class="mb-1">{{ $trigger($campaign->trigger_type) }}</p><span class="badge text-bg-info">{{ $freq($campaign->frequency) }}</span></div><div class="detail-box"><h6>C. Audience</h6><p class="mb-0">{{ $audience($campaign->audience_type) }}</p></div><div class="detail-box"><h6>D. Business Purpose</h6><p class="mb-0">{{ $campaign->description ?: data_get($campaign->config, 'business_value', '—') }}</p></div><div class="detail-box"><h6>E. App Navigation</h6><p class="mb-0">{{ $campaign->tap_screen ?: data_get($campaign->config, 'tap_destination', '—') }}</p></div><div class="detail-box"><h6>F. Delivery Settings</h6><div class="small text-muted">Channel</div><div class="mb-2">{{ $channel($campaign->channel) }}</div><div class="small text-muted">Priority / Status</div><div class="mb-2"><span class="badge bg-{{ notification_admin_priority_badge($campaign->priority) }}">{{ $human($campaign->priority) }}</span> <span class="badge bg-{{ $statusClass($campaign) }}">{{ $statusLabel($campaign) }}</span></div><div class="small text-muted">Daily limit / cooldown</div><div>{{ $campaign->daily_limit ?? '—' }} / {{ $campaign->cooldown_hours ?? '—' }} hours</div></div><div class="detail-box"><h6>Placeholders Used</h6><p class="mb-0 mono-code">{{ collect(data_get($campaign->config, 'placeholders', []))->filter()->implode(', ') ?: '—' }}</p></div><div class="detail-box"><h6>Timeline</h6><div class="small">Last sent: {{ optional($campaign->last_sent_at ? \Carbon\Carbon::parse($campaign->last_sent_at) : null)->format('d M Y H:i') ?: '—' }}</div><div class="small">Created: {{ optional($campaign->created_at)->format('d M Y H:i') ?: '—' }}</div><div class="small">Updated: {{ optional($campaign->updated_at)->format('d M Y H:i') ?: '—' }}</div></div></div></div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button><a href="{{ route('admin.notifications.campaigns.edit', $campaign->id) }}" class="btn btn-primary">Edit Campaign</a></div></div></div></div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9"><div class="empty-state"><div class="display-6 text-muted mb-3"><i class="bi bi-bell-slash"></i></div><h5>No notification campaigns found</h5><p class="text-muted">Try changing filters or seed default campaigns.</p><a href="{{ route('admin.notifications.campaigns') }}" class="btn btn-outline-primary">Clear Filters</a></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $campaigns->links() }}</div>
</div>
@endsection

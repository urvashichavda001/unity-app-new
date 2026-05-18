@extends('admin.layouts.app')

@section('title', 'Campaign Report')

@section('content')
    @include('admin.campaigns.partials.flash')
    @php $badge = fn ($status) => match ($status) { 'sent' => 'success', 'failed' => 'danger', 'partially_sent' => 'warning', default => 'secondary' }; @endphp

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-0">{{ $campaign->title }}</h1>
            <div class="text-muted small">Campaign Detail / Report</div>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.campaigns.index') }}" class="btn btn-outline-secondary">Back</a>
            @if ($campaign->isEditable())
                <a href="{{ route('admin.campaigns.edit', $campaign) }}" class="btn btn-outline-primary">Edit</a>
                <form method="POST" action="{{ route('admin.campaigns.send', $campaign) }}" onsubmit="return confirm('Send this campaign now? This cannot be undone.');">
                    @csrf
                    <button class="btn btn-success">Send Campaign</button>
                </form>
            @endif
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-4"><div class="card shadow-sm h-100"><div class="card-body">
            <h2 class="h6">Campaign Details</h2>
            <dl class="row mb-0 small">
                <dt class="col-5">Type</dt><dd class="col-7">{{ Str::headline($campaign->campaign_type) }}</dd>
                <dt class="col-5">Audience</dt><dd class="col-7">{{ Str::headline($campaign->audience_type) }}</dd>
                <dt class="col-5">Status</dt><dd class="col-7"><span class="badge bg-{{ $badge($campaign->status) }}">{{ Str::headline($campaign->status) }}</span></dd>
                <dt class="col-5">Sent At</dt><dd class="col-7">{{ optional($campaign->sent_at)->format('d M Y H:i') ?? '-' }}</dd>
                <dt class="col-5">Created At</dt><dd class="col-7">{{ optional($campaign->created_at)->format('d M Y H:i') ?? '-' }}</dd>
            </dl>
        </div></div></div>
        <div class="col-lg-4"><div class="card shadow-sm h-100"><div class="card-body">
            <h2 class="h6">Totals</h2>
            <div class="row text-center g-2">
                <div class="col-6"><div class="border rounded p-2"><div class="small text-muted">Recipients</div><div class="h5 mb-0">{{ number_format($campaign->total_recipients) }}</div></div></div>
                <div class="col-6"><div class="border rounded p-2"><div class="small text-muted">Emails</div><div class="h5 mb-0">{{ number_format($campaign->total_email_sent) }}</div></div></div>
                <div class="col-6"><div class="border rounded p-2"><div class="small text-muted">Notifications</div><div class="h5 mb-0">{{ number_format($campaign->total_notification_sent) }}</div></div></div>
                <div class="col-6"><div class="border rounded p-2"><div class="small text-muted">Failed</div><div class="h5 mb-0">{{ number_format($campaign->total_failed) }}</div></div></div>
            </div>
        </div></div></div>
        <div class="col-lg-4"><div class="card shadow-sm h-100"><div class="card-body">
            <h2 class="h6">Selected Filters</h2>
            @if (! empty($campaign->email_template_snapshot) || $campaign->emailTemplate)
                @php($templateName = data_get($campaign->email_template_snapshot, 'name', $campaign->emailTemplate?->name))
                @php($templateType = data_get($campaign->email_template_snapshot, 'template_type', $campaign->emailTemplate?->template_type))
                <div class="mb-2">
                    <span class="text-muted small d-block">Selected Email Template</span>
                    <div class="d-flex align-items-center gap-2">
                        <div class="campaign-template-mini campaign-template-mini-{{ $templateType }}"><span></span><span></span><span></span><span></span></div>
                        <span class="badge bg-info-subtle text-info border border-info-subtle">{{ $templateName ?? 'Simple Text' }}</span>
                    </div>
                </div>
            @endif
            @if (! empty($campaign->pamphlet_snapshot))
                <div class="mb-2">
                    <span class="text-muted small d-block">Selected Pamphlet</span>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">{{ $campaign->pamphlet_snapshot['title'] ?? $campaign->pamphlet_id }}</span>
                    @if (! empty($campaign->pamphlet_snapshot['image_url']))
                        <div class="small mt-1 text-break">Image: {{ $campaign->pamphlet_snapshot['image_url'] }}</div>
                    @endif
                </div>
            @endif
            @if (! empty($filterSummary['business_categories']))
                <div class="mb-2">
                    <span class="text-muted small d-block">Business Categories</span>
                    @foreach ($filterSummary['business_categories'] as $categoryName)
                        <span class="badge bg-light text-dark border me-1 mb-1">{{ $categoryName }}</span>
                    @endforeach
                </div>
            @endif
            <pre class="bg-light border rounded p-2 small mb-0" style="white-space:pre-wrap;">{{ json_encode($campaign->filters ?: [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </div></div></div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header"><strong>Recipient Logs</strong></div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Member Name</th><th>Email</th><th>Email Status</th><th>Notification Status</th><th class="campaign-error-column">Error Message</th><th>Sent At</th></tr></thead>
                <tbody>
                @forelse ($recipients as $recipient)
                    <tr>
                        <td>{{ $recipient->user?->adminDisplayName() ?? $recipient->user_id }}</td>
                        <td>{{ $recipient->email ?? '-' }}</td>
                        <td><span class="badge bg-{{ $badge($recipient->email_status) }}">{{ Str::headline($recipient->email_status) }}</span></td>
                        <td><span class="badge bg-{{ $badge($recipient->notification_status) }}">{{ Str::headline($recipient->notification_status) }}</span></td>
                        <td class="text-danger small campaign-error-column">{{ $recipient->error_message ?? '-' }}</td>
                        <td>{{ optional($recipient->sent_at)->format('d M Y H:i') ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No recipient logs yet. Logs appear after sending.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $recipients->links() }}</div>
@endsection


@push('styles')
    <style>
        .campaign-error-column {
            max-width: 360px;
            white-space: normal;
            word-break: break-word;
        }
        .campaign-template-mini {
            width: 52px;
            height: 38px;
            border: 1px solid #dbe3ef;
            border-radius: 8px;
            background: #f8fafc;
            padding: 4px;
            display: grid;
            gap: 3px;
        }
        .campaign-template-mini span {
            display: block;
            border-radius: 4px;
            background: #dbeafe;
        }
        .campaign-template-mini-blank span { display: none; }
        .campaign-template-mini-one_two_column,
        .campaign-template-mini-one_two_column_alternate,
        .campaign-template-mini-one_two_one_two_column { grid-template-columns: 1fr 1fr; }
        .campaign-template-mini-one_three_column { grid-template-columns: repeat(3, 1fr); }
    </style>
@endpush

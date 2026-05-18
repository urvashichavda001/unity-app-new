@extends('admin.layouts.app')

@section('title', 'Campaign Dashboard')

@section('content')
    @php
        $badge = fn ($status) => match ($status) {
            'sent' => 'success', 'failed' => 'danger', 'partially_sent' => 'warning', default => 'secondary',
        };
    @endphp

    @include('admin.campaigns.partials.flash')

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-0">Notifications &amp; Email Campaigns</h1>
            <div class="text-muted small">Campaign Dashboard</div>
        </div>
        <a href="{{ route('admin.campaigns.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Create Campaign</a>
    </div>

    <div class="row g-3 mb-4">
        @foreach ([
            ['label' => 'Total Campaigns', 'value' => $stats['total'], 'class' => 'primary'],
            ['label' => 'Draft Campaigns', 'value' => $stats['draft'], 'class' => 'secondary'],
            ['label' => 'Sent Campaigns', 'value' => $stats['sent'], 'class' => 'success'],
            ['label' => 'Failed Campaigns', 'value' => $stats['failed'], 'class' => 'danger'],
            ['label' => 'Total Emails Sent', 'value' => $stats['emails_sent'], 'class' => 'info'],
            ['label' => 'Total Notifications Sent', 'value' => $stats['notifications_sent'], 'class' => 'warning'],
        ] as $card)
            <div class="col-md-4 col-xl-2">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <div class="small text-muted">{{ $card['label'] }}</div>
                        <div class="h4 mb-0 text-{{ $card['class'] }}">{{ number_format($card['value']) }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Campaign Title</th>
                    <th>Type</th>
                    <th>Audience</th>
                    <th>Total Recipients</th>
                    <th>Email Sent</th>
                    <th>Notification Sent</th>
                    <th>Failed</th>
                    <th>Status</th>
                    <th>Sent At</th>
                    <th>Created At</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($campaigns as $campaign)
                    <tr>
                        <td class="fw-semibold">{{ $campaign->title }}</td>
                        <td>{{ Str::headline($campaign->campaign_type) }}</td>
                        <td>{{ Str::headline($campaign->audience_type) }}</td>
                        <td>{{ number_format($campaign->total_recipients) }}</td>
                        <td>{{ number_format($campaign->total_email_sent) }}</td>
                        <td>{{ number_format($campaign->total_notification_sent) }}</td>
                        <td>{{ number_format($campaign->total_failed) }}</td>
                        <td><span class="badge bg-{{ $badge($campaign->status) }}">{{ Str::headline($campaign->status) }}</span></td>
                        <td>{{ optional($campaign->sent_at)->format('d M Y H:i') ?? '-' }}</td>
                        <td>{{ optional($campaign->created_at)->format('d M Y H:i') ?? '-' }}</td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <a class="btn btn-outline-primary" href="{{ route('admin.campaigns.show', $campaign) }}">View</a>
                                @if ($campaign->isEditable())
                                    <a class="btn btn-outline-secondary" href="{{ route('admin.campaigns.edit', $campaign) }}">Edit</a>
                                    <form method="POST" action="{{ route('admin.campaigns.send', $campaign) }}" onsubmit="return confirm('Send this campaign now? This cannot be undone.');">
                                        @csrf
                                        <button class="btn btn-outline-success">Send</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="text-center text-muted py-4">No campaigns yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $campaigns->links() }}</div>
@endsection

@extends('admin.layouts.app')

@section('title', 'Referral Report')

@section('content')
<div class="bg-white border rounded-4 shadow-sm p-3 p-lg-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1">Referral Users / Referral Report</h1>
            <p class="text-muted mb-0">See which peer referred how many users and how many referral coins were granted.</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="badge bg-light text-dark border">Total Referrers: {{ number_format($records->total()) }}</span>
            <a href="{{ route('admin.referral-report.export', request()->query()) }}" class="btn btn-success btn-sm">
                <i class="bi bi-download me-1"></i>Export CSV
            </a>
        </div>
    </div>

    <form id="referralReportFilters" method="GET" action="{{ route('admin.referral-report.index') }}"></form>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div class="d-flex gap-2 align-items-center">
            <label for="perPage" class="small text-muted mb-0">Show</label>
            <select id="perPage" name="per_page" form="referralReportFilters" class="form-select form-select-sm" style="width: 90px;">
                @foreach ([10, 20, 50, 100] as $size)
                    <option value="{{ $size }}" @selected(($filters['per_page'] ?? 20) == $size)>{{ $size }}</option>
                @endforeach
            </select>
        </div>
        <div class="small text-muted">
            @if($records->total() > 0)
                Records {{ $records->firstItem() }} to {{ $records->lastItem() }} of {{ $records->total() }}
            @else
                No records found
            @endif
        </div>
    </div>

    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="table-light">
                <tr>
                    <th>Referrer</th>
                    <th>Referral Code</th>
                    <th class="text-center">Total Users</th>
                    <th class="text-center">Coins Granted</th>
                    <th>Last Referral Date</th>
                    <th class="text-end">Action</th>
                </tr>
                <tr class="bg-light align-middle">
                    <th>
                        <input
                            type="text"
                            name="q"
                            form="referralReportFilters"
                            value="{{ $filters['q'] ?? '' }}"
                            class="form-control form-control-sm"
                            placeholder="Name, email, phone, code"
                        >
                    </th>
                    <th>
                        <div class="d-flex gap-2">
                            <input type="date" name="from" form="referralReportFilters" value="{{ $filters['from'] ?? '' }}" class="form-control form-control-sm" title="From referral date">
                            <input type="date" name="to" form="referralReportFilters" value="{{ $filters['to'] ?? '' }}" class="form-control form-control-sm" title="To referral date">
                        </div>
                    </th>
                    <th>
                        <select name="sort" form="referralReportFilters" class="form-select form-select-sm">
                            <option value="last_referral_date" @selected(($filters['sort'] ?? '') === 'last_referral_date')>Sort: Last Referral</option>
                            <option value="total_referred_users" @selected(($filters['sort'] ?? '') === 'total_referred_users')>Sort: Total Users</option>
                        </select>
                    </th>
                    <th>
                        <select name="reward_status" form="referralReportFilters" class="form-select form-select-sm" @disabled(! $hasRewardStatus)>
                            <option value="">All Statuses</option>
                            @foreach (['granted' => 'Granted', 'pending' => 'Pending', 'failed' => 'Failed'] as $value => $label)
                                <option value="{{ $value }}" @selected(($filters['reward_status'] ?? '') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </th>
                    <th>
                        <select name="direction" form="referralReportFilters" class="form-select form-select-sm">
                            <option value="desc" @selected(($filters['direction'] ?? 'desc') === 'desc')>Descending</option>
                            <option value="asc" @selected(($filters['direction'] ?? 'desc') === 'asc')>Ascending</option>
                        </select>
                    </th>
                    <th class="text-end">
                        <button type="submit" form="referralReportFilters" class="btn btn-primary btn-sm">Apply</button>
                        <a href="{{ route('admin.referral-report.index') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
                    </th>
                </tr>
            </thead>
            <tbody>
                @forelse ($records as $record)
                    <tr>
                        <td>
                            <div class="fw-semibold text-dark">{{ $record->referrer_name ?: 'Deleted / Unknown User' }}</div>
                            <div class="text-muted small">{{ $record->referrer_email ?: 'No email' }}</div>
                            <div class="text-muted small">{{ $record->referrer_phone ?: 'No phone' }}</div>
                            <div class="text-muted small">{{ $record->referrer_company ?: 'No company' }}</div>
                        </td>
                        <td>
                            <span class="badge bg-light text-dark border text-wrap">{{ $record->referral_codes ?: '—' }}</span>
                        </td>
                        <td class="text-center fw-semibold">{{ number_format((int) $record->total_referred_users) }}</td>
                        <td class="text-center">
                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                {{ number_format((int) $record->total_coins_granted) }} coins
                            </span>
                        </td>
                        <td>{{ $record->last_referral_date ? \Illuminate\Support\Carbon::parse($record->last_referral_date)->format('d-m-Y h:i A') : '—' }}</td>
                        <td class="text-end">
                            @if($record->referrer_user_id)
                                <a href="{{ route('admin.referral-report.show', $record->referrer_user_id) }}" class="btn btn-outline-primary btn-sm">
                                    View Users
                                </a>
                            @else
                                <span class="text-muted small">No referrer ID</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No referral users found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-2">
        {{ $records->links() }}
    </div>
</div>
@endsection

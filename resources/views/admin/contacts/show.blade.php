@extends('admin.layouts.app')

@section('title', 'Contact Detail')

@section('content')
@push('styles')
    <style>
        .detail-section {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            margin-top: 16px;
        }

        .detail-group {
            margin-top: 18px;
        }

        .detail-group h4 {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .detail-line {
            display: flex;
            gap: 8px;
            padding: 8px 0;
            border-bottom: 1px solid #eef0f3;
            line-height: 1.5;
        }

        .detail-line:last-child {
            border-bottom: none;
        }

        .detail-line strong {
            min-width: 110px;
            font-weight: 600;
            color: #111827;
        }

        .detail-line span,
        .detail-line a {
            color: #374151;
            word-break: break-word;
        }
    </style>
@endpush
@php
    $displayValue = fn ($value) => filled($value) ? $value : '—';
    $detailFilters = $detailFilters ?? [];
    $selectedDataType = $detailFilters['data_type'] ?? 'all';
    $filterActive = filled($detailFilters['search'] ?? null)
        || ! in_array($selectedDataType, ['all', ''], true)
        || filled($detailFilters['from_date'] ?? null)
        || filled($detailFilters['to_date'] ?? null)
        || ! in_array($detailFilters['quick'] ?? 'any', ['any', ''], true);
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
    <div>
        <p class="text-muted mb-1">Contacts</p>
        <h1 class="h4 mb-0">Contact Detail</h1>
    </div>
    <div class="d-flex flex-wrap gap-2 justify-content-end">
        <a href="{{ route('admin.contacts.index') }}" class="btn btn-outline-secondary">Back to Contacts</a>
        <a href="{{ route('admin.contacts.import') }}" class="btn btn-primary">Import CSV</a>
        <a href="{{ route('admin.contacts.show.export', array_merge(['id' => $contactPost->id], request()->query())) }}" class="btn btn-outline-primary">Export CSV</a>
    </div>
</div>

<div class="card p-3 mb-3">
    <form method="GET" action="{{ route('admin.contacts.show', $contactPost->id) }}" class="row g-3 align-items-end">
        <div class="col-12 col-xl-3">
            <label for="search" class="form-label">Search</label>
            <input type="text" id="search" name="search" value="{{ $detailFilters['search'] ?? '' }}" class="form-control" placeholder="Search email / phone / address / notes">
        </div>
        <div class="col-sm-6 col-xl-2">
            <label for="data_type" class="form-label">Data Type</label>
            <select id="data_type" name="data_type" class="form-select">
                <option value="all" @selected(($detailFilters['data_type'] ?? 'all') === 'all')>All</option>
                <option value="emails" @selected(($detailFilters['data_type'] ?? 'all') === 'emails')>Email Details</option>
                <option value="phones" @selected(($detailFilters['data_type'] ?? 'all') === 'phones')>Phone Details</option>
                <option value="addresses" @selected(($detailFilters['data_type'] ?? 'all') === 'addresses')>Address Details</option>
                <option value="notes" @selected(($detailFilters['data_type'] ?? 'all') === 'notes')>Notes</option>
            </select>
        </div>
        <div class="col-sm-6 col-xl-2">
            <label for="from_date" class="form-label">Date From</label>
            <input type="date" id="from_date" name="from_date" value="{{ $detailFilters['from_date'] ?? '' }}" class="form-control">
        </div>
        <div class="col-sm-6 col-xl-2">
            <label for="to_date" class="form-label">Date To</label>
            <input type="date" id="to_date" name="to_date" value="{{ $detailFilters['to_date'] ?? '' }}" class="form-control">
        </div>
        <div class="col-sm-6 col-xl-2">
            <label for="quick" class="form-label">Quick</label>
            <select id="quick" name="quick" class="form-select">
                <option value="any" @selected(($detailFilters['quick'] ?? 'any') === 'any')>Any</option>
                <option value="today" @selected(($detailFilters['quick'] ?? 'any') === 'today')>Today</option>
                <option value="this_week" @selected(($detailFilters['quick'] ?? 'any') === 'this_week')>This Week</option>
                <option value="this_month" @selected(($detailFilters['quick'] ?? 'any') === 'this_month')>This Month</option>
            </select>
        </div>
        <div class="col-12 col-xl-1 d-flex gap-2">
            <button type="submit" class="btn btn-primary flex-fill">Filter</button>
        </div>
        <div class="col-12 d-flex justify-content-end">
            <a href="{{ route('admin.contacts.show', $contactPost->id) }}" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>
</div>

@if ($filterActive && ! ($hasMatchingContactDetails ?? true))
    <div class="alert alert-info">No matching contact details found.</div>
@endif

@if ($showBasicSection ?? true)
<div class="card p-4 mb-3">
    <h2 class="h6 mb-3">Basic Contact Information</h2>
    <div class="row g-3">
        @foreach ([
            'Full Name' => $contactPost->full_name,
            'First Name' => $contactPost->first_name,
            'Middle Name' => $contactPost->middle_name,
            'Last Name' => $contactPost->last_name,
            'Email' => $contactPost->email,
            'Phone' => $contactPost->phone,
            'Company' => $contactPost->company,
            'Job Title' => $contactPost->job_title,
            'Nickname' => $contactPost->nickname,
        ] as $label => $value)
            <div class="col-md-6 col-xl-4">
                <div class="p-3 rounded border bg-white h-100">
                    <p class="text-muted small mb-1">{{ $label }}</p>
                    <div class="fw-semibold">{{ $displayValue($value) }}</div>
                </div>
            </div>
        @endforeach
    </div>
</div>
@endif

@if ($showNotesSection ?? true)
<div class="card p-4 mb-3">
    <h2 class="h6 mb-3">Notes</h2>
    <div class="p-3 rounded border bg-white">{{ $displayValue($contactPost->notes) }}</div>
</div>
@endif

@if ($showDateSection ?? true)
<div class="card p-4 mb-3">
    <h2 class="h6 mb-3">Date Information</h2>
    <div class="row g-3">
        @foreach ([
            'Created At' => optional($contactPost->created_at)->format('d M Y, h:i A'),
            'Updated At' => optional($contactPost->updated_at)->format('d M Y, h:i A'),
        ] as $label => $value)
            <div class="col-md-6">
                <div class="p-3 rounded border bg-white h-100">
                    <p class="text-muted small mb-1">{{ $label }}</p>
                    <div class="fw-semibold">{{ $displayValue($value) }}</div>
                </div>
            </div>
        @endforeach
    </div>
</div>
@endif


<div class="card p-4 mb-3">
    <h2 class="h6 mb-3">Linked User Details</h2>
    @if ($contactPost->user)
        @php
            $linkedUser = $contactPost->user;
            $linkedUserName = $linkedUser->display_name
                ?: trim(collect([$linkedUser->first_name ?? null, $linkedUser->last_name ?? null])->filter()->implode(' '));
            $linkedUserPhone = $linkedUser->phone ?? $linkedUser->mobile ?? $linkedUser->secondary_mobile ?? null;
            $linkedUserMembership = $linkedUser->membership_status ?? $linkedUser->membership_type ?? null;
        @endphp
        <div class="row g-3">
            @foreach ([
                'User ID' => $linkedUser->id,
                'User Name' => $linkedUserName,
                'User Email' => $linkedUser->email,
                'User Phone' => $linkedUserPhone,
                'Membership' => $linkedUserMembership,
                'Status' => $linkedUser->status,
            ] as $label => $value)
                <div class="col-md-6 col-xl-4">
                    <div class="p-3 rounded border bg-white h-100">
                        <p class="text-muted small mb-1">{{ $label }}</p>
                        <div class="fw-semibold text-break">{{ $displayValue($value) }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <p class="text-muted mb-0">No linked user found.</p>
    @endif
</div>

@if ($selectedDataType !== 'notes')
<section class="detail-section">
    <h2 class="h6 mb-0">Additional Contact Details</h2>

    @if (in_array($selectedDataType, ['all', 'emails'], true))
    <div class="detail-group">
        <h4>Email Details</h4>
        @if (($filteredEmails ?? []) === [])
            <p class="text-muted mb-0">No email details available.</p>
        @else
            @foreach (($filteredEmails ?? []) as $emailDetail)
                <div class="detail-line">
                    <strong>{{ $emailDetail['type'] }}:</strong>
                    <a href="mailto:{{ $emailDetail['value'] }}">{{ $emailDetail['value'] }}</a>
                </div>
            @endforeach
        @endif
    </div>
@endif

@if (in_array($selectedDataType, ['all', 'phones'], true))
    <div class="detail-group">
        <h4>Phone Details</h4>
        @if (($filteredPhones ?? []) === [])
            <p class="text-muted mb-0">No phone details available.</p>
        @else
            @foreach (($filteredPhones ?? []) as $phoneDetail)
                <div class="detail-line">
                    <strong>{{ $phoneDetail['type'] }}:</strong>
                    <span>{{ $phoneDetail['value'] }}</span>
                </div>
            @endforeach
        @endif
    </div>
@endif

@if (in_array($selectedDataType, ['all', 'addresses'], true))
    <div class="detail-group">
        <h4>Address Details</h4>
        @if (($filteredAddresses ?? []) === [])
            <p class="text-muted mb-0">No address details available.</p>
        @else
            @foreach (($filteredAddresses ?? []) as $addressDetail)
                <div class="detail-line">
                    <strong>{{ $addressDetail['type'] }}:</strong>
                    <span>{{ $addressDetail['value'] }}</span>
                </div>
            @endforeach
        @endif
    </div>
@endif
</section>
@endif
@endsection

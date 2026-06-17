@extends('admin.layouts.app')

@section('title', 'Contacts')

@section('content')
@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
    <div>
        <h1 class="h4 mb-1">Contacts</h1>
        <p class="text-muted mb-0">Manage imported and submitted contact details.</p>
    </div>
</div>

<style>
    .contacts-filter-card {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 16px;
        width: 100%;
        max-width: 100%;
        overflow: visible;
    }

    .contacts-filter-row {
        display: flex;
        flex-wrap: wrap;
        gap: 14px;
        align-items: flex-end;
        width: 100%;
    }

    .filter-item {
        display: flex;
        flex-direction: column;
        min-width: 150px;
        flex: 1 1 150px;
    }

    .search-item {
        min-width: 230px;
        flex: 1.8 1 230px;
    }

    .date-item {
        min-width: 145px;
        flex: 1 1 145px;
    }

    .quick-item {
        min-width: 120px;
        max-width: 140px;
        flex: 0 0 130px;
    }

    .filter-item label {
        font-size: 14px;
        font-weight: 500;
        color: #111827;
        margin-bottom: 8px;
    }

    .filter-item input,
    .filter-item select {
        width: 100%;
        height: 38px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        padding: 8px 12px;
        background: #ffffff;
        font-size: 14px;
    }

    .filter-buttons {
        display: flex;
        gap: 8px;
        align-items: flex-end;
        flex: 0 0 auto;
        margin-left: auto;
    }

    .filter-buttons .btn {
        height: 38px;
        padding: 8px 14px;
        white-space: nowrap;
    }

    @media (max-width: 1200px) {
        .filter-buttons {
            margin-left: 0;
        }
    }

    @media (max-width: 768px) {
        .contacts-filter-row {
            gap: 12px;
        }

        .filter-item,
        .search-item,
        .date-item,
        .quick-item {
            min-width: 100%;
            max-width: 100%;
            flex: 1 1 100%;
        }

        .filter-buttons {
            width: 100%;
            justify-content: flex-start;
        }
    }
</style>

<div class="contacts-filter-card">
    <form method="GET" action="{{ route('admin.contacts.index') }}">
        <div class="contacts-filter-row">
            <div class="filter-item search-item">
                <label for="search">Search</label>
                <input type="text" id="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Name/Email/Phone/Company">
            </div>
            <div class="filter-item">
                <label for="company">Company</label>
                <select id="company" name="company">
                    <option value="">All Companies</option>
                    @foreach ($companies as $company)
                        <option value="{{ $company }}" @selected(($filters['company'] ?? '') === $company)>{{ $company }}</option>
                    @endforeach
                </select>
            </div>
            <div class="filter-item">
                <label for="job_title">Job Title</label>
                <select id="job_title" name="job_title">
                    <option value="">All Job Titles</option>
                    @foreach ($jobTitles as $jobTitle)
                        <option value="{{ $jobTitle }}" @selected(($filters['job_title'] ?? '') === $jobTitle)>{{ $jobTitle }}</option>
                    @endforeach
                </select>
            </div>
            <div class="filter-item date-item">
                <label for="from_date">Date From</label>
                <input type="date" id="from_date" name="from_date" value="{{ $filters['from_date'] ?? '' }}">
            </div>
            <div class="filter-item date-item">
                <label for="to_date">Date To</label>
                <input type="date" id="to_date" name="to_date" value="{{ $filters['to_date'] ?? '' }}">
            </div>
            <div class="filter-item quick-item">
                <label for="quick">Quick</label>
                <select id="quick" name="quick">
                    <option value="any" @selected(($filters['quick'] ?? 'any') === 'any')>Any</option>
                    <option value="today" @selected(($filters['quick'] ?? 'any') === 'today')>Today</option>
                    <option value="this_week" @selected(($filters['quick'] ?? 'any') === 'this_week')>This Week</option>
                    <option value="this_month" @selected(($filters['quick'] ?? 'any') === 'this_month')>This Month</option>
                </select>
            </div>
            <div class="filter-buttons">
                <button type="submit" class="btn btn-primary">Filter</button>
                <a href="{{ route('admin.contacts.index') }}" class="btn btn-outline-secondary">Reset</a>
            </div>
        </div>
    </form>
</div>

<div class="card p-0 overflow-hidden">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Contact Name</th>
                    <th>Phone</th>
                    <th style="min-width: 180px;">Company</th>
                    <th style="min-width: 180px;">Job Title</th>
                    <th>Email</th>
                    <th>Total Contacts</th>
                    <th>Latest Date</th>
                    <th class="text-end pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($contactPosts as $contactPost)
                    <tr>
                        <td class="ps-3 fw-semibold">{{ $contactPost->full_name ?: trim(collect([$contactPost->first_name, $contactPost->middle_name, $contactPost->last_name])->filter()->implode(' ')) ?: '—' }}</td>
                        <td>{{ $contactPost->phone ?: '—' }}</td>
                        <td class="text-wrap">{{ $contactPost->company ?: '—' }}</td>
                        <td class="text-wrap">{{ $contactPost->job_title ?: '—' }}</td>
                        <td class="text-break">{{ $contactPost->email ?: '—' }}</td>
                        <td><span class="badge bg-light text-dark">{{ number_format($contactPost->total_contacts ?? 0) }}</span></td>
                        <td>{{ optional($contactPost->latest_created_at ? \Illuminate\Support\Carbon::parse($contactPost->latest_created_at) : null)->format('d M Y, h:i A') ?: '—' }}</td>
                        <td class="text-end pe-3">
                            <a href="{{ route('admin.contacts.user-details', $contactPost->user_id) }}" class="btn btn-sm btn-outline-primary">View Details</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">No contacts found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-3 border-top">
        {{ $contactPosts->links() }}
    </div>
</div>
@endsection

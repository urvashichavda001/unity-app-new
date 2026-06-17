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
    .contacts-filters-card {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 16px;
    }

    .contacts-filters-grid {
        display: grid;
        grid-template-columns: minmax(220px, 2fr) minmax(160px, 1.3fr) minmax(160px, 1.3fr) minmax(145px, 1.1fr) minmax(145px, 1.1fr) minmax(130px, 0.9fr) auto;
        gap: 14px;
        align-items: end;
    }

    .contacts-filter-field label {
        display: block;
        font-size: 14px;
        font-weight: 500;
        color: #111827;
        margin-bottom: 8px;
    }

    .contacts-filter-field input,
    .contacts-filter-field select {
        width: 100%;
        height: 38px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        padding: 8px 12px;
        background: #ffffff;
    }

    .contacts-filter-actions {
        display: flex;
        gap: 8px;
        align-items: center;
        justify-content: flex-end;
        white-space: nowrap;
    }

    .contacts-filter-actions .btn {
        height: 38px;
        padding: 8px 14px;
    }

    @media (max-width: 1200px) {
        .contacts-filters-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .contacts-filter-actions {
            justify-content: flex-start;
        }
    }

    @media (max-width: 768px) {
        .contacts-filters-grid {
            grid-template-columns: 1fr;
        }

        .contacts-filter-actions {
            width: 100%;
            justify-content: flex-start;
        }
    }
</style>

<div class="contacts-filters-card">
    <form method="GET" action="{{ route('admin.contacts.index') }}">
        <div class="contacts-filters-grid">
            <div class="contacts-filter-field">
                <label for="search">Search</label>
                <input type="text" id="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Name/Email/Phone/Company">
            </div>
            <div class="contacts-filter-field">
                <label for="company">Company</label>
                <select id="company" name="company">
                    <option value="">All Companies</option>
                    @foreach ($companies as $company)
                        <option value="{{ $company }}" @selected(($filters['company'] ?? '') === $company)>{{ $company }}</option>
                    @endforeach
                </select>
            </div>
            <div class="contacts-filter-field">
                <label for="job_title">Job Title</label>
                <select id="job_title" name="job_title">
                    <option value="">All Job Titles</option>
                    @foreach ($jobTitles as $jobTitle)
                        <option value="{{ $jobTitle }}" @selected(($filters['job_title'] ?? '') === $jobTitle)>{{ $jobTitle }}</option>
                    @endforeach
                </select>
            </div>
            <div class="contacts-filter-field">
                <label for="from_date">Date From</label>
                <input type="date" id="from_date" name="from_date" value="{{ $filters['from_date'] ?? '' }}">
            </div>
            <div class="contacts-filter-field">
                <label for="to_date">Date To</label>
                <input type="date" id="to_date" name="to_date" value="{{ $filters['to_date'] ?? '' }}">
            </div>
            <div class="contacts-filter-field">
                <label for="quick">Quick</label>
                <select id="quick" name="quick">
                    <option value="any" @selected(($filters['quick'] ?? 'any') === 'any')>Any</option>
                    <option value="today" @selected(($filters['quick'] ?? 'any') === 'today')>Today</option>
                    <option value="this_week" @selected(($filters['quick'] ?? 'any') === 'this_week')>This Week</option>
                    <option value="this_month" @selected(($filters['quick'] ?? 'any') === 'this_month')>This Month</option>
                </select>
            </div>
            <div class="contacts-filter-actions">
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

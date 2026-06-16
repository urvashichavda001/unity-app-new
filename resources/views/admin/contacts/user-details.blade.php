@extends('admin.layouts.app')

@section('title', 'User Contact Details')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
    <div>
        <h1 class="h4 mb-1">User Contact Details</h1>
        <p class="text-muted mb-0">All contacts saved by this selected user.</p>
    </div>
    <div class="d-flex flex-wrap gap-2 justify-content-end">
        <a href="{{ route('admin.contacts.index') }}" class="btn btn-outline-secondary">Back to Contacts</a>
        <a href="{{ route('admin.contacts.import') }}" class="btn btn-primary">Import CSV</a>
        <a href="{{ route('admin.contacts.user-details.export', array_merge(['user_id' => $userId], request()->query())) }}" class="btn btn-outline-primary">Export CSV</a>
    </div>
</div>

<div class="card p-4 mb-3">
    <h2 class="h6 mb-3">Selected User</h2>
    @if ($user)
        @php
            $userName = $user->display_name ?: trim(collect([$user->first_name ?? null, $user->last_name ?? null])->filter()->implode(' '));
            $userPhone = $user->phone ?? $user->mobile ?? $user->secondary_mobile ?? null;
        @endphp
        <div class="row g-3">
            @foreach ([
                'User ID' => $user->id,
                'User Name' => $userName,
                'User Email' => $user->email,
                'User Phone' => $userPhone,
            ] as $label => $value)
                <div class="col-md-6 col-xl-3">
                    <div class="p-3 rounded border bg-white h-100">
                        <p class="text-muted small mb-1">{{ $label }}</p>
                        <div class="fw-semibold text-break">{{ filled($value) ? $value : '—' }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <p class="text-muted mb-0">Selected User ID: {{ $userId }}</p>
    @endif
</div>

<div class="card p-3 mb-3">
    <form method="GET" action="{{ route('admin.contacts.user-details', $userId) }}" class="row g-3 align-items-end">
        <div class="col-12 col-xl-3">
            <label for="search" class="form-label">Search</label>
            <input type="text" id="search" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control" placeholder="Search Name / Email / Phone / Company">
        </div>
        <div class="col-sm-6 col-xl-2">
            <label for="company" class="form-label">Company</label>
            <select id="company" name="company" class="form-select">
                <option value="">All Companies</option>
                @foreach ($companies as $company)
                    <option value="{{ $company }}" @selected(($filters['company'] ?? '') === $company)>{{ $company }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-sm-6 col-xl-2">
            <label for="job_title" class="form-label">Job Title</label>
            <select id="job_title" name="job_title" class="form-select">
                <option value="">All Job Titles</option>
                @foreach ($jobTitles as $jobTitle)
                    <option value="{{ $jobTitle }}" @selected(($filters['job_title'] ?? '') === $jobTitle)>{{ $jobTitle }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-sm-6 col-xl-2">
            <label for="from_date" class="form-label">Date From</label>
            <input type="date" id="from_date" name="from_date" value="{{ $filters['from_date'] ?? '' }}" class="form-control">
        </div>
        <div class="col-sm-6 col-xl-2">
            <label for="to_date" class="form-label">Date To</label>
            <input type="date" id="to_date" name="to_date" value="{{ $filters['to_date'] ?? '' }}" class="form-control">
        </div>
        <div class="col-sm-6 col-xl-1">
            <label for="date_preset" class="form-label">Quick</label>
            <select id="date_preset" name="date_preset" class="form-select">
                <option value="">Any</option>
                <option value="today" @selected(($filters['date_preset'] ?? '') === 'today')>Today</option>
                <option value="this_week" @selected(($filters['date_preset'] ?? '') === 'this_week')>This Week</option>
                <option value="this_month" @selected(($filters['date_preset'] ?? '') === 'this_month')>This Month</option>
            </select>
        </div>
        <div class="col-12 d-flex justify-content-end gap-2">
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="{{ route('admin.contacts.user-details', $userId) }}" class="btn btn-outline-secondary">Reset</a>
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
                    <th>Email</th>
                    <th style="min-width: 180px;">Company</th>
                    <th style="min-width: 180px;">Job Title</th>
                    <th>Nickname</th>
                    <th>Date</th>
                    <th class="text-end pe-3">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($contacts as $contact)
                    <tr>
                        <td class="ps-3 fw-semibold">{{ $contact->full_name ?: trim(collect([$contact->first_name, $contact->middle_name, $contact->last_name])->filter()->implode(' ')) ?: '—' }}</td>
                        <td>{{ $contact->phone ?: '—' }}</td>
                        <td class="text-break">{{ $contact->email ?: '—' }}</td>
                        <td class="text-wrap">{{ $contact->company ?: '—' }}</td>
                        <td class="text-wrap">{{ $contact->job_title ?: '—' }}</td>
                        <td>{{ $contact->nickname ?: '—' }}</td>
                        <td>{{ optional($contact->created_at)->format('d M Y, h:i A') ?: '—' }}</td>
                        <td class="text-end pe-3">
                            <a href="{{ route('admin.contacts.show', $contact->id) }}" class="btn btn-sm btn-outline-primary">View Full Contact</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">No contacts found for this user.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-3 border-top">
        {{ $contacts->links() }}
    </div>
</div>
@endsection

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
        <a href="{{ route('admin.contacts.user-details.export', array_merge(['user_id' => $userId], request()->query())) }}" class="btn btn-outline-primary">Export CSV</a>
        <button type="submit" form="selected-export-form" id="export-selected-btn" class="btn btn-outline-success" disabled>Export Selected CSV</button>
        <span id="selected-count" class="align-self-center text-muted small">Selected: 0</span>
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
    <form method="GET" action="{{ route('admin.contacts.user-details', $userId) }}">
        <div class="contacts-filter-row">
            <div class="filter-item search-item">
                <label for="search">Search</label>
                <input type="text" id="search" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search Name / Email / Phone / Company">
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
                <a href="{{ route('admin.contacts.user-details', $userId) }}" class="btn btn-outline-secondary">Reset</a>
            </div>
        </div>
    </form>
</div>

<form method="POST" action="{{ route('admin.contacts.user-details.export-selected', $userId) }}" id="selected-export-form">
    @csrf
    <div class="card p-0 overflow-hidden">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-3" style="width: 42px;"><input type="checkbox" id="select-all-contacts" class="form-check-input" aria-label="Select all visible contacts"></th>
                    <th>Contact Name</th>
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
                        <td class="ps-3"><input type="checkbox" name="selected_ids[]" value="{{ $contact->id }}" class="form-check-input contact-checkbox" aria-label="Select contact {{ $contact->full_name ?: $contact->email ?: $contact->id }}"></td>
                        <td class="fw-semibold">{{ $contact->full_name ?: trim(collect([$contact->first_name, $contact->middle_name, $contact->last_name])->filter()->implode(' ')) ?: '—' }}</td>
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
                        <td colspan="9" class="text-center text-muted py-4">No contacts found for this user.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="p-3 border-top">
        {{ $contacts->links() }}
    </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const checkboxes = Array.from(document.querySelectorAll('.contact-checkbox'));
        const selectAll = document.getElementById('select-all-contacts');
        const exportBtn = document.getElementById('export-selected-btn');
        const selectedCount = document.getElementById('selected-count');

        const updateSelectionState = () => {
            const selected = checkboxes.filter((checkbox) => checkbox.checked).length;
            if (exportBtn) {
                exportBtn.disabled = selected === 0;
            }
            if (selectedCount) {
                selectedCount.textContent = `Selected: ${selected}`;
            }
            if (selectAll) {
                selectAll.checked = checkboxes.length > 0 && selected === checkboxes.length;
                selectAll.indeterminate = selected > 0 && selected < checkboxes.length;
            }
        };

        if (selectAll) {
            selectAll.addEventListener('change', () => {
                checkboxes.forEach((checkbox) => {
                    checkbox.checked = selectAll.checked;
                });
                updateSelectionState();
            });
        }

        checkboxes.forEach((checkbox) => checkbox.addEventListener('change', updateSelectionState));
        updateSelectionState();
    });
</script>
@endpush

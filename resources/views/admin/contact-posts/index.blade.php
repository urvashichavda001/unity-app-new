@extends('admin.layouts.app')

@section('title', 'Contact Posts')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <p class="text-muted mb-1">Admin</p>
        <h1 class="h4 mb-0">Contact Posts</h1>
    </div>
</div>

<div class="card p-3 mb-3">
    <form method="GET" action="{{ route('admin.contact-posts.index') }}" class="row g-3 align-items-end">
        <div class="col-12 col-lg-4">
            <label for="search" class="form-label">Search</label>
            <input type="text" id="search" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control" placeholder="Name, email, phone, company...">
        </div>
        <div class="col-sm-6 col-lg-3">
            <label for="from_date" class="form-label">From Date</label>
            <input type="date" id="from_date" name="from_date" value="{{ $filters['from_date'] ?? '' }}" class="form-control">
        </div>
        <div class="col-sm-6 col-lg-3">
            <label for="to_date" class="form-label">To Date</label>
            <input type="date" id="to_date" name="to_date" value="{{ $filters['to_date'] ?? '' }}" class="form-control">
        </div>
        <div class="col-12 col-lg-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary flex-fill">Filter</button>
            <a href="{{ route('admin.contact-posts.index') }}" class="btn btn-outline-secondary flex-fill">Reset</a>
        </div>
    </form>
</div>

<div class="card p-3">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Sr No</th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Company</th>
                    <th>Job Title</th>
                    <th>Nickname</th>
                    <th>Created Date</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($contactPosts as $contactPost)
                    <tr>
                        <td>{{ $contactPosts->firstItem() + $loop->index }}</td>
                        <td>{{ $contactPost->full_name ?: trim(collect([$contactPost->first_name, $contactPost->middle_name, $contactPost->last_name])->filter()->implode(' ')) ?: '—' }}</td>
                        <td>{{ $contactPost->email ?: '—' }}</td>
                        <td>{{ $contactPost->phone ?: '—' }}</td>
                        <td>{{ $contactPost->company ?: '—' }}</td>
                        <td>{{ $contactPost->job_title ?: '—' }}</td>
                        <td>{{ $contactPost->nickname ?: '—' }}</td>
                        <td>{{ optional($contactPost->created_at)->format('d M Y, h:i A') ?: '—' }}</td>
                        <td class="text-end">
                            <a href="{{ route('admin.contact-posts.show', $contactPost->id) }}" class="btn btn-sm btn-outline-primary">View Details</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">No contact posts found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">
        {{ $contactPosts->links() }}
    </div>
</div>
@endsection

@extends('admin.layouts.app')

@section('title', 'Campaign Pamphlets')

@section('content')
    @include('admin.campaigns.partials.flash')

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-0">Campaign Pamphlets</h1>
            <div class="text-muted small">Reusable content for campaign emails and notifications.</div>
        </div>
        <a href="{{ route('admin.campaign-pamphlets.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Pamphlet</a>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Title</th>
                    <th>Image</th>
                    <th>Short Message</th>
                    <th>Status</th>
                    <th>Updated</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($pamphlets as $pamphlet)
                    <tr>
                        <td class="fw-semibold">{{ $pamphlet->title }}</td>
                        <td>
                            @if ($pamphlet->image_url)
                                <img src="{{ $pamphlet->image_url }}" alt="{{ $pamphlet->title }}" style="width:70px;height:45px;object-fit:cover;border-radius:6px;">
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td>{{ Str::limit($pamphlet->short_message, 80) }}</td>
                        <td><span class="badge bg-{{ $pamphlet->status === 'active' ? 'success' : 'secondary' }}">{{ Str::headline($pamphlet->status) }}</span></td>
                        <td>{{ optional($pamphlet->updated_at)->format('d M Y H:i') ?? '-' }}</td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <a href="{{ route('admin.campaign-pamphlets.edit', $pamphlet) }}" class="btn btn-outline-primary">Edit</a>
                                <form method="POST" action="{{ route('admin.campaign-pamphlets.destroy', $pamphlet) }}" onsubmit="return confirm('Deactivate this pamphlet?');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-outline-danger">Deactivate</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No pamphlets found.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $pamphlets->links() }}</div>
@endsection

@extends('admin.layouts.app')

@section('title', $event->title)

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h4 mb-0">{{ $event->title }}</h1><a href="{{ route('admin.events.index') }}" class="btn btn-outline-secondary">Back</a></div>
    <div class="card mb-3"><div class="card-body">
        <div class="row g-3">
            <div class="col-md-3"><strong>Type:</strong> {{ $event->event_type }}</div>
            <div class="col-md-3"><strong>Circle:</strong> {{ $event->circle?->name ?? '-' }}</div>
            <div class="col-md-3"><strong>Mode:</strong> {{ $event->mode }}</div>
            <div class="col-md-3"><strong>Recurrence:</strong> {{ $event->recurrence_type ?? 'none' }}</div>
            <div class="col-md-6"><strong>Location:</strong> {{ $event->location_text ?? '-' }}</div>
            <div class="col-md-6"><strong>Online:</strong> {{ $event->online_meeting_url ?? '-' }}</div>
            <div class="col-12"><strong>Description:</strong> {{ $event->description ?? '-' }}</div>
        </div>
    </div></div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center"><span>Occurrences</span><a class="btn btn-sm btn-success" href="{{ route('admin.events.attendance', $event->id) }}">Attendance</a></div>
        <div class="table-responsive"><table class="table table-striped mb-0"><thead><tr><th>Date</th><th>Start</th><th>End</th><th>Registered</th><th>Checked-in</th><th>Status</th></tr></thead><tbody>
        @forelse($event->occurrences as $occurrence)
            <tr><td>{{ optional($occurrence->occurrence_date)->format('d M Y') }}</td><td>{{ optional($occurrence->start_at)->format('d M Y h:i A') }}</td><td>{{ optional($occurrence->end_at)->format('d M Y h:i A') }}</td><td>{{ $occurrence->registered_count ?? 0 }}</td><td>{{ $occurrence->checked_in_count ?? 0 }}</td><td>{{ $occurrence->status }}</td></tr>
        @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No occurrences generated.</td></tr>
        @endforelse
        </tbody></table></div>
    </div>
</div>
@endsection

@extends('admin.layouts.app')

@section('title', 'Create Event')

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h4 mb-0">Create Event</h1><a href="{{ route('admin.events.index') }}" class="btn btn-outline-secondary">Back</a></div>
    <form method="POST" action="{{ route('admin.events.store') }}" class="card card-body">
        @csrf
        @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Title</label><input class="form-control" name="title" value="{{ old('title') }}" required></div>
            <div class="col-md-3"><label class="form-label">Event Type</label><select class="form-select" name="event_type" required>@foreach(['circle_meeting','global_event','public_event'] as $type)<option value="{{ $type }}">{{ $type }}</option>@endforeach</select></div>
            <div class="col-md-3"><label class="form-label">Category</label><input class="form-control" name="event_category" value="{{ old('event_category') }}"></div>
            <div class="col-md-4"><label class="form-label">Circle</label><select class="form-select" name="circle_id"><option value="">None</option>@foreach($circles as $circle)<option value="{{ $circle->id }}">{{ $circle->name }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label">Mode</label><select class="form-select" name="mode">@foreach(['offline','online','hybrid'] as $mode)<option value="{{ $mode }}">{{ ucfirst($mode) }}</option>@endforeach</select></div>
            <div class="col-md-3"><label class="form-label">Start At</label><input class="form-control" type="datetime-local" name="start_at" required></div>
            <div class="col-md-3"><label class="form-label">End At</label><input class="form-control" type="datetime-local" name="end_at"></div>
            <div class="col-md-6"><label class="form-label">Location</label><input class="form-control" name="location_text"></div>
            <div class="col-md-6"><label class="form-label">Online Meeting URL</label><input class="form-control" name="online_meeting_url"></div>
            <div class="col-12"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3"></textarea></div>
            <div class="col-md-2"><label class="form-label">Recurrence</label><select class="form-select" name="recurrence_type">@foreach(['none','weekly','monthly','yearly'] as $type)<option value="{{ $type }}">{{ ucfirst($type) }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label">Interval</label><input class="form-control" type="number" name="recurrence_interval" value="1"></div>
            <div class="col-md-2"><label class="form-label">Week of Month</label><input class="form-control" type="number" name="recurrence_week_of_month"></div>
            <div class="col-md-2"><label class="form-label">Day of Week (0-6)</label><input class="form-control" type="number" name="recurrence_day_of_week"></div>
            <div class="col-md-2"><label class="form-label">Day of Month</label><input class="form-control" type="number" name="recurrence_day_of_month"></div>
            <div class="col-md-2"><label class="form-label">Month</label><input class="form-control" type="number" name="recurrence_month"></div>
            <div class="col-md-3"><label class="form-label">Recurrence Ends At</label><input class="form-control" type="date" name="recurrence_ends_at"></div>
            <div class="col-md-3"><label class="form-label">Registration Limit</label><input class="form-control" type="number" name="registration_limit"></div>
            <div class="col-md-3"><label class="form-label">Ticket Price</label><input class="form-control" type="number" step="0.01" name="ticket_price"></div>
            <div class="col-md-3"><label class="form-label">Zoho Form URL</label><input class="form-control" name="zoho_form_url"></div>
            <div class="col-12 d-flex gap-3 flex-wrap">
                @foreach(['is_paid'=>'Paid','qr_checkin_enabled'=>'QR Check-in','visitor_registration_enabled'=>'Visitor Registration','member_registration_enabled'=>'Member Registration'] as $name=>$label)
                    <div class="form-check"><input type="hidden" name="{{ $name }}" value="0"><input class="form-check-input" type="checkbox" name="{{ $name }}" value="1" id="{{ $name }}" @checked($name !== 'is_paid')><label class="form-check-label" for="{{ $name }}">{{ $label }}</label></div>
                @endforeach
            </div>
            <div class="col-12"><button class="btn btn-primary">Create Event</button></div>
        </div>
    </form>
</div>
@endsection

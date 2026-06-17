@extends('admin.layouts.app')

@section('title', 'Import Contacts')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">Import Contacts</h1>
        <p class="text-muted mb-0">Upload a CSV file to create or update contact records.</p>
    </div>
    <a href="{{ route('admin.contacts.index') }}" class="btn btn-outline-secondary">Back to Contacts</a>
</div>

@if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="card p-4">
    <form method="POST" action="{{ route('admin.contacts.import.store') }}" enctype="multipart/form-data">
        @csrf
        @if (! empty($defaultUserId))
            <input type="hidden" name="default_user_id" value="{{ $defaultUserId }}">
            <div class="alert alert-info">Records without a CSV user_id will be assigned to selected user ID: {{ $defaultUserId }}</div>
        @endif
        <div class="mb-3">
            <label for="csv_file" class="form-label">CSV File</label>
            <input type="file" id="csv_file" name="csv_file" class="form-control" accept=".csv,text/csv" required>
            <div class="form-text">
                Allowed columns: user_id, full_name, first_name, middle_name, last_name, email, phone, company, job_title, nickname, notes, emails, phones, addresses.
                JSON columns may contain valid JSON text. user_id is optional and must match an existing user when provided.
            </div>
        </div>
        <div class="d-flex justify-content-end gap-2">
            <a href="{{ route('admin.contacts.index') }}" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Submit</button>
        </div>
    </form>
</div>
@endsection

@extends('admin.layouts.app')

@section('title', $mode === 'edit' ? 'Edit Pamphlet' : 'Add Pamphlet')

@section('content')
    @include('admin.campaigns.partials.flash')

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">{{ $mode === 'edit' ? 'Edit Pamphlet' : 'Add Pamphlet' }}</h1>
        <a href="{{ route('admin.campaign-pamphlets.index') }}" class="btn btn-outline-secondary">Back</a>
    </div>

    <form method="POST" enctype="multipart/form-data" action="{{ $mode === 'edit' ? route('admin.campaign-pamphlets.update', $pamphlet) : route('admin.campaign-pamphlets.store') }}">
        @csrf
        @if ($mode === 'edit') @method('PUT') @endif

        <div class="card shadow-sm"><div class="card-body">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Title</label>
                    <input type="text" name="title" class="form-control" value="{{ old('title', $pamphlet->title) }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select" required>
                        <option value="active" @selected(old('status', $pamphlet->status ?: 'active') === 'active')>Active</option>
                        <option value="inactive" @selected(old('status', $pamphlet->status) === 'inactive')>Inactive</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Email Content</label>
                    <textarea name="content" rows="10" class="form-control" placeholder="HTML content is supported">{{ old('content', $pamphlet->content) }}</textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">Notification Short Message</label>
                    <textarea name="short_message" rows="4" class="form-control">{{ old('short_message', $pamphlet->short_message) }}</textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Upload Image</label>
                    <input type="file" name="image" class="form-control" accept="image/*">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Image URL</label>
                    <input type="url" name="image_url" class="form-control" value="{{ old('image_url', $pamphlet->image_url) }}" placeholder="https://...">
                    @if ($pamphlet->image_url)
                        <img src="{{ $pamphlet->image_url }}" alt="{{ $pamphlet->title }}" class="mt-2" style="max-width:160px;border-radius:8px;">
                    @endif
                </div>
            </div>
        </div></div>

        <div class="mt-3 d-flex gap-2">
            <button class="btn btn-primary">Save Pamphlet</button>
            <a href="{{ route('admin.campaign-pamphlets.index') }}" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
@endsection

@extends('admin.layouts.app')

@section('title', 'Admin Dashboard')

@section('content')
<div class="row g-3 mb-4">
    <div class="col-12 col-xl-8">
        <div class="card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <p class="text-muted mb-1">Overview</p>
                    <h5 class="mb-0">Platform Pulse</h5>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-sm-6 col-lg-3">
                    <div class="p-3 rounded border bg-white h-100">
                        <p class="text-muted mb-1">Total Peers</p>
                        <h4 class="mb-0">{{ number_format($stats['total_users'] ?? 0) }}</h4>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="p-3 rounded border bg-white h-100">
                        <p class="text-muted mb-1">Active Circles</p>
                        <h4 class="mb-0">{{ number_format($stats['active_circles'] ?? 0) }}</h4>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="p-3 rounded border bg-white h-100">
                        <p class="text-muted mb-1">Pending Circles</p>
                        <h4 class="mb-0">{{ number_format($stats['pending_approvals'] ?? 0) }}</h4>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="p-3 rounded border bg-white h-100">
                        <p class="text-muted mb-1">New Signups</p>
                        <h4 class="mb-0">{{ number_format($stats['new_signups'] ?? 0) }}</h4>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-4">
        <div class="card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0">Pending Approvals & Reviews</h6>
                <button class="btn btn-sm btn-outline-secondary">View All</button>
            </div>
            <div class="list-group list-group-flush">
                @foreach ($pendingItems as $item)
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <span>{{ $item['title'] }}</span>
                        <span class="badge bg-primary">{{ $item['count'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

<div class="row g-3">

    <div class="col-12 col-xxl-6">
        <div class="card p-4 h-100">
            <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                <div>
                    <p class="text-muted mb-1">Admin Directory</p>
                    <h6 class="mb-0">Contacts</h6>
                </div>
                <a href="{{ route('admin.contacts.index') }}" class="btn btn-sm btn-primary">View</a>
            </div>
            <div class="row g-3">
                <div class="col-sm-6">
                    <div class="p-3 rounded border bg-white h-100">
                        <p class="text-muted mb-1">Total Contacts</p>
                        <h4 class="mb-0">{{ number_format($totalContactPosts ?? 0) }}</h4>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="p-3 rounded border bg-white h-100">
                        <p class="text-muted mb-1">Today Contacts</p>
                        <h4 class="mb-0">{{ number_format($todayContactPosts ?? 0) }}</h4>
                    </div>
                </div>
            </div>
            <div class="mt-3 d-flex justify-content-between align-items-center">
                <span class="text-muted small">Latest Contacts</span>
                <span class="badge bg-light text-dark">{{ isset($recentContactPosts) ? $recentContactPosts->count() : 0 }}</span>
            </div>
        </div>
    </div>
    <div class="col-12 col-xxl-6">
        <div class="card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0">Activity & Coins</h6>
                <a href="#" class="btn btn-sm btn-light">View</a>
            </div>
            <p class="text-muted mb-0">Placeholder widget for activity trends.</p>
        </div>
    </div>
    <div class="col-12 col-xxl-6">
        <div class="card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0">Compliance & Reports</h6>
                <a href="#" class="btn btn-sm btn-light">View</a>
            </div>
            <p class="text-muted mb-0">Placeholder widget for audit & compliance summaries.</p>
        </div>
    </div>
</div>
@endsection

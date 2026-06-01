@extends('admin.layouts.app')

@section('title', 'DED Dashboard')

@section('content')
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
    <div>
        <p class="text-muted mb-1">District Executive Director</p>
        <h4 class="mb-0">DED Dashboard</h4>
    </div>
    @if ($districtName)
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">District: {{ $districtName }}</span>
    @endif
</div>

@if (! $districtName)
    <div class="alert alert-warning">
        No district assigned. Please contact Global Admin.
    </div>
@else
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 h-100">
                <p class="text-muted mb-1">District Peers</p>
                <h4 class="mb-0">{{ number_format($stats['peers'] ?? 0) }}</h4>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 h-100">
                <p class="text-muted mb-1">District Circles</p>
                <h4 class="mb-0">{{ number_format($stats['activeCircles'] ?? 0) }}</h4>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 h-100">
                <p class="text-muted mb-1">Activities Today</p>
                <h4 class="mb-0">{{ number_format($stats['activitiesToday'] ?? 0) }}</h4>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 h-100">
                <p class="text-muted mb-1">Pending Circle Requests</p>
                <h4 class="mb-0">{{ number_format($stats['pendingCircleJoinRequests'] ?? 0) }}</h4>
            </div>
        </div>
    </div>

    <div class="card p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Latest District Peers</h5>
            <a href="{{ route('admin.users.index') }}" class="btn btn-sm btn-outline-primary">View Peers</a>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Company</th>
                        <th>City</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($peers as $peer)
                        <tr>
                            <td>{{ $peer->display_name ?: trim(($peer->first_name ?? '') . ' ' . ($peer->last_name ?? '')) ?: '—' }}</td>
                            <td>{{ $peer->email ?? '—' }}</td>
                            <td>{{ $peer->company_name ?? '—' }}</td>
                            <td>{{ $peer->city?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-muted text-center py-4">No peers found for this district.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection

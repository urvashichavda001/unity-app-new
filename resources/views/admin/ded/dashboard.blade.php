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
    @php
        $statCards = [
            ['label' => 'Total District Peers', 'key' => 'peers'],
            ['label' => 'Total District Circles', 'key' => 'circles'],
            ['label' => 'Total Referrals', 'key' => 'referrals'],
            ['label' => 'Total Requirements', 'key' => 'requirements'],
            ['label' => 'Total Testimonials', 'key' => 'testimonials'],
            ['label' => 'Total Business Deals', 'key' => 'businessDeals'],
            ['label' => 'Total P2P Meetings', 'key' => 'p2pMeetings'],
            ['label' => 'Total Coins Earned', 'key' => 'coinsEarned'],
            ['label' => 'Pending Requests', 'key' => 'pendingRequests'],
        ];
    @endphp

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-5 col-lg-4">
                    <label for="circle_id" class="form-label">Circle Filter</label>
                    <select id="circle_id" name="circle_id" class="form-select" onchange="this.form.submit()">
                        <option value="">All Circles</option>
                        @foreach ($circleOptions as $circle)
                            <option value="{{ $circle->id }}" @selected((string) $selectedCircleId === (string) $circle->id)>{{ $circle->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-primary">Apply</button>
                    @if ($selectedCircleId)
                        <a href="{{ route('admin.ded.dashboard') }}" class="btn btn-outline-secondary">Reset</a>
                    @endif
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        @foreach ($statCards as $card)
            <div class="col-sm-6 col-xl-4">
                <div class="card p-3 h-100">
                    <p class="text-muted mb-1">{{ $card['label'] }}</p>
                    <h4 class="mb-0">{{ number_format($stats[$card['key']] ?? 0) }}</h4>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">{{ $selectedCircleId ? 'Latest Circle Peers' : 'Latest District Peers' }}</h5>
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
                            <td>{{ $peer->adminCity() }}</td>
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

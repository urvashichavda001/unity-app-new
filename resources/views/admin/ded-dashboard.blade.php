@extends('admin.layouts.app')

@section('title', 'DED Dashboard')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <p class="text-muted mb-1">District Executive Director</p>
        <h5 class="mb-0">DED Dashboard</h5>
    </div>
    @if ($districtName)
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">District: {{ $districtName }}</span>
    @endif
</div>

@if (! $districtName)
    <div class="alert alert-warning">No district assigned. Please contact Global Admin.</div>
@else
    @php
        $cards = [
            ['label' => 'Total District Peers', 'value' => $stats['total_users'] ?? 0],
            ['label' => 'Total District Circles', 'value' => $stats['active_circles'] ?? 0],
            ['label' => 'Total Referrals', 'value' => $stats['referrals'] ?? 0],
            ['label' => 'Total Requirements', 'value' => $stats['requirements'] ?? 0],
            ['label' => 'Total Testimonials', 'value' => $stats['testimonials'] ?? 0],
            ['label' => 'Total Business Deals', 'value' => $stats['business_deals'] ?? 0],
            ['label' => 'Total P2P Meetings', 'value' => $stats['p2p_meetings'] ?? 0],
            ['label' => 'Total Coins Earned', 'value' => $stats['coins_earned'] ?? 0],
            ['label' => 'Pending Requests', 'value' => $stats['pending_requests'] ?? 0],
        ];
    @endphp

    <div class="card p-3 mb-4">
        <form method="GET" action="{{ route('admin.ded.dashboard') }}" class="row g-2 align-items-end">
            <div class="col-md-6 col-xl-4">
                <label for="dedDashboardCircleFilter" class="form-label small text-muted mb-1">Circle Filter</label>
                <select id="dedDashboardCircleFilter" name="circle_id" class="form-select">
                    <option value="all" @selected(($selectedCircleId ?? '') === '')>All Circles</option>
                    @foreach (($districtCircles ?? collect()) as $circle)
                        <option value="{{ $circle->id }}" @selected(($selectedCircleId ?? '') === $circle->id)>
                            {{ $circle->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-auto">
                <button class="btn btn-primary">Apply</button>
            </div>
            @if (($selectedCircleId ?? '') !== '')
                <div class="col-md-auto">
                    <a href="{{ route('admin.ded.dashboard') }}" class="btn btn-outline-secondary">Reset</a>
                </div>
                <div class="col-md-auto text-muted small">Showing statistics for {{ $selectedCircle?->name ?? 'selected circle' }} only.</div>
            @else
                <div class="col-md-auto text-muted small">Showing district-wide statistics across all circles.</div>
            @endif
        </form>
    </div>

    <div class="row g-3 mb-4">
        @foreach ($cards as $card)
            <div class="col-sm-6 col-xl-4">
                <div class="p-3 rounded border bg-white h-100">
                    <p class="text-muted mb-1">{{ $card['label'] }}</p>
                    <h4 class="mb-0">{{ number_format($card['value']) }}</h4>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row g-3">
        <div class="col-12 col-xl-5">
            <div class="card p-4 h-100">
                <h6 class="mb-3">District Quick Reports</h6>
                <div class="list-group list-group-flush">
                    @foreach ($pendingItems as $item)
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <span>{{ $item['title'] }}</span>
                            <span class="badge bg-primary">{{ number_format($item['count']) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-7">
            <div class="card p-4 h-100">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">{{ ($selectedCircleId ?? '') !== '' ? 'Recent Circle Peers' : 'Recent District Peers' }}</h6>
                    <a class="btn btn-sm btn-outline-primary" href="{{ route('admin.users.index') }}">View Peers</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>City</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recentPeers as $peer)
                                <tr>
                                    <td>{{ $peer->display_name ?: trim(($peer->first_name ?? '') . ' ' . ($peer->last_name ?? '')) ?: '—' }}</td>
                                    <td>{{ $peer->email ?? '—' }}</td>
                                    <td>{{ $peer->city?->name ?? $peer->city ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-muted">No peers found for this {{ ($selectedCircleId ?? '') !== '' ? 'circle' : 'district' }}.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endif
@endsection

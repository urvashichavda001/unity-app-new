@extends('admin.layouts.app')

@section('title', 'Referrals')

@section('content')
    <style>
        .peer-name {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    </style>
    @php
        $displayName = function (?string $display, ?string $first, ?string $last): string {
            if ($display) {
                return $display;
            }
            $name = trim(($first ?? '') . ' ' . ($last ?? ''));
            return $name !== '' ? $name : '—';
        };

        $formatDateTime = function ($value): string {
            return $value ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d H:i') : '—';
        };

        $formatDate = function ($value): string {
            return $value ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d') : '—';
        };
    @endphp

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Referrals</h1>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-light text-dark border">Total Referrals: {{ number_format($total) }}</span>
        </div>
    </div>

    <form id="referralsFiltersForm" method="GET" action="{{ route('admin.activities.referrals.index') }}">
    @include('admin.components.activity-filter-bar-v2', [
        'actionUrl' => route('admin.activities.referrals.index'),
        'resetUrl' => route('admin.activities.referrals.index'),
        'filters' => $filters,
        'circles' => $circles ?? collect(),
        'showExport' => true,
        'exportUrl' => route('admin.activities.referrals.export', request()->query()),
        'renderFormTag' => false,
        'formId' => 'referralsFiltersForm',
    ])

    <div class="card shadow-sm mb-3">
        <div class="card-header bg-white">
            <strong>Top 5 Peers</strong>
        </div>
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Rank</th>
                        <th>Peer Name</th>
                        <th>Total Referrals</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($topMembers as $index => $member)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td>
                                @include('admin.components.peer-card', [
                                    'name' => $member->peer_name ?? $displayName($member->display_name ?? null, $member->first_name ?? null, $member->last_name ?? null),
                                    'company' => $member->peer_company ?? '',
                                    'city' => $member->peer_city ?? '',
                                    'maxWidth' => 260,
                                ])
                            </td>
                            <td>{{ $member->total_count ?? 0 }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-center text-muted">No data available.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>From</th>
                        <th>To</th>
                        <th>Type</th>
                        <th>Referral Date</th>
                        <th>Referral Of</th>
                        <th>Phone</th>
                        <th>Email</th>
                        <th>Address</th>
                        <th>Hot Value</th>
                        <th>Remarks</th>
                        <th>Media</th>
                        <th>Created At</th>
                    </tr>
                    <tr>
                        <th><input type="text" name="from_user" value="{{ $filters['from_user'] ?? '' }}" placeholder="From" class="form-control form-control-sm"></th>
                        <th><input type="text" name="to_user" value="{{ $filters['to_user'] ?? '' }}" placeholder="To" class="form-control form-control-sm"></th>
                        <th>
                            <select name="type" class="form-select form-select-sm">
                                <option value="">Any</option>
                                @foreach (($types ?? collect()) as $type)
                                    <option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type || ($filters['referral_type'] ?? '') === $type)>{{ $type }}</option>
                                @endforeach
                            </select>
                        </th>
                        <th><input type="date" name="referral_date" value="{{ $filters['referral_date'] ?? '' }}" class="form-control form-control-sm" placeholder="dd-mm-yyyy"></th>
                        <th><input type="text" name="referral_of" value="{{ $filters['referral_of'] ?? '' }}" placeholder="Referral Of" class="form-control form-control-sm"></th>
                        <th><input type="text" name="phone" value="{{ $filters['phone'] ?? '' }}" placeholder="Phone" class="form-control form-control-sm"></th>
                        <th><input type="text" name="email" value="{{ $filters['email'] ?? '' }}" placeholder="Email" class="form-control form-control-sm"></th>
                        <th class="text-muted">—</th>
                        <th><input type="number" name="hot_value" value="{{ $filters['hot_value'] ?? '' }}" placeholder="Hot" class="form-control form-control-sm"></th>
                        <th><input type="text" name="remarks" value="{{ $filters['remarks'] ?? '' }}" placeholder="Remarks" class="form-control form-control-sm"></th>
                        <th>
                            <select name="has_media" class="form-select form-select-sm">
                                <option value="">Any</option>
                                <option value="1" @selected(($filters['has_media'] ?? '') === '1')>Yes</option>
                                <option value="0" @selected(($filters['has_media'] ?? '') === '0')>No</option>
                            </select>
                        </th>
                        <th>
                            <div class="d-flex justify-content-end gap-2">
                                <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                                <a href="{{ route('admin.activities.referrals.index') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $referral)
                        @php
                            $actorName = $displayName($referral->actor_display_name ?? null, $referral->actor_first_name ?? null, $referral->actor_last_name ?? null);
                            $peerName = $displayName($referral->peer_display_name ?? null, $referral->peer_first_name ?? null, $referral->peer_last_name ?? null);
                        @endphp
                        <tr>
                            <td>
                                @include('admin.components.peer-card', [
                                    'name' => $referral->from_user_name ?? $actorName,
                                    'company' => $referral->from_company ?? '',
                                    'city' => $referral->from_city ?? '',
                                ])
                            </td>
                            <td>
                                @include('admin.components.peer-card', [
                                    'name' => $referral->to_user_name ?? $peerName,
                                    'company' => $referral->to_company ?? '',
                                    'city' => $referral->to_city ?? '',
                                ])
                            </td>
                            <td>{{ $referral->referral_type ?? '—' }}</td>
                            <td>{{ $formatDate($referral->referral_date ?? null) }}</td>
                            <td>{{ $referral->referral_of ?? '—' }}</td>
                            <td>{{ $referral->phone ?? '—' }}</td>
                            <td>{{ $referral->email ?? '—' }}</td>
                            <td>{{ $referral->address ?? '—' }}</td>
                            <td>{{ $referral->hot_value ?? '—' }}</td>
                            <td class="text-muted">{{ $referral->remarks ?? '—' }}</td>
                            <td>
                                @if ((int) ($referral->has_media ?? 0) === 1)
                                    <span class="badge bg-success">Yes</span>
                                    @if (!empty($referral->media_reference))
                                        @php
                                            $mediaUrl = \App\Support\MediaFileUrl::resolve($referral->media_reference);
                                        @endphp
                                        @if ($mediaUrl)
                                            <a href="{{ $mediaUrl }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary ms-2">View</a>
                                        @else
                                            <span class="text-warning small ms-2">Missing file</span>
                                        @endif
                                    @endif
                                @else
                                    <span class="text-muted">No</span>
                                @endif
                            </td>
                            <td>{{ $formatDateTime($referral->created_at ?? null) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="12" class="text-center text-muted">No referrals found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    </form>

    <div class="mt-3">
        {{ $items->links() }}
    </div>
@endsection

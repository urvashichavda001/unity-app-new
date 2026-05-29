@extends('admin.layouts.app')

@section('title', 'Requirements')

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

        $mediaSummary = function ($media): array {
            if (! $media) {
                return ['has' => false, 'count' => 0];
            }

            $decoded = is_string($media) ? json_decode($media, true) : $media;

            if (is_array($decoded)) {
                $count = count($decoded);
                return ['has' => $count > 0, 'count' => $count];
            }

            return ['has' => true, 'count' => 1];
        };

        $firstMediaId = function ($media): ?string {
            if (! $media) {
                return null;
            }

            $decoded = is_string($media) ? json_decode($media, true) : $media;
            $items = is_array($decoded) ? array_values($decoded) : [$decoded];
            $first = $items[0] ?? null;

            if (is_string($first)) {
                return $first;
            }

            if (is_array($first)) {
                return $first['file_id'] ?? $first['fileId'] ?? $first['id'] ?? null;
            }

            if (is_object($first)) {
                return $first->file_id ?? $first->fileId ?? $first->id ?? null;
            }

            return null;
        };

        $decodeFilter = function ($value): array {
            if (is_array($value)) {
                return $value;
            }

            if (is_string($value)) {
                $decoded = json_decode($value, true);
                return is_array($decoded) ? $decoded : [];
            }

            return [];
        };
    @endphp

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Requirements</h1>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-light text-dark border">Total Requirements: {{ number_format($total) }}</span>
        </div>
    </div>

    <form id="requirementsFiltersForm" method="GET" action="{{ route('admin.activities.requirements.index') }}">
        @include('admin.components.activity-filter-bar-v2', [
            'actionUrl' => route('admin.activities.requirements.index'),
            'resetUrl' => route('admin.activities.requirements.index'),
            'filters' => $filters,
            'circles' => $circles ?? collect(),
            'showExport' => true,
            'exportUrl' => route('admin.activities.requirements.export', request()->query()),
            'renderFormTag' => false,
            'formId' => 'requirementsFiltersForm',
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
                        <th>Total Requirements</th>
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
                        <th>Subject</th>
                        <th>Description</th>
                        <th>Region</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th>Media</th>
                        <th>Created At</th>
                    </tr>
                    <tr>
                        <th>
                            <input type="text" name="from_user" value="{{ $filters['from_user'] ?? '' }}" placeholder="From" class="form-control form-control-sm" />
                        </th>
                        <th>
                            <input type="text" name="subject" value="{{ $filters['subject'] ?? '' }}" placeholder="Subject" class="form-control form-control-sm" />
                        </th>
                        <th class="text-muted">—</th>
                        <th>
                            <input type="text" name="region" value="{{ $filters['region'] ?? '' }}" placeholder="Region" class="form-control form-control-sm" />
                        </th>
                        <th>
                            <input type="text" name="category" value="{{ $filters['category'] ?? '' }}" placeholder="Category" class="form-control form-control-sm" />
                        </th>
                        <th>
                            <select name="status" class="form-select form-select-sm">
                                <option value="">Any</option>
                                @foreach (($statuses ?? collect()) as $status)
                                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
                                @endforeach
                            </select>
                        </th>
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
                                <a href="{{ route('admin.activities.requirements.index') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $requirement)
                        @php
                            $actorName = $displayName($requirement->actor_display_name ?? null, $requirement->actor_first_name ?? null, $requirement->actor_last_name ?? null);
                            $mediaInfo = $mediaSummary($requirement->media ?? null);
                            $mediaUrl = \App\Support\MediaFileUrl::first($requirement->media ?? null);
                            $regionFilter = $decodeFilter($requirement->region_filter ?? null);
                            $categoryFilter = $decodeFilter($requirement->category_filter ?? null);
                            $regionLabel = $regionFilter['region_label'] ?? $regionFilter['region_name'] ?? $regionFilter['city_name'] ?? null;
                            $categoryLabel = $categoryFilter['category'] ?? null;
                        @endphp
                        <tr>
                            <td>
                                @include('admin.components.peer-card', [
                                    'name' => $requirement->from_user_name ?? $actorName,
                                    'company' => $requirement->from_company ?? '',
                                    'city' => $requirement->from_city ?? '',
                                ])
                            </td>
                            <td>{{ $requirement->subject ?? '—' }}</td>
                            <td class="text-muted">{{ $requirement->description ?? '—' }}</td>
                            <td>{{ $regionLabel ?: '—' }}</td>
                            <td>{{ $categoryLabel ?: '—' }}</td>
                            <td>{{ $requirement->status ?? '—' }}</td>
                            <td>
                                @if ($mediaInfo['has'] && $mediaUrl)
                                    <span class="badge bg-success">Yes ({{ $mediaInfo['count'] }})</span>
                                    <a href="{{ $mediaUrl }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary ms-2">View</a>
                                @else
                                    <span class="text-muted">No</span>
                                @endif
                            </td>
                            <td>{{ $formatDateTime($requirement->created_at ?? null) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted">No requirements found.</td>
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

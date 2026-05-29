@extends('admin.layouts.app')

@section('title', 'Business Deals')

@section('content')
    <style>
        .peer-name { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px; display: block; }
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
    @endphp

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <h1 class="h4 mb-0">Business Deals</h1>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-light text-dark border">Total Deals: {{ number_format($total) }}</span>
        </div>
    </div>

    <form id="businessDealsFiltersForm" method="GET" action="{{ route('admin.activities.business-deals.index') }}">
    @include('admin.components.activity-filter-bar-v2', [
        'actionUrl' => route('admin.activities.business-deals.index'),
        'resetUrl' => route('admin.activities.business-deals.index'),
        'filters' => $filters,
        'circles' => $circles ?? collect(),
        'showExport' => true,
        'exportUrl' => route('admin.activities.business-deals.export', request()->query()),
        'renderFormTag' => false,
        'formId' => 'businessDealsFiltersForm',
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
                        <th>Total Deals</th>
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
                        <th>Deal Date</th>
                        <th>Deal Amount</th>
                        <th>Business Type</th>
                        <th>Comment</th>
                        <th>Media</th>
                        <th>Created At</th>
                    </tr>
                    <tr>
                        <th><input type="text" name="from_user" value="{{ $filters['from_user'] ?? '' }}" placeholder="From" class="form-control form-control-sm"></th>
                        <th><input type="text" name="to_user" value="{{ $filters['to_user'] ?? '' }}" placeholder="To" class="form-control form-control-sm"></th>
                        <th><input type="date" name="deal_date" value="{{ $filters['deal_date'] ?? '' }}" class="form-control form-control-sm" placeholder="dd-mm-yyyy"></th>
                        <th><input type="number" step="0.01" name="deal_amount" value="{{ $filters['deal_amount'] ?? '' }}" placeholder="Deal Amount" class="form-control form-control-sm"></th>
                        <th><input type="text" name="business_type" value="{{ $filters['business_type'] ?? '' }}" placeholder="Business Type" class="form-control form-control-sm"></th>
                        <th><input type="text" name="comment" value="{{ $filters['comment'] ?? '' }}" placeholder="Comment" class="form-control form-control-sm"></th>
                        <th>
                            <select name="has_media" class="form-select form-select-sm">
                                <option value="">Any</option>
                                <option value="yes" @selected(($filters['has_media'] ?? '') === 'yes')>Yes</option>
                                <option value="no" @selected(($filters['has_media'] ?? '') === 'no')>No</option>
                            </select>
                        </th>
                        <th>
                            <div class="d-flex justify-content-end gap-2">
                                <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                                <a href="{{ route('admin.activities.business-deals.index') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $deal)
                        @php
                            $mediaValue = $deal->media_reference ?? null;
                            $hasMedia = false;

                            if (is_string($mediaValue)) {
                                $trim = trim($mediaValue);
                                $hasMedia = ($trim !== '' && $trim !== 'null' && $trim !== '[]' && $trim !== '{}');
                            } elseif (is_array($mediaValue)) {
                                $hasMedia = count($mediaValue) > 0;
                            } elseif (! is_null($mediaValue)) {
                                $hasMedia = true;
                            }

                            $actorName = $displayName($deal->actor_display_name ?? null, $deal->actor_first_name ?? null, $deal->actor_last_name ?? null);
                            $peerName = $displayName($deal->peer_display_name ?? null, $deal->peer_first_name ?? null, $deal->peer_last_name ?? null);
                        @endphp
                        <tr>
                            <td>
                                @include('admin.components.peer-card', [
                                    'name' => $deal->from_user_name ?? $actorName,
                                    'company' => $deal->from_company ?? '',
                                    'city' => $deal->from_city ?? '',
                                ])
                            </td>
                            <td>
                                @include('admin.components.peer-card', [
                                    'name' => $deal->to_user_name ?? $peerName,
                                    'company' => $deal->to_company ?? '',
                                    'city' => $deal->to_city ?? '',
                                ])
                            </td>
                            <td>{{ $formatDate($deal->deal_date ?? null) }}</td>
                            <td>{{ $deal->deal_amount ?? '—' }}</td>
                            <td>{{ $deal->business_type ?? '—' }}</td>
                            <td class="text-muted">{{ $deal->comment ?? '—' }}</td>
                            <td>
                                @if ($hasMedia)
                                    <span class="badge bg-success">Yes</span>
                                    <button type="button" class="btn btn-sm btn-outline-primary ms-2" data-bs-toggle="modal" data-bs-target="#mediaViewerModal" data-media-source="media-json-{{ $deal->id }}">View</button>
                                    <script type="application/json" id="media-json-{{ $deal->id }}">{{ e(json_encode(is_string($deal->media_reference ?? null) ? json_decode($deal->media_reference ?? '[]', true) : ($deal->media_reference ?? []))) }}</script>
                                @else
                                    <span class="text-muted">No</span>
                                @endif
                            </td>
                            <td>{{ $formatDateTime($deal->created_at ?? null) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted">No business deals found.</td>
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

    <div class="modal fade" id="mediaViewerModal" tabindex="-1" aria-labelledby="mediaViewerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="mediaViewerModalLabel">Media</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" data-media-container>
                    <p class="text-muted mb-0">No media available.</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.querySelectorAll('[data-media-source]').forEach((button) => {
            button.addEventListener('click', () => {
                const modal = document.getElementById('mediaViewerModal');
                const container = modal.querySelector('[data-media-container]');
                const sourceId = button.getAttribute('data-media-source');
                const scriptTag = document.getElementById(sourceId);
                let items = [];

                if (scriptTag) {
                    try {
                        items = JSON.parse(scriptTag.textContent || '[]');
                    } catch (error) {
                        items = [];
                    }
                }

                container.innerHTML = '';

                if (!Array.isArray(items) || items.length === 0) {
                    container.innerHTML = '<p class="text-muted mb-0">No media available.</p>';
                    return;
                }

                items.forEach((item, index) => {
                    let url = null;

                    if (typeof item === 'string') {
                        url = item;
                    } else if (item && typeof item === 'object') {
                        url = item.url || item.file_url || item.media_url || item.file_id || item.fileId || item.media_file_id || item.id || null;
                    }

                    if (!url) {
                        return;
                    }

                    if (!url.startsWith('http') && /^[0-9a-fA-F-]{36}$/.test(url)) {
                        url = `/api/v1/files/${url}`;
                    }

                    const wrapper = document.createElement('div');
                    wrapper.classList.add('border', 'rounded', 'p-2', 'mb-3');

                    const link = document.createElement('a');
                    link.href = url;
                    link.target = '_blank';
                    link.rel = 'noopener';
                    link.textContent = `Media ${index + 1}`;
                    link.classList.add('d-block', 'mb-2');

                    wrapper.appendChild(link);

                    if (/\.(jpg|jpeg|png|gif|webp)(\?.*)?$/i.test(url)) {
                        const img = document.createElement('img');
                        img.src = url;
                        img.alt = `Media ${index + 1}`;
                        img.classList.add('img-thumbnail');
                        img.style.maxWidth = '200px';
                        img.style.maxHeight = '200px';
                        wrapper.appendChild(img);
                    }

                    container.appendChild(wrapper);
                });
            });
        });
    </script>
@endsection

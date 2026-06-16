@extends('admin.layouts.app')

@section('title', 'Contact Detail')

@section('content')
@php
    $displayValue = fn ($value) => filled($value) ? $value : '—';
    $formatType = fn ($type, $fallback = 'Other') => filled($type)
        ? \Illuminate\Support\Str::of((string) $type)->replace(['_', '-'], ' ')->title()
        : $fallback;

    $normalizeItems = function ($items, array $valueKeys, string $typeFallback = 'Other') use ($formatType) {
        if (blank($items)) {
            return [];
        }

        if (is_string($items)) {
            $decoded = json_decode($items, true);
            $items = json_last_error() === JSON_ERROR_NONE ? $decoded : [$items];
        }

        if (! is_array($items)) {
            return [];
        }

        if (! array_is_list($items)) {
            $items = [$items];
        }

        return collect($items)
            ->map(function ($item) use ($valueKeys, $typeFallback, $formatType) {
                if (is_scalar($item)) {
                    return ['type' => $typeFallback, 'value' => (string) $item];
                }

                if (! is_array($item)) {
                    return null;
                }

                $type = $item['type'] ?? $item['label'] ?? $item['name'] ?? $typeFallback;
                $value = null;
                foreach ($valueKeys as $key) {
                    if (filled($item[$key] ?? null)) {
                        $value = $item[$key];
                        break;
                    }
                }

                if ($value === null && count($item) === 1) {
                    $value = collect($item)->first();
                }

                if (is_array($value)) {
                    $value = collect($value)->filter(fn ($part) => filled($part))->implode(', ');
                }

                if (blank($value)) {
                    return null;
                }

                return [
                    'type' => $formatType($type, $typeFallback),
                    'value' => (string) $value,
                ];
            })
            ->filter()
            ->values()
            ->all();
    };

    $emailDetails = $normalizeItems($contactPost->emails, ['email', 'value', 'address'], 'Email');
    $phoneDetails = $normalizeItems($contactPost->phones, ['phone', 'phone_number', 'number', 'value', 'mobile'], 'Phone');
    $addressDetails = $normalizeItems($contactPost->addresses, ['address', 'value', 'full_address', 'line', 'formatted'], 'Address');
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <p class="text-muted mb-1">Contacts</p>
        <h1 class="h4 mb-0">Contact Detail</h1>
    </div>
    <a href="{{ route('admin.contacts.index') }}" class="btn btn-outline-secondary">Back to Contacts</a>
</div>

<div class="card p-4 mb-3">
    <h2 class="h6 mb-3">Basic Contact Information</h2>
    <div class="row g-3">
        @foreach ([
            'Full Name' => $contactPost->full_name,
            'First Name' => $contactPost->first_name,
            'Middle Name' => $contactPost->middle_name,
            'Last Name' => $contactPost->last_name,
            'Email' => $contactPost->email,
            'Phone' => $contactPost->phone,
            'Company' => $contactPost->company,
            'Job Title' => $contactPost->job_title,
            'Nickname' => $contactPost->nickname,
        ] as $label => $value)
            <div class="col-md-6 col-xl-4">
                <div class="p-3 rounded border bg-white h-100">
                    <p class="text-muted small mb-1">{{ $label }}</p>
                    <div class="fw-semibold">{{ $displayValue($value) }}</div>
                </div>
            </div>
        @endforeach
    </div>
</div>

<div class="card p-4 mb-3">
    <h2 class="h6 mb-3">Notes</h2>
    <div class="p-3 rounded border bg-white">{{ $displayValue($contactPost->notes) }}</div>
</div>

<div class="card p-4 mb-3">
    <h2 class="h6 mb-3">Date Information</h2>
    <div class="row g-3">
        @foreach ([
            'Created At' => optional($contactPost->created_at)->format('d M Y, h:i A'),
            'Updated At' => optional($contactPost->updated_at)->format('d M Y, h:i A'),
        ] as $label => $value)
            <div class="col-md-6">
                <div class="p-3 rounded border bg-white h-100">
                    <p class="text-muted small mb-1">{{ $label }}</p>
                    <div class="fw-semibold">{{ $displayValue($value) }}</div>
                </div>
            </div>
        @endforeach
    </div>
</div>

<div class="card p-4">
    <h2 class="h6 mb-3">Additional Contact Details</h2>
    <div class="row g-3">
        <div class="col-12 col-xl-4">
            <div class="p-3 rounded border bg-white h-100">
                <h3 class="h6 mb-3">Email Details</h3>
                @if ($emailDetails === [])
                    <p class="text-muted mb-0">No email details available.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light"><tr><th>Type</th><th>Email</th></tr></thead>
                            <tbody>
                                @foreach ($emailDetails as $emailDetail)
                                    <tr>
                                        <td>{{ $emailDetail['type'] }}</td>
                                        <td><a href="mailto:{{ $emailDetail['value'] }}">{{ $emailDetail['value'] }}</a></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="p-3 rounded border bg-white h-100">
                <h3 class="h6 mb-3">Phone Details</h3>
                @if ($phoneDetails === [])
                    <p class="text-muted mb-0">No phone details available.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light"><tr><th>Type</th><th>Phone Number</th></tr></thead>
                            <tbody>
                                @foreach ($phoneDetails as $phoneDetail)
                                    <tr>
                                        <td>{{ $phoneDetail['type'] }}</td>
                                        <td>{{ $phoneDetail['value'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="p-3 rounded border bg-white h-100">
                <h3 class="h6 mb-3">Address Details</h3>
                @if ($addressDetails === [])
                    <p class="text-muted mb-0">No address details available.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light"><tr><th>Type</th><th>Address</th></tr></thead>
                            <tbody>
                                @foreach ($addressDetails as $addressDetail)
                                    <tr>
                                        <td>{{ $addressDetail['type'] }}</td>
                                        <td>{{ $addressDetail['value'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

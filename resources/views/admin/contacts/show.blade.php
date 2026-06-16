@extends('admin.layouts.app')

@section('title', 'Contact Detail')

@section('content')
@php
    $displayValue = fn ($value) => filled($value) ? $value : '—';
    $renderJsonValue = function ($value) use (&$renderJsonValue) {
        if (is_array($value)) {
            if (empty($value)) {
                return '—';
            }
            if (array_is_list($value)) {
                return collect($value)->map(fn ($item) => is_array($item) ? collect($item)->map(fn ($v, $k) => e(ucwords(str_replace('_', ' ', $k))).': '.e(is_scalar($v) ? $v : json_encode($v)))->implode('<br>') : e($item))->implode('<hr class="my-2">');
            }
            return collect($value)->map(fn ($v, $k) => e(ucwords(str_replace('_', ' ', $k))).': '.e(is_scalar($v) ? $v : json_encode($v)))->implode('<br>');
        }

        return filled($value) ? e($value) : '—';
    };
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <p class="text-muted mb-1">Contacts</p>
        <h1 class="h4 mb-0">Contact Detail</h1>
    </div>
    <a href="{{ route('admin.contacts.index') }}" class="btn btn-outline-secondary">Back to Contacts</a>
</div>

<div class="card p-4">
    <div class="mb-4">
        <h2 class="h6 mb-3">Basic Details</h2>
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

    <div class="mb-4">
        <h2 class="h6 mb-3">Other Details</h2>
        <div class="row g-3">
            <div class="col-12">
                <div class="p-3 rounded border bg-white">
                    <p class="text-muted small mb-1">Notes</p>
                    <div>{{ $displayValue($contactPost->notes) }}</div>
                </div>
            </div>
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

    <div>
        <h2 class="h6 mb-3">JSON Details</h2>
        <div class="row g-3">
            @foreach (['Emails' => $contactPost->emails, 'Phones' => $contactPost->phones, 'Addresses' => $contactPost->addresses] as $label => $value)
                <div class="col-12 col-xl-4">
                    <div class="p-3 rounded border bg-white h-100">
                        <p class="text-muted small mb-2">{{ $label }}</p>
                        <div class="small lh-lg">{!! $renderJsonValue($value) !!}</div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
@endsection

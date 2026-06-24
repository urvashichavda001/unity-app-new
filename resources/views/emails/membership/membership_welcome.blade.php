@php
    $peerName = $user->display_name ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'Peer';
    $formatDate = static function ($value) {
        if (blank($value)) {
            return '—';
        }
        try {
            return \Illuminate\Support\Carbon::parse($value)->format('d-m-Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    };
@endphp
@include('emails.membership.partials.dark_card', [
    'title' => 'Welcome to Peers Global Unity',
    'greetingName' => $peerName,
    'message' => 'Welcome to Peers Global Unity. Your membership has been activated successfully.',
    'rows' => [
        'Peer Name' => $peerName,
        'Email' => $user->email ?: '—',
        'Membership Status' => \Illuminate\Support\Str::headline(str_replace('_', ' ', (string) ($user->membership_status ?: '—'))),
        'Membership Start Date' => $formatDate($user->membership_starts_at ?? null),
        'Membership Expiry Date' => $formatDate($user->membership_ends_at ?? $user->membership_expiry ?? null),
        'Plan Code / Membership Plan' => $user->zoho_plan_code ?: '—',
        'Activated At' => now()->format('d-m-Y h:i A'),
    ],
])

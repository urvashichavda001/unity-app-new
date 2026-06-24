@php
    $welcomeSent = filled($user->welcome_membership_email_sent_at);
    $welcomeStatusRaw = $user->welcome_membership_email_status;
    $welcomeStatus = $welcomeStatusRaw ?: ($welcomeSent ? 'sent' : 'not_sent');
    $welcomeSentLabel = $welcomeSent ? 'Yes' : 'No';
    $welcomeSentAt = optional($user->welcome_membership_email_sent_at)->format('Y-m-d H:i:s') ?: '—';
    $welcomePlanCode = $user->welcome_membership_email_plan_code ?: '—';
    $welcomeError = $user->welcome_membership_email_error ?: '—';

    $showSendButton = ($showSendButton ?? false) && ! $welcomeSent;
    $sendButtonClass = $sendButtonClass ?? 'btn btn-outline-primary btn-sm';
    $sendButtonLabel = $sendButtonLabel ?? 'Send Welcome Mail';
@endphp

<div class="card {{ $cardClass ?? '' }}">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center {{ $headerClass ?? '' }}">
        <span>Membership Welcome Email</span>
        @if ($welcomeSent)
            <span class="badge bg-success-subtle text-success">Already Sent</span>
        @else
            <span class="badge bg-warning-subtle text-warning">Not Sent Yet</span>
        @endif
    </div>

    <div class="card-body {{ $bodyClass ?? '' }}">
        <div class="row g-3">
            <div class="col-md-4">
                <div class="small text-muted">Welcome Email Sent</div>
                <div class="fw-semibold">{{ $welcomeSentLabel }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">Sent At</div>
                <div class="fw-semibold">{{ $welcomeSentAt }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">Status</div>
                <div class="fw-semibold text-capitalize">{{ str_replace('_', ' ', $welcomeStatus) }}</div>
            </div>
            <div class="col-md-4">
                <div class="small text-muted">Plan Code At Send</div>
                <div class="fw-semibold">{{ $welcomePlanCode }}</div>
            </div>
            <div class="col-md-8">
                <div class="small text-muted">Last Error</div>
                <div class="text-break">{{ $welcomeError }}</div>
            </div>
            <div class="col-12 d-flex justify-content-end">
                @if ($welcomeSent)
                    <button type="button" class="btn btn-success btn-sm" disabled>Already Sent</button>
                @elseif ($showSendButton)
                    @if (! empty($sendFormId ?? null))
                        <button
                            type="submit"
                            form="{{ $sendFormId }}"
                            class="{{ $sendButtonClass }}"
                            onclick="return confirm('Send membership welcome email now?');"
                        >
                            {{ $sendButtonLabel }}
                        </button>
                    @else
                        <form method="POST" action="{{ route('admin.users.membership-welcome-email.send', $user->id) }}">
                            @csrf
                            <button
                                type="submit"
                                class="{{ $sendButtonClass }}"
                                onclick="return confirm('Send membership welcome email now?');"
                            >
                                {{ $sendButtonLabel }}
                            </button>
                        </form>
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>

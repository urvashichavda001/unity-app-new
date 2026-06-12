@php
    $actorName = $actor?->display_name ?: trim(($actor->first_name ?? '') . ' ' . ($actor->last_name ?? '')) ?: 'A peer';
    $recipientName = $recipient?->display_name ?: trim(($recipient->first_name ?? '') . ' ' . ($recipient->last_name ?? '')) ?: 'Peer';
@endphp

<p>Hello {{ $recipientName }},</p>

@if ($eventType === 'p2p_reschedule_requested')
    <p>{{ $actorName }} has requested to reschedule your P2P meeting.</p>
@elseif ($eventType === 'p2p_reschedule_approved')
    <p>{{ $actorName }} has approved your P2P meeting reschedule request.</p>
@elseif ($eventType === 'p2p_reschedule_rejected')
    <p>{{ $actorName }} has rejected your P2P meeting reschedule request.</p>
@elseif ($eventType === 'p2p_meeting_completed')
    <p>Your P2P meeting has been marked completed by both peers.</p>
@else
    <p>There is an update about your P2P meeting.</p>
@endif

<p><strong>Current meeting time:</strong> {{ optional($meetingRequest->scheduled_at)->format('Y-m-d H:i') ?? 'Not scheduled' }}</p>
<p><strong>Current place:</strong> {{ $meetingRequest->place ?: 'Not specified' }}</p>

@if ($rescheduleRequest)
    <p><strong>Old time:</strong> {{ optional($rescheduleRequest->old_scheduled_at)->format('Y-m-d H:i') ?? 'Not scheduled' }}</p>
    <p><strong>New time:</strong> {{ optional($rescheduleRequest->new_scheduled_at)->format('Y-m-d H:i') ?? 'Not scheduled' }}</p>
    <p><strong>Old place:</strong> {{ $rescheduleRequest->old_place ?: 'Not specified' }}</p>
    <p><strong>New place:</strong> {{ $rescheduleRequest->new_place ?: 'Not specified' }}</p>
    @if ($rescheduleRequest->reason)
        <p><strong>Reason:</strong> {{ $rescheduleRequest->reason }}</p>
    @endif
@endif

@if ($responseReason)
    <p><strong>Response note:</strong> {{ $responseReason }}</p>
@endif

<p>Regards,<br>Peers Global Unity</p>

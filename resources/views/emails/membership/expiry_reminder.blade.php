@extends('emails.layouts.email')

@section('title', 'Membership Expired – Action Required')

@section('content')
    <p style="margin:0 0 12px 0; color:#ffffff; font-size:18px; font-weight:700;">Dear {{ $user->display_name }},</p>
    <p style="margin:0 0 16px 0; color:#e2e8f0; font-size:15px; line-height:22px;">
        Your membership with Peers Global Unity has expired as of {{ $membership_ends_at }}.
    </p>
    <p style="margin:0 0 16px 0; color:#e2e8f0; font-size:15px; line-height:22px;">
        We truly appreciate your association with our community and the contributions you have made so far.
    </p>
    <p style="margin:0 0 16px 0; color:#e2e8f0; font-size:15px; line-height:22px;">
        To continue enjoying all member benefits, including access to events, resources, and networking opportunities, we request you to renew your membership at the earliest convenience.
    </p>
    <p style="margin:0 0 16px 0; color:#e2e8f0; font-size:15px; line-height:22px;">
        If you have already completed the renewal process, please ignore this message.
    </p>
    <p style="margin:0 0 24px 0; color:#e2e8f0; font-size:15px; line-height:22px;">
        For any questions or assistance, feel free to contact us at <a href="mailto:{{ $support_email }}" style="color:#38bdf8; text-decoration:underline;">{{ $support_email }}</a>.
    </p>
    <p style="margin:0; color:#94a3b8; font-size:13px; line-height:20px;">
        Warm regards,<br>
        Peers Global Unity
    </p>
@endsection

@section('footer')
    <p style="margin:0; font-size:12px; line-height:18px; color:#d1d5db;">
        This email was sent to notify you about your membership status. If you have already completed the renewal process, please ignore this message.
    </p>
@endsection

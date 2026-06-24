@extends('emails.layouts.email')

@section('title', 'Upcoming Membership Expiry – Renewal Reminder')

@section('content')
    <p style="margin:0 0 12px 0; color:#ffffff; font-size:18px; font-weight:700;">Dear {{ $user->display_name }},</p>
    <p style="margin:0 0 16px 0; color:#e2e8f0; font-size:15px; line-height:22px;">
        Your membership with Peers Global Unity is approaching its expiry date on {{ $membership_ends_at }}.
    </p>
    <p style="margin:0 0 16px 0; color:#e2e8f0; font-size:15px; line-height:22px;">
        We value your participation and look forward to your continued presence in our community.
    </p>
    <p style="margin:0 0 16px 0; color:#e2e8f0; font-size:15px; line-height:22px;">
        To avoid any interruption in accessing events, resources, and community networks, please renew your membership before the expiration date.
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
        This email was sent to notify you about your upcoming membership status. If you have already completed the renewal process, please ignore this message.
    </p>
@endsection

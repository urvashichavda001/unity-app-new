@extends('emails.layouts.email')

@section('title', 'Welcome to your Peers Unity Membership')

@section('content')
    Dear <strong>{{ $user->display_name }}</strong>,<br /><br />

    Welcome to <strong>Peers Global Unity</strong>.<br /><br />

    We are pleased to confirm that your membership has been successfully activated. Your welcome kit and membership documents are attached for your reference.<br /><br />

    <strong>Join Date:</strong> {{ optional($user->created_at)->format('d M Y') }}<br />
    <strong>Support Email:</strong> <a href="mailto:support@peersglobal.com" style="color:#38bdf8; text-decoration:underline;">support@peersglobal.com</a><br /><br />

    Thank you for joining the Peers Global community. We look forward to your active participation and growth journey with us.<br /><br />

    Warm Regards,<br />
    Peers Global Unity
@endsection

@section('footer')
    <p style="margin:0; font-size:14px; font-weight:bold; color:#ffffff; text-align:center;">
        Peers are partners in business and friends in life.
    </p>
@endsection

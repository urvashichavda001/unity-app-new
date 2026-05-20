<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Support Ticket Received</title>
</head>
<body style="font-family: Arial, sans-serif; color: #1f2937; line-height: 1.6;">
<p>Hello {{ $ticket->contact_name }},</p>
<p>We have received your support request.</p>
<p><strong>Ticket Number:</strong> {{ $ticket->ticket_number }}</p>
<p><strong>Subject:</strong> {{ $ticket->subject }}</p>
<p><strong>Description:</strong> {{ $ticket->description }}</p>
<p><strong>Submitted Date:</strong> {{ optional($ticket->created_at)->format('Y-m-d H:i:s') }}</p>
@if($ticket->media_url)
<p><strong>Attached Media:</strong> {{ $ticket->media_url }}</p>
@endif
<p>Our support team will review it and contact you soon.</p>
<p>Thank you,<br>Peers Unity Team</p>
</body>
</html>

<p>Hi {{ $user->display_name ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'Peer' }},</p>

<p>Congratulations! Your PeersGlobal membership has been approved.</p>

<ul>
    <li><strong>Membership:</strong> {{ $membershipLabel }}</li>
    <li><strong>Start date:</strong> {{ $startDate->format('d M Y') }}</li>
    <li><strong>End date:</strong> {{ $endDate->format('d M Y') }}</li>
</ul>

<p>Thank you for being part of PeersGlobal Unity.</p>

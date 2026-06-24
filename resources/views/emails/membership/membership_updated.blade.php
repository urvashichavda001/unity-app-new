@include('emails.membership.partials.dark_card', [
    'title' => 'Membership Updated',
    'greetingName' => $details['peer_name'] ?? 'Peer',
    'message' => 'Your Peers Global Unity membership details have been updated.',
    'rows' => [
        'Peer Name' => $details['peer_name'] ?? '—',
        'Email' => $details['email'] ?? '—',
        'Old Membership Status' => $details['old_membership_status'] ?? '—',
        'New Membership Status' => $details['new_membership_status'] ?? '—',
        'Old Membership Expiry' => $details['old_membership_expiry'] ?? '—',
        'New Membership Expiry' => $details['new_membership_expiry'] ?? '—',
        'Updated At' => $details['updated_at'] ?? '—',
    ],
])

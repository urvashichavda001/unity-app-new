@php
    $userName = trim((string) ($user->display_name ?? ''));
    if ($userName === '') {
        $userName = trim(trim((string) ($user->first_name ?? '')) . ' ' . trim((string) ($user->last_name ?? '')));
    }
    $userName = $userName !== '' ? $userName : 'Member';

    $renderedBodyHtml = $bodyHtml ?? $campaign->email_body;
    $bodyText = strtolower(strip_tags((string) $renderedBodyHtml));
    $hasClosing = str_contains($bodyText, 'peers global team')
        || str_contains($bodyText, 'wishing you')
        || str_contains($bodyText, 'with appreciation')
        || str_contains($bodyText, 'warm regards')
        || str_contains($bodyText, 'regards');
@endphp
<table style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 30px;" width="100%" cellspacing="0" cellpadding="0">
<tbody>
<tr>
<td align="center">
<table style="background-color: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05);" width="600" cellspacing="0" cellpadding="0"><!-- HEADER -->
<tbody>
<tr>
<td style="padding: 14px 14px; background-color: #240e5c; text-align: center;"><img style="vertical-align: middle;" src="https://unity.peersglobal.com/wp-content/uploads/2025/08/peersglobal_white-removebg-preview.png" alt="Peers Global" width="135" /></td>
</tr>
<!-- BODY -->
<tr>
<td style="padding: 5px 10px 5px 10px; font-size: 16px; color: #333333;">
Dear <strong>{{ $userName }}</strong>,<br /><br />
<div style="font-family: Arial, sans-serif; font-size: 16px; color: #333333; line-height: normal;">
{!! $renderedBodyHtml !!}
</div>
@if (! $hasClosing)
<br />
Wishing you a meaningful conversation,<br />
<strong>Peers Global Team</strong>
@endif
</td>
</tr>
<!-- FOOTER -->
<tr>
<td style="padding: 10px 14px; background-color: #240e5c; text-align: center; border-bottom-left-radius: 10px; border-bottom-right-radius: 10px;">
<p style="font-size: 14px; font-weight: bold; color: #ffffff; margin: 4px 0;">Peers are partners in business and friends in life.</p>
</td>
</tr>
</tbody>
</table>
</td>
</tr>
</tbody>
</table>

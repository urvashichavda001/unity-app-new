@php
    $rows = $rows ?? [];
    $title = $title ?? 'Peers Global Unity';
    $greetingName = $greetingName ?? 'Peer';
    $message = $message ?? '';
@endphp
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0;padding:0;background:#10091f;font-family:Arial,Helvetica,sans-serif;color:#f4f0ff;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#171025;border-radius:16px;overflow:hidden;border:1px solid #3a2468;">
                <tr>
                    <td align="center" style="background:#32106f;padding:22px 18px;">
                        <img src="https://unity.peersglobal.com/wp-content/uploads/2025/08/peersglobal_white-removebg-preview.png" alt="Peers Global" width="150" style="display:block;max-width:150px;height:auto;margin:0 auto 8px;">
                        <div style="font-size:20px;font-weight:700;color:#ffffff;letter-spacing:.2px;">Peers Global Unity</div>
                    </td>
                </tr>
                <tr>
                    <td style="padding:28px 24px;">
                        <h1 style="margin:0 0 18px;font-size:26px;line-height:1.25;color:#ffffff;">{{ $title }}</h1>
                        <p style="margin:0 0 14px;font-size:16px;line-height:1.6;color:#e7ddff;">Dear <strong>{{ $greetingName ?: 'Peer' }}</strong>,</p>
                        <p style="margin:0 0 22px;font-size:16px;line-height:1.6;color:#d9d2e8;">{{ $message }}</p>
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;background:#211734;border:1px solid #4b3480;border-radius:12px;overflow:hidden;">
                            @foreach($rows as $label => $value)
                                <tr>
                                    <td style="width:44%;padding:13px 14px;border-bottom:1px solid #3a295d;color:#b9aad8;font-size:14px;vertical-align:top;">{{ $label }}</td>
                                    <td style="padding:13px 14px;border-bottom:1px solid #3a295d;color:#ffffff;font-size:14px;font-weight:600;vertical-align:top;word-break:break-word;">{{ filled($value) ? $value : '—' }}</td>
                                </tr>
                            @endforeach
                        </table>
                        <p style="margin:22px 0 0;font-size:15px;line-height:1.6;color:#d9d2e8;">Warm regards,<br><strong>Peers Global Team</strong></p>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="background:#32106f;padding:16px 18px;color:#ffffff;font-size:14px;font-weight:700;">
                        Peers are partners in business and friends in life.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your PeersGlobal Membership Has Been Approved</title>
</head>
<body style="margin:0;padding:0;background:#202020;font-family:Arial,Helvetica,sans-serif;color:#e5e7eb;">
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#202020;padding:30px 12px;">
        <tr>
            <td align="center">
                <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="max-width:620px;background:#0f0f12;border-radius:18px;overflow:hidden;border-collapse:separate;">
                    <tr>
                        <td style="background:#2b076d;padding:34px 24px;text-align:center;">
                            @if(!empty($logoUrl))
                                <img src="{{ $logoUrl }}" alt="PeersGlobal" style="max-width:280px;height:auto;display:block;margin:0 auto;">
                            @else
                                <h1 style="margin:0;color:#ffffff;font-size:30px;font-weight:700;letter-spacing:.3px;">PeersGlobal</h1>
                                <p style="margin:6px 0 0;color:#e5e7eb;font-size:15px;">Community of Collaboration</p>
                            @endif
                        </td>
                    </tr>

                    <tr>
                        <td style="background:#101010;padding:38px 30px;color:#e5e7eb;">
                            <p style="margin:0 0 26px;font-size:30px;line-height:1.35;color:#e5e7eb;">
                                Dear <strong style="color:#ffffff;">{{ $userName ?: 'Peer' }}</strong>,
                            </p>

                            <p style="margin:0 0 24px;font-size:24px;line-height:1.55;color:#d8d8d8;">
                                Congratulations! Your PeersGlobal membership has been approved and upgraded to
                                <strong style="color:#ffffff;">Only Unity Peer</strong>.
                            </p>

                            <p style="margin:0 0 18px;font-size:22px;line-height:1.5;color:#d8d8d8;">
                                Your membership details are:
                            </p>

                            <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#18181b;border:1px solid #333333;border-radius:12px;overflow:hidden;margin:24px 0;">
                                <tr>
                                    <td style="padding:14px 18px;border-bottom:1px solid #2f2f2f;color:#a3a3a3;font-size:15px;width:38%;">Name</td>
                                    <td style="padding:14px 18px;border-bottom:1px solid #2f2f2f;color:#ffffff;font-size:15px;font-weight:700;">{{ $userName ?: '—' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 18px;border-bottom:1px solid #2f2f2f;color:#a3a3a3;font-size:15px;">Email</td>
                                    <td style="padding:14px 18px;border-bottom:1px solid #2f2f2f;color:#ffffff;font-size:15px;font-weight:700;">{{ $user->email ?: '—' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 18px;border-bottom:1px solid #2f2f2f;color:#a3a3a3;font-size:15px;">Phone</td>
                                    <td style="padding:14px 18px;border-bottom:1px solid #2f2f2f;color:#ffffff;font-size:15px;font-weight:700;">{{ $user->phone ?: '—' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 18px;border-bottom:1px solid #2f2f2f;color:#a3a3a3;font-size:15px;">Membership</td>
                                    <td style="padding:14px 18px;border-bottom:1px solid #2f2f2f;color:#ffffff;font-size:15px;font-weight:700;">Only Unity Peer</td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 18px;border-bottom:1px solid #2f2f2f;color:#a3a3a3;font-size:15px;">Membership Starts At</td>
                                    <td style="padding:14px 18px;border-bottom:1px solid #2f2f2f;color:#ffffff;font-size:15px;font-weight:700;">{{ $membershipStartsAt ?? '—' }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:14px 18px;color:#a3a3a3;font-size:15px;">Membership Ends At</td>
                                    <td style="padding:14px 18px;color:#ffffff;font-size:15px;font-weight:700;">{{ $membershipEndsAt ?? '—' }}</td>
                                </tr>
                            </table>

                            <p style="margin:28px 0 0;font-size:24px;line-height:1.55;color:#d8d8d8;">
                                Thank you for being a part of PeersGlobal Community of Collaboration.
                            </p>

                            <p style="margin:34px 0 0;font-size:24px;line-height:1.5;color:#d8d8d8;">
                                With appreciation,<br>
                                <strong style="color:#ffffff;">Peers Global Team</strong>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="background:#2b076d;padding:30px 24px;text-align:center;">
                            <p style="margin:0;color:#ffffff;font-size:22px;line-height:1.4;font-weight:700;">
                                Peers are partners in business and<br>
                                friends in life.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

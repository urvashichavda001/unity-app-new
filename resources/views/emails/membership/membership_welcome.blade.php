<table width="100%" cellspacing="0" cellpadding="0" style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 30px;">
    <tbody>
    <tr>
        <td align="center">
            <table width="600" cellspacing="0" cellpadding="0" style="background-color: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                <tbody>
                <tr>
                    <td style="padding: 14px 14px; background-color: #240e5c; text-align: center;">
                        <img src="https://unity.peersglobal.com/wp-content/uploads/2025/08/peersglobal_white-removebg-preview.png" alt="Peers Global" width="135" style="vertical-align: middle;" />
                    </td>
                </tr>
                <tr>
                    <td style="padding: 24px 22px; font-size: 16px; line-height: 1.65; color: #333333;">
                        Dear <strong>{{ $user->display_name ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'Peer' }}</strong>,<br /><br />

                        Welcome to <strong>Peers Global Unity</strong>.<br /><br />

                        We are pleased to confirm that your membership has been successfully activated. Your welcome kit and membership documents are attached for your reference.<br /><br />

                        Thank you for joining the Peers Global community. We look forward to your active participation and growth journey with us.<br /><br />

                        Warm regards,<br />
                        <strong>Peers Global Team</strong>
                    </td>
                </tr>
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

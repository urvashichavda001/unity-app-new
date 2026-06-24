<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Peers Global Unity')</title>
    <style>
        @media only screen and (max-width: 600px) {
            .email-container {
                width: 100% !important;
                border-radius: 0 !important;
            }
            .content-padding {
                padding: 24px 16px !important;
            }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#eaeaea; font-family:'Helvetica Neue', Arial, sans-serif;-webkit-font-smoothing:antialiased;-ms-text-size-adjust:100%;-webkit-text-size-adjust:100%;">
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color:#eaeaea; padding:24px 0; border-collapse:collapse; mso-table-lspace:0pt; mso-table-rspace:0pt;">
        <tr>
            <td align="center" style="border-collapse:collapse;">
                <!-- Centered Card Container -->
                <table role="presentation" cellpadding="0" cellspacing="0" class="email-container" width="600" style="max-width:600px; width:100%; background-color:#121824; border-radius:12px; overflow:hidden; border:none; box-shadow:0 10px 30px rgba(0,0,0,0.15); border-collapse:collapse; mso-table-lspace:0pt; mso-table-rspace:0pt;">
                    <!-- Purple Branded Header -->
                    <tr>
                        <td align="center" style="background-color:#240e5c; padding:24px; border-collapse:collapse; text-align:center;">
                            <img src="https://unity.peersglobal.com/wp-content/uploads/2025/08/peersglobal_white-removebg-preview.png" alt="Peers Global Unity" width="135" style="border:0; outline:none; text-decoration:none; vertical-align:middle; display:inline-block;" />
                        </td>
                    </tr>
                    <!-- Dark Content Body -->
                    <tr>
                        <td class="content-padding" style="padding:32px 24px; color:#e2e8f0; font-size:15px; line-height:1.65; border-collapse:collapse;">
                            @yield('content')
                        </td>
                    </tr>
                    <!-- Purple Footer -->
                    <tr>
                        <td align="center" style="background-color:#240e5c; padding:24px; color:#ffffff; font-size:13px; line-height:1.5; text-align:center; border-collapse:collapse;">
                            @yield('footer')
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

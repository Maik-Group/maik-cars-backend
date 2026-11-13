<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background-color:#FAF8F6;color:#1E1A17;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
    <div style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;mso-hide:all;">{{ $heading }} in the Maik Cat app. Your secure code expires in {{ $expiryMinutes }} minutes.</div>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#FAF8F6" style="background-color:#FAF8F6;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <!--[if mso]><table role="presentation" width="520" align="center" cellspacing="0" cellpadding="0" border="0"><tr><td><![endif]-->
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:520px;">
                    <tr>
                        <td bgcolor="#FFFFFF" style="padding:28px 24px;border:1px solid #E8DCCF;border-radius:20px;background-color:#FFFFFF;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td align="center" style="padding:0 0 22px;">
                                        <img src="cid:{{ $logoContentId }}" width="144" height="71" alt="Maik Cat" style="display:block;width:144px;height:auto;max-width:100%;border:0;margin:0 auto;color:#A84400;font-size:22px;font-weight:bold;">
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center" style="padding-bottom:12px;">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                            <tr><td bgcolor="#FFEDE1" style="padding:7px 12px;border-radius:20px;color:#A84400;font-size:11px;line-height:16px;font-weight:bold;letter-spacing:1px;">{{ $eyebrow }}</td></tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center" style="padding-bottom:10px;">
                                        <h1 style="margin:0;color:#1E1A17;font-size:24px;line-height:32px;font-weight:bold;">{{ $heading }}</h1>
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center" style="padding-bottom:24px;color:#6A625C;font-size:15px;line-height:24px;">{{ $intro }}</td>
                                </tr>
                                <tr>
                                    <td align="center" bgcolor="#FFF8F3" style="padding:20px 8px;border:1px solid #E8DCCF;border-radius:12px;background-color:#FFF8F3;">
                                        <p style="margin:0 0 10px;color:#6A625C;font-size:12px;line-height:18px;font-weight:bold;letter-spacing:.6px;">YOUR SIX-DIGIT CODE</p>
                                        <p dir="ltr" style="margin:0;color:#A84400;font-family:Arial,Helvetica,sans-serif;font-size:36px;line-height:46px;font-weight:bold;letter-spacing:5px;white-space:nowrap;font-variant-numeric:tabular-nums;">{{ $code }}</p>
                                        <p style="margin:10px 0 0;color:#6A625C;font-size:13px;line-height:20px;">Expires in <strong style="color:#1E1A17;">{{ $expiryMinutes }} minutes</strong></p>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:24px 0 20px;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                            <tr>
                                                <td width="4" bgcolor="#C55000" style="background-color:#C55000;font-size:0;line-height:0;">&nbsp;</td>
                                                <td style="padding:2px 0 2px 12px;color:#1E1A17;font-size:14px;line-height:22px;">{{ $instruction }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="border-top:1px solid #E8DCCF;padding-top:18px;color:#6A625C;font-size:13px;line-height:21px;">
                                        <strong style="color:#1E1A17;">Keep your account secure.</strong><br>
                                        Never share this code with anyone. If you did not request it, ignore this email.
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:20px 12px 0;color:#6A625C;font-size:12px;line-height:19px;">
                            <strong style="color:#1E1A17;">Maik Cat</strong><br>
                            This is an automated account security email.
                        </td>
                    </tr>
                </table>
                <!--[if mso]></td></tr></table><![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>

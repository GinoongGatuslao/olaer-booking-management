<div style="font-family: Arial, sans-serif; color: #111827; line-height: 1.5; max-width: 720px; margin: 0 auto;">
    <h1 style="font-size: 22px; margin-bottom: 4px;">Olaer Spring Resort</h1>
    <h2 style="font-size: 18px; color: #1d4ed8; margin-top: 0;">Password Reset Code</h2>

    <p>Hello {{ $name }},</p>
    <p>We received a request to reset your password. Enter the code below on the reset page to continue.</p>

    <table role="presentation" style="width: 100%; border-collapse: collapse; margin: 24px 0;">
        <tbody>
            <tr>
                <td align="center" style="background: #eff6ff; border: 2px dashed #1d4ed8; border-radius: 12px; padding: 20px;">
                    <div style="font-size: 12px; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; color: #1e40af; margin-bottom: 8px;">Your reset code</div>
                    <div style="font-family: 'Courier New', Courier, monospace; font-size: 38px; font-weight: bold; letter-spacing: 10px; padding-left: 10px; color: #1e3a8a;">{{ $code }}</div>
                </td>
            </tr>
        </tbody>
    </table>

    <table style="width: 100%; border-collapse: collapse;">
        <tbody>
            <tr>
                <td style="border: 1px solid #e5e7eb; padding: 8px; font-weight: bold; width: 35%;">Expires in</td>
                <td style="border: 1px solid #e5e7eb; padding: 8px;">{{ $minutes }} minutes</td>
            </tr>
            <tr>
                <td style="border: 1px solid #e5e7eb; padding: 8px; font-weight: bold;">Only the newest code works</td>
                <td style="border: 1px solid #e5e7eb; padding: 8px;">Requesting another code cancels this one.</td>
            </tr>
        </tbody>
    </table>

    <p style="margin-top: 20px; font-size: 13px; color: #4b5563;">
        Do not share this code with anyone. Resort staff will never ask you for it.
        If you did not request a password reset, you can ignore this email and your password will stay the same.
    </p>
    <p style="margin-top: 12px; font-size: 13px; color: #4b5563;">
        This is an automated email. Please contact the resort directly for urgent concerns.
    </p>
</div>

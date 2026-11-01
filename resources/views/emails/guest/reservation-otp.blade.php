<div style="font-family: Arial, sans-serif; color: #111827; line-height: 1.5; max-width: 720px; margin: 0 auto;">
    <h1 style="font-size: 22px; margin-bottom: 4px;">Olaer Spring Resort</h1>
    <h2 style="font-size: 18px; color: #047857; margin-top: 0;">Reservation Verification Code</h2>

    <p>Hello,</p>
    <p>Use the code below to verify your identity and manage your reservation.</p>

    <table role="presentation" style="width: 100%; border-collapse: collapse; margin: 24px 0;">
        <tbody>
            <tr>
                <td align="center" style="background: #ecfdf5; border: 2px dashed #047857; border-radius: 12px; padding: 20px;">
                    <div style="font-size: 12px; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; color: #065f46; margin-bottom: 8px;">Your verification code</div>
                    <div style="font-family: 'Courier New', Courier, monospace; font-size: 38px; font-weight: bold; letter-spacing: 10px; padding-left: 10px; color: #064e3b;">{{ $code }}</div>
                </td>
            </tr>
        </tbody>
    </table>

    <table style="width: 100%; border-collapse: collapse;">
        <tbody>
            <tr>
                <td style="border: 1px solid #e5e7eb; padding: 8px; font-weight: bold; width: 35%;">Reservation Reference No.</td>
                <td style="border: 1px solid #e5e7eb; padding: 8px;">{{ $reference }}</td>
            </tr>
            <tr>
                <td style="border: 1px solid #e5e7eb; padding: 8px; font-weight: bold;">Expires in</td>
                <td style="border: 1px solid #e5e7eb; padding: 8px;">{{ $minutes }} minutes</td>
            </tr>
        </tbody>
    </table>

    <p style="margin-top: 20px; font-size: 13px; color: #4b5563;">
        Do not share this code with anyone. If you did not request it, you can ignore this email.
    </p>
    <p style="margin-top: 12px; font-size: 13px; color: #4b5563;">
        This is an automated email. Please contact the resort directly for urgent changes.
    </p>
</div>

<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; color: #0f172a; background: #f8fafc; padding: 32px;">
    <div style="max-width: 420px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 32px; border: 1px solid #e2e8f0;">
        <p style="margin: 0 0 16px; font-size: 14px; color: #475569;">Код для входа в Nodus:</p>
        <p style="margin: 0 0 16px; font-size: 32px; font-weight: 700; letter-spacing: 0.15em; color: #0f172a;">{{ $code }}</p>
        <p style="margin: 0; font-size: 13px; color: #94a3b8;">
            Код действует {{ $ttlMinutes }} минут. Если вы не пытались войти — просто проигнорируйте это письмо.
        </p>
    </div>
</body>
</html>

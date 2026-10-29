<!doctype html>
<html>
<body style="margin:0;padding:24px;background:#f4f7f2;font-family:Arial,Helvetica,sans-serif;color:#1d2b1f;">
    <div style="max-width:420px;margin:0 auto;background:#ffffff;border-radius:16px;padding:28px;text-align:center;">
        <h1 style="margin:0 0 8px;font-size:22px;color:#2e7d32;">Verify your email</h1>
        <p style="margin:0 0 20px;">Hi {{ $name }}, enter this code in GreenPoints to finish creating your account.</p>
        <div style="font-size:34px;font-weight:bold;letter-spacing:8px;padding:14px;background:#eef6ec;border-radius:12px;">{{ $code }}</div>
        <p style="margin:20px 0 0;font-size:13px;color:#667066;">The code expires in {{ $ttlMinutes }} minutes. If you didn't sign up for GreenPoints, you can ignore this email.</p>
    </div>
</body>
</html>

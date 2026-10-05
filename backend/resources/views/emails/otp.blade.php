<!DOCTYPE html>
<html lang="vi">
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    <p>Chào {{ $recipientName }},</p>
    <p>Mã xác thực của bạn là:</p>
    <p style="font-size: 28px; font-weight: bold; letter-spacing: 6px;">{{ $code }}</p>
    <p>Mã có hiệu lực trong {{ $ttlMinutes }} phút. Không chia sẻ mã này với bất kỳ ai, kể cả người tự xưng là nhân viên VitaminVui.</p>
    <p>Nếu bạn không yêu cầu mã này, hãy bỏ qua email.</p>
</body>
</html>

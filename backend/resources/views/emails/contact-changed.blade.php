<!DOCTYPE html>
<html lang="vi">
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    <p>Chào {{ $recipientName }},</p>
    <p>Email đăng nhập của tài khoản VitaminVui gắn với địa chỉ này vừa được đổi sang <strong>{{ $maskedNewEmail }}</strong> lúc {{ $changedAt }}.</p>
    <p>Nếu là bạn, không cần làm gì thêm. Nếu không phải bạn, hãy liên hệ hỗ trợ ngay tại {{ $supportEmail }} để được khoá và khôi phục tài khoản. Chúng tôi sẽ không bao giờ hỏi mật khẩu hay mã xác thực của bạn qua email.</p>
</body>
</html>

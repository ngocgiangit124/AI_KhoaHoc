<!DOCTYPE html>
<html lang="vi">
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    <p>Chào {{ $recipientName }},</p>
    <p>Tài khoản quản trị VitaminVui của bạn vừa đăng nhập từ một thiết bị hoặc trình duyệt mới.</p>
    <ul>
        <li>Thời gian: {{ $loggedInAt }}</li>
        <li>Địa chỉ IP: {{ $ip ?? 'không xác định' }}</li>
        <li>Thiết bị: {{ $userAgent !== '' ? $userAgent : 'không xác định' }}</li>
    </ul>
    <p>Nếu là bạn, không cần làm gì thêm. Nếu không phải bạn, hãy đổi mật khẩu ngay và báo cho quản trị viên để khoá tài khoản.</p>
</body>
</html>

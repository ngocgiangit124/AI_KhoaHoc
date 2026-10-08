<!DOCTYPE html>
<html lang="vi">
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    <p>Chào {{ $recipientName }},</p>
    <p>@if ($purpose === \App\Enums\OtpPurpose::StaffLoginMfa)Mã đăng nhập trang quản trị VitaminVui của bạn là:@elseif ($purpose === \App\Enums\OtpPurpose::DeleteAccount)Mã xác nhận XOÁ TÀI KHOẢN VitaminVui của bạn là:@else Mã xác thực của bạn là:@endif</p>
    <p style="font-size: 28px; font-weight: bold; letter-spacing: 6px;">{{ $code }}</p>
    <p>Mã có hiệu lực trong {{ $ttlMinutes }} phút. Không chia sẻ mã này với bất kỳ ai, kể cả người tự xưng là nhân viên VitaminVui.</p>
    @if ($purpose === \App\Enums\OtpPurpose::DeleteAccount)
    <p><strong>Cảnh báo:</strong> nhập mã này sẽ xoá vĩnh viễn thông tin cá nhân của tài khoản và không thể hoàn tác. Chỉ nhập mã khi chính bạn muốn xoá tài khoản; VitaminVui không bao giờ yêu cầu bạn gửi mã này cho ai.</p>
    @endif
    <p>Nếu bạn không yêu cầu mã này, hãy bỏ qua email.</p>
</body>
</html>

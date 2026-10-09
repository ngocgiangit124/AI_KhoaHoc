<!DOCTYPE html>
<html lang="vi">
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    <p>Có đơn thanh toán thủ công mới cần liên hệ.</p>
    <ul>
        <li>Mã đơn: <strong>{{ $orderCode }}</strong></li>
        <li>Tổng tiền: {{ number_format($total, 0, ',', '.') }}đ</li>
        <li>Số khóa học: {{ $courseCount }}</li>
        <li>Thời điểm: {{ $createdAtText }}</li>
    </ul>
    <p><a href="{{ $adminUrl }}">Mở đơn trong trang quản trị</a></p>
</body>
</html>

<!DOCTYPE html>
<html lang="vi">
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    <p>Chào {{ $studentName }},</p>
    <p>Chúng tôi đã nhận đơn <strong>#{{ $orderCode }}</strong> của bạn. Quản trị viên sẽ liên hệ hướng dẫn thanh toán và kích hoạt khóa học cho bạn.</p>
    <ul>
        @foreach ($courseTitles as $title)
            <li>{{ $title }}</li>
        @endforeach
    </ul>
    <p>Tổng tiền: <strong>{{ number_format($total, 0, ',', '.') }}đ</strong></p>
    <p>Đơn được giữ đến {{ $expiresAtText }}. Khi chuyển khoản, bạn nhớ ghi mã đơn <strong>{{ $orderCode }}</strong> trong nội dung.</p>
    @include('emails._manual-contact', ['contact' => $contact])
    <p><a href="{{ $orderUrl }}">Xem đơn hàng</a></p>
    <p>Trân trọng,<br>VitaminVui</p>
</body>
</html>

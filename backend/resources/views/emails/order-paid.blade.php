<!DOCTYPE html>
<html lang="vi">
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    <p>Chào {{ $studentName }},</p>
    <p>Đơn <strong>#{{ $orderCode }}</strong> đã được xác nhận thanh toán. Bạn đã có thể vào học các khóa sau:</p>
    <ul>
        @foreach ($courseTitles as $title)
            <li>{{ $title }}</li>
        @endforeach
    </ul>
    <p>Tổng tiền: <strong>{{ number_format($total, 0, ',', '.') }}đ</strong></p>
    <p><a href="{{ $orderUrl }}">Xem đơn hàng</a></p>
    <p>Trân trọng,<br>VitaminVui</p>
</body>
</html>

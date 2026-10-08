<!DOCTYPE html>
<html lang="vi">
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    <h2>Thông báo từ VitaminVui</h2>
    <p>Xin chào quý phụ huynh,</p>
    @if ($kind === 'order_paid')
        <p>Đơn hàng <strong>{{ $orderCode }}</strong> của học sinh {{ $maskedStudentName }} đã được thanh toán lúc {{ $eventAt }}.</p>
        <ul>
            @foreach ($courseTitles as $title)
                <li>{{ $title }}</li>
            @endforeach
        </ul>
        <p>Tổng tiền: <strong>{{ number_format((int) $totalAmount, 0, ',', '.') }} đ</strong></p>
    @elseif ($kind === 'parent_contact_added')
        <p>Học sinh {{ $maskedStudentName }} vừa ghi địa chỉ email này là liên hệ phụ huynh trên VitaminVui (lúc {{ $eventAt }}).</p>
    @else
        <p>Học sinh {{ $maskedStudentName }} vừa tạo tài khoản học trên VitaminVui lúc {{ $eventAt }} và đã ghi địa chỉ email này là liên hệ phụ huynh.</p>
    @endif
    <p>VitaminVui là nền tảng học trực tuyến cho học sinh lớp 6 đến lớp 12. Đây chỉ là thư thông báo, quý phụ huynh không cần thực hiện thao tác nào.</p>
    <p>Xem <a href="{{ $policyUrl }}">Chính sách dữ liệu cá nhân</a> (phiên bản {{ $policyVersion }}). Cần hỗ trợ, vui lòng liên hệ {{ $supportEmail }}.</p>
    <p>Nếu không muốn nhận thêm thư thông báo về học sinh này, quý phụ huynh có thể <a href="{{ $unsubscribeUrl }}">ngừng nhận thông báo</a>.</p>
    <p>Trân trọng,<br>VitaminVui</p>
</body>
</html>

<!DOCTYPE html>
<html lang="vi">
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    <p>Chào {{ $studentName }},</p>
    @if ($variant === 'expired')
        <p>Đơn <strong>#{{ $orderCode }}</strong> đã hết thời hạn chờ nên được huỷ tự động. Giỏ hàng của bạn vẫn còn nguyên, bạn có thể đặt lại bất cứ lúc nào.</p>
    @else
        <p>Đơn <strong>#{{ $orderCode }}</strong> đã được Quản trị viên huỷ.</p>
        @if ($publicReason !== null && $publicReason !== '')
            <p>Lý do: {{ $publicReason }}</p>
        @endif
    @endif
    @include('emails._manual-contact', ['contact' => $contact])
    <p><a href="{{ $orderUrl }}">Xem đơn hàng</a></p>
    <p>Trân trọng,<br>VitaminVui</p>
</body>
</html>

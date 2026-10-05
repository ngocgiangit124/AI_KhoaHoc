<!DOCTYPE html>
<html lang="vi">
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    <p>Chào {{ $studentName }},</p>
    @if ($approved)
        <p>Yêu cầu đăng ký học khóa <strong>{{ $courseTitle }}</strong> của bạn đã được duyệt. Bạn có thể vào học ngay bây giờ.</p>
        <p><a href="{{ $courseUrl }}">Vào học khóa "{{ $courseTitle }}"</a></p>
    @else
        <p>Rất tiếc, yêu cầu đăng ký học khóa <strong>{{ $courseTitle }}</strong> của bạn chưa được chấp nhận.</p>
        @if ($reason !== null && $reason !== '')
            <p>Lý do: {{ $reason }}</p>
        @endif
        <p>Bạn có thể gửi lại yêu cầu hoặc xem các khóa học khác tại <a href="{{ $courseUrl }}">trang khóa học</a>.</p>
    @endif
    <p>Trân trọng,<br>VitaminVui</p>
</body>
</html>

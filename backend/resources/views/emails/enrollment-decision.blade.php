<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937;">
    <p>Chào {{ $recipientName }},</p>
    @if($approved)
        <p>Yêu cầu đăng ký khóa học <strong>{{ $courseTitle }}</strong> của bạn đã được duyệt. Bạn có thể vào học ngay bây giờ.</p>
    @else
        <p>Yêu cầu đăng ký khóa học <strong>{{ $courseTitle }}</strong> của bạn chưa được duyệt lần này.</p>
        @if($reason)
            <p>Lý do: {{ $reason }}</p>
        @endif
        <p>Bạn có thể gửi lại yêu cầu đăng ký bất cứ lúc nào.</p>
    @endif
</body>
</html>

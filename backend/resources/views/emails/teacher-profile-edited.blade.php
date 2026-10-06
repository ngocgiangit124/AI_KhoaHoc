<!DOCTYPE html>
<html lang="vi">
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    <p>Chào {{ $recipientName }},</p>
    <p>Quản trị viên {{ $editorName }} vừa chỉnh sửa hồ sơ công khai của bạn trên VitaminVui lúc {{ $editedAt }}.</p>
    <p>Phần đã thay đổi: {{ implode(', ', $fieldLabels) }}.</p>
    <p>Vì bạn đang đồng ý công khai hồ sơ, nội dung mới đã hiển thị ngay trên website. Hãy đăng nhập trang quản trị ({{ config('app.admin_url') }}), mở mục "Hồ sơ của tôi" để xem lại. Nếu bạn không đồng ý với nội dung này, bạn có thể sửa lại hoặc rút đồng ý công khai bất kỳ lúc nào.</p>
</body>
</html>

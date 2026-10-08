Thông báo từ VitaminVui

Xin chào quý phụ huynh,

@if ($kind === 'order_paid')
Đơn hàng {!! $orderCode !!} của học sinh {!! $maskedStudentName !!} đã được thanh toán lúc {!! $eventAt !!}.
@foreach ($courseTitles as $title)
- {!! $title !!}
@endforeach
Tổng tiền: {{ number_format((int) $totalAmount, 0, ',', '.') }} đ
@elseif ($kind === 'parent_contact_added')
Học sinh {!! $maskedStudentName !!} vừa ghi địa chỉ email này là liên hệ phụ huynh trên VitaminVui (lúc {!! $eventAt !!}).
@else
Học sinh {!! $maskedStudentName !!} vừa tạo tài khoản học trên VitaminVui lúc {!! $eventAt !!} và đã ghi địa chỉ email này là liên hệ phụ huynh.
@endif

VitaminVui là nền tảng học trực tuyến cho học sinh lớp 6 đến lớp 12. Đây chỉ là thư thông báo, quý phụ huynh không cần thực hiện thao tác nào.

Chính sách dữ liệu cá nhân (phiên bản {!! $policyVersion !!}): {!! $policyUrl !!}
Cần hỗ trợ, liên hệ: {!! $supportEmail !!}

Ngừng nhận thông báo về học sinh này: {!! $unsubscribeUrl !!}

Trân trọng,
VitaminVui

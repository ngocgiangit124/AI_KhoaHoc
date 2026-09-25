# US-004: Giỏ hàng khóa học

**Trạng thái:** Draft
**Ưu tiên:** Must

## User story
Là học sinh, tôi muốn thêm khóa học vào giỏ hàng, áp dụng mã giảm giá (nếu có) và quản lý giỏ hàng trước khi thanh toán để có thể mua nhiều khóa học cùng lúc với giá tốt nhất.

## Bối cảnh
Bước trung gian giữa trang chi tiết khóa học (US-003) và thanh toán (US-005). Yêu cầu học sinh đã đăng nhập (US-001). Chỉ áp dụng cho khóa học có phí (khóa học miễn phí đi theo luồng đăng ký/phê duyệt riêng ở US-012, không qua giỏ hàng). Mã giảm giá được quản trị ở US-013; story này chỉ mô tả trải nghiệm nhập/áp dụng mã ở phía học sinh.

## Business rules
- BR1: Chỉ tài khoản đã đăng nhập với role `hoc_sinh` mới thêm được khóa học vào giỏ hàng.
- BR2: Mỗi khóa học chỉ tồn tại tối đa 1 lần trong giỏ hàng của một học sinh (không cho thêm trùng).
- BR3: Không cho thêm vào giỏ khóa học mà học sinh đã sở hữu (có `enrollment` active).
- BR4: Giỏ hàng không giới hạn số lượng khóa học có thể thêm.
- BR5: Giỏ hàng thuộc về từng học sinh, lưu tại CSDL (không chỉ session) để không mất khi đổi thiết bị.
- BR6: Không cho thêm khóa học miễn phí (`price = 0`) vào giỏ hàng; khóa học miễn phí dùng nút "Đăng ký" riêng (US-003, US-012).
- BR7 (Quyết định của PO — mã giảm giá VÀO PHẠM VI MVP): Học sinh có thể nhập 1 mã giảm giá tại trang giỏ hàng/checkout. Mã hợp lệ (còn hiệu lực, còn lượt dùng, học sinh chưa từng dùng mã này — US-013 BR3) sẽ giảm trừ trên tổng tiền các khóa học **thuộc phạm vi áp dụng** của mã. Mỗi giỏ hàng/đơn hàng chỉ áp dụng được **1 mã** tại một thời điểm (không cộng dồn nhiều mã — Quyết định của PO).
- BR8: Số tiền giảm không được vượt quá tổng giá trị phần giỏ hàng thuộc phạm vi áp dụng của mã (tổng thanh toán tối thiểu là 0, không âm).
- BR9 (Quyết định của PO, chi tiết ở US-013 BR4): Mã giảm giá có thể chỉ áp dụng cho một số chuyên đề/khóa học cụ thể. Khi áp mã, các khóa học trong giỏ **thuộc phạm vi** của mã được giảm giá, các khóa học **ngoài phạm vi** vẫn giữ nguyên giá gốc trong cùng đơn hàng. Nếu không có khóa học nào trong giỏ thuộc phạm vi của mã, hệ thống báo mã không áp dụng được (không cho áp dụng).

## Acceptance criteria
- AC1: Given học sinh đã đăng nhập xem khóa học có phí chưa sở hữu, When bấm "Thêm vào giỏ hàng", Then khóa học xuất hiện trong giỏ và số lượng item trên icon giỏ hàng tăng lên tương ứng.
- AC2: Given khóa học đã có sẵn trong giỏ, When học sinh bấm "Thêm vào giỏ hàng" lại, Then hệ thống không tạo dòng trùng, hiển thị thông báo "Khóa học đã có trong giỏ hàng".
- AC3: Given khóa học đã được học sinh sở hữu (enrolled active), When cố thêm vào giỏ hàng (kể cả gọi API trực tiếp), Then hệ thống từ chối và hiển thị "Bạn đã sở hữu khóa học này".
- AC4: Given giỏ hàng có 2 khóa học, When học sinh bấm xóa 1 khóa học khỏi giỏ, Then giỏ chỉ còn 1 khóa học và tổng tiền hiển thị được cập nhật lại đúng (bao gồm cả tính lại giảm giá nếu đang áp dụng mã).
- AC5: Given giỏ hàng trống, When học sinh vào trang giỏ hàng, Then hiển thị thông báo "Giỏ hàng của bạn đang trống" kèm liên kết tới trang danh mục khóa học.
- AC6: Given khách chưa đăng nhập bấm "Thêm vào giỏ hàng", When thực hiện thao tác, Then hệ thống chuyển hướng tới trang đăng nhập/đăng ký, không cho thêm vào giỏ ẩn danh.
- AC7: Given học sinh nhập mã giảm giá hợp lệ (còn hiệu lực, còn lượt dùng) tại trang giỏ hàng, When bấm "Áp dụng", Then hệ thống hiển thị rõ số tiền được giảm và tổng tiền sau giảm.
- AC8: Given mã giảm giá không tồn tại, hết hạn, hết lượt dùng, học sinh đã từng dùng mã này, hoặc không có khóa học nào trong giỏ thuộc phạm vi áp dụng của mã, When học sinh bấm "Áp dụng", Then hệ thống báo lỗi rõ ràng tương ứng và không thay đổi tổng tiền.
- AC11: Given giỏ hàng có 2 khóa học, trong đó mã giảm giá chỉ áp dụng cho 1 khóa học (theo phạm vi chuyên đề/khóa học cụ thể của mã — US-013), When học sinh áp dụng mã, Then chỉ khóa học thuộc phạm vi được giảm giá, khóa học còn lại giữ nguyên giá gốc, và tổng tiền hiển thị rõ phần nào được giảm.
- AC9: Given học sinh đã áp dụng 1 mã giảm giá, When học sinh nhập và áp dụng mã khác, Then mã mới thay thế mã cũ (không cộng dồn), tổng tiền được tính lại theo mã mới.
- AC10: Given học sinh đã áp dụng mã giảm giá, When học sinh xóa bớt khóa học khỏi giỏ khiến mã không còn đủ điều kiện áp dụng (ví dụ mã chỉ áp dụng cho khóa học vừa bị xóa), Then hệ thống tự động gỡ mã và thông báo cho học sinh biết.

## Trường hợp biên & lỗi
- Khóa học trong giỏ bị admin gỡ (unpublish) trong lúc học sinh đang xem giỏ hàng → hiển thị cảnh báo khóa học không còn khả dụng, cho phép xóa khỏi giỏ.
- Học sinh mở giỏ hàng trên 2 tab cùng lúc và thao tác thêm/xóa đồng thời → dữ liệu cuối cùng phải nhất quán (không có 2 dòng trùng cho cùng course_id).
- Gọi API thêm giỏ hàng với `course_id` không tồn tại → trả lỗi 404/422 hợp lý, không lỗi 500.
- Giỏ hàng có số lượng khóa học lớn (ví dụ > 20) → trang vẫn hiển thị và tính tổng đúng, không timeout.
- Mã giảm giá bị Admin vô hiệu hóa (US-013) đúng lúc học sinh đang có mã đó áp dụng trong giỏ nhưng chưa thanh toán → khi vào lại trang giỏ hàng/checkout, hệ thống phải kiểm tra lại hiệu lực mã và gỡ nếu không còn hợp lệ.
- Nhập mã giảm giá với chữ hoa/chữ thường khác nhau → hệ thống xử lý không phân biệt hoa/thường theo BR1 của US-013.

## Phân quyền
| Vai trò | Xem | Tạo (thêm) | Sửa (xóa item/áp mã) | Xoá (xóa cả giỏ) |
|---|---|---|---|---|
| Khách | – | – | – | – |
| Học Sinh | Giỏ hàng của mình | Giỏ hàng của mình | Giỏ hàng của mình | Giỏ hàng của mình |
| Giáo Viên | – | – | – | – |
| Quản lý trang / Admin | – | – | – | – |

## Ảnh hưởng dữ liệu
- Bảng `cart_items`: `id`, `user_id`, `course_id`, `created_at`; ràng buộc unique(`user_id`, `course_id`).
- Bảng `carts` (nếu cần 1 bản ghi đại diện giỏ hàng của học sinh để lưu mã đang áp dụng) hoặc thêm field trên `users`/session: `applied_coupon_id` (nullable, tham chiếu `coupons.id` — bảng `coupons` định nghĩa ở US-013).
- Không cần thêm field mới trên bảng `courses`, chỉ đọc `price`, `status`.

## Ngoài phạm vi
- Gợi ý combo/khóa học kèm theo trong giỏ hàng.
- Lưu giỏ hàng cho người dùng ẩn danh (guest cart).
- Quà tặng/mua khóa học hộ người khác.
- Quản trị (tạo/sửa/vô hiệu hóa) mã giảm giá — xem US-013.
- Áp dụng đồng thời nhiều mã giảm giá trên cùng 1 đơn hàng.

## Quyết định của PO
- Không hỗ trợ mua hộ/quà tặng.
- Mã giảm giá/khuyến mãi **có** trong MVP (chi tiết quản trị → US-013, áp dụng cụ thể ở AC7–AC11 trên).
- Không cộng dồn nhiều mã/đơn (1 mã/đơn); mã có thể giới hạn phạm vi theo chuyên đề/khóa học cụ thể.

## Câu hỏi mở
- Không còn câu hỏi mở cho story này (PO đã chốt: 1 mã/đơn, không cộng dồn; mã có thể giới hạn phạm vi theo chuyên đề/khóa học — chi tiết xem US-013).

## Ghi chú cho Designer / Dev / QA
- Designer: icon giỏ hàng ở header cần hiển thị badge số lượng; thiết kế ô nhập mã giảm giá với trạng thái thành công/lỗi rõ ràng và dòng hiển thị "Giảm giá: -xxx đ" tách biệt với tổng tiền gốc.
- Dev: đảm bảo ràng buộc unique ở tầng CSDL (migration) cho `cart_items`, không chỉ kiểm tra ở tầng ứng dụng, để tránh race condition; xử lý việc trừ lượt dùng mã giảm giá thực tế chỉ diễn ra khi tạo `order` thành công ở US-005 (tránh giữ chỗ lượt dùng chỉ vì học sinh mới "áp dụng" ở giỏ hàng).
- QA: kiểm thử thêm đồng thời (concurrent request) cùng 1 course_id để xác nhận không tạo trùng dòng; kiểm thử áp mã hợp lệ/hết hạn/hết lượt/không áp dụng được cho khóa học trong giỏ.

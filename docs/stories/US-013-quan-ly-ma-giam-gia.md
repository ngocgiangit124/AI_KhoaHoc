# US-013: Quản lý mã giảm giá (Coupon)

**Trạng thái:** Draft
**Ưu tiên:** Must

## User story
Là Admin/Quản lý trang, tôi muốn tạo và quản lý mã giảm giá để chạy chương trình khuyến mãi thúc đẩy học sinh mua khóa học.

## Bối cảnh
Phát sinh từ quyết định của PO đưa mã giảm giá vào MVP (US-004/US-005). Story này định nghĩa phần **quản trị** (tạo/sửa/vô hiệu hóa mã); phần **áp dụng mã** ở giỏ hàng/checkout được đặc tả trong US-004 (nhập & áp mã ở giỏ hàng) và US-005 (tính giá cuối cùng khi thanh toán).

## Business rules
- BR1: Mã giảm giá (`code`) duy nhất, không phân biệt hoa/thường khi học sinh nhập (ví dụ "TOAN2026" và "toan2026" được coi là cùng 1 mã).
- BR2: Loại giảm giá gồm 2 kiểu: giảm theo **phần trăm** (%, tối đa 100%) hoặc giảm **số tiền cố định** (VNĐ); mỗi mã chỉ thuộc đúng 1 kiểu.
- BR3 (Quyết định của PO): Mỗi mã giảm giá chỉ được **mỗi học sinh sử dụng đúng 1 lần** (`max_uses_per_user` cố định = 1, không cấu hình khác được ở MVP). Ngoài ra mỗi mã có thể giới hạn thêm: ngày bắt đầu/kết thúc hiệu lực, tổng số lượt sử dụng tối đa toàn hệ thống (`max_uses`, để trống = không giới hạn).
- BR4 (Quyết định của PO): Mã giảm giá có thể giới hạn **phạm vi áp dụng theo chuyên đề và/hoặc khóa học cụ thể**, do Admin/Quản lý trang chọn khi tạo mã; nếu không chọn phạm vi cụ thể, mã mặc định áp dụng cho **toàn bộ khóa học**. Khi học sinh áp mã tại giỏ hàng (US-004): chỉ các khóa học trong giỏ **thuộc phạm vi** của mã được tính giảm giá, các khóa học **ngoài phạm vi** giữ nguyên giá gốc; nếu giỏ hàng không có khóa học nào thuộc phạm vi của mã, hệ thống báo "Mã không áp dụng được cho giỏ hàng hiện tại".
- BR5: Mã hết hạn hoặc đã dùng hết lượt không thể áp dụng được nữa; hệ thống báo lỗi rõ ràng khi học sinh nhập mã đó ở US-004.
- BR6: Số tiền giảm không được vượt quá tổng giá trị **phần đơn hàng thuộc phạm vi áp dụng của mã** (không tạo đơn hàng có tổng thanh toán âm; số tiền giảm thực tế = min(giá trị mã, tổng tiền các khóa học thuộc phạm vi trong giỏ)).
- BR7 (diễn giải của BA cho yêu cầu "đơn bị hủy/hết hạn thì trả lại lượt dùng" của PO — **cần PO xác nhận lại**, xem câu hỏi mở): Lượt sử dụng mã (`used_count` toàn hệ thống và giới hạn 1 lần/học sinh theo BR3) chỉ được **ghi nhận khi đơn hàng thanh toán thành công** (`orders.status = paid`), không ghi nhận khi học sinh mới "áp dụng" mã ở giỏ hàng hoặc khi đơn hàng còn ở trạng thái `pending`. Theo cách này, nếu đơn hàng bị hủy hoặc tự động hết hạn sau 12 giờ (US-005 BR7) mà chưa thanh toán, mã coi như **chưa từng được sử dụng** (không cần "hoàn trả" vì chưa từng bị trừ), nên học sinh vẫn dùng lại được đúng mã đó cho đơn hàng mới.

## Acceptance criteria
- AC1: Given admin nhập mã "TOAN2026", loại giảm 20%, ngày hiệu lực hợp lệ, When bấm "Tạo mã", Then mã được tạo với `status = active` và sẵn sàng áp dụng ở giỏ hàng (US-004).
- AC2: Given mã đã tồn tại, When admin tạo mã trùng code (không phân biệt hoa/thường), Then hệ thống báo lỗi "Mã giảm giá đã tồn tại".
- AC3: Given mã đang active và còn hiệu lực, When admin bấm "Vô hiệu hóa", Then mã chuyển sang `status = inactive` và học sinh không áp dụng được nữa kể từ thời điểm đó (không ảnh hưởng đơn hàng đã tạo trước đó).
- AC4: Given mã đã đạt giới hạn tổng số lượt sử dụng, When admin xem chi tiết mã, Then thấy rõ số lượt đã dùng/tổng số lượt cho phép và trạng thái "Đã hết lượt".
- AC5: Given admin đặt ngày kết thúc hiệu lực trước ngày bắt đầu, When lưu, Then hệ thống báo lỗi validate và không lưu.
- AC6: Given admin xem danh sách mã giảm giá, When lọc theo trạng thái (active/inactive/hết hạn/hết lượt), Then danh sách hiển thị đúng theo bộ lọc.
- AC7: Given admin nhập giá trị giảm phần trăm > 100, When lưu, Then hệ thống báo lỗi validate và không lưu.
- AC8: Given admin tạo mã giảm giá và chọn phạm vi áp dụng là 1 hoặc nhiều chuyên đề và/hoặc khóa học cụ thể, When lưu mã, Then chi tiết mã hiển thị rõ danh sách chuyên đề/khóa học thuộc phạm vi áp dụng, và mã chỉ giảm giá cho các khóa học thuộc phạm vi đó khi học sinh áp dụng ở giỏ hàng (US-004).
- AC9: Given học sinh đã sử dụng mã "TOAN2026" thành công cho 1 đơn hàng đã thanh toán, When học sinh cố áp dụng lại đúng mã đó cho một đơn hàng khác, Then hệ thống báo lỗi "Bạn đã sử dụng mã này rồi" và không cho áp dụng lại.
- AC10: Given giỏ hàng của học sinh chỉ có các khóa học nằm ngoài phạm vi áp dụng của mã đang nhập, When học sinh bấm "Áp dụng", Then hệ thống báo lỗi "Mã không áp dụng được cho giỏ hàng hiện tại" và không thay đổi tổng tiền.

## Trường hợp biên & lỗi
- Giảm giá số tiền cố định lớn hơn tổng tiền giỏ hàng của học sinh → áp dụng theo BR6, số tiền giảm thực tế không vượt quá tổng tiền giỏ hàng.
- Học sinh dùng nhiều tab áp mã đồng thời khi mã sắp hết lượt (race condition) → việc kiểm tra và trừ lượt dùng phải thực hiện trong transaction tại thời điểm tạo `order` (US-005), không phải tại thời điểm "áp dụng" ở giỏ hàng, để tránh vượt giới hạn `max_uses`.
- Xóa mã đã từng được sử dụng trong ít nhất 1 đơn hàng → không cho xóa cứng, chỉ cho vô hiệu hóa (`inactive`) để giữ toàn vẹn lịch sử đơn hàng đã áp mã đó.
- Mã có `max_uses_per_user` nhưng học sinh cố áp dụng lại sau khi đã dùng đủ số lượt cho phép → hệ thống chặn ở bước áp dụng (US-004) và báo lỗi rõ ràng "Bạn đã sử dụng hết lượt cho mã này".

## Phân quyền
| Vai trò | Xem | Tạo | Sửa | Vô hiệu hóa | Xoá |
|---|---|---|---|---|---|
| Admin | Có | Có | Có | Có | Có (nếu chưa từng sử dụng) |
| Quản lý trang | Có | Có | Có | Có | Có (nếu chưa từng sử dụng) |
| Giáo Viên | Không | Không | Không | Không | Không |
| Học Sinh | Không (chỉ nhập mã ở giỏ hàng, US-004) | – | – | – | – |

## Ảnh hưởng dữ liệu
- Bảng `coupons`: `id`, `code` (unique), `discount_type` (enum: percent, fixed_amount), `discount_value`, `max_uses` (nullable), `max_uses_per_user` (cố định = 1), `used_count` (mặc định 0), `valid_from`, `valid_until`, `status` (enum: active, inactive), `applicable_scope` (enum: all_courses, specific_courses, specific_subjects), timestamps.
- Bảng pivot `coupon_course` (dùng khi `applicable_scope = specific_courses`): `coupon_id`, `course_id`.
- Bảng pivot `coupon_subject` (dùng khi `applicable_scope = specific_subjects`): `coupon_id`, `subject_id` (tham chiếu `subjects` — US-011).
- Bảng `coupon_usages`: `id`, `coupon_id`, `user_id`, `order_id`, `used_at` — chỉ ghi khi order chuyển `paid` (BR7), phục vụ giới hạn 1 lần/học sinh (BR3) và audit lịch sử sử dụng.
- Liên kết với `orders.coupon_id`, `orders.discount_amount` đã định nghĩa ở US-005.

## Ngoài phạm vi
- Mã giảm giá tự động áp dụng không cần học sinh nhập (auto-apply theo điều kiện giỏ hàng).
- Chương trình giới thiệu bạn bè tích hợp với mã giới thiệu ở US-001 (2 khái niệm khác nhau — xem câu hỏi mở về việc có cần gộp chung không).
- Cộng dồn nhiều mã giảm giá trên 1 đơn hàng (US-004 chỉ cho áp dụng 1 mã/đơn).

## Quyết định của PO
- Mã giảm giá giới hạn phạm vi áp dụng theo chuyên đề và/hoặc khóa học cụ thể (admin chọn khi tạo mã); khóa học ngoài phạm vi không được giảm giá.
- Mỗi học sinh chỉ được dùng 1 mã đúng 1 lần (tính trên đơn đã thanh toán thành công).
- Vẫn giữ nguyên quy định 1 mã/đơn hàng (không cộng dồn nhiều mã).

## Câu hỏi mở
- [ ] Xác nhận lại cách hiểu "đơn bị hủy/hết hạn thì trả lại lượt dùng" ở BR7: BA diễn giải là lượt dùng chỉ tính khi đơn `paid` nên đơn hủy/hết hạn tự động không tốn lượt (không cần cơ chế "hoàn trả" riêng). Nếu PO có ý khác (ví dụ mã bị giữ chỗ ngay khi áp dụng ở giỏ hàng, rồi mới hoàn trả khi hủy), cần nêu rõ để điều chỉnh thiết kế.
- [ ] Có phân biệt giữa "mã giảm giá" (coupon, do admin tạo, công khai — story này) và "mã giới thiệu" (referral code, ở US-001, gắn với người giới thiệu) hay là cùng một cơ chế cần hợp nhất thiết kế?

## Ghi chú cho Designer / Dev / QA
- Designer: màn hình danh sách + form tạo mã, hiển thị rõ tình trạng hiệu lực/lượt dùng (progress bar số lượt đã dùng/tổng).
- Dev: xử lý kiểm tra & trừ lượt dùng (`used_count`, `coupon_usages`) trong cùng transaction với việc tạo `order` thành công (US-005) để tránh vượt giới hạn do race condition.
- QA: kiểm thử áp mã đồng thời nhiều học sinh khi mã sắp hết lượt (concurrent request), kiểm thử mã hết hạn/inactive, kiểm thử học sinh dùng lại mã đã sử dụng (phải bị chặn), kiểm thử áp mã có phạm vi chuyên đề/khóa học cụ thể với giỏ hàng có cả khóa học trong và ngoài phạm vi.

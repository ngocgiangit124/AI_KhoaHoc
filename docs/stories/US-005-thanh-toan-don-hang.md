# US-005: Thanh toán đơn hàng (Checkout)

**Trạng thái:** Draft
**Ưu tiên:** Must

## User story
Là học sinh, tôi muốn thanh toán các khóa học trong giỏ hàng (kèm mã giảm giá nếu có) để sở hữu và bắt đầu học ngay sau khi thanh toán thành công.

## Bối cảnh
Bước tiếp theo sau giỏ hàng (US-004). Đây là luồng liên quan trực tiếp đến tiền, cần review bảo mật kỹ (gọi `laravel-security` khi thiết kế/hiện thực). PO đã chốt cổng thanh toán cho MVP là **MoMo**. Story vẫn đặc tả nghiệp vụ theo hướng **trừu tượng hóa cổng thanh toán** (không hard-code logic riêng của MoMo lan khắp hệ thống, thông qua 1 interface chung kiểu `PaymentGatewayInterface`) để sau này có thể bổ sung/đổi thêm cổng khác mà không phải viết lại luồng nghiệp vụ; MoMo là cài đặt (implementation) đầu tiên của interface đó. Chi tiết kỹ thuật tích hợp API MoMo cụ thể (endpoint, tham số, thuật toán ký) để `laravel-architect`/Dev quyết định khi thiết kế kỹ thuật.

## Business rules
- BR1: Giá thanh toán = tổng giá các khóa học trong giỏ trừ đi số tiền giảm giá từ mã giảm giá hợp lệ đang áp dụng (nếu có, theo US-004/US-013) tại thời điểm tạo đơn hàng.
- BR2 (Quyết định của PO): Mỗi đơn hàng chỉ thanh toán bằng **đúng 1 phương thức** thanh toán, không chia nhỏ thanh toán qua nhiều phương thức trong cùng 1 đơn.
- BR3: Khi thanh toán thành công, hệ thống tạo `order` (status = paid), tạo `enrollment` (status = active) cho từng khóa học trong đơn, ghi nhận lượt sử dụng mã giảm giá (nếu có, theo US-013), và xóa các item tương ứng khỏi giỏ hàng.
- BR4: Callback/webhook xác nhận thanh toán từ cổng thanh toán phải được xử lý idempotent (gọi nhiều lần không tạo enrollment/order trùng).
- BR5: Nếu một khóa học trong giỏ bị gỡ (unpublish) trước khi thanh toán, khóa học đó bị loại khỏi đơn hàng và học sinh được thông báo trước khi xác nhận thanh toán phần còn lại.
- BR6: Đơn hàng thất bại/hủy không làm mất dữ liệu giỏ hàng của học sinh.
- BR7 (Quyết định của PO): Đơn hàng ở trạng thái `pending` quá **12 giờ** kể từ lúc tạo mà chưa có xác nhận thanh toán thành công sẽ được hệ thống **tự động hủy** (`status = cancelled`); giỏ hàng của học sinh không bị ảnh hưởng, học sinh có thể tạo lại đơn hàng mới.
- BR8 (Quyết định của PO): Sau khi đơn hàng chuyển `status = paid`, hệ thống **gửi email xác nhận đơn hàng** cho học sinh (danh sách khóa học, số tiền, mã đơn). Kênh SMS chưa được xác nhận, tạm để ngoài phạm vi MVP (xem câu hỏi mở).
- BR9: Tài khoản chưa xác thực (email/OTP theo US-001 BR7) không được phép hoàn tất checkout — phải xác thực trước.
- BR10 (Quyết định của PO — luồng MoMo ở mức nghiệp vụ): Khi học sinh bấm "Thanh toán", hệ thống tạo `order` (status = pending) rồi **chuyển hướng học sinh sang trang thanh toán MoMo**. Sau khi học sinh hoàn tất (hoặc hủy) trên MoMo, MoMo **redirect trình duyệt** học sinh về lại website kèm kết quả tạm thời (chỉ dùng để hiển thị màn hình chờ/kết quả cho học sinh, **không dùng để xác nhận đơn hàng**).
- BR11 (Quyết định của PO): Việc **xác nhận đơn hàng thanh toán thành công chỉ dựa trên IPN (Instant Payment Notification)/webhook** mà MoMo gọi ngầm về server, **sau khi kiểm tra chữ ký (signature) hợp lệ** theo tài liệu MoMo. Không bao giờ xác nhận `order` là `paid` chỉ dựa vào redirect phía trình duyệt (vì có thể bị giả mạo/mất kết nối giữa chừng).
- BR12 (Quyết định của PO): Xử lý IPN phải **idempotent** — MoMo có thể gọi lại IPN nhiều lần cho cùng 1 giao dịch (do retry), hệ thống chỉ xử lý xác nhận thanh toán (tạo enrollment, trừ lượt mã giảm giá...) **đúng 1 lần** cho mỗi `order`, các lần gọi IPN trùng lặp sau đó phải trả về xác nhận đã nhận (ví dụ HTTP 204/200) mà không lặp lại hiệu ứng phụ.
- BR13 (Quyết định của PO): Số tiền trong IPN phải **khớp chính xác** với `total_amount` (đã trừ giảm giá) của `order` tương ứng; nếu lệch, hệ thống từ chối xác nhận thanh toán, ghi log để admin kiểm tra thủ công, không tạo enrollment.
- BR14 (Quyết định của PO): Thời hạn tự động hủy `order` pending (12 giờ, theo BR7) cần **khớp/đồng bộ** với thời hạn hiệu lực của link thanh toán MoMo (MoMo thường có thời hạn riêng cho mỗi giao dịch) — nếu thời hạn kỹ thuật của MoMo ngắn hơn 12 giờ, `order` vẫn giữ pending tới khi hết 12 giờ nhưng học sinh sẽ không thanh toán được nữa qua link cũ (cần tạo lại giao dịch mới); chi tiết đồng bộ 2 mốc thời gian này để Architect quyết định.

## Acceptance criteria
- AC1: Given giỏ hàng có 2 khóa học tổng 500.000đ (không áp mã), When học sinh bấm "Thanh toán", được chuyển hướng sang MoMo và hoàn tất thanh toán thành công, Then MoMo gọi IPN về server với chữ ký hợp lệ và số tiền khớp, hệ thống xác nhận order status = paid, tạo enrollment active cho cả 2 khóa học, học sinh vào học được ngay, giỏ hàng được xóa, và nhận được email xác nhận đơn hàng.
- AC2: Given thanh toán bị hủy hoặc thất bại trên MoMo, When học sinh được MoMo redirect quay lại hệ thống, Then order được ghi nhận status = failed/cancelled (dựa trên IPN, không dựa vào tham số redirect), giỏ hàng giữ nguyên, học sinh có thể thử thanh toán lại.
- AC3: Given giỏ hàng trống, When học sinh cố vào trang checkout (kể cả qua URL trực tiếp), Then hệ thống chuyển hướng về trang giỏ hàng kèm thông báo.
- AC4: Given một khóa học trong giỏ bị admin unpublish trước khi thanh toán, When học sinh tiến hành checkout, Then hệ thống loại khóa học đó khỏi đơn, thông báo rõ cho học sinh, và cho phép tiếp tục thanh toán phần còn lại (nếu còn khóa học hợp lệ); nếu có mã giảm giá đang áp dụng không còn hợp lệ sau khi loại bớt khóa học, hệ thống tính lại hoặc gỡ mã theo AC10 của US-004.
- AC5: Given MoMo gửi IPN xác nhận thành công 2 lần cho cùng 1 order (do lỗi mạng/cơ chế retry của MoMo), When hệ thống xử lý IPN lần 2, Then không tạo thêm enrollment hoặc thay đổi trạng thái order lần nữa, hệ thống chỉ phản hồi xác nhận đã nhận cho MoMo.
- AC6: Given lỗi kết nối tới MoMo khi khởi tạo giao dịch (tạo link thanh toán), When học sinh bấm "Thanh toán", Then hiển thị thông báo lỗi hệ thống rõ ràng, order ở trạng thái pending, không có enrollment nào được tạo.
- AC7: Given học sinh xem lại lịch sử đơn hàng của mình, When vào trang "Đơn hàng của tôi", Then thấy đầy đủ danh sách đơn với trạng thái, số tiền (kèm số tiền đã giảm nếu có áp mã), phương thức thanh toán (MoMo), ngày mua.
- AC8: Given một order ở trạng thái pending đã quá 12 giờ kể từ lúc tạo mà chưa nhận được IPN xác nhận thành công, When tác vụ dọn dẹp định kỳ (scheduler) chạy, Then order tự động chuyển sang status = cancelled, không tạo enrollment.
- AC9: Given giỏ hàng có áp dụng mã giảm giá hợp lệ, When học sinh thanh toán thành công qua MoMo, Then `orders.discount_amount` và `orders.coupon_id` được lưu đúng, tổng tiền gửi sang MoMo và xác nhận qua IPN = tổng giá khóa học trừ số tiền giảm, và lượt sử dụng mã giảm giá được ghi nhận (US-013).
- AC10: Given tài khoản học sinh chưa xác thực email/OTP, When cố vào trang checkout, Then hệ thống chặn và yêu cầu xác thực tài khoản trước (liên kết US-001 BR7/AC9).
- AC11: Given IPN từ MoMo có chữ ký không hợp lệ (không khớp secret key) hoặc số tiền không khớp `total_amount` của order, When hệ thống nhận IPN đó, Then từ chối xác nhận thanh toán, không tạo enrollment, và ghi log để admin kiểm tra thủ công.

## Trường hợp biên & lỗi
- Giá khóa học thay đổi giữa lúc thêm vào giỏ và lúc thanh toán → dùng giá tại thời điểm tạo order (chốt giá), không dùng giá hiện tại của khóa học để tránh tranh chấp.
- Học sinh mở 2 tab và bấm thanh toán cùng lúc → chỉ 1 order hợp lệ được tạo, tránh trừ tiền/tạo enrollment 2 lần.
- Số tiền callback trả về không khớp với số tiền order gốc → hệ thống phải từ chối xác nhận, ghi log để admin kiểm tra thủ công, không tự động enroll.
- Học sinh đóng trình duyệt giữa chừng khi đang chuyển hướng tới MoMo (chưa quay lại, chưa có IPN) → order ở trạng thái pending, được dọn dẹp tự động sau 12 giờ theo BR7/AC8.
- Học sinh đã đóng cửa sổ MoMo/thoát app MoMo trước khi hoàn tất, nhưng sau đó MoMo vẫn gửi IPN xác nhận thành công (hiếm, do đã trừ tiền) → hệ thống vẫn xử lý enrollment bình thường theo IPN hợp lệ, không phụ thuộc việc học sinh có quay lại trình duyệt hay không.
- Link thanh toán MoMo hết hạn theo quy định kỹ thuật của MoMo trước khi order đạt mốc 12 giờ (BR14) → học sinh không thanh toán được qua link cũ, cần luồng tạo lại giao dịch/link thanh toán mới cho cùng order hoặc tạo order mới (quyết định UX cụ thể để Designer/Dev xử lý).
- Mã giảm giá hết lượt dùng đúng lúc 2 học sinh cùng thanh toán gần như đồng thời → chỉ 1 trong 2 được ghi nhận sử dụng mã thành công (transaction + kiểm tra lượt dùng ở tầng tạo order), người còn lại vẫn thanh toán được nhưng không có giảm giá (cần thông báo rõ).

## Phân quyền
| Vai trò | Xem đơn hàng | Tạo đơn hàng | Sửa | Xoá |
|---|---|---|---|---|
| Học Sinh | Đơn hàng của chính mình | Đơn hàng của chính mình (qua checkout) | – | – |
| Giáo Viên | – | – | – | – |
| Quản lý trang / Admin | Toàn bộ đơn hàng (US-010) | – | Cập nhật trạng thái (US-010) | – |

## Ảnh hưởng dữ liệu
- Bảng `orders`: `id`, `user_id`, `total_amount`, `discount_amount` (mặc định 0), `coupon_id` (nullable, FK `coupons` — US-013), `status` (enum: pending, paid, failed, cancelled, refunded), `payment_method` (MVP: mặc định `momo`, giữ dạng string/enum mở để thêm cổng sau), `payment_reference` (mã giao dịch phía MoMo — `momo_trans_id`/`request_id`, dùng để đối soát và cho luồng hoàn tiền thủ công ở US-010), `paid_at`, timestamps.
- Bảng `order_items`: `id`, `order_id`, `course_id`, `price` (giá chốt tại thời điểm mua).
- Bảng `enrollments`: cập nhật/tạo mới `status = active`, `purchased_at`, liên kết `order_id`.
- Cần bảng lưu log IPN từ MoMo (ví dụ `payment_logs`/`payment_ipn_logs`: `order_id`, `raw_payload`, `signature_valid` (boolean), `processed_at`) phục vụ đối soát, chống xử lý trùng (AC5) và điều tra khi có tranh chấp.
- Thiết kế tầng service/adapter cho cổng thanh toán (interface chung, ví dụ `PaymentGatewayInterface`, với `MoMoPaymentGateway` là cài đặt đầu tiên) để dễ bổ sung cổng khác sau — chi tiết kỹ thuật (endpoint MoMo, tham số, thuật toán ký HMAC...) do `laravel-architect` quyết định.
- Cần scheduled job/queue để tự động hủy order pending quá 12 giờ (AC8).

## Ngoài phạm vi
- Trả góp/thanh toán nhiều đợt.
- Hoàn tiền tự động qua API cổng thanh toán (MVP chỉ xử lý thủ công, xem US-010).
- Xuất hóa đơn VAT.
- Gửi SMS xác nhận đơn hàng (chỉ xác nhận qua email ở MVP, xem câu hỏi mở).
- Cộng dồn nhiều mã giảm giá trên 1 đơn (xem câu hỏi mở ở US-004).

## Quyết định của PO
- Cổng thanh toán MVP: **MoMo** — vẫn giữ kiến trúc trừu tượng hóa cổng thanh toán để có thể bổ sung cổng khác sau này.
- Xác nhận đơn hàng dựa trên IPN/webhook có kiểm tra chữ ký từ MoMo, không dựa vào redirect trình duyệt.
- Xử lý IPN idempotent; số tiền IPN phải khớp đơn hàng.
- Học sinh chỉ chọn 1 phương thức thanh toán cho mỗi đơn.
- Có gửi email xác nhận đơn hàng khi thanh toán thành công.
- Đơn hàng pending quá 12 giờ tự động hủy (khớp với thời hạn hiệu lực link thanh toán MoMo).

## Câu hỏi mở
- [ ] Ngoài email, có cần gửi thêm SMS xác nhận đơn hàng thành công không?
- [ ] Thời hạn hiệu lực kỹ thuật của link thanh toán MoMo là bao lâu, và nếu ngắn hơn 12 giờ thì UX tạo lại giao dịch cho order cũ như thế nào (để Architect/Designer thiết kế chi tiết)?

## Ghi chú cho Designer / Dev / QA
- Designer: màn hình checkout cần hiển thị rõ tổng tiền, số tiền giảm (nếu có mã), danh sách khóa học, nút "Thanh toán qua MoMo", và màn hình chờ/kết quả sau khi MoMo redirect về (lưu ý: đây chỉ là màn hình hiển thị tạm, trạng thái chính thức vẫn chờ IPN nên có thể cần trạng thái "Đang xác nhận thanh toán...").
- Dev: đây là luồng liên quan tiền — bắt buộc review với `laravel-security` trước khi release; xử lý IPN MoMo trong transaction DB, luôn kiểm tra chữ ký (signature) trước khi tin bất kỳ dữ liệu nào trong payload; thiết kế interface trừu tượng cho cổng thanh toán ngay từ đầu (MoMo là implementation đầu tiên) để không phải sửa luồng nghiệp vụ khi bổ sung cổng khác; dùng Laravel scheduler cho tác vụ tự hủy order pending sau 12 giờ.
- QA: kiểm thử idempotent của IPN (gửi lại cùng request), kiểm thử race condition khi thanh toán đồng thời trên nhiều tab, kiểm thử sai lệch số tiền trong IPN, kiểm thử IPN có chữ ký không hợp lệ bị từ chối, kiểm thử tự động hủy order sau 12 giờ, kiểm thử áp mã giảm giá khi thanh toán.

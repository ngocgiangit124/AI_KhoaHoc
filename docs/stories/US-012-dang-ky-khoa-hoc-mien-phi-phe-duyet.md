# US-012: Đăng ký khóa học miễn phí và phê duyệt

**Trạng thái:** Draft
**Ưu tiên:** Should

## User story
Là học sinh, tôi muốn đăng ký khóa học miễn phí để được giáo viên/quản trị viên phê duyệt và bắt đầu học mà không cần thanh toán.
Là Giáo Viên phụ trách khóa học (hoặc Admin/Quản lý trang), tôi muốn xem và duyệt/từ chối các yêu cầu đăng ký khóa học miễn phí để kiểm soát chất lượng học sinh tham gia.

## Bối cảnh
Phát sinh từ quyết định của PO ở US-003: khóa học có thể miễn phí (`price = 0`), nhưng học sinh đăng ký phải được Admin, Quản lý trang, hoặc một trong các Giáo viên phụ trách khóa học đó phê duyệt trước khi được cấp quyền học. Khác với luồng mua trả phí (US-004, US-005) vốn tự động kích hoạt enrollment ngay sau khi thanh toán, luồng này không đi qua giỏ hàng/thanh toán.

## Business rules
- BR1: Chỉ áp dụng cho khóa học có `price = 0` và đã `published`.
- BR2: Khi học sinh bấm "Đăng ký" trên trang chi tiết khóa học miễn phí (US-003), hệ thống tạo enrollment với `status = pending_approval`, không cần qua giỏ hàng/thanh toán.
- BR3: Học sinh không xem được nội dung đầy đủ (ngoài bài preview) cho tới khi enrollment được duyệt (`status = active`).
- BR4: Người có quyền duyệt một yêu cầu: Admin, Quản lý trang, hoặc bất kỳ Giáo viên nào nằm trong danh sách giáo viên phụ trách chính khóa học đó (theo mô hình đồng giảng dạy nhiều-nhiều ở US-009).
- BR5: Học sinh chỉ được có tối đa 1 yêu cầu đăng ký đang ở trạng thái `pending_approval` cho mỗi khóa học miễn phí tại một thời điểm (không cho gửi trùng khi đang chờ duyệt).
- BR6: Nếu yêu cầu bị từ chối, học sinh được thông báo (kèm lý do nếu người duyệt có nhập) và có thể gửi lại yêu cầu đăng ký mới cho cùng khóa học.
- BR7: Tài khoản học sinh phải đã xác thực email/OTP (US-001 BR7) mới được gửi yêu cầu đăng ký khóa học miễn phí, tương tự điều kiện checkout ở US-005.

## Acceptance criteria
- AC1: Given khóa học miễn phí đã published, When học sinh (đã đăng nhập, đã xác thực tài khoản) bấm "Đăng ký", Then hệ thống tạo enrollment `status = pending_approval` và trang khóa học hiển thị trạng thái "Đang chờ duyệt" thay cho nút "Đăng ký".
- AC2: Given có yêu cầu đăng ký đang chờ duyệt, When Admin, Quản lý trang, hoặc Giáo viên phụ trách khóa học đó vào danh sách yêu cầu chờ duyệt và bấm "Duyệt", Then enrollment chuyển sang `status = active`, ghi nhận `approved_by` và `approved_at`, học sinh được thông báo và có thể vào học ngay (US-006).
- AC3: Given có yêu cầu đăng ký đang chờ duyệt, When người duyệt bấm "Từ chối" (có thể nhập lý do), Then enrollment chuyển sang `status = rejected`, ghi nhận `rejection_reason` (nếu có), học sinh nhận thông báo và không có quyền truy cập nội dung đầy đủ.
- AC4: Given học sinh đã có 1 yêu cầu đang `pending_approval` cho khóa học X, When cố bấm "Đăng ký" lại cho cùng khóa học đó, Then hệ thống chặn và thông báo "Bạn đã gửi yêu cầu, vui lòng chờ duyệt".
- AC5: Given yêu cầu trước đó đã bị từ chối (`rejected`), When học sinh bấm "Đăng ký" lại, Then hệ thống tạo một yêu cầu `pending_approval` mới (không bị chặn vĩnh viễn bởi lần từ chối trước).
- AC6: Given giáo viên không nằm trong danh sách giáo viên phụ trách của khóa học, When cố truy cập trang duyệt yêu cầu đăng ký của khóa học đó (kể cả qua URL trực tiếp), Then bị từ chối quyền (403).
- AC7: Given danh sách yêu cầu chờ duyệt của 1 khóa học có nhiều học sinh, When người duyệt mở trang quản lý yêu cầu, Then thấy được danh sách đầy đủ kèm thời điểm gửi yêu cầu, sắp xếp theo thời gian gửi sớm nhất trước.

## Trường hợp biên & lỗi
- Khóa học đang có yêu cầu `pending_approval` bị Admin đổi từ miễn phí (`price = 0`) sang có phí → cần PO xác nhận cách xử lý các yêu cầu pending hiện có (giữ nguyên miễn phí cho các yêu cầu đã gửi trước đó, hay hủy yêu cầu và bắt học sinh chuyển sang luồng mua trả phí) — xem câu hỏi mở.
- Số lượng yêu cầu chờ duyệt lớn (ví dụ khóa học miễn phí phổ biến, hàng trăm yêu cầu) → danh sách chờ duyệt cần phân trang cho giáo viên/admin.
- Tất cả giáo viên phụ trách của 1 khóa học bị gỡ khỏi khóa học trong khi vẫn còn yêu cầu đang chờ họ duyệt → Admin/Quản lý trang luôn có quyền duyệt thay (theo BR4), đảm bảo không có yêu cầu bị "mồ côi" không ai duyệt được.
- Học sinh xóa tài khoản hoặc bị khóa trong lúc yêu cầu đang chờ duyệt → yêu cầu vẫn tồn tại nhưng không có tác dụng (học sinh không đăng nhập được để vào học dù có được duyệt).
- Người duyệt bấm "Duyệt" 2 lần liên tiếp (double click) → chỉ xử lý 1 lần, không lỗi trạng thái.

## Phân quyền
| Vai trò | Gửi yêu cầu đăng ký | Xem danh sách yêu cầu chờ duyệt | Duyệt/Từ chối |
|---|---|---|---|
| Học Sinh | Có (cho chính mình) | Không | Không |
| Giáo Viên phụ trách khóa học | – | Có (chỉ khóa học mình phụ trách) | Có (chỉ khóa học mình phụ trách) |
| Giáo Viên không phụ trách | – | Không | Không |
| Quản lý trang / Admin | – | Có (toàn bộ) | Có (toàn bộ) |

## Ảnh hưởng dữ liệu
- Bảng `enrollments`: mở rộng `status` thành enum (`pending_approval`, `active`, `rejected`, `revoked`); thêm field `approved_by` (nullable FK `users.id`), `approved_at` (nullable timestamp), `rejection_reason` (nullable text), `requested_at` (timestamp thời điểm gửi yêu cầu).
- Không cần bảng `orders`/`order_items` cho luồng này (khóa học miễn phí không đi qua US-005).
- Phụ thuộc bảng `course_teacher` (US-009) để xác định quyền duyệt theo BR4/AC6.

## Ngoài phạm vi
- Giới hạn số lượng học sinh tối đa được duyệt cho 1 khóa học miễn phí.
- Bộ lọc/tìm kiếm nâng cao trên danh sách chờ duyệt (theo tên học sinh, theo lớp...).
- Thông báo hàng loạt (bulk approve/reject) nhiều yêu cầu cùng lúc.

## Quyết định của PO
- Khóa học miễn phí có trong MVP; đăng ký phải qua phê duyệt của Admin, Quản lý trang, hoặc Giáo viên phụ trách.

## Câu hỏi mở
- [ ] Khóa học đổi từ miễn phí sang có phí (hoặc ngược lại) khi đang có yêu cầu `pending_approval` thì xử lý ra sao?
- [ ] Có gửi thông báo email khi yêu cầu được duyệt/từ chối không (đồng bộ với quyết định gửi email xác nhận đơn hàng ở US-005)?
- [ ] Thời gian tối đa hệ thống giữ 1 yêu cầu ở trạng thái `pending_approval` trước khi tự động hết hạn là bao lâu, hay không giới hạn (tương tự cơ chế tự hủy 12 giờ của đơn hàng pending ở US-005 — có áp dụng logic tương tự ở đây không)?

## Ghi chú cho Designer / Dev / QA
- Designer: cần màn hình danh sách yêu cầu chờ duyệt cho giáo viên/admin (có nút Duyệt/Từ chối nhanh), và trạng thái "Đang chờ duyệt"/"Đã từ chối" rõ ràng trên trang khóa học và dashboard học sinh (liên kết US-008).
- Dev: đảm bảo kiểm tra quyền duyệt theo quan hệ `course_teacher` (US-009) ở tầng backend, không chỉ ẩn UI; xử lý duyệt/từ chối trong transaction để tránh xử lý trùng khi double click.
- QA: kiểm thử giáo viên ngoài phạm vi cố duyệt yêu cầu không thuộc khóa học mình; kiểm thử học sinh gửi lại yêu cầu sau khi bị từ chối; kiểm thử Admin duyệt thay khi khóa học không còn giáo viên phụ trách nào.

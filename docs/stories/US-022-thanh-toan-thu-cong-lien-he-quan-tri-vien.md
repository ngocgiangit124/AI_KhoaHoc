# US-022: Thanh toán thủ công: liên hệ Quản trị viên

**Trạng thái:** Draft (chờ PO trả lời "Câu hỏi cho PO"; các mặc định đề xuất dùng được để không chặn thiết kế)
**Ưu tiên:** Must
**Ngày:** 2026-10-08 · Yêu cầu gốc của PO: "Về phần giỏ hàng thanh toán vẫn làm bình thường; khi chọn phương thức thì tạm thời ẩn chọn MoMo mà là chọn liên hệ với Quản trị viên. Sau đó Quản trị viên sẽ liên hệ và duyệt đơn hàng đó."

---

## Quyết định PO 2026-10-09

- Q1 kênh liên hệ: SĐT `0915 592 224`, Zalo `https://zalo.me/0915592224`, giờ hỗ trợ `8h–17h`, email `hotro@vitaminvui.vn`; sửa được qua cấu hình (`PAYMENT_CONTACT_*`) không cần sửa code.
- Q2 STK/QR: tạm thời KHÔNG hiển thị, bổ sung sau.
- Q3 hạn chờ: 72 giờ.
- Rủi ro QTV duyệt mà không thu tiền: CHẤP NHẬN; hệ thống ghi ai duyệt và lúc nào (`confirmed_by`, `order_status_logs.created_at`, audit log).
- Q15 giữ chỗ mã giảm giá: KHÔNG giới hạn số lượt giữ chỗ (giữ suốt thời gian chờ như đề xuất) — security T38 S2 chấp nhận, giảm nhẹ bằng QTV huỷ đơn (T39).
- Spam hộp thư QTV (security T38 S5): chấp nhận ở V1.
- Link trong thư gửi học sinh: dẫn tới màn "Đơn đã gửi" `/thanh-toan/da-gui/{code}`.
- `customer_note`: tự xoá (NULL) sau 90 ngày kể từ khi đơn kết thúc (đã thanh toán/huỷ/hết hạn/hoàn tiền); đơn, số tiền, lịch sử giữ nguyên (PO chọn phương án b) → task T38-1.

## Câu hỏi cho PO (kèm mặc định đề xuất)

Mỗi câu có mặc định. PO chưa trả lời thì đội làm theo mặc định. Đổi mặc định sau chỉ là sửa cấu hình hoặc thay đổi nhỏ, trừ các câu có ghi "ảnh hưởng thiết kế".

| # | Câu hỏi | Mặc định đề xuất | Ghi chú |
|---|---|---|---|
| Q1 | Học sinh thấy kênh liên hệ nào ở màn "Đơn đã gửi"? | SĐT/hotline, link Zalo, email hỗ trợ, khung giờ hỗ trợ. Cả 4 lấy từ cấu hình (env). Ô nào trống thì ẩn. Bắt buộc có ít nhất 1 kênh, thiếu cả 4 thì production không khởi động. Email mặc định lấy `SUPPORT_EMAIL` (`hotro@vitaminvui.vn`). | PO cần gửi số điện thoại, link Zalo và giờ hỗ trợ thật |
| Q2 | Có hiện thông tin chuyển khoản (số tài khoản, ngân hàng, QR) không? | **Không** ở bản này. Chỉ hiện kênh liên hệ và **mã đơn** (dặn ghi mã đơn khi chuyển khoản). Quản trị viên gửi số tài khoản khi liên hệ. | Nếu PO muốn hiện STK/QR thì thêm 1 khối văn bản cấu hình. **Ảnh hưởng thiết kế** |
| Q3 | Đơn chờ bao lâu thì tự huỷ? | **72 giờ** (cấu hình `ORDERS_MANUAL_PENDING_TTL_HOURS`). Đơn MoMo giữ 12 giờ như cũ. | 7 ngày thì giữ chỗ khóa/mã giảm giá quá lâu. 72 giờ đủ cho cuối tuần. |
| Q4 | Ai được duyệt đơn? | **Admin và Quản lý trang** (đúng US-010 BR1). Giáo viên không. | |
| Q5 | Có gửi email cho Quản trị viên khi có đơn mới không? | **Có.** Gửi 1 thư tới danh sách hộp thư cấu hình (`ORDERS_MANUAL_NOTIFY_EMAILS`, mặc định là `SUPPORT_EMAIL`), không gửi lần lượt cho từng tài khoản staff. Thư chỉ có mã đơn, tổng tiền, số khóa, thời điểm và link tới màn đơn trong trang quản trị. **Không** có email/SĐT học sinh. | Tránh lộ dữ liệu cá nhân qua hộp thư dùng chung |
| Q6 | Có gửi email cho học sinh không? | **Có**, 4 thư: (a) đã nhận đơn (mã đơn, số tiền, kênh liên hệ, hạn chờ); (b) đã duyệt (thư xác nhận đơn hàng của US-005 BR8); (c) Quản trị viên huỷ (kèm lý do); (d) đơn tự huỷ vì hết hạn. Học sinh tự huỷ thì không gửi thư. | |
| Q7 | Duyệt xong có gửi thư thông báo phụ huynh không (ADR-006: thư khi đơn có tiền đã thanh toán)? | **Có**, giữ đúng ADR-006. Đơn duyệt xong là đơn có tiền đã thanh toán, nên đi đúng đường `markPaid` hiện có và gửi 1 thư nếu có email phụ huynh, chưa huỷ nhận thư và cờ `FEATURE_PARENT_NOTICES` đang bật. Thư khi tạo đơn hoặc khi huỷ: **không** gửi. | |
| Q8 | Học sinh có tự huỷ được đơn đang chờ không? | **Có**, bằng nút "Huỷ đơn" ở chi tiết đơn, có hộp xác nhận. | Cần cho trường hợp đặt nhầm và để xoá tài khoản (BR16) |
| Q9 | Học sinh có nhập ghi chú khi đặt đơn không? | **Có, tuỳ chọn**, tối đa 500 ký tự văn bản thuần, ví dụ "Gọi sau 18h", "Zalo của mẹ: …". Có câu nhắc không ghi mật khẩu hay mã OTP. | Ghi chú có thể chứa dữ liệu cá nhân, nên chỉ hiện ở chi tiết đơn (có audit `order.view_pii`) |
| Q10 | Quản trị viên có nhập mã giao dịch hoặc ghi chú khi duyệt không? | **Có**: "Mã giao dịch / nội dung chuyển khoản" (tuỳ chọn, ≤ 100 ký tự) và "Ghi chú nội bộ" (tuỳ chọn, ≤ 1000 ký tự). Không nhập số tiền đã nhận: chỉ tick xác nhận "Đã nhận đủ {tổng tiền}". | Không hỗ trợ nhận thiếu tiền hay trả nhiều lần |
| Q11 | Có duyệt được đơn đã tự huỷ (hết hạn) hoặc học sinh đã huỷ nhưng sau đó mới chuyển tiền không? | **Có**, trong vòng **30 ngày** kể từ lúc huỷ, với hộp xác nhận thứ hai. Đơn được gắn cờ "Cần xem lại". | Hệ thống đã cho phép chuyển `cancelled → paid` (tiền về muộn) |
| Q12 | Học sinh đang có đơn chờ duyệt mà đặt đơn khác (thêm hoặc bớt khóa) thì sao? | Hệ thống **hỏi lại**: "Bạn đang có đơn #{mã} chờ duyệt. Đặt đơn mới sẽ huỷ đơn cũ." Học sinh đồng ý thì đơn cũ bị huỷ (lý do "thay bằng đơn mới"). | Hiện nay checkout tự huỷ đơn cũ không hỏi. Với đơn thủ công, học sinh có thể đã chuyển tiền cho đơn cũ |
| Q13 | Giới hạn số đơn thủ công 1 học sinh được tạo mỗi ngày? | **5 đơn/ngày/học sinh** (tính cả đơn đã huỷ). Vượt thì báo "Bạn đã đặt quá nhiều đơn hôm nay, vui lòng liên hệ Quản trị viên." | Chống spam thư tới hộp thư quản trị |
| Q14 | Quản trị viên có cần màn "Chờ duyệt" riêng và số đếm trên menu không? | **Có**: tab "Chờ duyệt" mặc định ở màn Đơn hàng, menu "Đơn hàng" có số đơn đang chờ. | |
| Q15 | Giữ chỗ lượt mã giảm giá cho đơn chờ duyệt bao lâu? | **Suốt thời gian chờ** (tới khi đơn được duyệt, bị huỷ hoặc hết hạn). Hiện nay chỉ giữ 30 phút, phù hợp MoMo nhưng không phù hợp chờ người duyệt. | Kết hợp Q13 và giới hạn 1 đơn chờ/học sinh để chống chiếm hết lượt mã |
| Q16 | Tên hiển thị của phương thức? | "Liên hệ Quản trị viên" (mô tả phụ: "Quản trị viên sẽ liên hệ hướng dẫn thanh toán và kích hoạt khóa học cho bạn"). Trạng thái đơn hiển thị là "Chờ Quản trị viên duyệt". | |

---

## User story

Là **học sinh**, tôi muốn đặt mua khóa học trong giỏ bằng phương thức "Liên hệ Quản trị viên" để được hướng dẫn thanh toán và kích hoạt khóa học khi chưa có thanh toán trực tuyến.

Là **Admin / Quản lý trang**, tôi muốn xem các đơn đang chờ, liên hệ học sinh, rồi **duyệt (đã nhận tiền)** hoặc **huỷ** đơn để học sinh được vào học đúng khóa đã trả tiền.

## Bối cảnh

- **Hiện trạng:**
  - Thanh toán có tiền đang khoá bằng cờ `FEATURE_PAID_CHECKOUT=false` (PO 2026-10-06, chờ kết nối MoMo).
    - `POST /checkout` có tổng > 0 trả 503 `PAYMENT_DISABLED`.
    - `GET /checkout/preview` trả `can_checkout=false`.
    - `/config/public.paid_checkout_enabled=false`, nên web hiện "Sắp mở bán".
  - `ProductionConfigGuard` chặn bật `FEATURE_PAID_CHECKOUT` khi `payments.ipn_ready=false`. Vì vậy **không dùng lại cờ này** cho thanh toán thủ công.
  - Backend đã xong và chạy được:
    - T16 giỏ hàng (`/cart*`);
    - T18 checkout (`CheckoutService`): một đơn chờ/học sinh qua unique `orders_user_pending_unique`, chốt giá vào `order_items`, giữ chỗ mã qua `coupon_hold_until`, huỷ đơn cũ khác nội dung (`superseded`);
    - `OrderFulfillmentService::markPaid`: đường duy nhất cấp enrollment, ghi `coupon_usages`, dọn giỏ, gửi thư phụ huynh sau commit; tự bật `needs_review` khi tiền về muộn, khóa đã sở hữu, khóa đã xoá hoặc mã vượt lượt;
    - `OrderStateMachine` (`pending → paid|failed|cancelled`, `cancelled|failed → paid`, `paid → refunded`) ghi `order_status_logs`.
  - **Chưa làm (V2):**
    - T19 (IPN), T20 (huỷ 12 giờ, `/pay`, đơn của tôi), T24 (admin đơn hàng), T25 (xuất file);
    - FW3 (giỏ, checkout, đơn của tôi trên web), FA8 (đơn hàng trong trang quản trị), FA9 (xuất file).
    - Web hiện **chưa có** trang giỏ hàng.
  - T34 (xoá tài khoản) chặn bằng 409 `ACCOUNT_HAS_PENDING_PAYMENT` **chỉ khi có link MoMo còn sống** (`payment_attempts` pending còn hạn). Đơn thủ công không có attempt, nên nếu không sửa thì không bị chặn. Pha B sẽ huỷ đơn đó với lý do `account_deleted`.
  - Mẫu tương tự đã có: FA6/T14 duyệt đăng ký khóa miễn phí (`approve`/`reject`, 409 `ALREADY_PROCESSED`, email báo kết quả, audit).
- **Thay đổi:** thêm phương thức `manual` ("Liên hệ Quản trị viên"):
  - Checkout tạo đơn `pending` không qua cổng thanh toán.
  - Admin hoặc Quản lý trang duyệt thủ công thì đơn chuyển `paid` bằng chính `markPaid`.
  - MoMo **ẩn, không xoá code**. Khi bật lại MoMo thì danh sách phương thức có cả hai.
- **Quan hệ với story cũ:**
  - Story này **ghi đè tạm** US-005 BR10–BR14 (luồng MoMo) và BR7 (12 giờ) cho phương thức `manual`. Các BR khác của US-005 vẫn giữ (chốt giá, xác thực tài khoản, đơn thất bại hoặc huỷ không mất giỏ, email xác nhận khi `paid`).
  - Dùng màn danh sách và chi tiết đơn của US-010. Hoàn tiền thủ công (US-010 AC4) áp dụng cho cả đơn thủ công đã duyệt.

## Business rules

**Phương thức và cờ**
- BR1: Có thêm phương thức thanh toán `manual`, tên hiển thị "Liên hệ Quản trị viên" (Q16), bật/tắt bằng cờ riêng `FEATURE_MANUAL_PAYMENT`. Đề xuất bật ở môi trường dev/staging ngay. Production bật khi PO cấu hình xong kênh liên hệ (Q1).
- BR2: Danh sách phương thức học sinh thấy **do server quyết định**:
  - `manual` có mặt khi `FEATURE_MANUAL_PAYMENT` bật;
  - `momo` có mặt khi `FEATURE_PAID_CHECKOUT` bật **và** có trong `PAYMENT_GATEWAYS`.
  - Hiện tại chỉ có `manual`, nên MoMo **ẩn**. Code MoMo (T17, T18) giữ nguyên.
- BR3: Khóa có phí được "Thêm vào giỏ" và "Mua" khi có **ít nhất 1** phương thức đang bật, tức thay cho điều kiện `paid_checkout_enabled` hiện nay.
- BR4: Mỗi đơn chỉ có 1 phương thức (US-005 BR2). Phương thức chọn lúc đặt được lưu vào `orders.payment_method = 'manual'`.

**Đặt đơn**
- BR5: Giỏ hàng, mã giảm giá, preview giữ nguyên quy tắc T16/T18.
  - Học sinh phải đã xác thực tài khoản (US-005 BR9).
  - Giá và số tiền giảm **chốt lúc tạo đơn** (US-005 BR1).
  - Đơn 0đ vẫn hoàn tất ngay như hiện nay, không qua duyệt.
- BR6: Đơn `manual` được tạo ở trạng thái `pending` (hiển thị "Chờ Quản trị viên duyệt"):
  - hạn chờ `expires_at = created_at + 72 giờ` (Q3);
  - **không** gọi cổng thanh toán, **không** tạo `payment_attempts`;
  - **không** cấp quyền học;
  - **không** xoá giỏ (giỏ chỉ được dọn khi đơn `paid`, theo US-005 BR3/BR6).
- BR7: Mỗi học sinh tối đa **1 đơn đang chờ** (mọi phương thức), giữ unique hiện có. Bấm đặt lại với **cùng nội dung** (cùng khóa, số tiền, mã, phương thức) thì trả lại đơn đang chờ, không tạo đơn mới. **Khác nội dung** thì phải xác nhận thay đơn (Q12, AC6).
- BR8: Giới hạn tạo đơn `manual`: 5 đơn/ngày/học sinh (Q13). Đơn được dùng lại (BR7) không tính.
- BR9: Ghi chú của học sinh (Q9):
  - tuỳ chọn, ≤ 500 ký tự, văn bản thuần;
  - cấm thẻ HTML, ký tự điều khiển (trừ xuống dòng) và ký tự bidi/zero-width, cùng quy tắc `PlainText` đang dùng;
  - không sửa được sau khi đặt.
- BR10: Đơn `manual` **giữ chỗ lượt mã giảm giá suốt thời gian chờ**: `coupon_hold_until = expires_at` (Q15). Đơn bị huỷ hoặc hết hạn thì nhả chỗ.
- BR11: Không áp hạn mức số tiền của cổng (min 1.000đ / max 50.000.000đ của MoMo) cho `manual`. Chỉ yêu cầu tổng > 0 (tổng 0 đi luồng đơn 0đ).

**Duyệt / huỷ**
- BR12: **Duyệt (đã nhận tiền):**
  - Ai được duyệt: Admin, Quản lý trang (Q4).
  - Điều kiện: đơn `manual`; trạng thái `pending`, hoặc `cancelled` trong vòng 30 ngày (Q11).
  - Bắt buộc tick "Đã nhận đủ {tổng tiền}".
  - Tuỳ chọn "Mã giao dịch" (≤ 100 ký tự, lưu vào `orders.payment_reference`) và "Ghi chú nội bộ" (≤ 1000 ký tự).
  - Hệ thống gọi `OrderFulfillmentService::markPaid` (nguồn `manual`, người thực hiện là staff). Kết quả:
    - đơn chuyển `paid`, `paid_at = now`;
    - cấp enrollment `active` cho mọi khóa trong đơn;
    - ghi lượt dùng mã;
    - xoá các khóa đó khỏi giỏ;
    - sau commit: gửi thư cho học sinh (Q6b) và thư phụ huynh (Q7).
- BR13: **Huỷ đơn bởi Quản trị viên:**
  - Áp cho đơn `manual` đang `pending`.
  - Bắt buộc **lý do gửi học sinh** (5–500 ký tự văn bản thuần), tuỳ chọn ghi chú nội bộ.
  - Đơn chuyển `cancelled`, lý do `admin_cancelled`, nhả chỗ mã, giỏ giữ nguyên, gửi thư cho học sinh (Q6c).
- BR14: **Học sinh tự huỷ** (Q8): chỉ áp cho đơn `manual` của chính mình đang `pending`. Đơn chuyển `cancelled`, lý do `user_cancelled`, nhả chỗ mã, giỏ giữ nguyên, không gửi thư.
- BR15: **Hết hạn:**
  - Có tác vụ định kỳ (mỗi 15 phút) huỷ đơn `manual` `pending` đã quá `expires_at`, lý do `expired`, nhả chỗ mã, gửi thư (Q6d).
  - Tác vụ này **chỉ** quét đơn `manual`. Job huỷ 12 giờ cho MoMo vẫn thuộc T20.
  - Quét cả đơn của tài khoản đã ẩn danh, nhưng không gửi thư cho tài khoản đó.
- BR16: **Xoá tài khoản (T34):**
  - Học sinh đang có đơn `manual` `pending` thì **bị chặn xoá tài khoản**: 409 `ACCOUNT_HAS_PENDING_PAYMENT` với `retry_after_at = expires_at` của đơn. Thông điệp gợi ý tự huỷ đơn trước nếu không còn muốn mua.
  - Lý do: học sinh có thể đã chuyển tiền; huỷ ngầm (pha B) thì Quản trị viên không còn cách liên hệ.
  - Đơn đã `paid` của tài khoản đã xoá giữ nguyên làm chứng từ (US-010).
- BR17: Mọi thao tác duyệt, huỷ hay ghi chú phải chặn xử lý trùng:
  - 2 người cùng bấm, bấm đúp, hoặc duyệt đúng lúc học sinh huỷ hay hệ thống hết hạn: chỉ 1 thao tác thắng;
  - thao tác thua nhận 409 kèm trạng thái hiện tại;
  - khoá đúng thứ tự chuẩn `carts → orders → courses → enrollments → coupons`.
- BR18: Ghi chú nội bộ (Should, Q10):
  - Quản trị viên thêm được ghi chú (≤ 1000 ký tự) vào đơn ở mọi trạng thái, không đổi trạng thái. Ví dụ: "Đã gọi 9h, hẹn chuyển khoản chiều nay".
  - Ghi chú chỉ thêm, không sửa, không xoá. Mỗi ghi chú có người viết và thời điểm.
  - Học sinh **không** thấy ghi chú nội bộ.

**Hiển thị và dữ liệu cá nhân**
- BR19: Danh sách đơn của quản trị **che** email/SĐT học sinh (api-contract S14). **Chi tiết** đơn hiện đầy đủ email, SĐT và ghi chú học sinh để liên hệ, và ghi audit `order.view_pii`. SĐT chưa xác thực có nhãn "chưa xác thực". **Không bao giờ** trả thông tin phụ huynh.
- BR20: Thư gửi hộp thư quản trị không có email, SĐT, tên học sinh hay ghi chú học sinh (Q5).

## Trạng thái đơn (`payment_method = manual`)

| Trạng thái (`status`) | Lý do (`status_reason`) | Hiển thị cho học sinh | Hiển thị cho quản trị | Ai/cái gì gây ra |
|---|---|---|---|---|
| `pending` | — | Chờ Quản trị viên duyệt (hạn {expires_at}) | Chờ duyệt (còn X giờ) | Học sinh đặt đơn |
| `paid` | `manual_confirmed` | Đã thanh toán | Đã duyệt bởi {tên} lúc {t} | Admin/QLT duyệt |
| `cancelled` | `user_cancelled` | Bạn đã huỷ đơn | HS tự huỷ | Học sinh |
| `cancelled` | `admin_cancelled` | Đã huỷ: {lý do gửi HS} | Huỷ bởi {tên}: {lý do} | Admin/QLT |
| `cancelled` | `expired` | Đã huỷ do quá hạn chờ | Tự huỷ (hết hạn) | Tác vụ định kỳ |
| `cancelled` | `superseded` | Đã thay bằng đơn #{mã mới} | Thay bằng đơn mới | Học sinh đặt đơn khác nội dung (đã xác nhận) |
| `cancelled` | `account_deleted` | — | Tài khoản đã xoá | Chỉ còn với đơn cũ (BR16 chặn trường hợp mới) |
| `paid` (từ `cancelled`) | `manual_confirmed` + `needs_review` | Đã thanh toán | Đã duyệt muộn, cờ "Cần xem lại" | Admin/QLT duyệt muộn (Q11) |
| `refunded` | — | Đã hoàn tiền | Đã hoàn tiền | Admin/QLT (US-010) |

Chuyển trạng thái hợp lệ: `pending → paid | cancelled`; `cancelled → paid` (trong 30 ngày); `paid → refunded`. Mọi lần chuyển ghi `order_status_logs` (người thực hiện: `user` / `staff` + id / `system`).

## Acceptance criteria

**A. Chọn phương thức và đặt đơn (học sinh)**
- AC1: Given `FEATURE_MANUAL_PAYMENT` bật và `FEATURE_PAID_CHECKOUT` tắt, When gọi `GET /config/public`, Then response cho biết phương thức đang có chỉ gồm `manual`, kèm các kênh liên hệ đã cấu hình (kênh trống không xuất hiện) và hạn chờ 72 giờ. Khóa có phí hiện nút "Thêm vào giỏ" và "Mua" thay cho "Sắp mở bán".
- AC2: Given học sinh đã xác thực có giỏ 2 khóa tổng 500.000đ, When mở trang thanh toán, Then thấy danh sách khóa, giảm giá, tổng tiền, **một** lựa chọn "Liên hệ Quản trị viên" (đã chọn sẵn) cùng mô tả, ô "Ghi chú cho Quản trị viên (không bắt buộc)", **không** thấy MoMo.
- AC3: Given AC2, When học sinh bấm "Gửi đơn", Then:
  - tạo đơn `pending` với `payment_method=manual`, `expires_at` = now + 72 giờ;
  - không có `payment_attempts`, không có enrollment mới;
  - giỏ giữ nguyên;
  - học sinh được chuyển tới màn "Đơn đã gửi" (AC9);
  - học sinh nhận thư "Đã nhận đơn";
  - hộp thư quản trị nhận thư "Đơn mới #{mã}" không có dữ liệu cá nhân học sinh.
- AC4: Given học sinh bấm "Gửi đơn" 2 lần liên tiếp hoặc ở 2 tab với cùng giỏ, When cả 2 request tới server, Then chỉ có **1** đơn. Request sau nhận lại đúng đơn đó (dùng lại) và không gửi thêm thư.
- AC5: Given học sinh đang có đơn `manual` `pending` và giỏ **không đổi**, When đặt đơn lại, Then nhận lại đơn đang chờ (cùng mã), không tạo đơn mới.
- AC6: Given học sinh đang có đơn `manual` `pending` #A và giỏ **đã đổi** (thêm/bớt khóa, đổi mã), When bấm "Gửi đơn", Then:
  - hệ thống báo 409 kèm mã đơn #A;
  - giao diện hỏi "Bạn đang có đơn #A chờ duyệt. Đặt đơn mới sẽ huỷ đơn cũ.";
  - chọn "Đặt đơn mới": #A chuyển `cancelled`/`superseded`, đơn #B mới được tạo;
  - chọn "Giữ đơn cũ": không có gì thay đổi.
- AC7: Given giá khóa, trạng thái bán hoặc mã giảm giá thay đổi giữa lúc xem và lúc gửi, When gửi đơn, Then nhận 409 `CHECKOUT_CHANGED` với preview mới như T18 hiện nay. Không tạo đơn; học sinh xem tổng mới rồi gửi lại.
- AC8: Given học sinh đã tạo 5 đơn `manual` trong ngày (giờ VN), When tạo đơn thứ 6 (khác nội dung), Then nhận 429 với thông điệp ở Q13 và không tạo đơn. Sang 00:00 giờ VN thì tạo được.

**B. Màn "Đơn đã gửi" và Đơn của tôi (học sinh)**
- AC9: Given vừa tạo đơn `manual`, When màn "Đơn đã gửi" hiển thị, Then thấy:
  - mã đơn (có nút sao chép);
  - danh sách khóa, tổng tiền;
  - trạng thái "Chờ Quản trị viên duyệt";
  - hạn chờ (ngày giờ VN);
  - các kênh liên hệ đã cấu hình (SĐT bấm gọi được trên điện thoại, link Zalo mở tab mới, email dạng `mailto:`), giờ hỗ trợ;
  - câu "Khi chuyển khoản, vui lòng ghi mã đơn {mã} trong nội dung";
  - nút "Xem đơn của tôi" và "Huỷ đơn".
- AC10: Given học sinh có nhiều đơn, When vào "Đơn của tôi", Then thấy danh sách mới nhất trước, mỗi dòng có: mã, ngày đặt, số khóa, tổng tiền (kèm giảm giá nếu có), phương thức "Liên hệ Quản trị viên", trạng thái theo bảng trạng thái. Đơn `pending` hiện thêm hạn chờ.
- AC11: Given học sinh mở chi tiết đơn của **học sinh khác** (đoán mã đơn), When gọi API, Then nhận 404 (không lộ đơn có tồn tại hay không).
- AC12: Given đơn `manual` `pending` của chính mình, When bấm "Huỷ đơn" và xác nhận, Then đơn chuyển `cancelled`/`user_cancelled`, nhả chỗ mã, giỏ giữ nguyên, trạng thái mới hiện ngay. Bấm huỷ lần 2, hoặc huỷ đơn đã `paid`/`cancelled`, nhận 409 kèm trạng thái hiện tại.
- AC13: Given đơn đã được duyệt, When học sinh mở "Khóa học của tôi" hoặc trang khóa, Then học được ngay các khóa trong đơn (enrollment `active`), giỏ không còn các khóa đó.
- AC14: Given đơn bị Quản trị viên huỷ, When học sinh xem chi tiết đơn, Then thấy lý do huỷ mà Quản trị viên đã nhập. **Không** thấy ghi chú nội bộ.

**C. Quản trị: danh sách, chi tiết, duyệt, huỷ**
- AC15: Given có đơn `manual` đang chờ, When Admin/QLT mở menu "Đơn hàng", Then:
  - menu có số đơn đang chờ;
  - tab mặc định "Chờ duyệt" liệt kê đơn `pending`, cũ nhất trước;
  - mỗi dòng có: mã, tên học sinh, email/SĐT **đã che**, số khóa, tổng tiền, thời điểm đặt, còn bao lâu tới hạn;
  - đơn còn < 12 giờ có nhãn "Sắp hết hạn".
- AC16: Given Admin/QLT mở chi tiết một đơn, When trang hiển thị, Then thấy:
  - email và SĐT **đầy đủ** (SĐT chưa xác thực có nhãn);
  - ghi chú học sinh;
  - danh sách khóa với giá chốt, mã giảm giá và số tiền giảm;
  - lịch sử trạng thái (ai, lúc nào, lý do), ghi chú nội bộ.
  
  Mỗi lần mở chi tiết ghi đúng 1 audit `order.view_pii`.
- AC17: Given đơn `manual` `pending` tổng 500.000đ, When Admin/QLT bấm "Duyệt: đã nhận tiền", tick "Đã nhận đủ 500.000đ", nhập mã giao dịch "FT2610…", bấm xác nhận, Then:
  - đơn `paid`, `payment_reference` = mã đã nhập, người duyệt và thời điểm hiện ở chi tiết;
  - enrollment `active` cho mọi khóa;
  - `coupon_usages` và `used_count` tăng nếu có mã;
  - các khóa đó bị xoá khỏi giỏ học sinh;
  - audit `order.manual_approve`;
  - học sinh nhận thư xác nhận; phụ huynh nhận đúng 1 thư nếu đủ điều kiện ADR-006.
- AC18: Given không tick "Đã nhận đủ", When bấm xác nhận, Then nút bị vô hiệu ở giao diện. Server cũng trả 422 nếu thiếu `confirm=true`.
- AC19: Given 2 Quản trị viên cùng bấm Duyệt (hoặc 1 người bấm đúp), When cả 2 request tới, Then đúng 1 thành công. Request còn lại nhận 409 `ALREADY_PROCESSED` với trạng thái hiện tại. Enrollment, `coupon_usages`, thư và audit đều chỉ có 1.
- AC20: Given đơn `manual` `pending`, When Admin/QLT bấm "Huỷ đơn", nhập lý do "Không liên hệ được qua SĐT và Zalo" và xác nhận, Then:
  - đơn `cancelled`/`admin_cancelled`, lý do lưu và hiện cho học sinh;
  - nhả chỗ mã, giỏ học sinh giữ nguyên;
  - audit `order.manual_cancel`;
  - học sinh nhận thư kèm lý do.
  
  Thiếu lý do hoặc lý do < 5 ký tự thì 422.
- AC21: Given Quản trị viên đang ở chi tiết đơn `pending` thì học sinh tự huỷ (hoặc đơn hết hạn), When Quản trị viên bấm Duyệt (không đánh dấu "duyệt muộn"), Then nhận 409 `ORDER_STATUS_CHANGED`. Giao diện tải lại đơn và hiện trạng thái mới; nếu đơn còn trong 30 ngày thì hiện nút "Duyệt muộn".
- AC22: Given đơn `manual` `cancelled` (hết hạn / học sinh huỷ / Quản trị viên huỷ / thay bằng đơn mới) trong vòng 30 ngày, When Admin/QLT bấm "Duyệt muộn" và xác nhận hộp thứ hai ("Đơn đã huỷ lúc …; chỉ duyệt khi chắc chắn đã nhận tiền cho đơn này"), Then:
  - đơn `paid` với cờ `needs_review`, cấp enrollment như AC17;
  - nếu mã giảm giá đã hết lượt hoặc học sinh đã sở hữu khóa từ đơn khác: vẫn duyệt, cờ "Cần xem lại" ghi rõ lý do (vượt lượt mã / khóa đã sở hữu) để Quản trị viên hoàn tiền phần trùng ngoài hệ thống.
  
  Quá 30 ngày thì 409 `ORDER_APPROVAL_WINDOW_PASSED`.
- AC23: Given đơn có khóa đã **ngừng bán** (unpublished) sau khi đặt, When duyệt, Then vẫn duyệt và cấp quyền học (học sinh đã trả tiền theo giá chốt); màn chi tiết hiện cảnh báo "Khóa X đã ngừng bán" trước khi bấm.
- AC24: Given đơn có khóa đã bị **xoá** (chỉ xảy ra với duyệt muộn, vì đơn đang chờ chặn xoá khóa), When duyệt, Then 409 `COURSE_UNAVAILABLE` kèm tên khóa, đơn giữ nguyên. Quản trị viên xử lý hoàn tiền ngoài hệ thống. Không duyệt một phần.
- AC25: Given Quản trị viên thêm ghi chú nội bộ "Đã gọi 9h, hẹn chiều", When lưu, Then ghi chú hiện ở chi tiết đơn kèm tên người viết và thời điểm, có audit `order.note_add`, trạng thái đơn không đổi. Học sinh không thấy ghi chú qua API.
- AC26: Given đơn `manual` đã `paid`, When Admin/QLT dùng "Đánh dấu hoàn tiền" (US-010 AC4), Then quy trình hoàn tiền áp dụng như đơn MoMo (thu hồi toàn bộ enrollment của đơn).

**D. Hết hạn, xoá tài khoản, cờ tắt**
- AC27: Given đơn `manual` `pending` có `expires_at` đã qua, When tác vụ định kỳ chạy, Then đơn `cancelled`/`expired`, nhả chỗ mã, học sinh nhận thư "Đơn đã huỷ do quá hạn". Chạy lại tác vụ không đổi gì thêm và không gửi thư lần 2. Đơn đã được duyệt ngay trước đó không bị huỷ.
- AC28: Given học sinh có đơn `manual` `pending`, When bấm xoá tài khoản (T34), Then nhận 409 `ACCOUNT_HAS_PENDING_PAYMENT` với `retry_after_at` = hạn đơn; thông điệp gợi ý huỷ đơn trước. Sau khi tự huỷ đơn thì xoá được.
- AC29: Given `FEATURE_MANUAL_PAYMENT` tắt và MoMo cũng tắt, When học sinh gửi đơn có tiền, Then 503 `PAYMENT_DISABLED` như hiện nay. Đơn `manual` đang chờ **vẫn** được Quản trị viên duyệt hoặc huỷ, học sinh vẫn tự huỷ được, và tác vụ hết hạn vẫn chạy.
- AC30: Given MoMo được bật lại sau này (`FEATURE_PAID_CHECKOUT=true` và có cổng), When mở trang thanh toán, Then thấy cả 2 phương thức, luồng MoMo chạy như US-005 mà không cần sửa code luồng thủ công.

**E. Phân quyền**
- AC31: Given tài khoản Giáo viên, When gọi API danh sách, chi tiết, duyệt, huỷ hoặc ghi chú đơn, Then 403. Học sinh gọi API quản trị thì 401/403 theo host. Học sinh gọi huỷ đơn của người khác thì 404.

## Trường hợp biên & lỗi

- **Giỏ rỗng hoặc mọi khóa không còn bán:** preview `can_checkout=false`, gửi đơn trả 422 `CART_EMPTY` (như T18), giao diện đưa về giỏ.
- **Khóa trong giỏ đã sở hữu** (được cấp sau khi thêm vào giỏ): khóa đó bị đánh dấu không khả dụng, không vào đơn (T16). Nếu học sinh sở hữu khóa **sau khi đặt** (vd từ đơn khác đã duyệt): lúc duyệt đơn này hệ thống giữ quyền cũ và bật `needs_review` lý do `already_owned` (AC22).
- **Giá khóa đổi sau khi đặt đơn:** đơn giữ giá chốt, Quản trị viên thu đúng tổng của đơn. Màn chi tiết có thể hiện "giá hiện tại khác giá chốt" (Could).
- **Mã giảm giá:**
  - hết lượt do đơn khác đang giữ chỗ: không áp được (409 `CHECKOUT_CHANGED` + `COUPON_EXHAUSTED`);
  - mã bị vô hiệu hoặc hết hạn **sau khi đặt**: đơn vẫn giữ mức giảm đã chốt, lúc duyệt vẫn ghi lượt dùng;
  - duyệt muộn khi mã đã đủ lượt: AC22;
  - mỗi học sinh dùng mỗi mã 1 lần: ràng buộc `coupon_usages (coupon_id, user_id)`; trùng thì `needs_review`.
- **Đơn trùng:** 1 đơn chờ/học sinh (unique DB). Gửi lại cùng nội dung thì dùng lại; khác nội dung thì xác nhận thay (AC6). Học sinh chuyển tiền cho đơn cũ đã bị thay: Quản trị viên duyệt muộn đơn cũ (AC22) rồi huỷ đơn mới, hoặc ngược lại.
- **Học sinh chuyển thiếu hoặc thừa tiền:** ngoài phạm vi xử lý trong hệ thống. Quản trị viên chỉ duyệt khi đã nhận đủ; chênh lệch ghi vào ghi chú nội bộ và xử lý ngoài hệ thống.
- **Học sinh huỷ đơn đúng lúc Quản trị viên duyệt:** khoá dòng đơn, ai tới trước thắng. Bên sau nhận 409 (AC12, AC21).
- **Hết hạn đúng lúc duyệt:** tác vụ hết hạn kiểm lại `status = pending` và `expires_at` dưới khoá. Đơn đã `paid` thì bỏ qua.
- **Duyệt khi khóa đã ngừng bán:** vẫn cấp quyền (AC23). **Khóa đã xoá:** chặn (AC24). Xoá khóa đang có đơn `manual` chờ: đã bị chặn bởi 409 `COURSE_HAS_PENDING_ORDERS`. Cần kiểm điều kiện "chưa hết hạn" dùng `expires_at` của đơn (72 giờ), không phải 12 giờ.
- **Tài khoản học sinh bị khoá (`status=locked`) khi đơn đang chờ:** vẫn duyệt được, màn chi tiết hiện cảnh báo "Tài khoản đang bị khoá". Thư vẫn gửi.
- **Tài khoản đã ẩn danh** (đơn cũ trước khi có BR16, hoặc dữ liệu lỗi): duyệt vẫn cấp quyền, không gửi thư học sinh và phụ huynh (giống quy tắc T19), màn hiển thị "Tài khoản đã xoá", không lỗi 500.
- **Học sinh chưa xác thực email:** không đặt được đơn (403 `ACCOUNT_NOT_VERIFIED`, như T18).
- **Học sinh chỉ có SĐT chưa xác thực:** Quản trị viên vẫn thấy SĐT kèm nhãn "chưa xác thực" để cân nhắc khi liên hệ.
- **Gửi thư lỗi** (SMTP chết, trần thư): không làm hỏng việc đặt, duyệt hay huỷ đơn. Thư đi qua hàng đợi sau commit, lỗi ghi log.
- **Cấu hình kênh liên hệ rỗng:** production không khởi động khi `FEATURE_MANUAL_PAYMENT` bật mà không có kênh nào (BR1). Local thì màn hiện email hỗ trợ mặc định.
- **Dữ liệu lớn:** tab "Chờ duyệt" chỉ có đơn `pending` (≤ 1 đơn/học sinh, tự hết hạn sau 72 giờ) nên nhỏ. Các tab khác theo quy tắc T24 (bắt buộc khoảng ngày ≤ 366, cursor). Đề xuất cho Architect: tab "Chờ duyệt" **không bắt buộc** khoảng ngày.
- **Ghi chú chứa HTML hoặc script:** 422; hiển thị luôn dạng văn bản (không render HTML).
- **Mất mạng sau khi bấm "Gửi đơn":** bấm lại thì dùng lại đơn (AC4/AC5), không tạo đơn thứ 2.
- **Học sinh mở màn "Đơn đã gửi" của đơn đã được duyệt hoặc huỷ:** màn hiện trạng thái hiện tại, không hiện hướng dẫn liên hệ.

## Phân quyền

| Vai trò | Xem đơn | Tạo đơn `manual` | Duyệt / Duyệt muộn | Huỷ đơn | Ghi chú nội bộ | Hoàn tiền |
|---|---|---|---|---|---|---|
| Admin | Mọi đơn | – | Có | Có (kèm lý do) | Có | Có (US-010) |
| Quản lý trang | Mọi đơn | – | Có (Q4) | Có (kèm lý do) | Có | Có (US-010) |
| Giáo Viên | – | – | – | – | – | – |
| Học Sinh | Chỉ đơn của mình (không thấy ghi chú nội bộ) | Có (đã xác thực) | – | Chỉ đơn `pending` của mình | – | – |

## Ảnh hưởng dữ liệu

Đề xuất. Tên cột và bảng cuối cùng do `laravel-architect` + `laravel-dba` chốt.

- **`orders`** (bảng đã có):
  - `payment_method` thêm giá trị `manual` (cột `string(20)`, không có CHECK nên không cần migration cho giá trị). Cân nhắc thêm CHECK giá trị hợp lệ (`none`, `momo`, `manual`, `fake`).
  - Thêm cột:
    - `customer_note` `varchar(500) NULL`: ghi chú học sinh;
    - `cancel_reason_public` `varchar(500) NULL`: lý do huỷ hiển thị cho học sinh;
    - `confirmed_by` `FK users NULL restrict`: người duyệt (có thể suy từ `order_status_logs.actor_id`, nhưng cột riêng giúp liệt kê nhanh "Duyệt bởi").
  - `payment_reference` dùng lại cho mã giao dịch nhập tay.
  - `status_reason` thêm giá trị `manual_confirmed`, `user_cancelled`, `admin_cancelled`, `expired`.
  - Cần index phục vụ tab "Chờ duyệt" và tác vụ hết hạn: `(status, expires_at)` đã có. Cân nhắc `(payment_method, status, created_at)`.
- **`order_status_logs`:** `actor_type` thêm `staff` (cột `string(10)`, đủ độ dài). `meta` ghi `source=manual`, có `late=true` khi duyệt muộn. Không ghi nội dung ghi chú hay PII vào `meta`.
- **Bảng mới `order_notes`** (Should, BR18): `id`, `order_id` (FK cascade theo `orders`), `author_id` (FK users restrict), `body` `varchar(1000)`, `created_at`. Index `(order_id, id)`.
- **`coupon_hold_until`:** đơn `manual` đặt bằng `expires_at` (BR10).
- **`audit_logs`:** action mới `order.manual_approve`, `order.manual_cancel`, `order.note_add`. Dùng lại `order.view_pii`, `order.refund`. Học sinh tự huỷ thì chỉ ghi `order_status_logs` (actor `user`), không cần audit.
- **Cấu hình (env):**
  - `FEATURE_MANUAL_PAYMENT` (bool);
  - `ORDERS_MANUAL_PENDING_TTL_HOURS` (72);
  - `ORDERS_MANUAL_APPROVAL_WINDOW_DAYS` (30);
  - `ORDERS_MANUAL_PER_DAY` (5);
  - `PAYMENT_CONTACT_PHONE`, `PAYMENT_CONTACT_ZALO_URL`, `PAYMENT_CONTACT_EMAIL` (mặc định `SUPPORT_EMAIL`), `PAYMENT_CONTACT_HOURS`;
  - `ORDERS_MANUAL_NOTIFY_EMAILS` (danh sách, mặc định `SUPPORT_EMAIL`).
  
  Thêm vào `.env.example`, `infra/production/.env.production.example` và `ProductionConfigGuard` (có ít nhất 1 kênh; link Zalo phải `https://zalo.me/…`).
- **API (đề xuất cho Architect, cập nhật api-contract §2.1, §2.3, §2.5):**
  - `GET /config/public`:
    - thêm `payment_methods: ["manual"]`;
    - thêm `manual_payment: {contact: {phone, zalo_url, email, hours}, pending_ttl_hours}`;
    - `paid_checkout_enabled` = có ít nhất 1 phương thức (hoặc thêm `checkout_enabled` mới; giữ field cũ để FE không vỡ).
  - `GET /checkout/preview`: thêm `payment_methods[]`. `can_checkout` xét theo BR3.
  - `POST /checkout`:
    - nhận `payment_method` (`manual`|`momo`; hoặc mở rộng `gateway`, Architect chọn), `customer_note`, `replace_pending` (bool, cho AC6);
    - thiếu `replace_pending` khi có đơn chờ khác nội dung → 409 `PENDING_ORDER_EXISTS` + `errors.order_code`;
    - 201 trả `payment: null`, `status: pending`, thêm `expires_at`.
  - `GET /orders`, `GET /orders/{code}`: phần "đơn của tôi" của T20, làm trước ở đây.
  - `POST /orders/{code}/cancel` (mới).
  - Admin:
    - `GET /admin/orders` (T24) thêm lọc `payment_method`; `status=pending` không bắt buộc khoảng ngày;
    - `GET /admin/orders/{code}` (T24) thêm `customer_note`, `notes[]`, cảnh báo khóa ngừng bán/đã xoá, `approval_window_until`;
    - `POST /admin/orders/{code}/approve` (`confirm=true`, `payment_reference?`, `note?`, `late?`);
    - `POST /admin/orders/{code}/cancel` (`reason`, `note?`);
    - `POST /admin/orders/{code}/notes` (`body`);
    - `GET /admin/orders/pending-count` (badge menu, hoặc trả trong `/admin/auth/me`).
- **Code (Dev lưu ý, không phải thiết kế cuối):**
  - `CheckoutService`: nhánh `manual` bỏ `assertPayable` theo cổng, không `resolveAttempt`, đặt `expires_at`/`coupon_hold_until` theo BR6/BR10. Bỏ điều kiện `paid_checkout` cho `manual`.
  - `OrderFulfillmentService::markPaid`: thêm nguồn `manual` với người thực hiện là staff (hiện nhánh không phải `checkout` ghi actor `gateway`). Thêm thư xác nhận đơn cho học sinh (`OrderPaidMail` chưa tồn tại, T19 dự kiến tạo).
  - `OrderStateMachine`: thêm `ACTOR_STAFF`.
  - `AccountAnonymizer::livePaymentUntil`: tính thêm đơn `manual` `pending` (BR16). Pha B (`FinalizeAccountDeletionJob`) vẫn bỏ qua đơn `manual` `pending` nếu gặp do race.
  - Mail mới:
    - `ManualOrderReceivedMail` (học sinh);
    - `ManualOrderCancelledMail` (học sinh; dùng chung cho admin huỷ và hết hạn, khác nội dung);
    - `NewManualOrderStaffMail` (hộp thư quản trị);
    - `OrderPaidMail` (học sinh).
    
    Tất cả `ShouldQueue` + `ShouldBeEncrypted`, gửi `afterCommit`.
  - Lệnh định kỳ `orders:expire-manual` mỗi 15 phút, `withoutOverlapping()->onOneServer()`.

## Ngoài phạm vi

- Thanh toán MoMo (US-005 luồng IPN, T19/T20 phần cổng): giữ code, ẩn khỏi giao diện.
- Hiển thị số tài khoản ngân hàng / QR VietQR, đối soát sao kê ngân hàng tự động (trừ khi PO chọn khác ở Q2).
- Nhận thiếu tiền, trả nhiều lần, duyệt một phần đơn (duyệt từng khóa).
- Hoàn tiền một phần (US-010 BR4 giữ nguyên).
- Xuất file đơn hàng (T25/FA9): làm sau. Tuy vậy danh sách có thể lọc `payment_method=manual`.
- Thông báo qua SMS/Zalo OA tự động.
- Trả thông tin phụ huynh cho Quản trị viên để liên hệ.
- Học sinh sửa ghi chú hoặc sửa đơn sau khi gửi (muốn sửa thì huỷ và đặt lại).
- Trạng thái "Đang chờ duyệt" trên thẻ khóa / trang chi tiết khóa (`viewer_state`): ghi backlog (Could), cần API bổ sung.

## Tách việc và ước lượng (đề xuất cho Architect chốt mã task)

Story vượt 3 ngày nên tách thành các phần giao được độc lập. Mã task là đề xuất.

| Phần | Nội dung | AC | Phụ thuộc | Ước lượng |
|---|---|---|---|---|
| **T38** Backend: đặt đơn thủ công + đơn của tôi + huỷ + hết hạn [SEC] [DBA] | Cờ và cấu hình, `config/public`, preview/checkout nhánh `manual`, `replace_pending`, giới hạn 5/ngày, `customer_note`, giữ chỗ mã, `GET /orders`, `GET /orders/{code}`, `POST /orders/{code}/cancel`, `orders:expire-manual`, thư học sinh (đã nhận / hết hạn) + thư hộp thư quản trị, T34 chặn xoá (BR16), guard production | AC1–AC14 (phần API), AC27–AC30 | T18, T34 (đã xong) | ~3 ngày |
| **T24 (thu gọn cho V1)** Backend: admin đơn hàng [SEC] [DBA] | Danh sách cursor, lọc, che PII, chi tiết + audit `order.view_pii`, hoàn tiền (như đặc tả T24 hiện tại) + lọc `payment_method`, tab chờ duyệt không bắt buộc khoảng ngày, đếm chờ duyệt | AC15, AC16, AC26 | T18, T28 | ~2 ngày (như T24) |
| **T39** Backend: duyệt / huỷ / ghi chú [SEC] | `approve` (gồm duyệt muộn), `cancel`, `notes`, `markPaid` nguồn `manual` + actor staff, `OrderPaidMail`, thư huỷ, audit, test race (2 người duyệt, duyệt ↔ HS huỷ ↔ hết hạn) | AC17–AC25, AC31 | T38, T24 | ~2 ngày |
| **Design** (`nextjs-designer`) | Trang thanh toán có chọn phương thức, màn "Đơn đã gửi", Đơn của tôi (danh sách + chi tiết + huỷ), hộp xác nhận thay đơn; admin: tab Chờ duyệt, chi tiết đơn, hộp Duyệt / Duyệt muộn / Huỷ, ghi chú | — | — | ~1,5 ngày |
| **FW3 (đổi phạm vi)** web học sinh | Trang giỏ (chưa có trên web), nút "Thêm vào giỏ"/"Mua" theo BR3, trang thanh toán chọn phương thức, màn "Đơn đã gửi", Đơn của tôi + chi tiết + huỷ, xử lý 409 thay đơn / `CHECKOUT_CHANGED` / 429. **Bỏ** phần MoMo (`/checkout/ket-qua` poll, "Kiểm tra lại", link hết hạn, kiểm host `pay_url`) sang lúc bật MoMo | AC1–AC14, AC28 (thông điệp) | T38, design | ~3 ngày (phần MoMo để sau ~1 ngày) |
| **FA8 (đổi phạm vi)** trang quản trị | Màn Đơn hàng: tab Chờ duyệt mặc định + badge menu, danh sách cursor + che PII, chi tiết, hộp Duyệt / Duyệt muộn / Huỷ / Hoàn tiền, ghi chú nội bộ, xử lý 409 tải lại | AC15–AC26, AC31 | T24, T39, design | ~3 ngày (FA8 gốc 2 + 1) |

Tổng: backend ~7 ngày (T38 3 + T24 2 + T39 2), FW3 ~3 ngày, FA8 ~3 ngày, design ~1,5 ngày. Thứ tự: T38 → (T24 ‖ FW3) → T39 → FA8. FW3 và FA8 làm song song được khi API đã chốt hợp đồng.

## Ghi chú cho Designer / Dev / QA

- **Designer:**
  - Trang thanh toán: khối "Phương thức thanh toán" dạng radio card. Hiện chỉ có 1 lựa chọn nhưng vẫn render dạng danh sách để thêm MoMo sau không phải thiết kế lại. Nút chính là "Gửi đơn" (không phải "Thanh toán").
  - Màn "Đơn đã gửi": mã đơn to, dễ sao chép; các kênh liên hệ là nút lớn (≥ 44px) trên 375px; nêu rõ "chưa phải trả tiền qua website"; có hạn chờ.
  - Admin: hộp Duyệt có checkbox xác nhận số tiền; hộp Duyệt muộn khác màu (cảnh báo) và nêu thời điểm huỷ; hộp Huỷ có ô lý do "sẽ gửi cho học sinh" tách khỏi ô "Ghi chú nội bộ (học sinh không thấy)".
  - Duyệt **có** hộp xác nhận (khác FA6), vì liên quan tiền.
- **Dev:**
  - Luồng tiền, nên phải qua `laravel-security`.
  - Không gọi cổng thanh toán trong nhánh `manual`.
  - `markPaid` vẫn là đường duy nhất cấp enrollment từ đơn.
  - Giữ đúng thứ tự khoá chuẩn.
  - Kiểm `OrderPolicy` theo chủ đơn (học sinh) và `isStaff()` (admin, QLT).
  - Không thêm `manual` vào `payments.enabled_gateways`, vì danh sách này dùng cho route webhook.
  - `ProductionConfigGuard` cho phép `FEATURE_MANUAL_PAYMENT=true` khi `ipn_ready=false`.
  - Sửa `CourseService::delete` nếu điều kiện "đơn chờ chưa hết hạn" đang giả định 12 giờ.
- **QA:**
  - Race: 2 tab gửi đơn; 2 admin cùng duyệt; duyệt ↔ học sinh huỷ; duyệt ↔ tác vụ hết hạn; N học sinh cùng mã cuối (giữ chỗ 72 giờ).
  - IDOR: chi tiết và huỷ đơn người khác.
  - Giáo viên gọi API quản trị đơn.
  - Đếm thư: phụ huynh đúng 1 thư khi duyệt, 0 khi huỷ; thư quản trị không có PII.
  - T34: chặn xoá khi có đơn chờ, xoá được sau khi tự huỷ.
  - Cờ tắt giữa chừng (AC29).
  - Giới hạn 5 đơn/ngày theo giờ VN.
  - Đơn có mã giảm giá: `used_count` chỉ tăng khi duyệt.
  - Tuyệt đối không chạy `migrate:fresh`/`db:seed` trong container php (quy tắc board); seed e2e dùng tiền tố riêng (vd `e2e-us022-*`).

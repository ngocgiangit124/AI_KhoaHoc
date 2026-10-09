# Đặc tả UX — US-022: Thanh toán thủ công "Liên hệ Quản trị viên"

**Ngày:** 2026-10-08 · **Design system:** v2 "Vở ô ly & mực tím" (`design-system-v2.md`), không thêm token, không thêm package.
**Thay thế:** US-004 (giỏ), US-005 §2.1/§2.3 (thanh toán, đơn của tôi) và US-010 §2 (đơn hàng quản trị) cho phương thức `manual`. Luồng MoMo của US-005 (`/checkout/ket-qua`, poll, "Kiểm tra lại") giữ nguyên tài liệu, chỉ dựng lại khi bật MoMo.
**Bản xem trước:** web `http://api.localhost:3000/v2/muc-luc` (4 mục "— mới"), admin `http://admin-api.localhost:3001/v2` (2 mục "— mới"). Ảnh: `mockups/v2/preview/us022-*.png` (19 ảnh, 375 và 1280 px, đã bật giảm chuyển động).

## 0. Brief

- **Người dùng:** học sinh lớp 6–12, chủ yếu dùng điện thoại, thường cần bố mẹ chuyển khoản hộ. Quản trị viên (Admin, Quản lý trang) dùng máy tính, đôi khi điện thoại khi gọi cho học sinh.
- **Việc chính của học sinh:** gửi đơn thật nhanh, rồi biết chắc ba điều: **mã đơn** là gì, **liên hệ ai**, **chờ đến bao giờ**. Không bị lừa chuyển khoản sai chỗ.
- **Việc chính của Quản trị viên:** thấy đơn nào sắp hết hạn, liên hệ được học sinh, chỉ duyệt khi đã nhận đủ tiền, không duyệt trùng với đồng nghiệp.
- **Điểm nhấn duy nhất:** "phiếu" mã đơn ở màn Đơn đã gửi: viền nét đứt màu mực tím trên nền `primary-soft`, mã cỡ H1, nút sao chép. Các khối khác giữ tiết chế (Sheet trắng, không bóng).

## 1. Luồng

```
Khóa có phí: "Mua khóa học" (BR3: hiện khi payment_methods khác rỗng) → /gio-hang
/gio-hang → "Tiếp tục đặt mua" → /thanh-toan
/thanh-toan → chọn phương thức (hiện chỉ "Liên hệ Quản trị viên", chọn sẵn) → ghi chú (tuỳ chọn) → "Gửi đơn"
   201/200 → /thanh-toan/da-gui/{code}
   409 PENDING_ORDER_EXISTS → hộp thoại "Bạn đang có đơn chờ duyệt" → "Giữ đơn cũ" | "Huỷ đơn cũ, đặt đơn mới" (replace_pending=true)
   409 CHECKOUT_CHANGED → Alert + tổng mới tại chỗ → bấm lại
   429 / 503 / 403 ACCOUNT_NOT_VERIFIED → Alert / màn "Cần xác thực tài khoản"
   Đơn 0đ → "Hoàn tất đăng ký", không có khối phương thức → kết quả "Đã thanh toán"
/thanh-toan/da-gui/{code} → "Xem đơn của tôi" → /tai-khoan/don-hang/{code} (có "Huỷ đơn")
/tai-khoan → "Đơn hàng của tôi" → /tai-khoan/don-hang
Admin: menu "Đơn hàng (6)" → tab "Chờ duyệt" → "Xử lý" → /quan-tri/don-hang/{code}
   → "Duyệt: đã nhận tiền" | "Huỷ đơn" | (đơn đã huỷ ≤ 30 ngày) "Duyệt muộn" | (đã duyệt) "Đánh dấu hoàn tiền"
```

Route thật đề xuất (bỏ tiền tố `/v2`): `/gio-hang`, `/thanh-toan` (thay tên `/checkout` của đặc tả v1 cho đồng bộ đường dẫn tiếng Việt — **PO chọn**), `/thanh-toan/da-gui/{code}`, `/tai-khoan/don-hang`, `/tai-khoan/don-hang/{code}`, admin `/quan-tri/don-hang`, `/quan-tri/don-hang/{code}`.

## 2. Web học sinh (FW3)

### 2.1 Giỏ hàng — `/v2/gio-hang`
- **Mobile:** danh sách khóa (bìa nhỏ, tên, lớp, giá dưới tên; giá gạch khi có giảm; nút "Xoá" có chữ, vùng chạm 44px) → khối "Mã giảm giá" → khối tổng → **thanh dính đáy** (Tổng cộng + "Tiếp tục đặt mua"). Không có bottom-nav ở trang này. Footer có khoảng chừa để thanh dính không che.
- **Desktop ≥ 1024:** 2 cột, cột phải 360px dính: mã giảm giá + tóm tắt + nút.
- Mã giảm giá: ô nhập có nhãn hiện + "Áp dụng"; lỗi ngay dưới ô (bảng lỗi §4); đã áp: dòng `success-soft` "VITAMIN50 · Giảm 50.000đ khóa Hình học 9" + "Gỡ mã". Dòng khóa được áp mã có chữ "Đã áp dụng mã VITAMIN50".
- Khóa ngừng bán: badge "Ngừng bán" + "Không tính vào đơn"; không có giá. Hết khóa hợp lệ → nút khoá kèm câu "Giỏ không còn khóa nào đang bán…".
- `notices` (ITEMS_UNAVAILABLE, COUPON_REMOVED) → `Alert warning` đầu danh sách.
- **Đang có đơn chờ:** `Alert info` "Bạn đang có đơn {mã} chờ Quản trị viên duyệt" + "Xem đơn" + câu "đã chuyển khoản thì đừng đặt đơn mới".
- Câu dưới nút: "Bước sau: chọn cách thanh toán và gửi đơn. Bạn chưa phải trả tiền ở bước này."
- Biến thể: có mã, chưa có mã, mã sai, có khóa ngừng bán, mã bị tự gỡ, đang có đơn chờ, rỗng (EmptyState + "Khám phá khóa học"), đang tải (skeleton đúng khung 2 cột), lỗi tải.

### 2.2 Thanh toán — `/v2/thanh-toan`
- Liên kết "‹ Quay lại giỏ hàng", H1 "Thanh toán".
- Sheet "Khóa học trong đơn" (chỉ đọc, "Sửa giỏ hàng").
- Sheet "Phương thức thanh toán": `<fieldset>` + `<legend>`, mỗi phương thức là **radio card** (radio thật, cả thẻ bấm được, đang chọn: viền `primary` + nền `primary-soft` + chữ "✓ Đã chọn"). Hiện chỉ một thẻ "Liên hệ Quản trị viên" — vẫn render dạng danh sách để thêm MoMo không phải thiết kế lại (biến thể `?trang-thai=momo` cho thấy 2 thẻ; chọn MoMo đổi nút thành "Thanh toán qua MoMo", ẩn phần ghi chú).
- Khi chọn "Liên hệ Quản trị viên" mở thêm (progressive disclosure):
  1. "Sau khi gửi đơn" — 3 bước đánh số (bước thật sự nối tiếp): QTV liên hệ → chuyển khoản ghi mã đơn → QTV xác nhận, khóa mở.
  2. "Quản trị viên sẽ liên hệ bạn qua" email + SĐT của tài khoản, liên kết "Sai thông tin? Sửa trong Tài khoản" — để học sinh sửa trước khi gửi (đơn không sửa được sau).
  3. Ô "Ghi chú cho Quản trị viên (không bắt buộc)", đếm /500, gợi ý "Không ghi mật khẩu hay mã OTP"; dạng thẻ HTML → lỗi ngay dưới ô (giống quy tắc `PlainText` server).
- Cột tổng (desktop dính) / thanh dính đáy (mobile): tổng + nút chính **"Gửi đơn"** (không phải "Thanh toán") + câu "Bạn chưa phải trả tiền trên website. Đơn được giữ 72 giờ…".
- Đang gửi: nút "Đang gửi đơn…", khoá (chống bấm 2 lần; server dùng lại đơn — AC4).
- **Hộp thoại thay đơn (AC6):** tiêu đề "Bạn đang có đơn chờ duyệt"; mô tả mã đơn cũ, số khóa, số tiền, lúc đặt, "Đặt đơn mới sẽ huỷ đơn cũ."; khối cảnh báo "Đã chuyển khoản cho đơn cũ? Hãy chọn Giữ đơn cũ và liên hệ Quản trị viên"; liên kết "Xem đơn cũ". Nút: "Giữ đơn cũ" (phụ, nhận focus đầu) và "Huỷ đơn cũ, đặt đơn mới" (chính). Mobile: nút chính ở trên.
- 409 CHECKOUT_CHANGED: `Alert warning` nhận focus, nêu lý do + tổng mới; tổng ở cột phải đổi theo `errors.preview`.
- 429: `Alert danger` "Bạn đã đặt quá nhiều đơn hôm nay" + email hỗ trợ. 503: `Alert info` "Đặt mua đang tạm đóng", nút khoá, không tự thử lại.
- Đơn 0đ: ẩn khối phương thức, `Alert info` "Đơn này được miễn phí nhờ mã giảm giá", nút "Hoàn tất đăng ký".

### 2.3 Đơn đã gửi — `/v2/thanh-toan/da-gui/{code}`
Thứ tự trên 375px (ưu tiên những gì học sinh cần chụp/ghi lại):
1. Icon tick `success`, H1 "Đã gửi đơn", câu "Quản trị viên sẽ liên hệ bạn… Bạn **chưa phải trả tiền** trên website", badge "Chờ Quản trị viên duyệt".
2. **Phiếu mã đơn** (điểm nhấn): "Mã đơn của bạn", mã 26/32px đậm `tabular-nums` (`break-all`, không chèn dấu cách để chép đúng), nút "Sao chép mã đơn" rộng hết trên mobile → "Đã sao chép" 2,5 giây + thông báo `role=status`; clipboard bị chặn → "Hãy chọn và sao chép mã". Câu "Khi chuyển khoản, vui lòng ghi mã đơn {mã} trong nội dung chuyển khoản."
3. "Liên hệ Quản trị viên": nút lớn 52px, 1 cột mobile / 2 cột desktop: **Gọi điện** (`tel:`), **Nhắn Zalo** (tab mới, có icon + chữ ẩn "mở trong tab mới"), **Gửi email** (`mailto:` có sẵn tiêu đề "Đơn {mã}"); "Giờ hỗ trợ: …". **Kênh trống (null) thì ẩn** — biến thể "Chỉ có email hỗ trợ". Khối thông tin: "VitaminVui không đăng số tài khoản trên website. Chỉ chuyển khoản theo hướng dẫn nhận được từ các kênh liên hệ ở trên." (chống lừa đảo, vì không hiện STK — Q2).
4. "Thông tin đơn": Hạn chờ duyệt "19:42, 10/10/2026 (còn 48 giờ)", tổng (đã giảm), phương thức; câu "Quá hạn… đơn tự huỷ. Các khóa vẫn nằm trong giỏ…"; danh sách khóa rút gọn.
5. Nút "Xem đơn của tôi" (chính) + "Huỷ đơn" (phụ, có xác nhận).
- Gửi lại cùng giỏ (200 `reused`): `Alert info` "Đơn này bạn đã gửi trước đó".
- Mở lại khi đơn đã duyệt/huỷ: chỉ hiện trạng thái hiện tại + "Vào học"/"Xem chi tiết đơn", **không** hiện hướng dẫn liên hệ.
- **Không có** STK, QR, đồng hồ đếm ngược chạy từng giây (hạn 72 giờ không cần áp lực thời gian; chữ "còn N giờ" đủ).

### 2.4 Đơn hàng của tôi — `/v2/tai-khoan/don-hang`
- Breadcrumb Tài khoản › Đơn hàng của tôi. Mỗi đơn là một thẻ-liên kết (cả thẻ bấm được): mã · badge trạng thái; "Tên khóa đầu và N khóa khác"; "Đặt ngày … · Liên hệ Quản trị viên"; đơn chờ thêm dòng cam "Hạn chờ duyệt: … (còn N giờ)"; cột phải tổng tiền + "đã giảm …". Mới nhất trước; `Pagination` khi > 1 trang.
- Trạng thái: đang tải (skeleton + `loading.tsx`), rỗng, lỗi tải. Trang Tài khoản có mục "Đơn hàng → Đơn hàng của tôi" kèm badge "1 đơn chờ duyệt".

### 2.5 Chi tiết đơn — `/v2/tai-khoan/don-hang/{code}`
- H1 "Đơn {mã}" + "Sao chép mã" + badge. Khối trạng thái theo bảng §3: chờ duyệt (viền cam, hạn chờ, nhắc ghi mã, kênh liên hệ); đã thanh toán (Alert success + "Vào học"); QTV huỷ (Alert danger + **"Lý do: …"** đúng chữ QTV nhập + "Về giỏ hàng"); quá hạn (Alert warning + "đã chuyển khoản thì liên hệ QTV kèm mã đơn"); thay bằng đơn mới (liên kết đơn mới); tự huỷ; hoàn tiền.
- Sheet khóa + bảng tiền + phương thức + ngày đặt; "Ghi chú bạn đã gửi"; "Lịch sử đơn" (không có ghi chú nội bộ — AC14).
- Đơn chờ: khối viền đỏ nhạt "Không muốn mua nữa?" + "Huỷ đơn". Hộp xác nhận (`ConfirmDialog` danger, focus đầu ở "Không huỷ"): "Huỷ đơn {mã}?", "…không thể khôi phục. Các khóa vẫn còn trong giỏ…", khối cảnh báo "Bạn đã chuyển khoản cho đơn này? Đừng huỷ…". Xong → Alert success "Đã huỷ đơn". 409 (QTV vừa duyệt) → Alert info "Không huỷ được: đơn vừa được duyệt" + trạng thái mới.
- Không tìm thấy / đơn của người khác: trang 404 "Không tìm thấy đơn hàng" (không nói là đơn người khác — AC11).

## 3. Nhãn trạng thái

| status / reason | Học sinh (badge) | Quản trị (badge) |
|---|---|---|
| pending | Chờ Quản trị viên duyệt (warning) | Chờ duyệt (warning) + "Còn N giờ"; < 12 giờ thêm "Sắp hết hạn" |
| paid / manual_confirmed | Đã thanh toán (success) | Đã duyệt (success); `needs_review` thêm "Cần xem lại" (danger) |
| cancelled / admin_cancelled | Đã huỷ bởi Quản trị viên (danger) + lý do | Huỷ bởi QTV |
| cancelled / expired | Đã huỷ do quá hạn chờ (neutral) | Tự huỷ (hết hạn) |
| cancelled / user_cancelled | Bạn đã huỷ đơn (neutral) | HS tự huỷ |
| cancelled / superseded | Đã thay bằng đơn mới (neutral) | Thay bằng đơn mới |
| cancelled / account_deleted | — | Tài khoản đã xoá |
| refunded | Đã hoàn tiền (info) | Đã hoàn tiền (info) |

Badge luôn có chấm + chữ (không dựa vào màu).

## 4. Thông điệp lỗi

| Mã | Ở đâu | Câu chữ |
|---|---|---|
| COUPON_INVALID / COUPON_EXPIRED / COUPON_ALREADY_USED / COUPON_NOT_APPLICABLE / 429 | Dưới ô mã | "Mã giảm giá không tồn tại hoặc chưa được kích hoạt." / "Mã giảm giá đã hết hạn." / "Bạn đã dùng mã này cho một đơn trước đó." / "Mã không áp dụng cho khóa nào trong giỏ của bạn." / "Bạn đã nhập sai quá nhiều lần…" (ưu tiên `message` server) |
| PENDING_ORDER_EXISTS (409) | Hộp thoại §2.2 | |
| CHECKOUT_CHANGED (409) | Alert đầu form | "Giỏ hàng vừa thay đổi" + lý do + tổng mới |
| 429 (5 đơn/ngày) | Alert danger | "Bạn đã đặt quá nhiều đơn hôm nay, vui lòng liên hệ Quản trị viên." |
| PAYMENT_DISABLED (503) | Alert info | `message` server, không tự thử lại |
| ACCOUNT_NOT_VERIFIED (403) | Chuyển màn "Cần xác thực tài khoản" (§12.8) | |
| CART_EMPTY (422) | Về `/gio-hang` + toast "Giỏ hàng của bạn đang trống" | |
| ACCOUNT_HAS_PENDING_PAYMENT (409, xoá tài khoản — AC28) | Trang Quyền dữ liệu (FW7) | "Bạn đang có đơn {mã} chờ duyệt tới {retry_after_at}. Huỷ đơn trước nếu không còn muốn mua." + liên kết đơn |

## 5. Quản trị (FA8)

### 5.1 Menu
- "Đơn hàng" (nhóm Bán hàng) mở cho Admin/QLT, badge cam số đơn chờ; trình đọc màn hình đọc "6 đơn chờ duyệt" (thêm `countLabel` cho `AdminFrame`, số trần bị `aria-hidden`). Giáo viên không thấy mục; vào URL → 403.

### 5.2 Danh sách — `/v2/quan-tri/don-hang`
- `LinkTabs` theo URL: **Chờ duyệt (6)** (mặc định) · Đã thanh toán · Đã huỷ · Đã hoàn tiền · Tất cả.
- Tab Chờ duyệt: chỉ ô tìm "Mã đơn hoặc tên học sinh"; ghi chú "Cũ nhất trước. Tab này không cần chọn khoảng ngày". Cột: Mã đơn (liên kết) | Học sinh (tên + email/SĐT đã che) | Khóa học (≥ xl) | Tổng tiền | Đặt lúc (≥ md) | Hạn chờ ("Còn N giờ", < 12 giờ chữ cam đậm + badge "Sắp hết hạn") | "Xử lý". Mobile: hạn chờ hiện ngay dưới mã đơn (cột phụ bị ẩn).
- Tab khác: form GET Từ ngày* / Đến ngày* (≤ 366, mặc định 30 ngày), Mã/tên/email/SĐT, Phương thức (Liên hệ QTV / MoMo / Miễn phí), "Chỉ đơn Cần xem lại"; cột Trạng thái thay cột Hạn chờ; "Khoảng N đơn khớp bộ lọc" + "Trang trước/Trang sau" (cursor).
- Trạng thái: đang tải (dòng skeleton), không có đơn chờ ("Không có đơn nào đang chờ duyệt"), lọc rỗng ("Xoá bộ lọc"), lỗi tải ("Tải lại").

### 5.3 Chi tiết — `/v2/quan-tri/don-hang/{code}`
- Đầu trang: "‹ Đơn hàng", H1 "Đơn {mã}" + "Sao chép mã", badge, "698.000đ · 2 khóa"; nút hành động bên phải (desktop) / dưới tiêu đề (mobile).
- Alert theo tình huống: kết quả thao tác, "Cần xem lại" (liệt kê lý do), "Tài khoản học sinh đang bị khoá" (vẫn duyệt được), "Tài khoản đã xoá", "Có khóa đã ngừng bán" (AC23).
- Cột trái: **Học sinh** (tên · lớp, email + SĐT **đầy đủ**, SĐT chưa xác thực có badge "Chưa xác thực", nút "Gọi" `tel:` và "Gửi email" `mailto:` có tiêu đề mã đơn, dòng "Lần xem thông tin liên hệ này đã được ghi vào nhật ký thao tác", **Ghi chú của học sinh**); **Khóa học trong đơn** (bảng giá chốt / giảm / thành tiền, badge "Ngừng bán"/"Đã xoá", mã giảm giá, "Tổng cần thu"); **Lịch sử trạng thái** (dòng thời gian: việc, ai — "Hệ thống"/"Học sinh …"/tên staff — lúc nào, lý do/mã giao dịch).
- Cột phải (dính): **Thông tin đơn** (phương thức, đặt lúc, hạn chờ + còn N giờ / huỷ lúc + lý do gửi HS, duyệt bởi + lúc, mã giao dịch); **Ghi chú nội bộ** (badge "Học sinh không thấy", ô thêm /1000 + "Lưu ghi chú", danh sách mới nhất trên: avatar chữ cái, tên, giờ; không sửa/xoá).
- Không bao giờ hiện thông tin phụ huynh.

### 5.4 Hộp thoại
- **Duyệt: đã nhận tiền** — mô tả hệ quả (mở N khóa, email xác nhận, ghi lượt mã); cảnh báo khóa ngừng bán nếu có; ô "Số tiền cần nhận 698.000đ" to; checkbox bắt buộc **"Đã nhận đủ 698.000đ"** + mô tả "Đã đối chiếu sao kê…"; khi chưa tick: nút "Duyệt đơn" khoá + câu "Tick ô này để bật nút duyệt." (không tooltip); "Mã giao dịch / nội dung chuyển khoản (không bắt buộc)" /100; "Ghi chú nội bộ (không bắt buộc) — Học sinh không thấy" /1000. Đang gửi: "Đang duyệt…", không đóng được.
- **Duyệt muộn** (đơn đã huỷ, còn hạn 30 ngày; dưới nút có câu "có thể duyệt muộn tới {ngày}" hoặc "Đã quá 30 ngày… hoàn tiền ngoài hệ thống"): bước 1 có khối **nền cảnh báo** "Đơn đã huỷ lúc {giờ} — {lý do}. Duyệt muộn sẽ mở khóa và gắn cờ Cần xem lại" + danh sách cảnh báo (đã sở hữu khóa, mã vượt lượt) + cùng form như Duyệt, nút "Tiếp tục"; bước 2 "Xác nhận lần 2: duyệt đơn đã huỷ?" nêu mã, tiền, hệ quả, "Không hoàn tác được", nút "Quay lại" / "Xác nhận duyệt muộn".
- **Huỷ đơn** — "Lý do gửi học sinh" **bắt buộc** (5–500, gợi ý "Học sinh đọc được lý do này trong email và trang đơn hàng"), 2 câu mẫu bấm để điền; đường kẻ tách rồi mới đến "Ghi chú nội bộ (không bắt buộc) — Chỉ Quản trị viên thấy" (icon ổ khoá SVG). Thiếu lý do → lỗi dưới ô + focus vào ô. Focus đầu ở "Không huỷ"; nút "Huỷ đơn" màu danger.
- **Đánh dấu hoàn tiền** (US-010): Alert danger "Hệ thống không tự chuyển tiền…", checkbox "Tôi đã hoàn {tiền} cho học sinh", ghi chú.
- **409 khi người khác đã xử lý** (AC19/AC21): đóng hộp, tải lại đơn, Alert ở đầu trang (`role=alert`): "Chưa duyệt: đơn vừa đổi trạng thái — Học sinh đã tự huỷ đơn lúc 19:59… dùng Duyệt muộn" (nút "Duyệt muộn" hiện ngay) hoặc "Đơn đã được người khác xử lý — Đỗ Thị Mai đã duyệt lúc 19:59". 409 COURSE_UNAVAILABLE: Alert danger "Không duyệt được: có khóa đã bị xoá… hoàn tiền ngoài hệ thống". Không tự thử lại.

## 6. Component

| Component | Nơi | Ghi chú |
|---|---|---|
| `CopyButton` (mới) | `packages/ui/src/v2` | Client; nút + `role=status`; dùng cả web và admin |
| `IconPhone`, `IconMessageCircle`, `IconBanknote` (mới) | `packages/ui/src/v2/icons.tsx` | Nét Lucide |
| `AdminNavItem.countLabel` (mới) | `AdminFrame` | Câu đọc cho số đếm; mặc định "{n} việc chờ" như cũ |
| `CartView` (client), `CheckoutForm` (client, gồm radio card + hộp thoại thay đơn), `CancelOrderButton` (client), `OrderParts` (server: `OrderStatusBadge`, `OrderItemRows`, `PriceCell`, `PricingSummary`, `ContactChannels`, `deadlineText`), `OrdersSkeleton` | `apps/web/components/v2/orders/` | |
| `OrderActions` (client: 5 hộp thoại), `InternalNotes` (client), `OrderBadges` (server: `AdminStatusBadge`, `DeadlineCell`) | `apps/admin/components/v2/orders/` | |
| `StudentShell`: `cartCount`, `reserveBottomBar`, section `cart` | web | |

Dữ liệu mẫu: `apps/web/lib/mock/v2/orders.ts`, `apps/admin/lib/mock/v2/orders.ts` (tên trường theo api-contract §2.3/§2.5 + phần ĐỀ XUẤT của story; giờ "bây giờ" cố định `2026-10-08T20:00+07:00`).

## 7. Dữ liệu UI cần từ API (đề xuất cho Architect, chưa có trong api-contract)

- `GET /config/public`: `payment_methods[]`, `manual_payment.contact {phone, zalo_url, email, hours}`, `pending_ttl_hours` (đúng như story).
- `GET /orders/{code}`: `customer_note`, `cancel_reason_public`, `expires_at`, `replaced_by_code` (đơn `superseded` liên kết sang đơn mới), `coupon {code, discount_amount}`, `items[]` có `slug`, `grade_level`.
- `POST /checkout` 409 `PENDING_ORDER_EXISTS`: ngoài `errors.order_code` cần **số khóa, tổng tiền, created_at** của đơn cũ để hộp thoại nói rõ đơn nào sẽ bị huỷ.
- `GET /admin/orders` item: `items_count`, `first_item_title`, `expires_at`, `needs_review`, `payment_method`.
- `GET /admin/orders/{code}`: `student.phone_verified`, `student.account_status`, `items[].course_status`, `notes[]`, `confirmed_by`, `approval_window_until`, `needs_review_reasons[]`, và **`late_approval_warnings[]`** (đã sở hữu khóa / mã vượt lượt) để báo **trước** khi duyệt muộn — story chỉ nói server tự gắn cờ sau khi duyệt.
- Số đơn chờ cho menu: `GET /admin/orders/pending-count` hoặc trong `/admin/auth/me`.

## 8. Checklist chất lượng (đã tự kiểm)

| Mục | Kết quả |
|---|---|
| Tương phản ≥ 4,5:1 | Đạt — chỉ dùng cặp token đã đo ở §4 design system; chữ cam dùng `warning` (5,3:1) trên `surface`, không dùng `accent` cho chữ |
| Focus bàn phím, `aria-label` nút chỉ có icon | Đạt — mọi nút/liên kết có `focus-ring`; nút "Xoá" có tên đầy đủ "Xoá khóa … khỏi giỏ" |
| HTML ngữ nghĩa, 1 `h1` | Đạt — đã kiểm `h1` mỗi trang bằng Playwright; phương thức là `fieldset/legend` + radio thật |
| Vùng chạm ≥ 44px mobile | Đạt — nút liên hệ 52px, nút chính 52px, "Xoá"/"Gỡ mã" 44px trên mobile; nút 36px chỉ ở admin desktop (theo §14) |
| 375–1440px không cuộn ngang | Đạt — `scrollWidth − innerWidth = 0` trên 19 ảnh; bảng admin cuộn trong khung |
| Chữ thân ≥ 16px, dòng ≤ 75 ký tự | Đạt ở web (14px chỉ cho meta/gợi ý); admin 14px theo §14 |
| Chuyển động / reduced-motion | Đạt — chỉ hộp thoại `motion-safe`, không đếm ngược chạy giây |
| Form: nhãn luôn hiện, lỗi dưới ô | Đạt — mã giảm giá, ghi chú, lý do huỷ, ghi chú nội bộ; lỗi nhận focus |
| Không dựa vào màu | Đạt — badge có chữ, "Sắp hết hạn" có chữ, disabled có câu giải thích |
| Đủ trạng thái tải/rỗng/lỗi/không tìm thấy | Đạt — biến thể trên dải xem trước, `loading.tsx` cho đơn của tôi, `not-found.tsx` cho đơn |
| `tsc --noEmit` (web, admin, ui), ESLint các thư mục xem trước, test `packages/ui` | Sạch / 27/27 |
| Liên kết `/v2` không 404 | Đạt — dò từ các màn mới: web 38 URL, admin 160 URL |

## 9. Điểm cần PO chọn

1. **Đường dẫn** `/thanh-toan` thay `/checkout` (v1)? Và `/thanh-toan/da-gui/{code}` cho màn Đơn đã gửi.
2. **Khối "Quản trị viên sẽ liên hệ bạn qua"** ở trang thanh toán hiện email + SĐT của tài khoản để học sinh sửa trước khi gửi — đồng ý hiện (dữ liệu của chính học sinh)?
3. **Câu chống lừa đảo** "VitaminVui không đăng số tài khoản trên website…" — giữ? Nếu sau này PO chọn hiện STK/QR (Q2) thì câu này phải đổi.
4. **Giỏ hàng trên header**: icon giỏ chỉ hiện khi có phương thức thanh toán (BR3). Bottom-nav mobile giữ 4 mục (không thêm Giỏ) — đồng ý?
5. **Câu mẫu lý do huỷ** trong hộp Huỷ (2 câu) — PO duyệt câu chữ hoặc gửi câu khác.
6. **Duyệt muộn**: có muốn API trả `late_approval_warnings[]` để báo trước (mục 7), hay chấp nhận chỉ biết sau khi duyệt (cờ "Cần xem lại")?
7. **Học sinh tự huỷ**: khi đơn đã có ghi chú "đã chuyển khoản" của QTV, có nên chặn nút "Huỷ đơn" không (hiện không chặn, chỉ nhắc)?
8. Kênh liên hệ thật (SĐT, link Zalo, giờ hỗ trợ — Q1 của story): bản xem trước dùng số mẫu `0909 123 456`.

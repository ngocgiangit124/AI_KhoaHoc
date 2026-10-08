# Đặc tả UX — US-017: Đồng ý xử lý dữ liệu cá nhân & xác nhận của phụ huynh

> **Hết hiệu lực từ 2026-10-08 (PO, ADR-006):** không còn trang phụ huynh xác nhận/từ chối/rút lại và không còn màn chặn "chờ phụ huynh". Phạm vi FE mới ở tasks.md FW1 và FW7: khối phụ huynh tuỳ chọn, trang `/phu-huynh/huy-nhan-thong-bao`. `nextjs-designer` cần cập nhật tài liệu này.

Xem thêm quy ước chung tại `docs/design/design-system.md`. Phần "2 checkbox đồng ý ở đăng ký" và "màn chặn checkout khi đang chờ phụ huynh" đã đặc tả trong `docs/design/US-001-dang-ky-dang-nhap-hoc-sinh.md` mục 2.1, 2.5 — tài liệu này tập trung vào **trang công khai phụ huynh xác nhận** (không cần tài khoản) và các trạng thái liên quan.

⚠️ Nội dung pháp lý cụ thể (ngưỡng tuổi, căn cứ pháp luật, hình thức đồng ý hợp lệ) **chưa được pháp chế xác nhận** — thiết kế này chỉ mô tả cơ chế kỹ thuật theo mặc định an toàn (README §8 mục 15, US-017 mục "Câu hỏi mở").

## 1. Luồng người dùng

```
Học sinh <18 tuổi đăng ký xong (US-001) → hệ thống gửi email chứa liên kết tới địa chỉ email phụ huynh đã khai
Phụ huynh mở email → bấm liên kết → /xac-nhan-phu-huynh/{token} (trang công khai, KHÔNG cần đăng nhập/tài khoản)
  → Liên kết còn hiệu lực (< 72h, chưa dùng) → hiện thông tin tối thiểu + 2 nút "Đồng ý" / "Từ chối"
    → Bấm "Đồng ý" → parent_consent_status = granted → trang xác nhận thành công, có link phụ "Rút lại đồng ý"
    → Bấm "Từ chối" → giữ trạng thái chờ (xem mục 2.1 biến thể "đã từ chối" — câu hỏi mở, xem mục 5)
  → Liên kết hết hạn (> 72h) hoặc đã dùng → trang báo lỗi, không cho xác nhận lại bằng liên kết cũ
Học sinh (trong lúc pending) → vào /checkout hoặc bấm "Đăng ký học miễn phí" → bị chặn, màn "Đang chờ phụ huynh xác nhận" (US-001 mục 2.5) → bấm "Gửi lại email cho phụ huynh" (tối đa 3 lần/ngày) → link cũ bị vô hiệu hoá, link mới được gửi
Phụ huynh đã từng "Đồng ý" muốn rút lại → mở lại trang xác nhận thành công (nếu còn lưu email) hoặc dùng link "Rút lại đồng ý" gửi kèm email xác nhận → bấm "Rút lại đồng ý" → parent_consent_status = revoked → học sinh bị chặn checkout/đăng ký miễn phí trở lại
```

## 2. Màn hình

### 2.1 `/xac-nhan-phu-huynh/{token}` — Trang công khai phụ huynh xác nhận (không cần tài khoản)
**Bố cục:** card căn giữa max-width 480px, branding VitaminVui nhưng **không có** menu/đăng nhập (trang độc lập, không phải 1 phần của app học sinh đã đăng nhập).

**Biến thể "Liên kết còn hiệu lực — chờ xác nhận" (mặc định):**
- Tiêu đề: "Xác nhận đồng ý cho con em sử dụng VitaminVui"
- Nội dung tối thiểu theo BR10 (**không hiển thị PII khác ngoài tên đã che**):
  - "Học sinh: Nguyễn Minh A** (lớp 9)"
  - Đoạn mô tả ngắn mục đích: "VitaminVui cần sự đồng ý của phụ huynh/người giám hộ trước khi học sinh dưới 18 tuổi có thể mua khóa học trên hệ thống. Việc đồng ý bao gồm việc xử lý dữ liệu cá nhân của học sinh theo Chính sách xử lý dữ liệu cá nhân bên dưới."
  - Link "Xem Chính sách xử lý dữ liệu cá nhân (phiên bản {policy_version})" mở tab mới
- 2 nút cạnh nhau: "Từ chối" (`variant="outline"`) và "Đồng ý" (`variant="primary"`)
- Chân trang nhỏ: "Liên kết này chỉ dành riêng cho phụ huynh/người giám hộ của học sinh nêu trên và có hiệu lực trong 72 giờ kể từ khi gửi."

**Biến thể "Đã xác nhận thành công":**
- Icon ✔ emerald, tiêu đề "Cảm ơn bạn đã xác nhận"
- Mô tả: "Bạn đã đồng ý cho Nguyễn Minh A** sử dụng VitaminVui. Con của bạn có thể mua khóa học ngay bây giờ."
- Link phụ nhỏ, ít nổi bật: "Đổi ý? Rút lại đồng ý" (mở modal xác nhận rút lại — xem 2.2)

**Biến thể "Liên kết đã hết hạn hoặc đã được sử dụng" (AC5):**
- Icon ⏱ amber, tiêu đề "Liên kết không còn hiệu lực"
- Mô tả: "Liên kết xác nhận này đã hết hạn hoặc đã được sử dụng trước đó. Vui lòng nhờ con em bạn gửi lại email xác nhận mới từ tài khoản VitaminVui của mình."
- Không có nút hành động nào khác (không cho xác nhận lại bằng token cũ)

**Biến thể "Đã từ chối" (câu hỏi mở — xem mục 5):**
- Icon thông tin, tiêu đề "Đã ghi nhận phản hồi của bạn"
- Mô tả: "Chúng tôi đã ghi nhận bạn chưa đồng ý. Con em bạn sẽ không thể mua khóa học cho tới khi có xác nhận đồng ý."
- Link phụ "Đổi ý, xác nhận đồng ý" (dùng lại chính token này nếu còn hạn — thiết kế giả định, cần PO/Dev xác nhận hành vi chính xác)

### 2.2 Modal "Rút lại đồng ý" (`<ConfirmModal>`, mở từ biến thể "Đã xác nhận thành công")
- Tiêu đề: "Rút lại đồng ý?"
- Mô tả hậu quả: "Sau khi rút lại, con em bạn sẽ KHÔNG thể mua khóa học mới hoặc đăng ký khóa học miễn phí cho tới khi có xác nhận đồng ý lại. Các khóa học đã mua trước đó không bị ảnh hưởng." *(câu cuối theo giả định "giữ nguyên enrollment cũ" — chờ PO xác nhận, xem câu hỏi mở của US-017)*
- Nút "Huỷ" / "Rút lại đồng ý" (danger)
- Sau khi xác nhận → chuyển sang thông báo "Đã rút lại đồng ý" (icon amber, không còn hành động nào khác trên trang)

## 3. Bảng trạng thái UI & thông điệp

| Màn hình | Trạng thái | Thông điệp |
|---|---|---|
| Trang xác nhận phụ huynh | Đang tải token | `<Skeleton variant="card">` |
| Trang xác nhận phụ huynh | Token không tồn tại/sai định dạng | Cùng biến thể "Liên kết không còn hiệu lực" (không phân biệt lý do cụ thể — tránh dò token) |
| Trang xác nhận phụ huynh | Đồng ý thành công (AC4) | Xem biến thể "Đã xác nhận thành công" |
| Trang xác nhận phụ huynh | Hết hạn/đã dùng (AC5) | Xem biến thể "Liên kết không còn hiệu lực" |
| Trang xác nhận phụ huynh | Rút lại đồng ý thành công (AC9) | `<Toast variant="success">` "Đã ghi nhận việc rút lại đồng ý" + chuyển trạng thái trang |
| Màn chặn checkout (US-001 mục 2.5) | `pending` (AC6) | Xem US-001 |
| Màn chặn checkout (US-001 mục 2.5) | `revoked` (AC9) | Xem US-001 |
| Nút "Gửi lại email cho phụ huynh" | Thành công (AC7) | `<Toast variant="success">` "Đã gửi lại email xác nhận cho phụ huynh" |
| Nút "Gửi lại email cho phụ huynh" | Đạt giới hạn 3 lần/ngày (AC8) | Nút disabled + "Đã đạt giới hạn gửi lại hôm nay, vui lòng thử lại vào ngày mai" |

## 4. Component React dùng/tạo mới
- `<Alert>`, `<Toast>`, `<Button>`, `<ConfirmModal>`, `<Skeleton>` (dùng lại — `packages/ui`)
- `<ParentConsentPendingBanner>` (dùng lại từ US-001 — `apps/web`)
- Trang `/xac-nhan-phu-huynh/[token]` (mới — `apps/web`, **route công khai, không bọc trong layout đã đăng nhập**, không gọi `authFetch`) chứa các biến thể ở mục 2.1–2.2; đặt tên component nội bộ tuỳ ý (vd `ParentConsentPage`), không cần liệt kê vào `packages/ui` vì gắn chặt với 1 route duy nhất.

## 5. Điểm cần PO duyệt
- Ngưỡng tuổi 18 và toàn bộ nội dung pháp lý — **cần pháp chế xác nhận** trước go-live (không phải việc của Designer).
- Có giữ nút "Từ chối" trên trang xác nhận không, hay chỉ có "Đồng ý" (câu hỏi mở của US-017) — mockup hiện có cả 2 để PO/pháp chế lựa chọn.
- Rút lại đồng ý có ảnh hưởng tới enrollment đã mua trước đó không (thu hồi như hoàn tiền, hay giữ nguyên) — câu hỏi mở của US-017; mô tả trong modal 2.2 đang giả định "giữ nguyên", cần xác nhận trước khi Dev code.
- Cơ chế cho phụ huynh rút lại đồng ý sau khi đã đóng tab xác nhận (không còn giữ email) — mockup giả định vẫn dùng lại chính token cũ trong 72h hoặc học sinh phải yêu cầu gửi lại; cần Dev/Architect xác nhận hành vi chính xác (token dùng 1 lần theo BR7 có mâu thuẫn với việc "mở lại trang để rút lại" — cần làm rõ có tách 2 loại token hay không).

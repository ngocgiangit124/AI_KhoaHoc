# Đặc tả UX — US-014: Giới hạn đăng nhập một thiết bị/một phiên

## 1. Luồng người dùng
```
Học sinh đăng nhập thiết bị A (đang xem video/làm bài...)
Học sinh đăng nhập cùng tài khoản trên thiết bị B → thiết bị B vào bình thường, phiên A bị vô hiệu hoá ở server
Thiết bị A thao tác tiếp theo (chuyển trang/gọi API) → bị chặn → hiện màn/thông báo đăng xuất cưỡng bức → yêu cầu đăng nhập lại
Học sinh đăng nhập lại ở thiết bị A → vào lại bình thường, tiến độ học không mất
```
Chỉ áp dụng vai trò Học Sinh; Giáo viên/Quản lý trang/Admin không bị ảnh hưởng (không cần thiết kế thông báo này ở khu vực quản trị).

## 2. Màn hình / thành phần

### 2.1 Thông báo đăng xuất cưỡng bức (toàn trang, không thể đóng khi đang trong luồng học)
Khi thiết bị gọi API và nhận `401` kèm 1 trong 4 mã lỗi (`code` — api-contract §1.7, **không dùng trường `reason` như bản thiết kế trước**), `<ForcedLogoutOverlay code="...">` hiển thị đúng 1 trong 4 nội dung dưới đây, chặn toàn bộ tương tác phía sau (không cho tắt bằng nút X hay bấm ra ngoài — vì phiên đã thực sự vô hiệu, không thể "bỏ qua"):

| `code` | Khi nào | Icon/màu | Tiêu đề | Mô tả | Nút |
|---|---|---|---|---|---|
| `SESSION_REPLACED` | Tài khoản đăng nhập ở **thiết bị khác** (ADR-003: `X-Device-Id` không khớp `current_device_id`) | ⚠ `warning` (amber) | "Tài khoản của bạn vừa đăng nhập trên một thiết bị khác" | "Vì lý do bảo mật, mỗi tài khoản học sinh chỉ được đăng nhập trên 1 thiết bị tại một thời điểm. Nếu không phải bạn, hãy đổi mật khẩu ngay sau khi đăng nhập lại." | "Đăng nhập lại" → `/dang-nhap` |
| `SESSION_EXPIRED` | Đăng nhập 2 lần trên **cùng thiết bị** (`X-Device-Id` khớp `current_device_id`) — ví dụ bấm "Đăng nhập" ở tab cũ sau khi đã đăng nhập ở tab mới | ℹ `info` (sky) — **không dùng amber/rose để tránh gây hiểu nhầm là bị chiếm tài khoản** | "Phiên đăng nhập đã hết hạn" | "Phiên làm việc trước đó trên thiết bị này đã kết thúc. Vui lòng đăng nhập lại để tiếp tục." | "Đăng nhập lại" → `/dang-nhap` |
| `SESSION_REVOKED` | Vừa đổi/đặt lại mật khẩu (US-015) | ℹ `info` (sky) | "Mật khẩu đã được thay đổi" | "Mật khẩu của tài khoản này vừa được thay đổi (do bạn hoặc yêu cầu đặt lại mật khẩu). Vui lòng đăng nhập lại bằng mật khẩu mới." | "Đăng nhập lại" → `/dang-nhap` |
| `UNAUTHENTICATED` | Chưa đăng nhập / không có tombstone tương ứng (trường hợp chung) | ⚠ `warning` (amber) | "Bạn cần đăng nhập để tiếp tục" | "Phiên làm việc của bạn không còn hợp lệ. Vui lòng đăng nhập lại." | "Đăng nhập lại" → `/dang-nhap` |

Không có nút "Huỷ"/"Đóng" ở cả 4 biến thể — chặn tuyệt đối vì phiên đã mất hiệu lực thật sự. Điểm quan trọng nhất cần Dev lưu ý: **`SESSION_EXPIRED` và `SESSION_REVOKED` không được dùng chung màu/giọng văn "cảnh báo bị người khác chiếm tài khoản"** của `SESSION_REPLACED` — tránh gây hoang mang không cần thiết khi bản chất chỉ là phiên cũ/đăng nhập lại bình thường.

### 2.2 Điểm chèn màn hình này trong các trang khác
- Trang học video (US-006): overlay phủ lên player đang phát, video tạm dừng ngay
- Trang làm quiz (US-007): overlay phủ lên form đang làm — vì câu trả lời đã autosave liên tục (US-007 BR8) nên không mất dữ liệu, chỉ cần đăng nhập lại là làm tiếp được (nếu quiz chưa hết giờ)
- Bất kỳ trang nào khác (giỏ hàng, checkout...): overlay tương tự, dữ liệu giỏ hàng không mất vì lưu ở CSDL (US-004 BR5)

## 3. Bảng trạng thái UI & thông điệp

| Tình huống | `code` | Thông điệp |
|---|---|---|
| Đăng nhập thiết bị khác, phát hiện qua gọi API tiếp theo | `SESSION_REPLACED` | Xem mục 2.1 |
| Đăng nhập 2 lần cùng thiết bị (tab cũ gọi API sau khi tab mới đã đăng nhập) | `SESSION_EXPIRED` | Xem mục 2.1 |
| Vừa đổi/đặt lại mật khẩu (US-015) | `SESSION_REVOKED` | Xem mục 2.1 |
| Đăng nhập lại thành công sau khi bị đăng xuất | — | Toast bình thường của luồng đăng nhập (US-001): tên hiển thị ở header |
| Học sinh chủ động đăng xuất rồi đăng nhập lại cùng thiết bị (AC3) | — | Không có thông báo đặc biệt nào, luồng đăng nhập bình thường |
| Mất mạng tạm thời, không phải đăng nhập nơi khác (AC5) | — (lỗi mạng, không có response) | Không hiện overlay này; dùng xử lý lỗi mạng thông thường (banner "Mất kết nối, đang thử lại..."); `packages/api-client` phải phân biệt rõ "lỗi mạng" (không coi là mất phiên) với 401 có `code` (coi là mất phiên) |

## 4. Component React dùng/tạo mới
- `<ForcedLogoutOverlay code={...}>` (`packages/ui`, mới) — modal toàn màn hình không thể đóng, chèn 1 lần ở layout gốc `apps/web`, nhận `code` từ sự kiện `forced-logout`/`login-required` do `packages/api-client` phát ra khi request trả 401 kèm 1 trong 4 `code` ở mục 2.1 (dùng đúng trường `code` theo api-contract §1.7 — **không dùng `reason` như bản thiết kế trước**).

## 5. Điểm cần PO duyệt
- Có cần gửi thêm email/SMS cảnh báo bảo mật khi phiên bị vô hiệu hoá (`SESSION_REPLACED`) không (câu hỏi mở của US-014 — README §8 mục 18 đang mặc định KHÔNG gửi, chỉ báo trên UI), hay chỉ thông báo trên giao diện là đủ như thiết kế hiện tại.

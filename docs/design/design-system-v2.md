# Hệ thống thiết kế v2 — VitaminVui: "Vở ô ly & mực tím"

**Trạng thái:** Bản hoàn chỉnh — chờ PO duyệt (cổng PO #2)
**Người lập:** `nextjs-designer` (skill `ui-ux-pro-max`), 2026-10-06. Thay bản đề xuất 2026-10-05.
**Cập nhật 2026-10-07:** dựng các màn còn thiếu ở §1.4 (§12.8, §14.1), đề xuất nền vở ô ly chủ đạo cho trang khách (§3.1 — **chờ PO duyệt**, đã áp vào bản xem trước).
**Cập nhật 2026-10-06 (chiều):** trang chủ theo US-019, khu vực giáo viên + hồ sơ giáo viên theo US-020 (§12.6), đủ các màn quản trị (§14.1), không còn liên kết `/v2` nào ra 404.
**Phạm vi:** toàn bộ lớp giao diện của web học sinh (`apps/web`) và trang quản trị (`apps/admin`). Khi PO duyệt, gộp vào [design-system.md](design-system.md) và bỏ các mục v1 bị thay thế (§2 màu, §3 chữ, §5.1 component dùng chung). Sitemap §7 và bảng "chờ PO" §8–9 của v1 giữ nguyên.

| Ở đâu | Gì |
|---|---|
| `frontend/packages/ui/src/v2/tokens.css` | Token Tailwind v4 (`@theme`), sáng + tối |
| `frontend/packages/ui/src/v2/` | Component lõi, import `@vitaminvui/ui/v2` |
| `frontend/apps/web/app/(v2-preview)/v2/` | Màn học sinh xem trước, dữ liệu mẫu — mục lục: `/v2/muc-luc` |
| `frontend/apps/admin/app/(v2-preview)/v2/` | Màn quản trị xem trước — `/v2/quan-tri/khoa-hoc` |
| `frontend/apps/*/lib/mock/v2/` | Dữ liệu mẫu, tên trường theo `api-contract.md` |
| [mockups/v2/preview/](mockups/v2/preview/) | Ảnh chụp 375px và 1280px (cả giao diện tối) |

---

## 1. Phân tích

### 1.1 Bản v1 (`design-system.md`, 2026-09-25)

**Tốt, giữ lại**
- Nội dung nghiệp vụ rất đầy đủ: sitemap, luồng chính, bảng 6 trạng thái, danh sách component theo app, copy chuẩn ("Lưu", "Huỷ", "Vào học"...), các điểm "chờ PO" được đánh dấu rõ.
- Đã nghĩ tới mobile-first, vùng chạm 44px, input 16px chống iOS phóng to, hộp xác nhận cho hành động nguy hiểm.

**Chưa ổn**
- Không có bản sắc: `indigo-600` + `amber-500` + font mặc định là bộ màu Tailwind có sẵn, trông như mọi trang SaaS khác, không gợi gì tới môn Toán hay học sinh Việt Nam.
- Font Inter/Geist hiện tại chỉ có subset `latin` (`apps/*/app/layout.tsx`) → chữ có dấu chồng (ặ, ổ, ữ) rơi về font hệ thống, lệch nét giữa các máy.
- Nội dung chính 14px trên mobile (`text-sm md:text-base`), chữ phụ `text-xs text-gray-500` (12px) — nhỏ cho học sinh đọc lâu trên điện thoại.
- Không có quy tắc hiển thị công thức Toán, không có chuyển động, không có dark mode, không phân biệt mật độ giữa web và quản trị.
- Mockup v1 dùng emoji làm icon (🔍 🛒 🎉 🔒 ✔).
- Một số trạng thái dựa vào tooltip trên nút bị khoá ("Đang chờ duyệt", "Không thể xoá"): không đọc được trên điện thoại và trình đọc màn hình.

### 1.2 Bản đề xuất v2 (2026-10-05)

**Tốt, giữ lại**
- Ý tưởng "vở ô ly & mực tím" xuất phát từ chính người dùng (học sinh Việt Nam nào cũng nhận ra), không phải lối mòn "giao diện AI".
- Chỉ táo bạo ở một điểm (nền ô ly + lề đỏ ở hero và bìa khóa học), màn học giữ yên tĩnh.
- Font Be Vietnam Pro có subset `vietnamese`; hero mở bằng một bài học thật thay vì "số liệu lớn + gradient".
- Bảng màu đã tính tương phản; bỏ emoji, thêm icon SVG.

**Chưa ổn (đã sửa ở bản này)**

| Tiêu chí | Vấn đề | Sửa |
|---|---|---|
| Tương phản & a11y | Viền ô nhập/checkbox dùng `line` `#E6E1F2` = 1,3:1 với nền, trượt WCAG 1.4.11 (cần 3:1) | Thêm token `line-strong` `#8A82A6` (3,6:1) cho mọi viền điều khiển |
| Tương phản & a11y | Nút disabled kiểu `opacity-60` làm chữ mờ dưới 4.5:1 | Disabled = nền `line` + chữ `ink-soft` (≥ 6:1), con trỏ `not-allowed` |
| Tương phản & a11y | Trạng thái "Đang chờ duyệt"/"Không thể xoá" dựa vào tooltip | Thay bằng khối trạng thái có chữ đầy đủ ngay tại chỗ |
| Khả năng đọc | Meta 13px trên mobile | Tối thiểu 14px cho chữ phụ; 12px chỉ cho dòng phụ thứ ba trong bảng quản trị |
| Công thức Toán | Chưa có quy tắc nào cho KaTeX/công thức | Thêm §5.3: cỡ, khoảng dòng, công thức riêng dòng cuộn ngang, đáp án trắc nghiệm |
| Thương hiệu | Logo tạm, chưa có quy tắc dùng | Giữ logo tạm (viên vitamin), ghi rõ chờ logo chính thức |
| Mobile | Không nói gì về thanh hành động dính đáy, bottom-nav ở trang học | §12.1: trang học/quiz bỏ header site và bottom-nav; trang chi tiết có thanh CTA dính đáy |
| Icon | Ghi dùng `lucide-react` nhưng package **chưa được cài** (không có trong danh sách G2) | Bộ icon SVG nội bộ `packages/ui/src/v2/icons.tsx` vẽ theo Lucide; PO quyết có cài `lucide-react` không |
| Dark mode, quản trị, trạng thái | Chưa có | §13, §14, §11 |

### 1.3 Lệch với story / API contract

| Chỗ lệch (mockup v2 cũ) | Contract nói gì | Xử lý trong bản này |
|---|---|---|
| Thẻ khóa học ở danh mục hiện "24 bài · 6 giờ 30 phút" | Item `GET /courses` **không có** `lessons_count`, `total_duration_seconds` (chỉ có ở chi tiết) | Thẻ hiện chuyên đề, mô tả ngắn, giáo viên, số học sinh, giá. **Hỏi PO**: có muốn Architect thêm 2 trường vào list (thay đổi tương thích) không |
| Giá gốc gạch ngang "~~499.000đ~~" | Không có trường giá gốc/khuyến mãi | Bỏ |
| Ô chọn lớp ghi "14 khóa · ôn thi vào 10" | Không có API đếm khóa theo lớp | Ghi chú cố định theo lớp ("Ôn thi vào 10"), không số đếm |
| Trang học có tab "Mô tả bài học", vị trí "ghi chú/tài liệu, phụ đề" | `LessonShow` không có mô tả, tài liệu đính kèm hay phụ đề | Bỏ tab; dưới video là tên bài, bài trước/tiếp, "Bài tập của bài này" (`quizzes`). Không vẽ nút phụ đề |
| Nút "Mua ngay"/giỏ hàng luôn hiện | `paid_checkout_enabled=false` (V2): phải ẩn/khoá lối mua; `POST /checkout` trả 503 `PAYMENT_DISABLED` | §12.2: khóa có phí hiện giá + "Sắp mở bán", không có nút mua; header ẩn giỏ hàng |
| "Khóa học của tôi" hiện tên bài học tiếp | `/me/courses` chỉ có `resume_lesson_id` (không có tên bài) | Hiện "Tiếp tục học" không kèm tên bài. Hỏi PO có cần thêm `resume_lesson_title` |
| Trang chủ "phụ huynh xác nhận rồi con mới thanh toán" | `parent.consent` mặc định **tắt** (`FEATURE_PARENT_CONSENT_ENFORCED`) | Copy chỉ nói "phụ huynh nhận email xác nhận" |
| Bìa khóa học dựng bằng code là mặc định | `thumbnail` **bắt buộc** khi tạo khóa (T08) | Ảnh thật luôn ưu tiên; bìa dựng sẵn chỉ là ảnh thay thế (khóa cũ, ảnh lỗi) và mẫu gợi ý cho giáo viên |
| Form đổi email/SĐT không có mật khẩu | `PUT /auth/contact` bắt buộc `current_password` (2026-10-06) | Ô "Mật khẩu hiện tại" bắt buộc, lỗi ngay dưới ô; 429 hiện Alert |
| Mật khẩu staff "tối thiểu 8" | Staff min **12**, chặn mật khẩu phổ biến/chứa tên email | §12.5 |
| Video tải lên sai định dạng | 422 `code: VIDEO_INVALID`, message tiếng Việt dùng thẳng | Badge "Lỗi video" + Alert hiện nguyên message + "Chọn tệp khác" |
| Lỗi OTP gộp | Nay tách `OTP_INVALID` / `OTP_EXPIRED`; reset mật khẩu mọi lỗi là `OTP_EXPIRED` | §12.5 |

### 1.4 Màn hình còn thiếu thiết kế (v1 + v2 cũ)

Đã dựng trong bản xem trước: trang chủ, danh mục, chi tiết khóa học (đủ biến thể `viewer_state`), đăng nhập, đăng ký, trang học video, làm quiz, kết quả quiz, khóa học của tôi, tiến độ một khóa, tài khoản (đổi liên hệ, đổi mật khẩu), thư viện thành phần; quản trị: khung + menu theo vai trò, danh sách khóa học, sửa khóa học (thông tin chung, chương & bài, form bài + trạng thái video).

Bổ sung chiều 2026-10-06: quên mật khẩu (bước 1), trang giữ chỗ giỏ hàng/điều khoản/chính sách; quản trị: tạo khóa học, chuyên đề, duyệt đăng ký, giáo viên trang chủ, hồ sơ giáo viên, mã giảm giá (danh sách, tạo, sửa), tài khoản staff, nhật ký, trang giữ chỗ đơn hàng.

Bổ sung 2026-10-07 (§12.8): ~~xác thực OTP~~, ~~đặt lại mật khẩu bước 2~~, ~~màn chặn "Cần xác thực tài khoản"/"Chờ phụ huynh"~~ (trang + hộp thoại), ~~hộp thoại phiên bị thay thế/thu hồi~~ (US-014), ~~đổi email/SĐT: email trùng, vừa đổi email (chưa xác thực)~~; quản trị: ~~soạn quiz~~ (tab "Bài tập", danh sách câu, soạn câu + xem trước công thức).

Vẫn còn thiếu: trang công khai xác nhận phụ huynh và quyền dữ liệu cá nhân (US-017/018) — **chỉ có bố cục ngắn ở §12.9, chưa dựng** (thuộc V2). Đăng nhập + MFA + đổi mật khẩu lần đầu của admin do `nextjs-dev` áp v2 trực tiếp lên route thật (không dựng bản xem trước). Giỏ hàng/checkout/đơn hàng thuộc V2.

### 1.5 Kết luận

**Giữ hướng "Vở ô ly & mực tím"**: nó có bản sắc thật, đã được tiết chế đúng chỗ, và phù hợp cả học sinh lớp 6 lẫn lớp 12. Bản này sửa phần a11y (viền, disabled, tooltip), khớp lại với API contract, bổ sung công thức Toán, dark mode, quản trị, trạng thái component, và dựng thành code chạy được.

---

## 2. Brief

| | |
|---|---|
| Sản phẩm | Website bán khóa học **Toán lớp 6–12**: video bài giảng, trắc nghiệm có công thức, theo dõi tiến độ; trang quản trị cho admin, quản lý trang, giáo viên |
| Người dùng chính | Học sinh 11–18 tuổi, chủ yếu điện thoại, học buổi tối ở nhà hoặc trên đường; phụ huynh xác nhận đồng ý (US-017) |
| Việc chính | Tìm đúng khóa theo lớp/chuyên đề → học từng bài → làm trắc nghiệm → biết mình tới đâu, lần sau học tiếp đúng chỗ |
| Người dùng quản trị | Giáo viên soạn nội dung (thường trên laptop), admin/quản lý trang vận hành — cần mật độ thông tin cao, ít trang trí |
| Cảm giác | Gần như góc học tập ở nhà, đủ nghiêm túc để phụ huynh tin; không trẻ con hoá với học sinh lớp 11–12 |
| Ràng buộc | Tiếng Việt đầy đủ dấu; không cài package mới khi chưa hỏi; thanh toán tạm khoá (V2) |

## 3. Hướng thiết kế

- **Mực tím** (`primary`) là màu hành động: nút chính, liên kết, bài đang học, tiến độ.
- **Cam vitamin** (`accent`) dùng tiết kiệm: nhãn "Miễn phí", số việc chờ, vạch đánh dấu trong hình minh hoạ.
- **Trang vở ô ly + lề đỏ + chú thích viết tay** là điểm nhấn: lưới **đậm** (`bg-oly`) ở hero trang chủ, nửa trái trang đăng nhập/đăng ký, bìa khóa học dựng sẵn, ô minh hoạ trạng thái rỗng; lưới **nhạt** (`bg-oly-page`) làm nền chủ đạo của trang khách (§3.1, đề xuất chờ PO duyệt).
- **Màn học yên tĩnh**: trang học video và làm quiz bỏ header site, footer, bottom-nav; chỉ còn đường quay lại, tiến độ và nội dung.

Nguyên tắc riêng của sản phẩm:
1. Học sinh luôn biết bước tiếp theo: mọi trạng thái rỗng/lỗi có một hành động đi tiếp; "Tiếp tục học" luôn ở chỗ dễ thấy.
2. Không bao giờ chỉ dùng màu để nói trạng thái: luôn có chữ hoặc icon ("Đã hoàn thành", "Sai", "Đang chờ duyệt").
3. Công thức Toán là nội dung chính, không phải chú thích: cỡ to hơn chữ thường, không bị cắt, không làm vỡ bố cục trên 375px.
4. Không có lối đi vào ngõ cụt: tính năng đang khoá thì ẩn hoặc giải thích ngay tại chỗ, không để học sinh bấm rồi mới báo lỗi.
5. Quản trị dày thông tin nhưng cùng một hệ: cùng màu, cùng component, chỉ khác cỡ và khoảng cách.

### 3.1 Nền vở ô ly chủ đạo cho trang khách (đề xuất 2026-10-07, chờ PO duyệt — §18 mục 12)

**Quy tắc đổi:** trước đây "chỉ một điểm nhấn ô ly, không bao giờ đặt lưới sau đoạn văn/form/bảng" (§3, §17). Nay tách làm **hai mức lưới**:

| Mức | Utility | Ở đâu | Quy tắc chữ |
|---|---|---|---|
| Lưới đậm | `bg-oly` (`grid` `#DCD2F7`, ô 32px + dòng kẻ 8px) | Hero, nửa trái đăng nhập, bìa khóa dựng sẵn, ô minh hoạ rỗng (như cũ) | Không đặt đoạn văn, form, bảng lên trên (giữ nguyên) |
| Lưới nhạt — **mới** | `bg-oly-page` (`grid-faint` `#ECE6FA` sáng / `#1F1A2B` tối) | Nền vùng `main` của mọi trang dùng `StudentShell`: trang chủ, danh mục, chi tiết khóa, giỏ hàng, điều khoản/chính sách, đăng nhập/đăng ký/OTP/quên + đặt lại mật khẩu, màn chặn, **Khóa học của tôi, Tài khoản** (đề xuất áp luôn — PO quyết) | Tiêu đề, nhãn, meta, đoạn ≤ 3 dòng được nằm thẳng trên lưới. **Đoạn văn > 3 dòng, form, bảng, danh sách dài, công thức nằm trên "tờ giấy trơn"** (`Sheet` hoặc thẻ `surface` có viền `line`) |

Không áp: trang học video, làm quiz (giữ nền `paper` trơn — "màn học yên tĩnh", §12.3–12.4), toàn bộ quản trị (§14).

**Tương phản đo được (WCAG 2.1, chữ đặt ngay trên vạch kẻ — trường hợp xấu nhất):**

| Chữ | Trên `grid-faint` sáng `#ECE6FA` | Trên `grid-faint` tối `#1F1A2B` |
|---|---|---|
| `ink` | 14,2:1 | 14,3:1 |
| `ink-soft` | 5,9:1 | 7,9:1 |
| `primary` (liên kết) | 6,6:1 | 6,3:1 |
| `danger` / `warning` / `info` / `accent-ink` | 4,6 / 4,8 / 5,0 / 5,0:1 | 6,9 / 9,2 / 7,6 / 9,9:1 |
| `success` | **4,4:1 — không đạt** cho chữ nhỏ | 7,7:1 |

→ Chữ màu `success` (giá "Miễn phí", "Đã hoàn thành") chỉ đặt trên `surface`/nền `-soft`, không thẳng trên lưới (thực tế đã vậy: luôn nằm trong thẻ/badge). Vạch kẻ chỉ chênh 1,17:1 với `paper` nên không cạnh tranh với chữ.

**Mật độ theo cỡ màn:** < 640px ô 24px, chỉ đường ô (lề trang 16px, bỏ dòng kẻ mảnh để bớt rối cạnh chữ); ≥ 640px ô 32px + dòng kẻ mảnh 8px như vở thật. **Lề đỏ** (`margin` 40%, 2px) chỉ hiện khi màn ≥ ~1232px, nằm trong khoảng trống bên trái khung `max-w-6xl` (cách mép chữ ≥ 48px), màn hẹp hơn tự ra ngoài khung nhìn — không bao giờ chạy dưới chữ.

**Hiệu năng:** chỉ là `linear-gradient` CSS (0 byte ảnh, không request mới), không `background-attachment: fixed`, không chuyển động → không ảnh hưởng `prefers-reduced-motion`; đo 375px không cuộn ngang. Trạng thái tải/rỗng/lỗi (§11.2) không đổi: skeleton và `Alert` vẫn nền đặc của chúng.

**Cách áp (cho `nextjs-dev`, nhận tự động khi dùng component dùng chung):**
- Token `--color-grid-faint`, `--vv-grid-faint`, `--vv-margin-faint` và utility `bg-oly-page` trong `packages/ui/src/v2/tokens.css`.
- Component mới `Sheet` (`@vitaminvui/ui/v2`) = "tờ giấy trơn": `surface` + viền `line` + `rounded-sheet`, không bóng.
- Bản xem trước: `StudentShell` (prop `background="oly"|"plain"`, mặc định `oly`), `AuthFrame` (form nằm trong `Sheet`; trang tối giản `main` là cột flex để nửa trái lưới đậm cao hết màn), chi tiết khóa (Giới thiệu + Giáo viên trong `Sheet`), bộ lọc chuyên đề (thẻ `surface`), trang giữ chỗ (`Sheet`), dải "Khóa học nổi bật" trang chủ bỏ nền `surface` để lưới chạy liền.
- App thật: thêm `bg-oly-page` vào `<main>` của `components/shell/SiteShell.tsx` (một dòng), bọc form/đoạn dài theo bảng trên. Không sửa route thật trong đợt này.

Ảnh trước/sau (375 + 1280): trước = ảnh đã có `web-trang-chu-{375,1280}.png`, `web-danh-muc-375.png`, `web-chi-tiet-khach-1280.png`, `web-dang-nhap-{375,1280}.png`; sau = `oly-sau-trang-chu-{375,1280}.png`, `oly-sau-danh-muc-375.png`, `oly-sau-chi-tiet-1280.png`, `oly-sau-dang-nhap-{375,1280}.png`, tối: `oly-sau-chi-tiet-toi-1280.png`, `oly-sau-dang-nhap-toi-375.png`. Ở giao diện tối lưới rất kín đáo (1,1:1) — cố ý, để không chói buổi tối.

## 4. Màu

Tỉ lệ tương phản tính theo WCAG 2.1 (script trong quá trình làm, kết quả dưới đây). Chữ thường cần ≥ 4.5:1, viền điều khiển/icon cần ≥ 3:1.

### 4.1 Giao diện sáng (mặc định)

| Token | Hex | Dùng cho | Tương phản |
|---|---|---|---|
| `paper` | `#FBFAFF` | Nền trang | — |
| `surface` | `#FFFFFF` | Thẻ, header, hộp thoại | — |
| `sunken` | `#F4F1FB` | Nền phụ: đầu bảng, skeleton, lời giải | — |
| `ink` | `#1E1537` | Chữ chính, tiêu đề | 16,6:1 trên `paper`; 17,3:1 trên `surface` |
| `ink-soft` | `#5B5378` | Chữ phụ, meta, placeholder | 6,9:1 `paper`; 7,1:1 `surface`; 6,4:1 `sunken` |
| `line` | `#E6E1F2` | Viền tách lớp (thẻ, đường chia) — trang trí | 1,3:1 (không dùng cho viền điều khiển) |
| `line-strong` | `#8A82A6` | **Viền ô nhập, checkbox, nút phụ** | 3,6:1 `surface`; 3,5:1 `paper` |
| `grid` / `margin` | `#DCD2F7` / `#E5484D` | Lưới ô ly đậm / lề đỏ — chỉ trang trí | — |
| `grid-faint` / `margin-faint` | `#ECE6FA` / `margin` 40% | Lưới nhạt nền trang khách (`bg-oly-page`, §3.1) / lề đỏ nhạt; tối `#1F1A2B` | `ink` 14,2:1, `ink-soft` 5,9:1 ngay trên vạch |
| `primary` | `#5B2EC4` | Mực tím: nút chính, liên kết, đang chọn | Chữ trắng 8,0:1; trên `paper` 7,7:1 |
| `primary-hover` | `#4A21A8` | Hover/nhấn nút chính | Chữ trắng 10,1:1 |
| `primary-soft` | `#EFE9FD` | Nền mục đang chọn, bài đang học | `primary` 6,7:1; `ink` 14,6:1 |
| `accent` | `#FF8A3D` | Cam vitamin: nhãn Miễn phí, số đếm | Chữ `ink` (`on-accent`) 7,4:1 — **không** dùng chữ trắng |
| `accent-soft` / `accent-ink` | `#FFF0E4` / `#A84300` | Nền nhạt / chữ cam | 5,4:1 |
| `success` / `success-soft` | `#127A55` / `#E3F5EC` | Hoàn thành, đúng, đã xuất bản | 4,7:1 trên nền nhạt; 5,3:1 trên trắng; chữ trắng trên `success` 5,3:1 |
| `danger` / `danger-soft` | `#C42B3C` / `#FDECEE` | Lỗi, sai, xoá | 4,9:1 / 5,6:1; chữ trắng 5,6:1 |
| `warning` / `warning-soft` | `#995400` / `#FFF3DC` | Đang chờ, sắp hết giờ, đang xử lý | 5,3:1 / 5,8:1 |
| `info` / `info-soft` | `#0B66A3` / `#E5F2FB` | Thông tin trung tính | 5,4:1 / 6,1:1 |
| `player` | `#120C24` | Khung video (luôn tối) | Chữ trắng 19:1 |
| `focus` | = `primary` | Viền focus bàn phím 2px, cách 2px | 7,7:1 với nền |

Token phụ: `on-primary` (chữ trên nền primary), `on-accent`, `on-status` (chữ trên nền success/danger đặc), `danger-hover`, `scrim` (nền mờ sau hộp thoại), `player-ink`.

### 4.2 Giao diện tối

| Token | Hex | Tương phản |
|---|---|---|
| `paper` / `surface` / `sunken` | `#14111C` / `#1C1827` / `#100D17` | — |
| `ink` | `#EEEAF7` | 15,8:1 `paper`; 14,7:1 `surface` |
| `ink-soft` | `#B4ADC8` | 8,7:1 `paper`; 8,1:1 `surface` |
| `line` / `line-strong` | `#2D2740` / `#6F6789` | `line-strong` 3,3:1 |
| `primary` / `primary-hover` / `primary-soft` | `#A98BFF` / `#BDA6FF` / `#2A2147` | Chữ `on-primary` `#14092E` 7,1:1; `primary` trên `surface` 6,5:1 |
| `accent` / `accent-soft` / `accent-ink` | `#FF9A55` / `#3A2417` / `#FFB683` | `accent-ink` trên nền 8,5:1 |
| `success` / `danger` / `warning` / `info` | `#4FC48C` / `#FF7D87` / `#F0B45C` / `#62B6F0` | Trên `surface` lần lượt 7,9 / 7,1 / 9,4 / 7,8:1; trên nền `-soft` ≥ 6,6:1 |

Ở giao diện tối, nút chính là nền tím sáng + chữ tím đậm (không dùng chữ trắng trên tím sáng).

### 4.3 Quy tắc

- Component chỉ dùng tên token (`bg-primary`, `text-ink-soft`, `border-line-strong`). Không dùng `indigo-600`, `gray-500`, không mã hex trong JSX.
- Màu trạng thái luôn đi kèm chữ/icon. Badge trạng thái: nền `-soft` + chữ màu đậm.
- `accent` không dùng cho chữ trên nền trắng (2,4:1); chữ cam dùng `accent-ink`.

## 5. Chữ

### 5.1 Font

| Vai trò | Font | Weight | Ghi chú |
|---|---|---|---|
| Toàn bộ giao diện | **Be Vietnam Pro** (`next/font/google`, subset `latin` + `vietnamese`) | 400, 500, 600, 800 | Thiết kế riêng cho tiếng Việt; dấu chồng (ặ, ổ, ữ, Ỗ) rõ, không va dòng trên. Không dùng 700 (không tải) |
| Chú thích viết tay | **Mali** (subset `vietnamese`) | 500 | Chỉ 1–2 dòng ở hero và trang đăng nhập/đăng ký. Không cho nút, nhãn, nội dung |
| Công thức | Font của KaTeX (bản thật) | — | Bản xem trước dùng MathML của trình duyệt |
| Mã/slug (quản trị) | Geist Mono (đã có) | — | Chỉ trong thư viện thành phần |

Skill gợi ý Libre Bodoni + Public Sans (phong cách tạp chí) và trước đó Comic Neue + Baloo 2 — đều bỏ (xem §17).

### 5.2 Thang cỡ chữ

| Cấp | Token | Mobile | Desktop | Weight | Line-height |
|---|---|---|---|---|---|
| Hero (chỉ trang chủ) | `text-display` / `md:text-display-lg` | 34px | 52px | 800 | 1,18 / 1,15 |
| H1 trang | `text-title` / `md:text-title-lg` | 26px | 32px | 800 | 1,25 |
| H2 khu vực | `text-heading` / `md:text-heading-lg` | 20px | 24px | 800 | 1,3 |
| Tiêu đề thẻ | `text-lg` | 18px | 18px | 600 | 1,4 |
| Câu hỏi trắc nghiệm | `text-question` | 18px | 18px | 400 | 1,75 |
| Nội dung | `text-base` | 16px | 16px | 400 | 1,6 (`leading-relaxed` cho đoạn dài) |
| Nhãn, meta | `text-sm` | 14px | 14px | 500–600 | 1,45 |
| Dòng phụ trong bảng quản trị | `text-xs` | 12px | 12px | 400 | 1,4 — chỉ dòng thứ ba, không dùng cho nội dung |

- Tiêu đề 800 dùng `tracking-heading` (−0,01em). Line-height tiêu đề không dưới 1,15 để dấu tiếng Việt không bị cắt.
- Đoạn văn tối đa ~75 ký tự (`max-w-prose`, `max-w-2xl`).
- Câu viết thường (sentence case); không viết HOA toàn bộ nhãn.
- Số (giá, điểm, đồng hồ, tiến độ) dùng class `num` (`tabular-nums`) để thẳng cột.
- Giá: "399.000đ"; giá 0: "Miễn phí" (màu `success`). Điểm: "7,5" (dấu phẩy thập phân).

### 5.3 Công thức Toán

- Nội dung câu hỏi/đáp án/lời giải là văn bản thuần chứa `$...$` (trong dòng) và `$$...$$` (riêng dòng) — đúng T21/T22. Component `<MathText content>` tách công thức và chữ, không dùng `dangerouslySetInnerHTML`.
- Bản thật (FW5, FA5): KaTeX `trust:false, strict:'warn', maxSize:10, maxExpand:1000, throwOnError:false`. Bản xem trước: bộ chuyển TeX → MathML nội bộ (một tập con), chỉ để PO nhìn bố cục.
- Cỡ công thức = 1,1 lần chữ xung quanh; câu hỏi 18px, line-height 1,75 để phân số trong dòng không chạm dòng trên/dưới.
- `\dfrac` luôn hiện cỡ đầy đủ kể cả trong dòng (đáp án trắc nghiệm hay là phân số).
- Công thức riêng dòng nằm trong khung cuộn ngang (`overflow-x-auto`, `tabIndex=0`, `role=group`, nhãn "Công thức") → không tràn trang ở 375px, dùng được bàn phím.
- Đáp án trắc nghiệm: cả hàng là vùng chạm ≥ 56px, chữ cái A–D trong vòng tròn bên trái, công thức 17–18px.
- Lời giải đặt trong khối `sunken` có tiêu đề "Lời giải".
- Khi KaTeX lỗi cú pháp: hiện nguyên văn TeX màu `danger` (KaTeX `throwOnError:false` đã làm), không chặn trang.

## 6. Khoảng cách, lưới, breakpoint

- Đơn vị 4px (thang Tailwind): 4, 8, 12, 16, 20, 24, 32, 40, 48, 64.
- Web học sinh: khoảng cách giữa khu vực 40–56px (`py-10`–`py-14`), trong thẻ 16px; container `max-w-6xl` (1152px), lề 16px mobile / 24px từ `sm`.
- Trang đọc (tài khoản, khóa học của tôi): `max-w-2xl`–`max-w-4xl`.
- Quản trị: khoảng cách 12–24px, container `max-w-7xl`, sidebar 256px.
- Breakpoint mặc định Tailwind: `sm` 640, `md` 768, `lg` 1024, `xl` 1280, `2xl` 1536. Thiết kế mobile-first, kiểm từ 375px tới 1440px, không cuộn ngang (đã đo `scrollWidth` = 0 trên mọi màn xem trước).
- Vùng chạm tối thiểu 44×44px; nút chính mobile 52px (`size="lg"`).

## 7. Bo góc, viền, đổ bóng

| Token | Giá trị | Dùng cho |
|---|---|---|
| `rounded-control` | 12px | Nút, ô nhập, mục danh sách |
| `rounded-card` | 16px | Thẻ, khung, bảng |
| `rounded-sheet` | 24px | Hộp thoại, bottom-sheet, khối nổi lớn |
| `rounded-full` | — | Chip, badge, avatar |
| `shadow-raised` | 2 lớp mờ nhẹ | Thẻ nổi (bìa có nhãn lớp, khung sửa bài) |
| `shadow-overlay` | 2 lớp đậm hơn | Hộp thoại, toast, thanh dính đáy |

- Tách lớp bằng viền `line`, không bằng bóng. Thẻ mặc định **không** có bóng.
- Hover thẻ: viền chuyển `primary`, tiêu đề đổi màu; không phóng to/nhấc thẻ.
- Focus bàn phím: utility `focus-ring` (outline 2px `focus`, cách 2px) trên mọi phần tử bấm được; trên nền video tối dùng viền trắng.

## 8. Icon

- Bộ SVG nội bộ `packages/ui/src/v2/icons.tsx` (~60 icon), nét vẽ theo Lucide (giấy phép ISC): khung 24, nét 1,75, đầu tròn. Cỡ 16 (trong badge), 18–20 (trong nút, meta), 24 (điều hướng).
- Mặc định `aria-hidden`. Icon mang nghĩa mà không có chữ: truyền `title` (ví dụ ổ khoá "Cần sở hữu khóa học").
- Nút chỉ có icon dùng `<IconButton label>` — luôn có `aria-label`.
- Không dùng emoji làm icon.

## 9. Bìa khóa học

- Tỉ lệ 16:9, nhãn "Lớp N" góc trái trên (nền `surface`), "Miễn phí" góc phải.
- **Ảnh admin tải lên luôn ưu tiên** (bắt buộc khi tạo khóa — T08). Hướng dẫn ảnh cho giáo viên: 16:9, chữ lớn ở giữa, tránh góc trái trên (nhãn lớp đè lên).
- Bìa dựng sẵn (lưới ô ly + ký hiệu Toán) dùng khi khóa không có ảnh hoặc ảnh lỗi, và cho ảnh thu nhỏ trong bảng quản trị khi chưa có ảnh:

| Chuyên đề (theo slug) | Nền | Ký hiệu |
|---|---|---|
| Đại số, phương trình, số học | `primary-soft` | x² |
| Hình học | `accent-soft` | △ |
| Giải tích, hàm số | `info-soft` | ∫ |
| Xác suất – thống kê | `success-soft` | σ |
| Ôn thi | `warning-soft` | Σ |
| Khác | `primary-soft` | π |

## 10. Chuyển động

- Chỉ để phản hồi thao tác: đổi màu nút/chip 150ms; hộp thoại hiện lên 200ms (`rise-in`), bottom-sheet trượt lên 220ms (`sheet-in`); dấu tick "Đã lưu" 200ms; thanh tiến độ chạy 300ms.
- Đường cong `ease-out-soft` (`cubic-bezier(0.2, 0.7, 0.2, 1)`); thoát nhanh hơn vào.
- Mọi chuyển động bọc `motion-safe:`. Với `prefers-reduced-motion`: không chuyển động, skeleton không nhấp nháy, vòng xoay đứng yên (vẫn có chữ "Đang tải…").
- Không hiệu ứng khi cuộn trang, không tự phát video, không thư viện GSAP (skill có gợi ý scroll-reveal — bỏ).

## 11. Component lõi và trạng thái

### 11.1 Danh sách (`@vitaminvui/ui/v2`)

| Component | Ghi chú |
|---|---|
| `Button`, `ButtonLink`, `IconButton` | Biến thể `primary`, `secondary`, `soft`, `ghost`, `danger`; cỡ 36/44/52px; `loading` + `loadingText` |
| `Field` + `TextInput`, `PasswordInput`, `Select`, `Textarea`, `Checkbox` | Nhãn luôn hiện, `*` có chữ ẩn "(bắt buộc)", gợi ý + lỗi ngay dưới ô, nối `aria-describedby`/`aria-invalid`; `id` tuỳ chọn để liên kết từ hộp tóm tắt lỗi |
| `Badge` | Tone `neutral`, `primary`, `free`, `success`, `warning`, `danger`, `info`; `dot` cho trạng thái quản trị |
| `Alert` | Thông báo trong trang; `role=alert` cho lỗi; có `action` |
| `ProgressBar` | `role=progressbar` + chữ "6/16 bài · 37%"; `hasContent=false` → "Chưa có nội dung" |
| `Tabs` (client), `LinkTabs` | Tab theo mẫu WAI-ARIA (mũi tên, Home/End); tab theo URL cho trạng thái cần giữ khi F5 |
| `Dialog`, `ConfirmDialog` | Dựa trên `<dialog>` gốc: bẫy focus, Esc, trả focus; `sheetOnMobile`; `dismissible=false` khi đang nộp bài |
| `ToastProvider`/`useToast` | Góc dưới phải (desktop), trên bottom-nav (mobile); 5 giây, dừng khi rê chuột/focus, có nút đóng |
| `EmptyState` | Ô vở nhỏ + tiêu đề + mô tả + hành động; `page`/`inline` |
| `Skeleton`, `LoadingRegion` | Khối giữ chỗ đúng kích thước; vùng có `role=status` + chữ ẩn |
| `CourseCard`, `CourseCover` | Theo item `GET /courses` |
| `Countdown` | Theo `remaining_seconds`; 3 mức màu + chữ; chỉ báo trình đọc màn hình ở mốc 5 phút và 1 phút |
| `MathText` | §5.3 |
| `DataTable` | Đầu bảng dính, số căn phải, ẩn cột theo breakpoint, dòng skeleton, ô rỗng |
| `Pagination`, `Breadcrumb`, `Avatar`, `Logo`, `Spinner`, `ThemeSwitch` | |
| `TeacherCard`, `formatGrades` | Thẻ giáo viên trang chủ (US-020 BR1); ảnh vuông, chữ cái đầu khi không có ảnh |
| `Switch` | Công tắc `role=switch`, có chữ "Bật/Tắt" đi kèm |
| `OtpInput` (mới 2026-10-07) | **Một** `<input>` thật vẽ thành 6 ô: một nhãn/lỗi qua `Field`, dán cả mã, `autocomplete="one-time-code"`, không chặn dán (WCAG 3.3.8); `onComplete` tự gửi, `busy`, `focusSignal`. API giống v1 nên `MfaForm` admin dùng được (test 9/9 qua) |
| `ResendCode` (mới) | Nút "Gửi lại mã" đếm ngược theo `waitSeconds` (`resend_available_at`/`Retry-After`), chữ nói rõ "Gửi lại mã sau 0:45"; `emphasis` khi mã hết hạn (thành nút chính); `lockedReason` khi hết lượt trong ngày; `label` đổi được ("Gửi lại email cho phụ huynh") |
| `SessionEndedDialog` (mới) | Hộp thoại chặn không đóng được cho 401 `SESSION_REPLACED` (`replaced`, icon thiết bị, nền `warning-soft`, có "Không phải bạn? Đặt lại mật khẩu") và `SESSION_REVOKED` (`revoked`, icon thông tin, giọng bình thường); `context` lesson/quiz thêm câu "tiến độ/bài làm đã được lưu" |
| `Sheet` (mới) | "Tờ giấy trơn" trên nền ô ly (§3.1) |
| `SiteHeader`, `SiteFooter`, `BottomNav`, `AdminFrame`, `NavDrawer` | Khung trang |
| `UiLink`/`UiLinkProvider` | Cho component dùng chung dùng `next/link` mà `packages/ui` không phụ thuộc `next` |

Component nghiệp vụ dựng trong app (xem trước): web — `CatalogFilters`, `CourseAction` (CTA), `CourseOutlinePublic`, `PreviewLessonButton`, `VideoFrame`, `LessonOutline`, `QuizRunner`, `ResultQuestion`/`ScoreRing`, `MyCourseCard`, `LoginForm`, `RegisterForm`, `ChangeContactForm`, `ChangePasswordForm`, `OtpVerifyForm`, `ResetPasswordForm`, `AccountGateDialog`/`AccountGatePage`; admin — `CourseStatusBadge`, `VideoStatusBadge`, `CourseInfoForm`, `CurriculumTree`, `LessonEditor`, `QuizList`, `QuizSettingsDialog`, `QuizQuestionEditor`.

### 11.2 Bảng trạng thái chuẩn

| Trạng thái | Cách hiện | Ví dụ trong bản xem trước |
|---|---|---|
| Đang tải | Skeleton đúng khung (không giật layout), `aria-busy`; nút đang gửi: vòng xoay + chữ "Đang …", khoá nút | Danh mục `?trang-thai=dang-tai`, `loading.tsx` của danh mục; bảng quản trị |
| Rỗng | `EmptyState` nói rõ vì sao rỗng + 1 hành động (Xoá bộ lọc / Khám phá khóa học / Tạo khóa học) | Danh mục lọc không khớp; Khóa học của tôi `?trang-thai=rong` |
| Lỗi tải | `Alert tone=danger` + nút "Tải lại"/"Thử lại"; giữ phần trang còn lại | Danh mục, khóa học của tôi, quản trị `?trang-thai=loi` |
| Lỗi nhập liệu | Chữ đỏ + icon ngay dưới ô; form dài thêm hộp tóm tắt lỗi đầu form (liên kết tới ô, nhận focus) | Đăng ký: bấm "Tạo tài khoản" khi để trống |
| Disabled | Nền `line`/`sunken` + chữ `ink-soft`, con trỏ `not-allowed`; **luôn có chữ giải thích bên cạnh** (không tooltip) | "Dán link ngoài" khi bài không cho xem thử; "Xoá khóa học" khi đã có học sinh |
| Focus | `focus-ring` 2px, cách 2px | Mọi nút, liên kết, ô nhập, đáp án |
| Thành công | Toast ngắn (không chặn) hoặc khối trạng thái tại chỗ | "Đã lưu thay đổi", "Bạn đã hoàn thành Bài 7" |
| Không có quyền / chưa mở | Ẩn hẳn mục không có quyền; tính năng chưa mở hiện mờ kèm nhãn ("Đơn hàng · V2", "Sắp mở bán") | Menu quản trị, CTA khóa có phí |
| Không tìm thấy | Trang `EmptyState` có `h1` + đường về | `/v2/khoa-hoc/khong-co-khoa-nay` |

## 12. Quy tắc màn hình và nghiệp vụ

### 12.1 Khung và điều hướng (web)

- Nền vùng nội dung trang khách: vở ô ly nhạt `bg-oly-page` (§3.1); form, đoạn dài, bảng đặt trên `Sheet`/thẻ `surface`. Trang học/quiz giữ nền trơn.
- Header dính: logo · "Khóa học" · ("Khóa học của tôi" khi đăng nhập) · tìm kiếm · tài khoản. Mobile: logo + tìm kiếm + menu (ngăn kéo).
- Bottom-nav (mobile, đã đăng nhập): Trang chủ · Khóa học · Học của tôi · Tài khoản. Không có Giỏ hàng khi thanh toán tạm khoá.
- Trang học video và làm quiz: không header site, không footer, không bottom-nav — header gọn (quay lại khóa học, tiến độ, avatar / thoát, đồng hồ, nộp bài).
- Trang chi tiết khóa học (mobile): thanh giá + hành động dính đáy thay cho bottom-nav.
- Bộ lọc danh mục nằm trên URL (`grade`, `subject_ids`, `q`, `sort`, `page`); chọn lớp bằng chip (đổi là điều hướng), chuyên đề bằng checkbox (desktop áp dụng ngay, mobile bottom-sheet bấm "Xem kết quả"). Tìm kiếm là form GET chạy cả khi chưa tải JS.

### 12.2 Hành động chính ở trang chi tiết (`viewer_state` × `paid_checkout_enabled`)

| Khóa | `viewer_state` | Thanh toán bật | Thanh toán tạm khoá (hiện tại) |
|---|---|---|---|
| Có phí | khách | "Mua khóa học" → đăng nhập `?next=` | Giá + khối "Sắp mở bán" + "Học thử bài miễn phí" (nếu `has_preview`) |
| Có phí | `can_buy` | "Mua khóa học" (thêm giỏ → `/gio-hang`) + "Học thử" | như trên |
| Có phí | `in_cart` | "Xem giỏ hàng" + "Khóa học đã có trong giỏ" | như trên (giỏ hàng bị ẩn) |
| Miễn phí | khách | "Đăng ký học miễn phí" → đăng nhập | giống bật |
| Miễn phí | `can_register_free` (kể cả từng bị từ chối) | "Đăng ký học miễn phí" + "Khóa miễn phí cần giáo viên duyệt…" | giống bật |
| Miễn phí | `pending_approval` | Khối trạng thái `warning` "Đang chờ duyệt" (không phải nút bị khoá) | giống bật |
| Bất kỳ | `owned` | "Tiếp tục học" → `resume_lesson_id` + "Bạn đã sở hữu khóa học này" | giống bật |

- Nếu gặp 503 `PAYMENT_DISABLED` (cờ vừa tắt giữa chừng): hiện `Alert tone=info` với `message` của API, **không** tự thử lại, làm mới config để ẩn lối mua.
- 403 `ACCOUNT_NOT_VERIFIED` khi đăng ký miễn phí → màn "Cần xác thực tài khoản" (US-001 §2.4).
- Bài khoá trong mục lục: icon ổ khoá + câu giải thích phía trên danh sách; không bật thông báo khi bấm.

### 12.3 Trang học video

- Khung 16:9 cố định cho mọi trạng thái: đang tải (vòng xoay), lỗi (AC5: "Không tải được video, vui lòng thử lại." + Thử lại), video đang xử lý (409 `VIDEO_NOT_READY`), đang phát. Link HLS hết hạn → lấy lại ngầm, không báo lỗi.
- Điều khiển: phát/dừng, tua (có `aria-valuetext`), âm lượng, tốc độ 0,75–2×, toàn màn hình; vùng chạm 44px; viền focus trắng.
- Hoàn thành ≥ 90%: toast không chặn + icon mục lục đổi ngay.
- Mục lục: chương là `<details>` (mở sẵn chương chứa bài hiện tại); bài hiện tại có nền `primary-soft` + vạch trái + `aria-current`; icon hoàn thành/đang học/chưa học kèm chữ ẩn; bài trắc nghiệm nằm ngay dưới bài/chương gắn với nó.
- Desktop: cột phải 400px dính, cuộn riêng. Mobile: mục lục ngay dưới khối bài.

### 12.4 Làm quiz và kết quả

- Header dính: thoát (bài làm đã lưu), tên quiz, "Đã trả lời 3/8", đồng hồ, "Nộp bài"; thanh tiến độ mảnh dưới header. Mobile: thanh đáy "3/8" (mở bảng câu hỏi) + "Nộp bài".
- Đồng hồ theo `remaining_seconds` của server; > 5 phút nền `sunken`, ≤ 5 phút `warning`, ≤ 1 phút nền `danger` chữ trắng; hết giờ → hộp thoại không đóng được "Đã hết giờ làm bài" → trang kết quả (`auto_submitted`). Không có giới hạn (`remaining_seconds=null`) → ẩn đồng hồ.
- Mỗi câu là `fieldset`; đáp án là radio thật (ẩn), cả hàng bấm được, chọn = viền + nền tím + chữ cái tô đặc. Chọn là tự lưu: "Đang lưu…" → "Đã lưu ✓" cạnh câu (`aria-live`).
- Nộp khi còn câu trống → `ConfirmDialog` "Bạn còn N câu chưa trả lời" / "Làm tiếp" / "Vẫn nộp bài". Đang nộp → khoá toàn form.
- Mất mạng → `Alert warning` đầu danh sách. `QUIZ_NOT_READY` → `EmptyState` "Bài kiểm tra chưa sẵn sàng".
- Kết quả: vòng điểm (chữ số luôn hiện), "Đúng 6/8 câu · Sai 1 · Bỏ trống 1", lời động viên theo mức điểm, "Học bài tiếp theo" + "Làm lại"; tab theo URL "Tất cả / Câu sai / Bỏ trống"; mỗi câu: badge Đúng/Sai/Chưa trả lời, đáp án đúng luôn đánh dấu xanh + "Đáp án đúng", đáp án chọn sai đỏ + "Bạn chọn", khối "Lời giải".

### 12.5 Form và mã lỗi mới

- **Đổi email/SĐT** (`PUT /auth/contact`): ô "Mật khẩu hiện tại" bắt buộc, gợi ý "Để bảo vệ tài khoản…"; 422 `current_password` → lỗi dưới ô; 429 → `Alert warning` "Bạn đã nhập sai mật khẩu nhiều lần. Vui lòng thử lại sau N phút."; mô tả ô email báo trước "các thiết bị khác sẽ bị đăng xuất".
- **Mật khẩu học sinh**: min 8, gợi ý "Tránh mật khẩu dễ đoán như 12345678"; 422 `password` (mật khẩu phổ biến) hiện `errors.password[0]` dưới ô.
- **Mật khẩu staff** (đổi lần đầu, đổi mật khẩu): `minLength` 12, gợi ý "Tối thiểu 12 ký tự, không chứa phần trước @ của email"; lỗi server hiện dưới ô.
- **OTP**: `OTP_INVALID` → "Mã OTP không đúng, vui lòng thử lại." dưới ô; `OTP_EXPIRED` → thông điệp hết hạn + nút "Gửi lại mã" nổi bật. Màn đặt lại mật khẩu: mọi lỗi mã hiện như hết hạn.
- **Video sai định dạng** (`VIDEO_INVALID`, 422 khi TUS PATCH): badge "Lỗi video" ở cây chương/bài + `Alert danger` "Video xử lý thất bại" với nguyên `message` tiếng Việt từ API + vùng "Chọn tệp khác".
- **Đăng nhập**: lỗi sai thông tin là banner chung (không chỉ ô nào sai); bị đăng xuất do thiết bị khác → `Alert info` (không đỏ) ở trang đăng nhập.

### 12.6 Trang chủ (US-019) và giáo viên công khai (US-020)

- Thứ tự: hero → chọn lớp → khóa nổi bật (tối đa 4, `sort=featured`) → **Người sáng lập** (poster, §12.7) → **Thầy cô giảng dạy** → một buổi học → dành cho phụ huynh.
- Hero chỉ có "Xem khóa học" (+ "Chọn lớp của bạn" cuộn tới khu chọn lớp). Không có nút "Học thử" gắn cứng (Q8); câu chữ trung tính, không "10–25 phút", không "học thử miễn phí" (Q9); hình bài học trong hero là minh hoạ `aria-hidden`.
- Khóa nổi bật: thẻ khóa có phí hiện giá + "Sắp mở bán" khi `paid_checkout_enabled=false`; rỗng → "Khóa học sẽ sớm được cập nhật" + liên kết danh mục; lỗi → Alert + "Tải lại" tại chỗ, các khu khác vẫn hiện.
- Phụ huynh: chỉ 2 ý ("Một tài khoản, một thiết bị", "Tiến độ rõ ràng"); ẩn ý email xác nhận phụ huynh khi cờ còn tắt (Q10). Footer không còn liên kết Điều khoản/Chính sách cho tới khi có trang.
- Khu giáo viên: tối đa 6 thẻ; 1 cột (mobile) → 2 cột (≥ 768) → 3 cột (≥ 1024); 1 người → một thẻ rộng vừa, không lưới trống; không có ai/lỗi → không render gì. Thẻ: ảnh vuông (lỗi/không có → chữ cái đầu trên ô vở), họ tên tối đa 2 dòng, chuyên môn, "Lớp 9, 11 · 3 khóa đang bán", giới thiệu cắt 3 dòng (giữ xuống dòng), "Xem N khóa học" → danh mục `?teacher_id=` (Q6 mặc định). Alt ảnh: "Ảnh thầy/cô {họ tên}". Không carousel.
- Tên trường đang dùng tạm (chờ Architect): `id, name, avatar_url, headline, grade_levels, published_courses_count, bio`.
- **Hồ sơ của tôi** (giáo viên): ảnh (chọn → hộp thoại cắt vuông 1:1: kéo, phím mũi tên, thanh phóng to; xem trước bằng data URL vì CSP admin không có `blob:`), chuyên môn (đếm /120), giới thiệu (đếm /600, văn bản thuần), ô đồng ý với đúng câu BR4 + phiên bản; đã đồng ý → thời điểm + nút "Rút đồng ý" (xác nhận, nói rõ ẩn trong tối đa 1 phút). Cột phải: checklist 6 điều kiện hiển thị (đạt/chưa đạt bằng icon + chữ) và xem trước thẻ trang chủ theo nội dung đang nhập. Khi Admin/QLT sửa hộ: Alert "Chỉnh sửa gần nhất bởi…".
- **Giáo viên trên trang chủ** (Admin/QLT): nhãn "Đang bật N/6"; danh sách "Đang bật" theo thứ tự với nút Lên/Xuống (không cần kéo-thả), công tắc, "Sửa hồ sơ"; badge "Đã đồng ý/Chưa đồng ý" và "Đang hiện"/"Chưa hiện: lý do". Bật người thứ 7 → Alert "Trang chủ chỉ hiển thị tối đa 6 giáo viên. Hãy tắt bớt một người trước." và không đổi gì. Màn sửa hộ: ô đồng ý bị khoá + "Chỉ giáo viên được đồng ý công khai…".

### 12.7 Trang chủ: poster người sáng lập (quyết định PO 2026-10-06, nội dung tạm 2026-10-07)

Nội dung **cố định trong frontend** (ảnh tĩnh trong `apps/web/public/`, họ tên, vai trò, câu thông điệp, nút tuỳ chọn) — không API, không màn quản trị, không liên quan hồ sơ giáo viên (US-020). Vị trí: khối riêng ngay sau "Khóa học nổi bật", hero giữ nguyên. Component `FounderPoster` (`apps/web/components/v2/home/FounderPoster.tsx`, Server Component). Nội dung dùng cho app thật: hằng số `founderPoster` trong `apps/web/lib/home/founder.ts` (biến thể xem trước `apps/web/lib/mock/v2/founder.ts` lấy lại hằng số này).

**Nội dung tạm (quyết định PO 2026-10-07)** — chưa có ảnh/tên/câu thật của người sáng lập; PO cho hiển thị nội dung tạm ở trang chủ thật tới khi gửi nội dung thật:

| Mục | Giá trị tạm |
|---|---|
| Ảnh | `public/trang-chu/nguoi-sang-lap-minh-hoa.svg` (1600×2000, ~10 KB) — minh hoạ tự vẽ: giáo viên cách điệu (kính tròn, áo len mực tím) đứng bên bảng kẻ ô ly có lề đỏ, cầm bút cam chỉ vào lời giải $x^2 - 5x + 6 = 0$ (Δ = 1, x₁ = 3, x₂ = 2, dấu tích), đồ thị parabol cắt trục tại 2 và 3, tam giác vuông; bàn phía trước có vở ô ly mở, chồng sách, cốc nước; ánh sáng ấm (cam nhạt) chiếu chéo từ trên phải. Không phải ảnh người thật, không giống ai cụ thể, không chữ "ảnh mẫu"/khung nét đứt. Không có tím bão hoà ở mép ảnh tiếp giáp khối tím |
| `focus` | `50% 32%` |
| Alt | "Hình minh hoạ: giáo viên cách điệu đứng bên bảng kẻ ô ly, cầm bút chỉ vào lời giải phương trình bậc hai, bên cạnh là đồ thị parabol" |
| Họ tên (chủ thể) | "Đội ngũ sáng lập VitaminVui" — chủ thể chung, không đặt họ tên người thật |
| Vai trò | "Những người làm VitaminVui" |
| Câu thông điệp (115 ký tự, cỡ chữ lớn) | "Toán dễ hiểu hơn khi được giảng chậm, rõ từng bước. Chúng tôi làm VitaminVui để đi cùng các em từ lớp 6 đến lớp 12." |
| Nút | "Xem khóa học" → `/khoa-hoc` |
| Tiêu đề ẩn (`h2` sr-only) | "Lời nhắn từ đội ngũ sáng lập" (prop `heading`) |

- Câu viết theo BR5: không số liệu, không hứa kết quả, không danh hiệu; xưng "chúng tôi" vì chủ thể là đội ngũ.
- Ảnh minh hoạ là SVG: Next để `unoptimized` (không `srcset`), không ảnh hưởng vì file ~10 KB và vẽ vector nét ở mọi cỡ. Chữ công thức trong SVG dùng font hệ thống có chân nghiêng (Times/Noto Serif/DejaVu Serif) vì `<img>` SVG không tải được web font — hình chữ có thể khác nhẹ giữa hệ điều hành, là trang trí (nội dung đã nằm trong `alt`).
- Nội dung chính đặt trong 10–90% bề ngang → khung cắt ở 768–1023px chỉ mất phần mép bảng và một phần cốc nước.
- Khi PO gửi ảnh/tên/câu thật: chỉ sửa `lib/home/founder.ts` (+ đặt ảnh 4:5 vào `public/trang-chu/`), xoá file minh hoạ nếu không dùng nữa; một người thì bỏ `heading` để về "Lời nhắn từ người sáng lập".

**Bố cục đã chọn: chia đôi — ảnh tràn mép một bên, thông điệp trên nền mực tím một bên.** Không chọn "ảnh tràn nền + lớp phủ" vì:
1. Tương phản chữ phụ thuộc ảnh PO gửi (áo sáng, phông sáng → chữ trắng không đạt AA); muốn chắc phải phủ gradient đậm, trái quy tắc "không gradient trang trí" (§17) và làm tối mặt người.
2. Trên 375px chữ đè lên mặt hoặc phải cắt ảnh rất mạnh; bố cục chia đôi chỉ cần xếp dọc, ảnh giữ nguyên 4:5.
3. Nền `primary` đặc là "mực tím" của hệ — đủ ấn tượng mà không thêm màu/hiệu ứng mới; điểm cam duy nhất là dấu ngoặc kép.

| | Mobile (< 768) | 768–1023 | ≥ 1024 |
|---|---|---|---|
| Bố cục | Xếp dọc: ảnh trên, chữ dưới | Ảnh 5/12 trái + chữ 7/12 | Ảnh 6/12 trái + chữ 6/12 |
| Khung ảnh | 4:5 đúng tỉ lệ gốc (343×429 ở 375px) | Cao theo cột chữ, ≥ 4:5 → cắt hai bên (≈ 300×475, giữ ~79% bề ngang) | 4:5 (488×610 ở 1024; 552×690 từ 1152) |
| Câu thông điệp | 26px / 600 (câu > 120 ký tự: 20px) | 26px (20px) | 32px / 600, line-height 1,25 (24px) |
| Họ tên / vai trò | 20px 800 / 16px 500 | như mobile | như mobile |

- Khối rộng bằng nội dung (`max-w-6xl`), `rounded-sheet`, nền `primary`, chữ `on-primary` (7,98:1 sáng; tối: `#14092E` trên `#A98BFF` 7,06:1). Không có bóng. Ngăn cách tên bằng đường kẻ `on-primary/30` (trang trí).
- Nút tuỳ chọn (`action`): `ButtonLink variant="secondary" size="lg"` (cao 52px) — nền `surface` trên tím 8:1, chữ `ink` 17:1; không truyền `action` → không có nút, không chừa chỗ.
- Focus bàn phím trong khối đổi sang màu `on-primary` (`[--vv-focus:var(--vv-on-primary)]`) vì viền tím mặc định không thấy trên nền tím.
- Ngữ nghĩa: `section` có `h2` ẩn ("Lời nhắn từ người sáng lập", đọc được bằng trình đọc màn hình); câu là `figure > blockquote + figcaption` (họ tên, vai trò); dấu ngoặc kép SVG `aria-hidden`.
- Ảnh: `next/image` có `width`/`height` theo file gốc, khung giữ chỗ bằng `aspect-[4/5]` → không CLS; `loading="lazy"` (khối nằm dưới màn đầu); `sizes="(min-width: 1152px) 552px, (min-width: 1024px) 50vw, (min-width: 768px) 42vw, 100vw"`; `object-cover` + `object-position` mặc định `50% 30%` (prop `image.focus` để chỉnh khi mặt lệch). Ảnh SVG được Next tự để `unoptimized` (bản mẫu); ảnh JPG/WebP thật được tối ưu và có `srcset`.
- Hằng số `founderPoster = null` hoặc thiếu ảnh, họ tên hoặc câu → **không render gì**: không tiêu đề, không khoảng trắng; khoảng cách khóa nổi bật → giáo viên vẫn là 48px.
- Không chuyển động, không parallax, không phóng to ảnh khi hover.
- Giao diện tối (chờ PO, §13): dấu ngoặc cam trên nền tím sáng chỉ còn 1,3:1 (trang trí, không bắt buộc); nếu PO bật dark mode, đổi dấu sang `on-primary` hoặc thêm token riêng.

**Yêu cầu ảnh PO gửi**

| Mục | Yêu cầu |
|---|---|
| Tỉ lệ | **4:5 dọc** (chân dung nửa người, từ ngực/eo trở lên) |
| Kích thước gốc | **1600×2000 px** (tối thiểu 1200×1500 — đủ nét cho màn 3x ở mobile và 2x ở desktop) |
| Định dạng | JPG chất lượng cao hoặc WebP, hệ màu sRGB, ≤ 1 MB (Next.js tự nén và cắt cỡ) |
| Vùng an toàn cho mặt | Đầu và mặt nằm trong khung giữa: **15%–85% bề ngang, 12%–50% chiều cao** (khung nét đứt trong ảnh mẫu). Chừa ≥ 8% phía trên đỉnh đầu. Ở 768–1023px ảnh bị cắt ~10% mỗi bên; mọi cỡ khác thấy trọn ảnh |
| Phông nền | Đơn giản, không chữ/logo trong ảnh (sẽ bị cắt và không đọc được bằng trình đọc màn hình); không bắt buộc màu, nhưng tránh tím bão hoà trùng màu khối |
| Kèm theo | Họ tên đầy đủ, vai trò (vd. "Người sáng lập VitaminVui"), câu thông điệp **≤ 160 ký tự** (đẹp nhất ≤ 120), nhãn + đích của nút nếu muốn có nút, và câu mô tả ảnh (alt), vd. "Ảnh chân dung {họ tên}, người sáng lập VitaminVui" |
| Quyền | Ảnh của chính người sáng lập, có đồng ý đăng công khai |

Bàn giao `nextjs-dev` (FW8): trang `/` thật render `founderPoster` từ `lib/home/founder.ts` (nội dung tạm đã được PO cho hiển thị); khi có nội dung thật, đặt ảnh vào `apps/web/public/trang-chu/` (vd. `nguoi-sang-lap.webp`) và thay giá trị trong hằng số; muốn ẩn khối thì đặt `null`. Bản xem trước: `/v2` (nội dung tạm, có nút), `?nsl=khong-nut`, `?nsl=dai` (câu dài 155 ký tự), `?nsl=anh-mau` (ảnh mẫu có khung vùng an toàn — chỉ để đối chiếu ảnh chân dung thật), `?nsl=an` (ẩn).

### 12.8 Xác thực, phiên và màn chặn (dựng 2026-10-07)

**Xác thực OTP** (`/xac-thuc-otp`, US-001 §2.2, AC8) — học sinh đã đăng nhập, chưa xác thực. Bố cục `AuthFrame`; phụ đề nói gửi tới email đã che + "xác thực xong bạn có thể đăng ký khóa học".
- `OtpInput` 6 ô; nhập đủ 6 số tự gửi, vẫn có nút "Xác nhận". Gợi ý dưới ô: hiệu lực `otp.ttl_minutes` phút + "xem mục Thư rác".
- Dưới đường kẻ: "Chưa nhận được mã?" + `ResendCode` (đếm theo `resend_available_at`). Gửi lại xong: `Alert info` "Đã gửi mã mới tới … Mã cũ không còn dùng được", focus về ô mã.
- Lỗi: `OTP_INVALID` → dưới ô "Mã OTP không đúng, vui lòng thử lại.", xoá mã, focus lại. `OTP_EXPIRED` → dưới ô thông điệp hết hạn, ô mã khoá, **`ResendCode` thành nút chính rộng hết** (việc duy nhất làm được), ẩn "Xác nhận". 429 của **mã** (sai 5 lần) → như hết hạn, câu "Bạn đã nhập sai mã này 5 lần". 429 throttle (`Retry-After`, khoá 24h khi quá 20 lần/ngày) → `Alert warning` + khoá ô và nút. 503 `OTP_DELIVERY_FAILED` → `Alert danger` "Chưa gửi được mã", gửi lại được ngay. 429 khi gửi (trần 5/giờ, 10/ngày) → nút gửi lại khoá + câu "thử lại vào ngày mai".
- Lối thoát: "Sai email? Đổi email" (→ Tài khoản `#doi-lien-he`) · "Để sau" (→ trang chủ). Thành công: về trang chủ + toast "Xác thực tài khoản thành công".

**Đặt lại mật khẩu bước 2** (`/quen-mat-khau/dat-lai`, US-015 §2.2). Bước 1 luôn chuyển sang đây (không lộ tài khoản); `login` giữ trong sessionStorage (không đặt lên URL), hiện "Tài khoản: … · Đổi".
- Mã (6 ô) + Mật khẩu mới (gợi ý "Tối thiểu 8 ký tự. Tránh mật khẩu dễ đoán như 12345678") + Nhập lại + câu "mọi thiết bị đang đăng nhập sẽ bị đăng xuất".
- **Mọi lỗi mã** (422 `OTP_EXPIRED` — kể cả sai mã, hết lượt, tài khoản không tồn tại) hiện một thông điệp "Mã OTP đã hết hạn hoặc không còn hiệu lực. Bấm 'Gửi lại mã'…", ô mã khoá, `ResendCode` nổi bật ngay dưới; **mật khẩu đã nhập được giữ**. Gửi lại = gọi lại `POST /auth/password/forgot` nên cần captcha Turnstile (chế độ ẩn/managed).
- 422 `password` (mật khẩu phổ biến) → hiện nguyên `errors.password[0]` dưới ô; `password_confirmation` → dưới ô nhập lại; 429 throttle → `Alert warning`, khoá nút. Thành công → `/dang-nhap` với `Alert success` "Đặt lại mật khẩu thành công…".

**Màn chặn** (403 khi đăng ký học miễn phí; sau này checkout). Không dùng màu đỏ (không phải lỗi của học sinh); luôn có một việc làm tiếp + một lối thoát.
- Ở trang chi tiết khóa: **hộp thoại** (bottom-sheet trên mobile) mở tại chỗ, giữ ngữ cảnh khóa. Mở thẳng trang cần điều kiện: **trang đầy đủ** (`Sheet` giữa trang, `h1`).
- `ACCOUNT_NOT_VERIFIED`: ô vở + icon thư, "Xác thực email để tiếp tục", email đã che; "Gửi mã xác nhận" (gửi rồi sang `/xac-thuc-otp`) + "Tôi đã có mã".
- `PARENT_CONSENT_REQUIRED` (chỉ khi bật `FEATURE_PARENT_CONSENT_ENFORCED`): "Đang chờ phụ huynh xác nhận" + email phụ huynh đã che; `ResendCode` "Gửi lại email cho phụ huynh" (3 lần/ngày, hết lượt → khoá + giải thích); Alert info "vẫn xem danh mục và học thử"; biến thể `revoked` "Phụ huynh đã rút lại đồng ý". Không hứa gì về khóa đã có (câu hỏi mở US-017).

**Phiên kết thúc** (US-014 §2.1): `SessionEndedDialog` không đóng được, phủ lên trang học/quiz (video dừng). `SESSION_REPLACED` → "Tài khoản vừa đăng nhập trên thiết bị khác" (warning) + "Không phải bạn? Đặt lại mật khẩu"; `SESSION_REVOKED` → "Bạn cần đăng nhập lại" vì mật khẩu/email vừa đổi (info). Có câu "tiến độ/bài làm đã được lưu". Nút duy nhất "Đăng nhập lại" → `/dang-nhap?next=`. `SESSION_EXPIRED`/`UNAUTHENTICATED` giữ cách hiện tại (chuyển thẳng đăng nhập + Alert info).

**Tài khoản — đổi email/SĐT** bổ sung: 422 `email` trùng → dưới ô email; 429 → Alert + khoá nút "Lưu thay đổi"; vừa đổi email → Alert info "Đã đổi email… cần xác thực lại" + nút "Xác thực ngay", badge email đổi sang "Chưa xác thực".

### 12.9 Chỉ bố cục (V2, chưa dựng): quyền dữ liệu cá nhân và trang phụ huynh

- **Tài khoản › Dữ liệu cá nhân** (US-017/018): một `Sheet` với 3 hàng danh sách (icon + tiêu đề + mô tả 1 dòng + hành động bên phải): (1) "Trạng thái đồng ý" — phiên bản chính sách, ngày đồng ý, trạng thái phụ huynh (badge chữ: Không cần / Đang chờ / Đã đồng ý / Đã rút) + "Gửi lại email cho phụ huynh" (`ResendCode`); (2) "Tải dữ liệu của tôi" — nút phụ, tạo tệp bất đồng bộ, trạng thái "Đang chuẩn bị… / Tải về (hết hạn sau N giờ)"; (3) "Xoá tài khoản" — vùng viền `danger` cuối trang, nói rõ hệ quả (ẩn danh hoá, giữ đơn hàng), bấm → hộp thoại xác nhận bằng **mã OTP** (`OtpInput`) + gõ lại "XOÁ"? (chờ PO), không hoàn tác.
- **Trang công khai `/xac-nhan-phu-huynh/{token}`** (không cần đăng nhập, không header site đầy đủ — chỉ logo): `Sheet` giữa trang max 560px trên nền ô ly nhạt; tiêu đề "Xác nhận cho con học tại VitaminVui", tên học sinh đã che + lớp, tóm tắt dữ liệu thu thập (danh sách 3–4 ý, liên kết chính sách), 2 nút: "Tôi đồng ý" (chính) / "Tôi không đồng ý" (phụ). Biến thể: đã xác nhận (success + "Rút lại đồng ý" có hộp xác nhận), liên kết hết hạn/đã dùng (EmptyState, hướng dẫn nhờ con gửi lại), lỗi tải. Chữ ≥ 16px, ngôn ngữ cho phụ huynh, không thuật ngữ kỹ thuật.

## 13. Dark mode

- Token tối đã có đủ (§4.2), component không cần class `dark:` — chỉ đổi `data-theme` trên phần tử `.theme-v2` (`light` / `dark` / `system`).
- **Đề xuất**: bật cho web học sinh dưới dạng tuỳ chọn trong trang Tài khoản (Sáng / Tối / Theo máy), mặc định **Sáng**. Lý do: học sinh học buổi tối nhiều, trang học vốn đã có khung video tối; nhưng ảnh bìa do giáo viên làm và nội dung mô tả HTML chưa kiểm ở nền tối.
- Quản trị: chỉ giao diện sáng (ít giá trị, thêm việc kiểm).
- Bản xem trước có công tắc ở dải "Xem trước v2"; ảnh: `web-trang-chu-toi-375.png`, `web-hoc-video-toi-1280.png`, `web-quiz-toi-375.png`.

## 14. Trang quản trị

- Cùng token, cùng component; khác mật độ: chữ trong bảng/menu 14px, ô nhập và nút 36px (`size="sm"`), khoảng cách 12–24px, không hình minh hoạ ô ly (trừ ảnh thu nhỏ bìa khóa).
- Khung: sidebar 256px (≥ `lg`) nhóm "Nội dung / Bán hàng / Hệ thống", mục hiện tại nền `primary-soft` + vạch trái; số việc chờ (duyệt đăng ký) bằng badge cam; topbar + ngăn kéo trên mobile/tablet; khối người dùng + vai trò + Đăng xuất ở đáy sidebar.
- Menu theo `permissions` của `/admin/auth/me`, **ẩn hẳn** mục không có quyền:

| Mục | Admin | Quản lý trang | Giáo viên |
|---|---|---|---|
| Khóa học | ✓ | ✓ | ✓ ("Khóa học của tôi") |
| Chuyên đề | ✓ | ✓ | — (chờ PO: GV xem chỉ đọc?) |
| Duyệt đăng ký | ✓ | ✓ | ✓ (khóa mình phụ trách) |
| Mã giảm giá | ✓ | ✓ | — |
| Đơn hàng | mờ "V2" | mờ "V2" | — |
| Tài khoản staff, Nhật ký | ✓ | — | — |

- Danh sách: bộ lọc là form GET trên URL; bảng `DataTable` compact, cột ít quan trọng gộp vào dòng phụ dưới tên ở màn < 1536px; trạng thái bằng badge có chấm + chữ; "Sửa" có chữ.
- Màn sửa: tab theo URL (`?tab=thong-tin|chuong-bai`), bài đang sửa `?bai=`; form 2 cột (nội dung | ảnh bìa, giáo viên, hiển thị); thanh "Lưu thay đổi" dính đáy; quyền theo `abilities` (GV không sửa giá/giáo viên; lớp khoá khi đã xuất bản) và luôn có câu giải thích khi khoá; vùng "Xoá khóa học" viền đỏ ở cuối, nói rõ vì sao không xoá được và gợi ý "Ngừng bán".
- Cây chương/bài: tay cầm ⠿ luôn hiện; thay thế bàn phím cho kéo-thả là nút "Lên/Xuống" trong khung sửa bài (@dnd-kit KeyboardSensor khi dựng thật); mỗi bài hiện "Học thử", trạng thái video, thời lượng. Khung sửa bài: "Cho xem thử" đặt trước nguồn video; "Dán link YouTube/Vimeo" chỉ chọn được khi cho xem thử, lý do hiện ngay bên dưới.

### 14.1 Các màn quản trị đã dựng (xem trước)

| Màn | Điểm thiết kế chính |
|---|---|
| Tạo khóa học | Dùng lại form sửa ở chế độ tạo: ảnh bìa bắt buộc (ô trống có chữ), đường dẫn tự sinh, khóa mới là Nháp, giáo viên tự là người phụ trách |
| Chuyên đề | Bảng + công tắc ẩn/hiện; tạo/sửa trong hộp thoại (đường dẫn xem trước khi tạo, trùng tên → lỗi dưới ô); xoá chuyên đề đang gán → hộp "Không thể xoá" + nút "Ẩn chuyên đề này"; giáo viên: chỉ xem (Alert "Chế độ chỉ xem"), chờ PO câu 8 |
| Duyệt đăng ký | Tab theo URL Chờ duyệt / Đã duyệt / Đã từ chối; cũ nhất trước; email/SĐT đã che; "Duyệt" chuyển "Đang duyệt…" và khoá (chống bấm 2 lần); "Từ chối" mở hộp thoại lý do tuỳ chọn (đếm /1000); giáo viên chỉ thấy khóa mình phụ trách |
| Mã giảm giá | Tab theo `state` (Đang hoạt động, Sắp diễn ra, Hết hạn, Hết lượt, Đã tắt) + tìm; cột lượt dùng có thanh + số. Form: loại giảm dạng radio lớn, cảnh báo mã giảm hết giá trị đơn (bắt buộc giới hạn lượt + ngày hết hạn), phạm vi 3 lựa chọn (tìm khóa); mã đã dùng: mã/loại/giá trị khoá kèm câu giải thích, "Xoá mã" khoá kèm lý do, "Tắt mã/Bật lại mã" có xác nhận |
| Tài khoản staff | Lọc vai trò/trạng thái/tìm; tạo tài khoản → hộp "Mật khẩu khởi tạo" không đóng bằng Esc, hiện một lần, nút sao chép, cảnh báo + "đặt mật khẩu mới tối thiểu 12 ký tự"; khoá/mở khoá/đặt lại có xác nhận; dòng của chính mình và Admin hoạt động cuối có chữ giải thích vì sao không khoá/đổi vai trò; đổi vai trò giáo viên → cảnh báo trước trong hộp thoại và Alert sau khi đổi "N khóa không còn giáo viên phụ trách, hãy gán lại" kèm liên kết từng khóa (`released_course_ids`) |
| Nhật ký thao tác | Huy hiệu "Chỉ đọc"; lọc từ ngày/đến ngày/hành động/người (form GET); hành động hiện tên tiếng Việt + mã; chi tiết `changes` mở bằng `<details>`; chỉ "Trang trước/Trang sau" (simplePaginate); QLT/GV → trang 403 |
| Đơn hàng | Mục menu khoá, dòng giải thích "Mở khi bật thanh toán trực tuyến" ngay dưới; mở thẳng URL → trang "Đơn hàng sẽ có ở V2" |
| Soạn quiz (FA5, 2026-10-07) | Tab "Bài tập" trong màn sửa khóa: bảng quiz (tên, gắn với chương/bài, số câu — 0 câu là badge "Chưa có câu" + Alert "học sinh sẽ thấy 'chưa sẵn sàng'", thời gian), "Tạo bài tập"/"Sửa thông tin" trong hộp thoại (ô "Gắn với" gộp chương + bài bằng `optgroup` vì API cần đúng 1; thời gian 1–300 hoặc "Không giới hạn"; ẩn khi `quiz_time_limit_enabled=false`). Trang quiz: danh sách câu theo thứ tự (nội dung có công thức, "Đáp án đúng B: …", có/chưa có lời giải, "Sửa"), đếm "8/200 câu", đủ 200 → nút thêm khoá + giải thích; ghi rõ chưa đổi được thứ tự câu. Soạn câu `?cau=`: dãy số câu để nhảy nhanh + "Câu mới"; trái là form (thanh **chèn nhanh** `$x$`, `$$x$$`, phân số, căn, mũ, độ, π, ≠, ≤, ≥, `\lt`, `\gt` vào ô đang soạn; ghi chú "dấu < > sát chữ phải dùng `\lt`, `\gt`"; nội dung /5.000; 4 đáp án, mỗi đáp án một radio "Là đáp án đúng" — đáp án đúng nền `success-soft` + chữ "Đáp án đúng"; lời giải tuỳ chọn), phải là **xem trước dính** đúng như học sinh thấy, cập nhật theo phím. Kiểm tại chỗ như server (dạng thẻ HTML, thiếu `$` đóng, ô trống, chưa chọn đáp án đúng). 422 → hộp tóm tắt lỗi có liên kết tới ô + lỗi dưới từng ô; đang lưu → khoá form, "Đang lưu…"; lưu xong "Đã lưu lúc 20:15" (`aria-live`); PUT trả `id` mới (copy-on-write) → Alert info "Đã lưu thành bản mới…". Xoá câu có xác nhận |

## 15. Token và cài đặt (Tailwind v4)

- `packages/ui/src/v2/tokens.css` được import trong `apps/web/app/globals.css` và `apps/admin/app/globals.css` (ngay sau `@import "tailwindcss"`). Khối `@theme inline` ánh xạ `--color-*`, `--text-*`, `--radius-*`, `--shadow-*`, `--animate-*` sang biến `--vv-*`.
- Giá trị `--vv-*` chỉ có trong `.theme-v2`. Ngoài phạm vi này, `primary/accent/success/danger/warning/info` và `font-sans` rơi về đúng giá trị v1 → **các màn FW1/FW2/FA hiện có không đổi giao diện**.
- Utility riêng: `focus-ring`, `bg-oly` (chỉ vẽ lưới, màu nền đặt bằng `bg-*`), class `num`.
- Font: `next/font/google` `Be_Vietnam_Pro` (400/500/600/800) và `Mali` (500), subset `latin` + `vietnamese`, biến `--font-be-vietnam`, `--font-mali` gắn trên phần tử `.theme-v2`.
- `packages/ui/package.json` thêm `exports` `"./v2"` (giữ `"."` như cũ).

**Lộ trình gộp sau khi PO duyệt** (việc của `nextjs-dev`):
1. Gắn `theme-v2` + font lên `<html>` ở `app/layout.tsx` hai app (thay Geist), bỏ fallback v1 trong `tokens.css`.
2. Thay component v1 trong `packages/ui/src` bằng bản v2 (API `Button`/`Badge`/`EmptyState`/`Skeleton`/`Toast` gần tương thích; đổi `variant` → `tone` ở Badge), chạy lại test.
3. Áp lại giao diện cho FW1, FW2 (đang dở) và FA1–FA3 theo các màn xem trước; giữ nguyên logic API/bảo mật đã có.
4. Xoá nhóm route `(v2-preview)` và `lib/mock/v2` khi màn thật đã xong.

## 16. Bản xem trước

Chạy: `HOST_UID=$(id -u) HOST_GID=$(id -g) docker compose -f frontend/docker-compose.yml up` rồi mở:
- Web: `http://api.localhost:3000/v2/muc-luc` (mục lục mọi màn + biến thể). Mỗi màn có dải "Xem trước v2" chọn trạng thái và Sáng/Tối.
- Quản trị: `http://admin-api.localhost:3001/v2/quan-tri/khoa-hoc` (dải chọn vai trò Admin / Quản lý trang / Giáo viên).

| Màn | Route xem trước | Route thật |
|---|---|---|
| Trang chủ | `/v2` (`?khoa=…&gv=…&nsl=khong-nut\|dai\|an`) | `/` |
| Danh mục | `/v2/khoa-hoc` | `/khoa-hoc`, `/lop-{n}` |
| Chi tiết khóa học | `/v2/khoa-hoc/{slug}?viewer=…&thanh-toan=bat` | `/khoa-hoc/{slug}` |
| Đăng nhập / Đăng ký | `/v2/dang-nhap`, `/v2/dang-ky` | `/dang-nhap`, `/dang-ky` |
| Học video | `/v2/hoc/101/bai/307` | `/hoc/{course}/bai/{lesson}` |
| Làm quiz / Kết quả | `/v2/hoc/101/quiz/502`, `…/ket-qua` | `/hoc/{course}/quiz/{quiz}` |
| Khóa học của tôi / Tiến độ | `/v2/tai-khoan/khoa-hoc-cua-toi`, `…/101` | `/tai-khoan/khoa-hoc-cua-toi` |
| Tài khoản | `/v2/tai-khoan` | `/tai-khoan/*` |
| Thư viện thành phần | `/v2/thanh-phan` | — |
| Quên mật khẩu | `/v2/quen-mat-khau` | `/quen-mat-khau` |
| Giữ chỗ: giỏ hàng, điều khoản, chính sách | `/v2/gio-hang`, `/v2/dieu-khoan`, `/v2/chinh-sach-du-lieu` | V2 |
| Quản trị: mục lục | `/v2` (admin) | — |
| Quản trị: khóa học (danh sách/tạo/sửa) | `/v2/quan-tri/khoa-hoc`, `…/tao`, `…/101/sua?tab=chuong-bai&bai=310` | `/quan-tri/khoa-hoc…` |
| Quản trị: chuyên đề, duyệt đăng ký | `/v2/quan-tri/chuyen-de`, `/v2/quan-tri/duyet-dang-ky` | `/quan-tri/…` |
| Quản trị: giáo viên trang chủ, hồ sơ | `/v2/quan-tri/giao-vien`, `…/giao-vien/17`, `/v2/quan-tri/ho-so` | chờ Architect |
| Quản trị: mã giảm giá | `/v2/quan-tri/ma-giam-gia`, `…/tao`, `…/32` | `/quan-tri/ma-giam-gia…` |
| Quản trị: tài khoản staff, nhật ký, đơn hàng | `/v2/quan-tri/tai-khoan`, `/v2/quan-tri/nhat-ky`, `/v2/quan-tri/don-hang` | `/quan-tri/…` |
| Xác thực OTP (mới) | `/v2/xac-thuc-otp?trang-thai=sai\|het-han\|het-luot\|qua-nhieu\|gui-loi\|het-luot-gui` | `/xac-thuc-otp` |
| Đặt lại mật khẩu bước 2 (mới) | `/v2/quen-mat-khau/dat-lai?trang-thai=het-han\|pho-bien\|khong-khop\|qua-nhieu` | `/quen-mat-khau/dat-lai` |
| Màn chặn (mới) | `/v2/can-xac-thuc`, `/v2/cho-phu-huynh?trang-thai=het-luot\|rut-lai`; hộp thoại: `/v2/khoa-hoc/can-bac-hai-can-bac-ba?viewer=can_register_free&chan=xac-thuc\|phu-huynh` (bấm "Đăng ký học miễn phí") | trong luồng đăng ký học / checkout |
| Phiên kết thúc (mới) | `/v2/hoc/101/bai/307?trang-thai=phien-thay-the\|phien-thu-hoi` | mọi trang học sinh |
| Tài khoản — biến thể mới | `/v2/tai-khoan?trang-thai=email-trung\|vua-doi-email`; đăng nhập `?trang-thai=dat-lai-xong` | |
| Quản trị: bài tập, soạn quiz (mới) | `/v2/quan-tri/khoa-hoc/101/sua?tab=bai-tap`, `/v2/quan-tri/khoa-hoc/101/bai-tap/502` (`?trang-thai=dang-tai\|rong\|day\|loi`), `…/502?cau=9006` (`&trang-thai=loi-luu\|dang-luu\|ban-moi`), `…/502?cau=moi` | `/quan-tri/khoa-hoc/{id}/bai-tap/{quiz}` |

Kiểm liên kết: script dò mọi `href` thuộc `/v2` (BFS từ các trang gốc) trên dev server web và admin — 460 URL (web 247, admin 213) đều trả 200 (2026-10-06); 2026-10-07: web 391 URL, admin 400 URL (giới hạn dò), không URL nào lỗi.

Ảnh 2026-10-07: `web-xac-thuc-otp-375`, `web-xac-thuc-otp-het-han-1280`, `web-dat-lai-mat-khau-het-han-375`, `web-chan-xac-thuc-hop-thoai-375`, `web-can-xac-thuc-1280`, `web-phien-thay-the-375`, `web-tai-khoan-vua-doi-email-375`, `admin-bai-tap-1280`, `admin-soan-quiz-cau-1280`, `admin-soan-quiz-loi-luu-1280`, và bộ `oly-sau-*` (§3.1).

Ảnh chụp (Playwright, `prefers-reduced-motion`): [mockups/v2/preview/](mockups/v2/preview/) — `web-*-375.png`, `web-*-1280.png`, `admin-*`, `*-toi-*` (giao diện tối). Poster người sáng lập (2026-10-06): `web-trang-chu-nguoi-sang-lap-{375,768,1280}.png`, `…-cau-dai-{768,1280}`, `…-khong-nut-375`, `…-focus-1280`, `…-toi-1280`, `web-trang-chu-an-nguoi-sang-lap-375.png`; `web-trang-chu-{375,1280}.png` và `web-trang-chu-toi-375.png` đã chụp lại kèm khối mới. Ảnh của bản nháp HTML cũ chuyển vào `preview/ban-nhap-html-2026-10-05/`; các file HTML `mockups/v2/*.html` là bản nháp cũ, **không còn là chuẩn**.

Giới hạn của bản xem trước: không gọi API; công thức dùng MathML (ngoặc `\left(` không giãn theo phân số khi máy thiếu font Toán — KaTeX ở bản thật không bị); video là khung minh hoạ; ảnh bìa là bìa dựng sẵn; mô tả khóa học là đoạn văn thuần (bản thật dùng `CourseDescription` + DOMPurify của FW2).

## 17. Những gì đã sửa sau bước rà, và "không làm"

Lệnh skill đã chạy (từ gốc dự án):
```
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "online math course platform students" --design-system -p "VitaminVui" -f markdown --variance 4 --motion 3 --density 6
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "admin dashboard data table dense" --design-system -p "VitaminVui Admin" -f markdown --variance 2 --motion 2 --density 8
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "dark mode contrast" --domain ux -n 3
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "data table admin density" --domain ux -n 3
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "suspense loading skeleton" --stack nextjs
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "math formula readability" --domain typography   # 0 kết quả
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "quiz timer countdown" --domain ux               # 0 kết quả
```
Hai truy vấn về công thức và đồng hồ quiz không có kết quả trong dữ liệu skill; quy tắc §5.3 và §12.4 là mặc định của designer (WCAG 2.2.1 điều chỉnh thời gian, kinh nghiệm hiển thị KaTeX), không phải từ dữ liệu skill.

| Skill gợi ý | Quyết định | Lý do |
|---|---|---|
| Teal `#0D9488` + cam, nền xanh ngọc nhạt | Bỏ | Lối mòn "edtech teal"; không gắn với vở/mực tím; mất bản sắc đã có |
| Libre Bodoni + Public Sans (tạp chí) | Bỏ | Serif tạp chí không hợp học sinh; Libre Bodoni không có subset `vietnamese` |
| Pattern "Hero + Testimonials carousel" | Bỏ | Chưa có lời chứng thực thật; carousel tự chạy gây xao nhãng. Giữ hero bài học thật |
| Scroll reveal GSAP, "certificate reveals" | Bỏ | Không cần thư viện; chuyển động chỉ phản hồi thao tác |
| Admin: navy/xám "Enterprise Gateway", "Contact Sales" | Bỏ phần màu/pattern | Quản trị dùng cùng hệ màu với web; giữ "Minimalism & Swiss" ở mật độ |
| Bảng rộng: cuộn ngang hoặc thẻ trên mobile | Giữ | `DataTable` cuộn ngang trong khung + ẩn cột theo breakpoint |
| `loading.tsx`, skeleton đúng tỉ lệ (Next.js) | Giữ | Danh mục có `loading.tsx`; mọi skeleton đúng kích thước |
| Không emoji, focus thấy được, tôn trọng reduced-motion, 375–1440px | Giữ | Checklist |
| (2026-10-07) PO: nền ô ly chủ đạo trang khách | Đổi quy tắc "lưới chỉ là điểm nhấn" | Tách lưới đậm / lưới nhạt (§3.1); đo tương phản trên vạch kẻ; chữ dài/form/bảng lên `Sheet` |
| (2026-10-07) Skill: cho dán mã, không chặn (accessible authentication) | Giữ | `OtpInput` một ô thật, `one-time-code` |

Rà "giao diện AI": không nền kem + serif + đất nung; không nền đen + neon; thẻ chỉ dùng cho thứ thật sự là đối tượng (khóa học, câu hỏi), còn lại là danh sách/hàng; không nhãn VIẾT HOA giãn chữ; không gradient trang trí (gradient duy nhất là lớp mờ dưới thanh điều khiển video để chữ đọc được); đánh số 1-2-3 chỉ ở các bước học thật sự nối tiếp.

**Không làm**
- Không đặt lưới ô ly **đậm** (`bg-oly`) sau đoạn văn, form, bảng. Lưới **nhạt** (`bg-oly-page`) làm nền trang khách được, nhưng đoạn > 3 dòng, form, bảng, công thức phải nằm trên `Sheet`/thẻ `surface` (§3.1). Không đặt chữ màu `success` thẳng trên lưới.
- Không đặt lưới (đậm hay nhạt) ở trang học video, làm quiz, quản trị.
- Không dùng tooltip làm nơi duy nhất giải thích trạng thái.
- Không dùng màu là tín hiệu duy nhất (đúng/sai, trạng thái, tiến độ).
- Không dùng chữ trắng trên `accent`; không dùng `accent` cho chữ trên nền trắng.
- Không dùng `opacity` để làm mờ nội dung bị khoá có chữ cần đọc.
- Không hiện nút mua khi `paid_checkout_enabled=false`.
- Không tự phát video, không hiệu ứng khi cuộn, không carousel tự chạy.
- Không `dangerouslySetInnerHTML` cho công thức hay nội dung câu hỏi.

## 18. Câu hỏi cho PO

1. Duyệt hướng "Vở ô ly & mực tím" bản hoàn chỉnh này (màu §4, chữ §5, quy tắc §12) để `nextjs-dev` áp vào FW/FA?
2. **Thanh toán tạm khoá**: đồng ý ẩn hẳn giỏ hàng và nút mua, chỉ hiện giá + "Sắp mở bán" (§12.2)? Hay muốn hiện nút mua bị khoá?
3. **Dark mode**: bật cho web học sinh dạng tuỳ chọn (mặc định Sáng) ngay đợt này, hay để sau MVP? Quản trị chỉ sáng — đồng ý?
4. **Icon**: giữ bộ SVG nội bộ, hay cho cài `lucide-react` (đổi tên import là xong)?
5. **API**: có muốn Architect thêm (thay đổi tương thích) `lessons_count`, `total_duration_seconds` vào item `GET /courses` (thẻ khóa học) và `resume_lesson_title` vào `/me/courses` không?
6. ~~Trang chủ~~: đã có US-019 (Ready), bản xem trước làm theo mặc định Q8–Q10, Q14. PO gửi câu chữ cuối nếu muốn khác (Q9).
7. **Logo** chính thức đã có chưa? Hiện là viên vitamin tạm.
8. Giáo viên có thấy mục "Chuyên đề" (chỉ đọc) trong menu quản trị không (board đang để chờ PO)?
9. Tốc độ phát video 0,75–2× và nút toàn màn hình: đồng ý đưa vào FW4? (Phụ đề chưa có dữ liệu trong API nên không thiết kế.)
10. Mã giảm giá: API cho phép phạm vi = khóa chọn ∪ chuyên đề chọn, nhưng màn (US-013) chỉ cho chọn một kiểu. Có cần chọn đồng thời cả hai không?
11. US-020: tên trường API khu giáo viên và hồ sơ đang dùng tạm — cần Architect chốt; bản xem trước chưa có ảnh giáo viên thật (thẻ hiện chữ cái đầu).
12. **Yêu cầu của PO (2026-10-06): nền vở ô ly làm chủ đạo trên các trang khách (web học sinh).** PO muốn nền vở ô ly là nét nhận diện chủ đạo xuyên suốt web học sinh, không chỉ hero trang chủ, nửa trái đăng nhập/đăng ký và bìa khóa học như §3 đang giới hạn. Việc của `nextjs-designer`: đề xuất cách áp, chụp ảnh bản xem trước 375 và 1280 px, để PO duyệt. BA không thiết kế. Ràng buộc nghiệp vụ phải giữ:
    - **Chữ đọc được (WCAG AA):** mọi chữ trên nền có lưới vẫn đạt tương phản ≥ 4,5:1 (chữ lớn ≥ 3:1) ở cả giao diện sáng và tối; lưới và lề đỏ chỉ là trang trí, không mang thông tin. Kiểm bằng số đo, không bằng mắt.
    - **Trang học video và làm quiz vẫn yên tĩnh** (§3, §12.3, §12.4): không để lưới làm xao nhãng khi tập trung. Công thức Toán, câu hỏi và khung video không đặt trên lưới đậm.
    - **Trang quản trị không áp** (§14): quản trị giữ nền phẳng, mật độ cao.
    - **Phạm vi "trang khách":** gồm trang chủ, danh mục, chi tiết khóa, giỏ hàng, đăng nhập/đăng ký/OTP/quên mật khẩu, điều khoản; "Khóa học của tôi" (đã đăng nhập) designer đề xuất, PO duyệt.
    - **Tự phát hiện mâu thuẫn:** yêu cầu này trái quy tắc "không đặt lưới sau đoạn văn, form, bảng" (§3 và §17 "Không làm"). Designer phải nêu rõ quy tắc nào đổi, và cách giữ form/bảng/đoạn văn dài dễ đọc (ví dụ nền chữ đặc, lưới nhạt ở lề).
    - **Không làm chậm và không làm giật:** không tải thêm ảnh nền nặng, 375 px không cuộn ngang, tôn trọng `prefers-reduced-motion`, không thêm package khi chưa hỏi PO.
    - **Mô tả đủ trạng thái:** sáng/tối, trạng thái tải/rỗng/lỗi vẫn đúng §11.2; không dùng màu làm tín hiệu duy nhất.
    - Sau khi PO duyệt, cập nhật §3, §12 và §17 cho khớp (hiện chưa sửa).
    - **Designer trả lời (2026-10-07):** đề xuất ở §3.1 (đã áp vào bản xem trước, ảnh `oly-sau-*`); §3, §4, §12.1, §17 đã ghi phiên bản đề xuất, đánh dấu chờ duyệt. PO cần chọn: (a) duyệt như ảnh; (b) có áp cho "Khóa học của tôi" và "Tài khoản" không (đang áp); (c) lưới ở giao diện tối có cần rõ hơn không (đang rất nhạt, 1,1:1).
13. **OTP**: đồng ý dùng một ô nhập vẽ thành 6 ô (`OtpInput` v2, dán được, đọc màn hình là một ô) thay cho 6 ô rời của v1? (Admin MFA đã dùng.)
14. **Đặt lại mật khẩu**: "Gửi lại mã" ở bước 2 phải qua captcha (gọi lại `forgot`) — chấp nhận Turnstile chế độ ẩn/managed, hay cho bước 2 một endpoint gửi lại không captcha (cần Architect)?
15. **Màn chặn**: hiện ở trang chi tiết khóa dạng **hộp thoại** (giữ ngữ cảnh) và dạng trang đầy đủ khi vào thẳng checkout — đồng ý? Học sinh chỉ khai SĐT phụ huynh (không email) thì không gửi được email xác nhận — cần câu chữ/luồng riêng (chờ US-017).
16. **Phiên**: `SESSION_REVOKED` (đổi mật khẩu/email) hiện **hộp thoại chặn** giống `SESSION_REPLACED` khi đang học/làm quiz (bản xem trước), hay giữ cách hiện tại của app thật là chuyển thẳng trang đăng nhập? Và có gửi email cảnh báo khi bị thay phiên không (câu hỏi mở US-014)?
17. **Soạn quiz**: API câu hỏi không cho biết câu đã có lượt làm hay chưa — có muốn Architect thêm `attempts_count`/`has_attempts` vào `QuizQuestionResource` để báo **trước khi lưu** "sửa sẽ tạo bản mới" (hiện chỉ báo sau khi lưu, dựa vào `id` đổi)? Có cần API đổi thứ tự câu (ngoài MVP T21)?

### Quyết định PO 2026-10-07
- Mục 12 (nền ô ly chủ đạo): **duyệt** theo §3.1 và ảnh `oly-sau-*`, áp cho mọi trang khách kể cả "Khóa học của tôi" và "Tài khoản"; không áp trang học video, làm quiz, quản trị.
- Mục 13 (OTP): **đồng ý** một ô nhập vẽ thành 6 ô (`OtpInput` v2).
- Mục 14 (gửi lại mã ở bước 2 đặt lại mật khẩu): **Turnstile chế độ ẩn**, không thêm endpoint.
- Mục 16 (phiên): `SESSION_REPLACED` và `SESSION_REVOKED` đều hiện **hộp thoại báo lý do** kèm nút "Đăng nhập lại" (thay cho chuyển thẳng trang đăng nhập).
- Mục 15, 17 và câu hỏi email cảnh báo khi bị thay phiên: chưa quyết, dùng mặc định của bản xem trước.

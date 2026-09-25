# Hệ thống thiết kế — VitaminVui (Website bán khóa học Toán 6–12)

**Stack đã chốt (PO 2026-09-25, `CLAUDE.md`, `docs/architecture/README.md`, ADR-004):** backend Laravel 13 chỉ làm API JSON; giao diện là **2 app Next.js (App Router) + React + TypeScript + Tailwind CSS**, tách origin:
- **Web học sinh** — `vitaminvui.vn` (SEO, mobile-first).
- **Quản trị** — `admin.vitaminvui.vn` (origin riêng, không dùng chung cookie/CSS với web học sinh, có thêm MFA + idle timeout theo ADR-004 §2.2).

Component trong tài liệu này đặt tên theo quy ước **React/TSX (PascalCase)**, chia theo `frontend/packages/ui` (dùng chung 2 app) và component riêng của từng app (xem mục 5). Mockup tĩnh trong `docs/design/mockups/` dùng HTML + Tailwind CDN, markup cố tình gần cấu trúc component thật (section/div lồng theo đúng cây component) để Dev chuyển đổi sang JSX nhanh; đây **không phải** code Blade — chỉ là công cụ trình bày tĩnh, mở trực tiếp bằng trình duyệt được.

---

## 1. Đối tượng người dùng & nguyên tắc thiết kế

- Người dùng chính: học sinh 11–18 tuổi, phần lớn thao tác trên điện thoại → **mobile-first**, tối ưu từ màn hình 375px, chữ đủ lớn (tối thiểu 14px body, 16px cho input để tránh zoom trên iOS), vùng chạm tối thiểu 44x44px.
- Giao diện thân thiện, trẻ trung nhưng vẫn đáng tin cậy (liên quan tới tiền — thanh toán, mã giảm giá).
- Luôn thiết kế đủ 6 trạng thái cho mỗi màn hình có dữ liệu động: **mặc định, đang tải (loading), rỗng (empty), lỗi (error), thành công (success), không có quyền (403/khóa)**.
- Hành động nguy hiểm (xoá, huỷ, hoàn tiền, khoá tài khoản, xoá/ẩn danh tài khoản) luôn có hộp xác nhận (`<ConfirmModal>`), nêu rõ hậu quả không thể hoàn tác.
- Copy tiếng Việt ngắn gọn, nhất quán: dùng **"Lưu", "Huỷ", "Xoá", "Áp dụng", "Xác nhận", "Đăng ký", "Đăng nhập", "Vào học"** — không lẫn tiếng Anh hoặc biến thể khác.
- Mọi input bắt buộc có dấu `*` đỏ cạnh label; lỗi validate hiển thị ngay dưới field bằng chữ đỏ (`text-rose-600 text-sm`), kèm icon cảnh báo; khi submit lỗi, dữ liệu đã nhập được giữ nguyên trên form.

## 2. Bảng màu (Tailwind palette)

| Vai trò | Màu chủ đạo | Dùng cho |
|---|---|---|
| Primary | `indigo-600` (hover `indigo-700`, nền nhạt `indigo-50`) | Nút hành động chính, link, header active |
| Accent/Năng lượng | `amber-500` | Nhãn "Miễn phí" khi cần nổi bật, huy hiệu ưu đãi, icon vitamin/mascot |
| Success | `emerald-600` (nền `emerald-50`) | Thành công, đã hoàn thành, đã duyệt, đã thanh toán |
| Danger | `rose-600` (nền `rose-50`) | Lỗi, xoá, hoàn tiền, từ chối, hết hạn |
| Warning | `amber-600` (nền `amber-50`) | Đang chờ, sắp hết hạn, cảnh báo |
| Info | `sky-600` (nền `sky-50`) | Thông tin trung tính, đang xử lý/đang xác nhận |
| Neutral | `gray-50`…`gray-900` | Nền, viền, chữ phụ |

Tương phản chữ/nền phải đạt WCAG AA (tối thiểu 4.5:1 cho chữ thường). Không dùng chữ xám nhạt hơn `gray-500` trên nền trắng cho nội dung quan trọng.

## 3. Typography

- Font: `font-sans` (Inter/hệ thống mặc định Tailwind).
- H1 trang: `text-2xl md:text-3xl font-bold`
- H2 khu vực: `text-xl font-semibold`
- H3/tiêu đề thẻ: `text-base font-semibold`
- Nội dung: `text-sm md:text-base text-gray-700`
- Meta/phụ: `text-xs text-gray-500`
- Giá tiền: `font-bold text-indigo-600` (giá hiện tại), giá gốc gạch ngang `line-through text-gray-400 text-sm` khi có giảm giá.

## 4. Bố cục & điều hướng

- **Header công khai/học sinh (desktop)**: logo trái, menu (Danh mục, Khóa học của tôi), ô tìm kiếm, icon giỏ hàng có badge số lượng, avatar/tên tài khoản hoặc nút Đăng nhập/Đăng ký.
- **Bottom tab nav (mobile, đã đăng nhập)**: Trang chủ · Danh mục · Giỏ hàng (badge) · Khóa học của tôi · Tài khoản — cố định đáy màn hình, icon + label, tab hiện tại tô `indigo-600`.
- **Sidebar quản trị (desktop, ≥ lg)**: menu dọc trái theo nhóm (Khóa học, Chương/Bài, Chuyên đề, Đơn hàng, Mã giảm giá, Duyệt đăng ký), thu gọn thành menu hamburger trên mobile/tablet.
- Breadcrumb dùng ở trang chi tiết/quản trị sâu: `Trang chủ / Lớp 9 / Hình học nâng cao`.

## 5. Component React/TSX dùng chung & theo app (tạo mới nếu chưa có)

Theo cấu trúc repo ở `docs/architecture/tasks.md` (FE0): `frontend/packages/ui` chứa component **dùng chung cho cả 2 app**; component chỉ dùng riêng cho nghiệp vụ học sinh đặt trong `apps/web`, riêng nghiệp vụ quản trị đặt trong `apps/admin`. Tên component PascalCase, file `.tsx`.

### 5.1 `packages/ui` — dùng chung web + admin

| Component | Mô tả | Props chính |
|---|---|---|
| `<Button>` | Nút bấm | `variant` (primary/secondary/outline/danger/ghost), `size` (sm/md/lg), `loading`, `disabled` |
| `<Badge>` | Nhãn nhỏ | `variant` (success/warning/danger/info/neutral/free) |
| `<StatusPill>` | Nhãn trạng thái nghiệp vụ (đơn hàng, enrollment, mã giảm giá, tài khoản staff) | `status` |
| `<Alert>` | Thông báo banner | `variant`, `title`, `dismissible` |
| `<Toast>` / `useToast()` | Thông báo nổi góc màn hình (thành công/lỗi ngắn hạn) | `variant`, `message` |
| `<Modal>` | Hộp thoại chung | `title`, `onClose` |
| `<ConfirmModal>` | Hộp xác nhận hành động nguy hiểm (kế thừa `Modal`) | `title`, `description`, `confirmLabel`, `confirmVariant`, `requireReason?` |
| `<Card>` | Khung nội dung bo góc, có shadow nhẹ | `children` |
| `<EmptyState>` | Trạng thái rỗng | `icon, title, description, actionLabel, onAction` |
| `<Pagination>` | Phân trang kiểu length-aware (danh mục, đơn của tôi...) | `currentPage, lastPage, onChange` |
| `<CursorPagination>` | Phân trang kiểu cursor **chỉ Trước/Tiếp** + tổng số bản ghi (danh sách đơn quản trị — DBA #9, không nhảy tới trang N) | `hasPrev, hasNext, total, onPrev, onNext` |
| `<ProgressBar>` | Thanh tiến độ % | `percent, color` |
| `<FormField>` / `<TextInput>` / `<Select>` / `<Textarea>` | Input có label/lỗi/required, dùng với `react-hook-form` | `label, name, error, required, hint` |
| `<OtpInput>` | 6 ô nhập OTP | `length=6, onComplete` |
| `<PasswordInput>` | Input mật khẩu có nút hiện/ẩn | `label, name, error, hint` |
| `<Countdown>` | Đồng hồ đếm ngược (hạn thanh toán MoMo, cooldown gửi lại OTP/email, cooldown "Kiểm tra lại thanh toán" 30s) | `expiresAt`, `onExpire` |
| `<Avatar>` | Ảnh đại diện giáo viên/học sinh | `name, src` |
| `<Breadcrumb>` | Điều hướng cấp | `items` |
| `<DataTable>` | Bảng dữ liệu chuẩn (kèm slot filter, header sticky, trạng thái loading/rỗng) | `columns, rows, isLoading` |
| `<Skeleton>` | Khung xám nhấp nháy khi đang tải, đúng layout thật | `variant` (text/card/table-row) |
| `<TurnstileWidget>` | Widget captcha Cloudflare Turnstile (script chính thức, không cần package — FE0) | `siteKey, onVerify` |
| `<ForcedLogoutOverlay>` | Overlay chặn toàn màn hình khi phiên học sinh bị thay thế/hết hạn/thu hồi (US-014) — gắn 1 lần ở layout gốc web, lắng sự kiện `forced-logout`/`login-required` từ `packages/api-client` | `reason: 'SESSION_REPLACED' \| 'SESSION_EXPIRED' \| 'SESSION_REVOKED'` |
| `<AuditLogTable>` | Bảng nhật ký thao tác, chỉ đọc, có filter (US-016) | `filters, rows, isLoading` |

### 5.2 Riêng `apps/web` (học sinh)

| Component | Mô tả | Props chính |
|---|---|---|
| `<CourseCard>` | Thẻ khóa học trong danh mục/lưới | `title, grade, price, thumbnail, badge` |
| `<BottomNav>` | Thanh điều hướng đáy mobile | `activeTab` |
| `<OrderSummary>` | Tóm tắt giỏ hàng/checkout (tạm tính/giảm giá/tổng) | `items, discount, total` |
| `<PaymentResultState>` | 1 trong các biến thể trạng thái `/checkout/ket-qua` (đang xác nhận, thành công, thất bại, **link hết hạn**, **CHECKOUT_CHANGED**) | `status` |
| `<CheckPaymentButton>` | Nút "Kiểm tra lại thanh toán", tự khoá 30 giây sau khi bấm (giới hạn 1 lần/30s) | `orderCode, onChecked` |
| `<ConsentCheckboxGroup>` | 2 checkbox đồng ý tách riêng khi đăng ký (điều khoản, chính sách dữ liệu — không tick sẵn, US-017) | `values, onChange, errors` |
| `<ParentConsentPendingBanner>` | Banner/màn chặn checkout khi `parent_consent_status = pending`/`revoked`, có nút gửi lại email phụ huynh (US-017) | `status, resendCooldown` |

### 5.3 Riêng `apps/admin` (quản trị)

| Component | Mô tả | Props chính |
|---|---|---|
| `<AdminSidebar>` | Sidebar quản trị, menu theo vai trò | `role` |
| `<VideoSourceToggle>` | Toggle "Tải video lên" / "Dán link ngoài" (link ngoài chỉ bật khi bài đang bật "Cho xem thử") | `value, isPreviewOnly` |
| `<VideoStatusBadge>` | Trạng thái xử lý video: đang tải lên / đang xử lý / sẵn sàng / lỗi | `status` |
| `<MultiSelect>` | Chọn nhiều (giáo viên phụ trách, chuyên đề, phạm vi mã giảm giá) | `options, selected` |
| `<ChapterLessonTree>` | Cây chương/bài kéo-thả (`@dnd-kit`) | `chapters, onReorder` |
| `<FileUpload>` | Vùng kéo-thả chọn ảnh (JPG/PNG/WebP, không nhận SVG/GIF) | `accept, maxSizeMb, onSelect` |
| `<TusVideoUpload>` | Vùng tải video lên qua TUS + thanh tiến trình | `endpoint, headers` |
| `<DateRangePicker>` | Chọn khoảng ngày (bắt buộc ở bộ lọc đơn quản trị, ≤ 366 ngày) | `from, to, onChange` |
| `<OrderTimeline>` | Timeline lịch sử trạng thái 1 đơn | `logs` |
| `<PiiMaskedText>` | Hiển thị email/SĐT đã che ở danh sách, có thể yêu cầu hiện đầy đủ ở màn chi tiết (ghi audit `order.view_pii`) | `masked, full?, revealed` |
| `<ExportOptionsModal>` | Modal xuất file: chọn định dạng, **mặc định không kèm liên hệ**; ô "Kèm email/SĐT" chỉ hiện khi vai trò Admin và bắt buộc nhập lý do | `role, onSubmit` |
| `<MfaOtpForm>` | Form nhập OTP MFA sau khi Admin/QLT đăng nhập đúng mật khẩu (US-016) | `onSubmit, resendCooldown` |
| `<ForcePasswordChangeForm>` | Form buộc đổi mật khẩu lần đầu (`must_change_password`) | `onSubmit` |

## 6. Bảng trạng thái UI chuẩn (áp dụng chung)

| Trạng thái | Hiển thị | Copy mẫu |
|---|---|---|
| Đang tải | `<Skeleton>` xám nhấp nháy đúng khung layout thật | (không có chữ, hoặc "Đang tải…") |
| Rỗng | `<EmptyState>` với icon + mô tả + nút hành động gợi ý | "Chưa có dữ liệu" tuỳ ngữ cảnh |
| Lỗi hệ thống | `<Alert variant="danger">` trên đầu trang/form | "Đã có lỗi xảy ra, vui lòng thử lại sau." |
| Lỗi validate | Chữ đỏ dưới field (`<FormField error>`) | Tuỳ field, ví dụ "Email đã được sử dụng" |
| Thành công | `<Toast variant="success">` hoặc `<Alert variant="success">` | "Đã lưu thành công" |
| Không có quyền | Trang 403 riêng hoặc `<Alert variant="warning">` | "Bạn không có quyền truy cập trang này." |
| Phiên hết hiệu lực (học sinh) | `<ForcedLogoutOverlay>` — thông điệp khác nhau theo `code` (`SESSION_REPLACED`/`SESSION_EXPIRED`/`SESSION_REVOKED`, xem US-014 §3) | Xem US-014 |

## 7. Sitemap tổng thể

### 7.1 Khách / Học sinh (public + student area) — app `web`, domain `vitaminvui.vn`
```
/                               Trang chủ
/dang-ky                        Đăng ký (US-001, US-017 — 2 checkbox đồng ý + captcha)
/xac-thuc-otp                   Xác thực OTP (US-001)
/dang-nhap                      Đăng nhập (US-001)
/quen-mat-khau                  Quên mật khẩu — nhập email/SĐT + captcha (US-015)
/quen-mat-khau/dat-lai          Nhập OTP + mật khẩu mới (US-015)
/khoa-hoc                       Danh mục khóa học (US-002) — filter lớp/chuyên đề/từ khóa/sắp xếp
/lop-{grade}                    Trang danh mục theo lớp, SEO (US-002)
/khoa-hoc/{slug}                Chi tiết khóa học (US-003)
/gio-hang                       Giỏ hàng + mã giảm giá (US-004)
/checkout                       Thanh toán — tóm tắt + nút MoMo (US-005)
/checkout/ket-qua                Màn hình chờ xác nhận / kết quả sau khi MoMo redirect, kể cả link hết hạn & CHECKOUT_CHANGED (US-005)
/tai-khoan/don-hang              Đơn hàng của tôi (US-005 AC7)
/tai-khoan/doi-mat-khau          Đổi mật khẩu khi đang đăng nhập (US-015)
/tai-khoan/quyen-du-lieu-ca-nhan Xem trạng thái đồng ý, tải dữ liệu, xoá tài khoản (US-017, US-018)
/tai-khoan/khoa-hoc-cua-toi       Khóa học của tôi + tiến độ (US-008)
/hoc/{course}/bai/{lesson}        Trang học video (US-006)
/hoc/{course}/quiz/{quiz}         Làm bài trắc nghiệm (US-007)
/hoc/{course}/quiz/{quiz}/ket-qua Kết quả quiz (US-007)
/xac-nhan-phu-huynh/{token}       Trang công khai phụ huynh xác nhận/rút đồng ý, KHÔNG cần đăng nhập (US-017)
(thông báo toàn cục ForcedLogoutOverlay) (US-014)
```

### 7.2 Quản trị (Admin / Quản lý trang / Giáo viên) — app `admin`, domain riêng `admin.vitaminvui.vn`
```
/dang-nhap                               Đăng nhập quản trị + bước MFA OTP email (Admin/QLT) + buộc đổi mật khẩu lần đầu (US-016)
/quan-tri/khoa-hoc                       Danh sách khóa học (US-009)
/quan-tri/khoa-hoc/tao                   Tạo khóa học (US-009)
/quan-tri/khoa-hoc/{id}/sua              Sửa khóa học + tab Chương/Bài (US-009)
/quan-tri/chuyen-de                      Quản lý chuyên đề CRUD (US-011)
/quan-tri/don-hang                       Danh sách đơn hàng + filter + xuất file (cursor Trước/Tiếp) (US-010)
/quan-tri/don-hang/{id}                  Chi tiết đơn hàng + hoàn tiền (US-010)
/quan-tri/ma-giam-gia                    Danh sách mã giảm giá (US-013)
/quan-tri/ma-giam-gia/tao                Tạo/sửa mã giảm giá (US-013)
/quan-tri/khoa-hoc/{id}/duyet-dang-ky    Duyệt đăng ký khóa học miễn phí (US-012)
/quan-tri/tai-khoan                      Quản lý tài khoản staff: danh sách, tạo, khoá/mở khoá, đặt lại mật khẩu (US-016, chỉ Admin)
/quan-tri/nhat-ky                        Nhật ký thao tác (audit log), chỉ đọc (US-016, chỉ Admin)
```
Ghi chú miền: mọi route trên chạy dưới origin `admin.vitaminvui.vn` — không dùng chung cookie/localStorage với web học sinh (ADR-004 §2.2). `EnsureAdminOrigin` chặn gọi API quản trị từ origin khác.

### 7.3 Luồng chính (happy path)
1. **Mua khóa học có phí**: Danh mục (US-002) → Chi tiết khóa học (US-003) → Thêm vào giỏ (US-004) → Áp mã giảm giá (US-004) → Checkout (US-005) → Chuyển sang MoMo → Redirect về màn "Đang xác nhận thanh toán" → (IPN xử lý nền) → Thành công → Vào học (US-006) → Làm quiz (US-007) → Theo dõi tiến độ (US-008).
2. **Đăng ký khóa học miễn phí**: Chi tiết khóa học (US-003, giá 0) → bấm "Đăng ký" → trạng thái "Đang chờ duyệt" (US-012) → Giáo viên/Admin duyệt → email/thông báo → học sinh vào học (US-006).
3. **Đăng ký tài khoản**: Form đăng ký (kèm điều kiện phụ huynh nếu <18 tuổi, mã giới thiệu) → tự đăng nhập → xác thực OTP → có thể duyệt danh mục ngay, phải xác thực OTP trước khi mua.
4. **Đăng xuất cưỡng bức**: Học sinh đăng nhập thiết bị B → thiết bị A thao tác tiếp theo → chặn + thông báo → yêu cầu đăng nhập lại (US-014).
5. **Quản trị nội dung**: Admin/Giáo viên tạo khóa học (draft) → thêm chương/bài (US-009) → Admin xuất bản → xuất hiện ở danh mục (US-002).
6. **Vận hành đơn hàng**: Admin xem danh sách đơn (US-010) → lọc theo trạng thái/thời gian (cursor Trước/Tiếp) → xuất file (chạy nền, mặc định không kèm liên hệ) → xử lý khiếu nại → đánh dấu hoàn tiền → enrollment bị thu hồi.
7. **Học sinh dưới 18 tuổi đăng ký**: Form đăng ký (2 checkbox đồng ý + captcha, ≥1 liên hệ phụ huynh) → tự đăng nhập → xác thực OTP tài khoản → `parent_consent_status=pending` → chặn checkout/đăng ký miễn phí, hiện màn "Đang chờ phụ huynh xác nhận" (US-017) → phụ huynh mở email, bấm link công khai `/xac-nhan-phu-huynh/{token}` (không cần tài khoản) → xác nhận → học sinh checkout được ngay.
8. **Quên/đổi mật khẩu học sinh**: `/dang-nhap` → "Quên mật khẩu?" → `/quen-mat-khau` (email/SĐT + captcha) → OTP qua email → `/quen-mat-khau/dat-lai` (OTP + mật khẩu mới) → mọi phiên khác bị huỷ (US-015).
9. **Đăng nhập quản trị**: `admin.vitaminvui.vn/dang-nhap` → Admin/QLT nhập đúng mật khẩu → màn MFA (OTP email) → (nếu `must_change_password`) buộc đổi mật khẩu → vào hệ thống. Giáo viên vào thẳng, chỉ nhận email cảnh báo nếu thiết bị mới (US-016).
10. **Học sinh tự phục vụ dữ liệu cá nhân**: `/tai-khoan/quyen-du-lieu-ca-nhan` → "Tải dữ liệu của tôi" (JSON) hoặc "Xoá tài khoản" → OTP xác nhận → tài khoản bị ẩn danh hoá, đăng xuất (US-018).

## 8. Các điểm "chờ PO xác nhận" cần đánh dấu rõ trong mockup

Các phần dưới đây vẫn được thiết kế đầy đủ theo story hiện tại nhưng gắn nhãn rõ để dễ bỏ/điều chỉnh khi PO chốt phương án khác:

1. **Trường "Mã giới thiệu" ở form đăng ký (US-001)** — mục đích dùng (chỉ lưu vết hay có cơ chế thưởng) chưa chốt. Thiết kế: input không bắt buộc, helper text "Nếu có, nhập mã của người giới thiệu bạn", đánh dấu `⚠ Chờ PO xác nhận mục đích sử dụng`.
2. **Đồng hồ đếm ngược khi làm quiz (US-007)** — story đã có BR6/AC7 mô tả countdown khi quiz có `time_limit_minutes`, nhưng câu hỏi mở là quiz có bắt buộc luôn có giới hạn thời gian hay tùy chọn. Thiết kế countdown đầy đủ, đánh dấu `⚠ Chờ PO xác nhận: mọi quiz có bắt buộc giới hạn thời gian không`.
3. **Cách tính lượt dùng mã giảm giá (US-013 BR7)** — BA diễn giải "chỉ trừ lượt khi đơn `paid`", chưa được PO xác nhận chính thức. UI mã giảm giá ở giỏ hàng/checkout thiết kế theo diễn giải này (không "giữ chỗ" lượt dùng), đánh dấu `⚠ Chờ PO xác nhận cách tính lượt dùng`.
4. **MFA quản trị (US-016 BR3)** — OTP email bắt buộc mỗi lần đăng nhập cho Admin/QLT (`FEATURE_STAFF_MFA`, mặc định bật); GV chỉ nhận email cảnh báo thiết bị mới, không bắt buộc MFA. Đánh dấu `⚠ Chờ PO xác nhận`.
5. **Ngưỡng tuổi cần phụ huynh xác nhận (US-017 BR3)** — mặc định dưới 18 tuổi (`privacy.parent_consent_age = 18`), chặn checkout/đăng ký miễn phí tới khi có xác nhận. Đánh dấu `⚠ Chờ PO/pháp chế xác nhận`.
6. **Phân trang đơn quản trị (US-010, DBA #9)** — cursor Trước/Tiếp + tổng số bản ghi, không nhảy tới trang N (bảng có thể tới ~1 triệu dòng). Đánh dấu `⚠ Chờ PO xác nhận` (mặc định an toàn đang áp dụng theo README §8 mục 12).
7. **Xuất file đơn hàng kèm liên hệ (US-010, S14)** — mặc định không kèm email/SĐT; chỉ Admin được chọn kèm và bắt buộc nhập lý do; Quản lý trang không có quyền này (khác US-009 nơi QLT ngang Admin). Đánh dấu `⚠ Chờ PO xác nhận`.

## 9. Câu hỏi cần xác nhận (ngoài các điểm trên)

- [ ] Thời hạn/số lần gửi lại OTP cụ thể (US-001, US-015) — ảnh hưởng copy nút "Gửi lại mã" có đếm ngược hay không.
- [ ] Thời hạn hiệu lực kỹ thuật của link thanh toán MoMo so với mốc 12 giờ (US-005) — **đã có trả lời của Architect (ADR-001 §7):** `GET /orders/{code}` trả thêm `payment.link_expired`; thiết kế UI đã tách biến thể riêng "Link thanh toán đã hết hạn" khác với "Thất bại" (xem US-005 mục 2.2).
- [ ] Mật khẩu mới có được trùng mật khẩu cũ không (US-015 — câu hỏi mở của BA).
- [ ] Kênh gửi mật khẩu khởi tạo cho staff mới tạo: email tự động hay Admin tự gửi thủ công (US-016 — câu hỏi mở của BA); mockup hiện thiết kế theo phương án "hiển thị 1 lần cho Admin sao chép" (an toàn hơn, không phụ thuộc email đến tay đúng người).
- [ ] Phụ huynh bấm "Từ chối" trên trang xác nhận có nút riêng hay chỉ có "Đồng ý" (US-017 — câu hỏi mở của BA); mockup hiện có cả 2 nút, cần PO xác nhận có giữ nút "Từ chối" không.

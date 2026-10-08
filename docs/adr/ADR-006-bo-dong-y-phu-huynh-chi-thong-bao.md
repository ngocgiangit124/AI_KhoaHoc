# ADR-006: Bỏ đồng ý của phụ huynh, chỉ gửi thông báo; quyền dữ liệu cá nhân tự phục vụ

**Trạng thái:** Proposed (thiết kế 2026-10-08 theo quyết định PO cùng ngày; chờ PO duyệt các mặc định ở mục "Điểm chờ PO")
**Liên quan:** US-017, US-018, task T29, T34, FW1, FW7; data-model §3.1, §7; api-contract §2.2, §2.8; ADR-003 (một phiên học sinh), ADR-005 (`TeacherProfileService::erase()`)
**Thay thế một phần:** US-017 BR3–BR10, AC3–AC9 (luồng phụ huynh xác nhận/rút lại); README §8 mục 15; api-contract §2.8 bản "hợp đồng dự kiến"

## Bối cảnh
Thiết kế cũ (US-017) yêu cầu phụ huynh của học sinh dưới 18 tuổi bấm link ký 72 giờ để đồng ý. Trước khi có đồng ý, học sinh bị chặn checkout và đăng ký miễn phí (middleware `parent.consent`, `users.parent_consent_status = pending`). Code hiện tại mới làm một phần:
- `RegisterRequest` bắt buộc ≥ 1 liên hệ phụ huynh khi dưới `privacy.parent_consent_age` (18).
- `RegistrationService` đặt `parent_consent_status = pending` cho học sinh dưới 18 tuổi. Vì vậy dữ liệu hiện có đã có dòng `pending`, và FE (`AccountBanner`, `AccountGateRoute`) đang hiện banner "chờ phụ huynh" cho các tài khoản này.
- `EnsureParentConsent` nằm sau cờ `features.parent_consent_enforced` (mặc định tắt), nên hiện chưa chặn ai. Chưa có trang phụ huynh, mail hay endpoint resend.

**Quyết định của PO ngày 2026-10-08:**
- Không cần phụ huynh đồng ý. Học sinh ở mọi độ tuổi đăng ký, mua và học bình thường.
- Hệ thống chỉ **gửi thông báo** cho phụ huynh khi có email phụ huynh.
- Thông tin phụ huynh **không bắt buộc**.
- Không có luồng phụ huynh rút đồng ý.
- Học sinh tải dữ liệu cá nhân tối đa 2 lần/ngày.
- Nội dung chính sách dùng bản TẠM, sẽ thay khi pháp chế gửi bản chính thức.
- Không chuyển dữ liệu ra nước ngoài (board, "Chờ PO trả lời" mục 4).

## Các phương án

### 1. Cột `users.parent_consent_status` và field `parent_consent_status` trong API
| Phương án | Ưu | Nhược |
|---|---|---|
| A. Xoá cột và field ngay | Sạch | Field nằm trong shape `user` của API v1 (register/login/`/auth/me`). Theo api-contract §1.1, xoá field là thay đổi phá vỡ, phải lên v2. Đồng thời vi phạm quy tắc "không xoá cột đang dùng trong cùng release" |
| **B. Giữ cột và field, backfill mọi dòng về `not_required`, từ nay luôn ghi `not_required`** | Tương thích v1. FE cũ tự hết banner "chờ phụ huynh". Migration rẻ | Còn một cột "chết". Xoá ở release sau (backlog T29-1) |
| C. Giữ cờ `parent_consent_enforced` để bật lại khi cần | Có đường lui nếu pháp chế đổi ý | Giữ code chết, và bật nhầm cờ sẽ khoá mọi học sinh cũ (dữ liệu đã backfill `not_required`, nhưng code mới luôn ghi `not_required`) |

### 2. Middleware `parent.consent`
- Giữ ở dạng luôn cho qua: route vẫn mang alias không có tác dụng, người đọc dễ hiểu sai.
- **Xoá hẳn** class `EnsureParentConsent`, alias, cờ `features.parent_consent_enforced` (cùng `FEATURE_PARENT_CONSENT_ENFORCED` trong `.env.example` và mẫu production), và gỡ khỏi route checkout. Mã lỗi `PARENT_CONSENT_REQUIRED` vẫn để trong bảng mã của api-contract với ghi chú "không còn phát ra", để FE cũ không vỡ.

### 3. Thông báo cho phụ huynh: gửi khi nào
| Sự kiện | Gửi? | Lý do |
|---|---|---|
| Tạo tài khoản, có `parent_email` | **Có, nhưng chỉ SAU KHI học sinh xác thực OTP lần đầu** (review T29 R1: tài khoản chưa chứng minh được email không được dùng hệ thống làm relay thư tới bên thứ ba). Thư `parent_contact_added` cũng chỉ gửi cho tài khoản đã xác thực | PO yêu cầu |
| Học sinh thêm hoặc đổi `parent_email` sau khi đăng ký | **Có**, gửi tới địa chỉ MỚI | Cùng ý nghĩa với "tạo tài khoản": báo cho người vừa được ghi là phụ huynh |
| Đơn hàng có tiền (`total_amount > 0`) chuyển `paid` | **Có** (chỉ phát sinh khi bật thanh toán V2) | Có giao dịch tiền của người chưa thành niên, phụ huynh cần biết |
| Đơn 0đ, xin học miễn phí, được duyệt học miễn phí | Không | Không có tiền. Gửi quá nhiều thư dễ bị coi là thư rác, phụ huynh sẽ bỏ qua cả thư quan trọng |
| Chỉ có `parent_phone` | Không gửi | Chưa có nhà cung cấp SMS (production chỉ có kênh email) |

### 4. Cách phụ huynh huỷ nhận thông báo
- Không có cách huỷ: gửi thư cho một bên thứ ba chưa từng đồng ý mà không cho từ chối là thực hành xấu. Nếu học sinh nhập email của người lạ, người đó bị làm phiền mãi.
- **Có link huỷ nhận thông báo** (token HMAC, không hết hạn) dẫn tới trang web `/phu-huynh/huy-nhan-thong-bao`. Trang có một nút xác nhận và gọi `POST /parent-notices/unsubscribe`. Thư có thêm header `List-Unsubscribe` + `List-Unsubscribe-Post` (one-click). Opt-out gắn với cặp (học sinh, email phụ huynh hiện tại); đổi email phụ huynh thì `parent_notice_opt_out_at` được đặt lại. **Bổ sung (review T29 R2):** địa chỉ đã huỷ nhận còn được ghi vào danh sách chặn theo địa chỉ `parent_notice_suppressions` (HMAC có khoá, bảng mới), không bị gỡ khi học sinh đổi/xoá/thêm lại email hay tạo tài khoản khác: địa chỉ đó không nhận thư nữa.

### 5. Xuất dữ liệu cá nhân
| Phương án | Ưu | Nhược |
|---|---|---|
| **A. Đồng bộ: `POST` trả file JSON ngay** | Không lưu file PII trên đĩa, không cần job, link ký hay dọn file. Dữ liệu một học sinh nhỏ (vài trăm đến vài nghìn dòng, < 2 MB) | Request dài hơn bình thường (dự kiến < 2 s). Không phù hợp nếu dữ liệu tăng gấp nhiều lần |
| B. Chạy nền, giống `ExportOrdersJob` | Chịu được dữ liệu lớn | File PII nằm trên đĩa, kéo theo signed URL, purge và poll ở FE. Thêm ~1 ngày công cho lợi ích chưa cần |

Định dạng chỉ là JSON (US-018 ngoài phạm vi: không PDF). Dùng `POST` (có CSRF) chứ không dùng `GET`, vì mỗi lần tải bị tính hạn mức và ghi audit: GET có thể bị prefetch hoặc kích hoạt từ trang khác. Hạn mức tính theo ngày lịch giờ Việt Nam. Nguồn đếm là `audit_logs` (đã có IX `(actor_id, created_at)`), kiểm lại dưới khoá dòng `users` nên 2 request song song không vượt trần.

### 6. Xoá tài khoản
- Ẩn danh hoá, không xoá cứng (giữ quyết định data-model §1, §7). Email và SĐT đặt `NULL` nên **được giải phóng** để đăng ký lại (unique index InnoDB cho phép nhiều NULL). Đây là câu hỏi mở BR4 của US-018: chọn giải phóng vì tài khoản đã ẩn danh không còn liên hệ nào để giữ.
- Xác nhận bằng OTP purpose mới `delete_account`, tái dùng `OtpService::issue/consume` (giữ trần gửi/verify sẵn có).
- Chia hai pha:
  - **Pha A**, một transaction đồng bộ do `OtpService::consume` mở và khoá `users`: xoá PII, thu hồi consents, xoá `otp_codes`, gọi `TeacherProfileService::erase()`, ghi audit. Sau commit thì huỷ mọi phiên.
  - **Pha B**, job idempotent: huỷ đơn `pending` không có link thanh toán còn sống, rút yêu cầu học miễn phí `pending_approval`, dọn giỏ. Mỗi bước là một transaction riêng theo đúng đoạn con của thứ tự khoá chuẩn.
- Pha A chạy trước nên học sinh mất phiên ngay và không tạo được giao dịch mới trong lúc pha B chạy.

## Quyết định
1. Bỏ toàn bộ luồng phụ huynh đồng ý: không có trang `/parent-consents/{token}`, không có `POST /me/parent-consent/resend`, không có `ParentConsentService` hay `ParentConsentMail`, không có `OtpPurpose::ParentConsent`.
2. Phương án 1B: giữ cột và field `parent_consent_status`, backfill về `not_required` (migration [DBA]), luôn ghi `not_required`. Xoá cột ở T29-1, khi lên API v2 hoặc khi field được thay bằng hằng.
3. Xoá hẳn middleware `parent.consent` và cờ `parent_consent_enforced` (mục 2).
4. Liên hệ phụ huynh là tuỳ chọn với mọi độ tuổi, thêm/sửa được ở trang tài khoản (`PUT /me/parent-contact`, cần mật khẩu hiện tại). Có thêm thông báo cho phụ huynh theo bảng mục 3, link huỷ nhận theo mục 4, cờ tắt khẩn `features.parent_notices`.
5. `/config/public`:
   - giữ khoá `parent_consent_age` (deprecated; giá trị = `privacy.parent_contact_suggest_age`, 18), FE chỉ dùng để quyết định có **gợi ý** khối phụ huynh hay không;
   - thêm `parent_contact_required: false`.
6. Xuất dữ liệu đồng bộ, JSON, tối đa `privacy.data_export_daily_limit` = 2 lần/ngày lịch (Asia/Ho_Chi_Minh), cần mật khẩu hiện tại (lý do ở mục "Điểm chờ PO").
7. Xoá tài khoản bằng OTP email, chia hai pha như mục 6. Chặn khi còn đơn có link thanh toán đang sống (409 `ACCOUNT_HAS_PENDING_PAYMENT`). Enrollment `active` giữ nguyên (đã mua; tài khoản không còn đăng nhập được).
8. Chính sách TẠM:
   - `privacy.policy_version` đổi sang phiên bản tạm (`2026-10-tam`);
   - văn bản đặt ở FE theo phiên bản;
   - khi đổi phiên bản, `/auth/me.needs_policy_acceptance = true` và học sinh chấp nhận lại qua `POST /me/consents/accept`. Không chặn học hay mua (chỉ hiện banner).
9. Học sinh không rút được đồng ý `terms`/`privacy_policy` riêng lẻ: muốn ngừng xử lý dữ liệu thì xoá tài khoản. Bỏ `POST /me/consents/{type}/revoke` khỏi contract. US-017 và US-018 không có đồng ý marketing (`marketing` ngoài phạm vi), nên chưa có gì để rút.

## Hệ quả
- **Tích cực:** không còn điểm chặn mua/học ngoài `account.verified`. Bớt khoảng 1 ngày công so với T29 cũ (không có trang công khai, token 72h, resend). Không lưu file PII nào trên đĩa.
- **Tiêu cực / rủi ro:**
  - Học sinh có thể nhập email người lạ làm phụ huynh, gây thư không mong muốn. Giảm bằng: trần theo địa chỉ nhận (5 thư/ngày/email), link huỷ, `different:email`, throttle sửa liên hệ.
  - Thông báo thanh toán chỉ có tác dụng khi bật V2: T19 phải gọi `ParentNotifier::orderPaid` trong `markPaid` (đã ghi vào T19).
  - Đơn `pending` có link MoMo còn sống mà tài khoản đã ẩn danh: bị chặn ở bước xoá. T19 vẫn phải xử lý trường hợp IPN về cho tài khoản đã ẩn danh: cấp quyền bình thường, không gửi mail học sinh, chỉ log.
  - FE đang có code cho luồng cũ: `AccountGate` `parent-pending`/`parent-revoked`, trang `/cho-phu-huynh`, `mapFreeEnrollError` `parent_consent`, khối phụ huynh bắt buộc ở `RegisterForm`. FW1 và FW7 phải gỡ (ghi trong tasks.md).
- **Pháp lý:** nội dung chính sách vẫn là bản TẠM. Ngưỡng tuổi, việc có cần phụ huynh đồng ý hay không và quy trình xác minh yêu cầu của phụ huynh vẫn là câu hỏi cho pháp chế. Architect không kết luận. Nếu pháp chế yêu cầu đồng ý của phụ huynh, cần ADR mới; dữ liệu `consents` vẫn đủ chỗ (`granted_by=parent`, `ConsentType::ParentConsent` giữ lại, đánh dấu "không ghi mới").

## Điểm chờ PO (mặc định đang dùng để không chặn dev)
1. Gửi thông báo phụ huynh khi đơn có tiền được thanh toán: **có** (chỉ khi bật V2). Không gửi khi học miễn phí hoặc đơn 0đ.
2. Trang "huỷ nhận thông báo" cho phụ huynh: **có**.
3. Sửa thông tin phụ huynh sau đăng ký: **có**, cần mật khẩu hiện tại.
4. Xuất dữ liệu: **đồng bộ, JSON**, cần mật khẩu hiện tại. Lý do: file chứa email/SĐT phụ huynh chưa che (`/auth/me` chỉ trả bản che). Không có mật khẩu thì người chiếm được phiên tải được hết.
5. Hạn mức "2 lần/ngày": theo **ngày lịch giờ Việt Nam** (đặt lại lúc 00:00). Lần lỗi không tính.
6. Xoá tài khoản: **không** cần mật khẩu (OTP qua email đã đủ). Email/SĐT **được giải phóng**. Đơn có link thanh toán còn sống thì **chặn** (409). Yêu cầu học miễn phí đang chờ thì **tự rút**. Khóa đang học thì giữ nguyên dòng.
7. File xuất **không** gồm nhật ký truy cập/audit (trừ danh sách thông báo đã gửi cho phụ huynh) và **không** gồm đáp án từng câu quiz (chỉ điểm/tổng hợp).
8. Khi ẩn danh: xoá `ip`/`user_agent` trong `consents` của tài khoản đó, giữ loại/phiên bản/thời điểm. `audit_logs` giữ nguyên tới khi `audit:purge` dọn (24 tháng).
   - **Bổ sung sau security T34 (S2, tạm theo đề xuất, chờ PO/pháp chế chốt):** `ip`/`user_agent` trong `audit_logs` của tài khoản đã xoá được GIỮ, tự xoá sau 24 tháng bằng `audit:purge` đã có; không thêm code. Nếu PO chọn xoá ngay thì làm ở pha B, theo lô.
   - Pha B bỏ qua đơn còn link thanh toán sống thì tự phát lại sau 15 phút, tối đa 6 vòng; hết vòng, job huỷ 12h (T20) dọn.
9. Đổi phiên bản chính sách thì **chỉ hiện banner** chấp nhận lại, không chặn mua/học.

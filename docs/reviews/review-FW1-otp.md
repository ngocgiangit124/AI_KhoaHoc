# REVIEW: FW1 phần 2 — Màn `/xac-thuc-otp` (US-001 AC8/AC9)
**Kết luận:** APPROVE
**Phạm vi:** `git -C <worktree> diff claude/zen-dirac-fmucf7...fw1-otp -- frontend docs` (nhánh local `fw1-otp`, đã merge `claude/zen-dirac-fmucf7`@`a65c28b`; gồm commit cloud `7e5fa1f` chưa từng review + `a02b2b9` sửa theo T04 thật) · 18 file, 1131 dòng thêm / 42 xoá.

## Tổng quan
Chất lượng tốt, đáng khen ở phần "đối chiếu giả định với thực tế" — 4 giả định ghi ở `docs/board.md` mục 93 (dạng `resend_available_at`, register không trả field này, FE tự che liên hệ, 429 khoá 24h không có mốc mở khoá) đều được dev tự kiểm lại với `OtpController`/`OtpService` thật trên nhánh chính và ghi thẳng kết quả đối chiếu vào comment code thay vì chỉ giả định suông — đúng tinh thần review T04 §L3 (dùng `resend_available_at` làm nguồn chính, `Retry-After` chỉ phụ). Test (140, bao gồm 12 case mới cho `VerifyOtpForm`) bám sát đúng các nhánh 200/422/429/lỗi mạng, dùng fake timers thật cho countdown thay vì mock thời gian hời hợt. Không thấy XSS/`dangerouslySetInnerHTML`/`localStorage` cho token, không log mã OTP, che email/SĐT chỉ áp dụng cho chính chủ tài khoản (đúng lưu ý phân biệt với che PII người khác ở `MeResource`). Phạm vi đúng những gì `docs/board.md` giao (chỉ `/xac-thuc-otp`, AC9/chặn checkout chưa tới lượt vì `/checkout` chưa tồn tại) — không làm lấn sang phần khác.

Một điểm SHOULD về UX (làm tròn phút cho khoá 24h) và vài NIT nhỏ, không có BLOCKER.

## Phát hiện

### R1 [SHOULD] `formatRetryAfter()` hiển thị sai đơn vị khi khoá xác thực 24h ("khoảng 1440 phút")
- Vị trí: `frontend/apps/web/app/xac-thuc-otp/VerifyOtpForm.tsx:48-54` (`formatRetryAfter`)
- Vấn đề: hàm chỉ có 2 nhánh — dưới 60 giây hiện giây, còn lại luôn hiện phút (`Math.ceil(retryAfterSeconds / 60)`). Đối chiếu backend thật: `otp-verify` có trần 20 lần/ngày (`AppServiceProvider.php:148-160`, `Limit::perDay`), khi vượt thì Laravel `ThrottleRequests` trả `Retry-After` bằng số giây còn lại tới hết cửa sổ ngày — có thể lên tới ~86400 giây. Với input đó, `formatRetryAfter(86400)` trả `"khoảng 1440 phút"`, người dùng phải tự quy đổi ra giờ/ngày trong đầu. Đây đúng là trường hợp `docs/security/review-T04.md` L3 mô tả ("khoá xác thực 24h") mà FW1 cần hiển thị cho người dùng thật.
- Đề xuất: thêm bậc giờ/ngày, ví dụ:
  ~~~ts
  function formatRetryAfter(retryAfterSeconds: number): string {
    if (retryAfterSeconds < 60) return `khoảng ${Math.ceil(retryAfterSeconds)} giây`;
    if (retryAfterSeconds < 3600) return `khoảng ${Math.ceil(retryAfterSeconds / 60)} phút`;
    if (retryAfterSeconds < 86400) return `khoảng ${Math.ceil(retryAfterSeconds / 3600)} giờ`;
    return `khoảng ${Math.ceil(retryAfterSeconds / 86400)} ngày`;
  }
  ~~~
  Nên thêm 1 test case cho nhánh giờ/ngày (hiện `VerifyOtpForm.test.tsx` chỉ có case `Retry-After: 120` → phút, chưa có case ≥ 3600s).

### R2 [NIT] `computeFallbackResendAvailableAt`/nhánh 429 ở `handleResend` không kiểm tra unmount trước khi `setState`
- Vị trí: `VerifyOtpForm.tsx` — `handleVerify`/`handleResend` không dùng cờ `cancelled` như effect load `/auth/me` (dòng ~76-100) đã làm.
- Vấn đề: nếu người dùng điều hướng đi trong lúc `authFetch` đang chờ (verify/resend), `setState` sau khi unmount chỉ in warning ở dev, không gây lỗi thực tế vì các hành động này luôn do người dùng bấm (không tự chạy lại nền), nhưng để nhất quán với effect load `/auth/me` thì nên áp dụng cùng pattern hoặc bọc bằng `AbortController`.
- Đề xuất: không bắt buộc sửa ngay, có thể để dành khi refactor chung các form khác cùng pattern.

### R3 [NIT] `maskEmail` hiện toàn bộ phần local khi ≤ 3 ký tự
- Vị trí: `frontend/apps/web/lib/auth/maskContact.ts:10-16`
- Vấn đề: với email dạng `ab@x.com`, `visible = local.slice(0, min(3, len))` = `"ab"` → hiện nguyên văn phần trước `@`. Không phải lỗ hổng (chỉ hiển thị cho chính chủ tài khoản, không phải che PII người khác), nhưng hơi lệch tinh thần "che một phần" của mockup khi local part ngắn. Chấp nhận được, chỉ ghi nhận NIT.

## Đối chiếu acceptance criteria
| AC | Code đáp ứng | Ghi chú |
|---|---|---|
| AC8 (nhập đúng OTP trong hạn → verified, mua khoá học được) | Có | `VerifyOtpForm.handleVerify` gọi `POST /auth/otp/verify`, thành công → `notifyAuthChanged()` + điều hướng `/`; `is_verified` cập nhật qua `GET /auth/me`. Enforcement thật ở phía checkout (`account.verified` middleware) chưa gắn route nào — đúng phạm vi, ghi nhận ở `docs/security/review-T04.md` I2, không thuộc FW1 phần 2. |
| AC9 (chặn checkout khi chưa xác thực, có nút gửi lại OTP) | Chưa (đúng phạm vi) | `/checkout` (US-005) chưa tồn tại; `docs/board.md` dòng 16 xác nhận phạm vi FW1 phần 2 chỉ là `/xac-thuc-otp`. Màn "Cần xác thực tài khoản" (design §2.4) để dành task sau. |
| Banner nhắc xác thực toàn site (US-001 §2.4 liên quan) | Có | `SiteHeader.tsx` thêm banner amber khi `user.is_verified === false`, ẩn khi `undefined`/`true` — đúng thiết kế "không nháy sai khi field vắng". |
| Đếm ngược gửi lại mã + khoá nút trong lúc chờ (design §2.2) | Có | `Countdown` + `resendAvailableAt`; fallback khi chưa có mốc thật từ server (`register` không trả `resend_available_at`) dùng `otp.resend_cooldown_seconds` từ `config/public` — đúng khuyến nghị T04 §L3. |
| 429 hiển thị hợp lý, không dựa `Retry-After` làm nguồn chính | Có (trừ R1) | Đúng theo khuyến nghị `docs/board.md` mục 8: `resend_available_at` là nguồn chính, `Retry-After` chỉ phụ trợ hiển thị. |
| Không lộ OTP qua log/state thừa | Có | Không thấy `console.log` mã hay lưu OTP ngoài state cục bộ `code`; xoá `code` sau lỗi 422/429. |

## Gợi ý cho QA
- Test tay case khoá 24h thật (verify sai 5 lần liên tục qua nhiều mã, hoặc set `max_verify_per_day` thấp ở env test) để thấy trực tiếp câu chữ "khoảng 1440 phút" trước khi R1 được sửa — xác nhận đúng là vấn đề thật, không chỉ lý thuyết.
- Kiểm luồng "đăng ký → tự động vào `/xac-thuc-otp` → thoát giữa chừng → quay lại trang chủ → banner nhắc xác thực → bấm lại vào `/xac-thuc-otp`" để chắc `resendAvailableAt` ước lượng (fallback) không lệch quá xa cooldown thật khi mở lại trang sau một khoảng thời gian dài.
- Kiểm autofill OTP qua SMS/WebOTP trên trình duyệt di động thật (Android Chrome) — `autoComplete="one-time-code"` chỉ gắn ở ô đầu tiên, cần xác nhận hành vi dán nhiều ký tự vào 1 ô (`handleChange` nhánh `raw.length > 1`) hoạt động đúng trên thiết bị thật, không chỉ trong test giả lập `userEvent`.
- Kiểm 401 giữa chừng khi đang gõ OTP (phiên hết hạn) — xác nhận `ForcedLogoutOverlay` chuyển hướng đúng `/dang-nhap?next=/xac-thuc-otp` như comment mô tả (cơ chế nằm ngoài diff này, nhưng đây là điểm tích hợp mới dùng tới nó).

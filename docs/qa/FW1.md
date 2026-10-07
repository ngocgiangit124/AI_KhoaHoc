# QA: FW1 (màn xác thực web học sinh, design v2)
**Kết quả:** PASS (không có Critical/High/Major; 1 Minor, 2 ghi chú)
**Ngày:** 2026-10-07 · QA: laravel-qa · Phạm vi: `frontend/apps/web/**`, `packages/ui/src/TurnstileWidget.tsx` (đã gồm sửa R14 ở `LoginForm.tsx`).

## Môi trường kiểm
- tsc (`pnpm.sh --filter @vitaminvui/web exec tsc --noEmit`): sạch. Lint (`eslint .`): sạch. Unit web: 25 file / 221 test pass.
- **Build production riêng**: rsync `frontend/` (bỏ `.next`, `.pnpm-store`, `apps/admin`) sang thư mục scratchpad, `next build` với `NODE_OPTIONS=--max-old-space-size=1536` và `NEXT_PUBLIC_TURNSTILE_SITE_KEY=1x00000000000000000000AA` (key test Cloudflare). Build thành công (Proxy/CSP nonce hoạt động ở `NODE_ENV=production`). Không đụng `frontend/apps/web/.next` của dev server, không restart dev server, không `compose up`.
- E2E: Playwright trong Docker, backend thật, `next start` trong container Playwright (cổng 3000 của container, tách khỏi dev server host), `--workers=1 --retries=0`, seed bằng `seed-e2e-auth.sh` + `seed-e2e-catalog.sh`.
- Máy tải nặng (load 6 đến 26 do agent khác chạy CI backend). Một lần chạy bị treo 12 phút ở test đầu (form chưa hydrate), chạy lại riêng thì pass: nhiễu do tải, không phải lỗi ứng dụng.

## Độ phủ
| Hạng mục | Test | Kết quả |
|---|---|---|
| Đăng ký (đủ tuổi, <18 + phụ huynh, trùng, xác nhận mật khẩu, 2 đồng ý, rỗng, HTML trong tên) | `auth.spec.ts` | PASS |
| Đăng nhập email/SĐT, sai thông tin không lộ tài khoản, WRONG_PORTAL | `auth.spec.ts` | PASS |
| Open redirect `next` (https://, //, /\\, javascript:, %2F%2F) về trang chủ, cùng origin | `auth.spec.ts`, `fw1-qa-final.spec.ts` | PASS |
| CSP: Cloudflare chỉ ở connect/frame-src của route captcha; không violation ở /dang-ky, /dang-nhap | `auth.spec.ts` | PASS |
| R1: từ /dang-nhap bấm "Quên mật khẩu?", "Đăng ký ngay", nút "Đăng ký" ở header: không CSP violation, Turnstile cấp token, nút mở khoá | `fw1-v2-qa.spec.ts` | PASS |
| Nút "Đăng ký" header từ trang chi tiết khóa học | `fw1-qa-final.spec.ts` | PASS |
| R14: `/dang-nhap?next=/dang-ky` sau đăng nhập tải cứng, Turnstile cấp token, không CSP violation | `fw1-qa-final.spec.ts` | PASS |
| OTP (một ô 6 hộp): đúng/sai/5 lần khoá/gửi lại/cooldown/429/F5/đổi liên hệ/lỗi mạng /auth/me | `otp.spec.ts` (+375px) | PASS |
| Quên + đặt lại mật khẩu 2 bước, mật khẩu phổ biến, mã sai giữ mật khẩu, Gửi lại mã (Turnstile ẩn), vào thẳng bước 2, đổi mật khẩu | `password.spec.ts` | PASS |
| Không lộ tài khoản: email có/không tồn tại cho cùng thông điệp ở "Quên mật khẩu" (so toàn văn trang bước 2, bỏ đếm ngược); email không nằm trên URL | `fw1-qa-final.spec.ts`, `password.spec.ts` | PASS |
| SESSION_REPLACED/REVOKED: hộp thoại không đóng bằng Esc/nền; tự đóng khi sang trang đăng nhập; giữ `?next` | `session.spec.ts`, `fw1-v2-qa.spec.ts` | PASS |
| Hộp thoại không đóng khi bấm Back (điều hướng mềm từ danh mục sang chi tiết, reload, Back, Esc) | `fw1-qa-final.spec.ts` | PASS |
| Màn chặn `/can-xac-thuc`, `/cho-phu-huynh`, hộp thoại tại chỗ ở chi tiết khóa | `fw1-v2-qa.spec.ts` | PASS |
| Nền ô ly (`bg-oly-page`) mọi trang khách; trang pháp lý không 404 | `fw1-v2-qa.spec.ts` | PASS |
| 375px: không cuộn ngang (/, /khoa-hoc, /dang-nhap, /dang-ky, /quen-mat-khau, /dieu-khoan, OTP); vùng chạm >= 44px cho nút, ô nhập, liên kết đứng riêng ở /dang-nhap, /dang-ky, /quen-mat-khau | `fw1-v2-qa.spec.ts`, `otp.spec.ts`, `fw1-qa-final.spec.ts` | PASS (trừ logo, xem BUG-1) |

Tổng e2e thật: 69 pass + 2 skip (2 test chụp ảnh `fw-v2-qa` cố ý skip) ở lượt chạy toàn bộ; `fw1-qa-final.spec.ts` 8/8 pass (1 test chạy lại riêng do nhiễu tải).
Test mới thêm: `frontend/apps/web/e2e/fw1-qa-final.spec.ts`.

## Bug phát hiện
### BUG-1: Logo "VitaminVui" ở header cao 39px ở 375px
- Mức độ: Minor
- Bước tái hiện: mở `/dang-nhap` ở viewport 375px, đo `header a` logo: 150x39 px.
- Mong đợi: vùng chạm >= 44px (design §6). Thực tế: 39px chiều cao.
- Vị trí nghi ngờ: component header dùng chung (`components/shell/ShellHeader.tsx` / `SiteHeader` trong `packages/ui`), không riêng FW1. Bản gốc ngoài phạm vi FW1; ghi backlog.

## Ghi chú và rủi ro
- Đăng ký với email/SĐT trùng hiện lỗi dưới field (auth.spec AC2): đúng theo story/contract (đăng ký không phải luồng chống enumerate; chỉ forgot/login/reset giữ thông điệp chung). Chống dò bằng throttle 30/giờ/IP + Turnstile ở backend.
- Chưa kiểm được trường hợp Cloudflare buộc tương tác (key test luôn tự qua): widget ẩn ở "Gửi lại mã" bước 2 có `onError` + hướng dẫn (đã review, chưa thấy bằng mắt).
- Hộp thoại phiên chưa dừng video (không có player ở FW1); FW4/FW5 phải `pause()` (đã ghi chú trong code).
- Thư mục rác rỗng trong repo: `frontend/apps/--`, `frontend/apps/--workers=1`, `frontend/apps/X=1`, `frontend/apps/apps` (do lệnh sai tham số trước đó); khớp glob workspace `apps/*`, nên xoá trước khi commit (QA không xoá).
- Cloud/CI: cần chạy lại build + e2e trên MySQL 8.4 Docker local khi có đổi hạ tầng; lượt này dùng backend Docker local.

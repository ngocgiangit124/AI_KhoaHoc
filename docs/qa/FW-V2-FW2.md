# QA: FW-V2 + FW2 (frontend web học sinh)
**Kết quả:** PASS (sau khi dev sửa BUG-1, QA xác nhận lại 2026-10-07; lần đầu là FAIL vì BUG-1)

Ngày: 2026-10-07. Môi trường: web dev :3000 (host Docker), backend thật qua Nginx :8000, Mailpit :8025, Playwright 1.63 trong Docker. Không sửa code ứng dụng.

## Cách chạy
- Chạy e2e bằng bản sao `playwright.sh --real-backend` có hai chỗ khác: dùng dev server host :3000 (chuyển tiếp cổng, không chạy `next dev` thứ hai trên cùng `.next`) và đặt `E2E_MAILPIT_URL`/`MAILPIT_URL=http://host.docker.internal:8025` (script gốc không truyền được, Mailpit không tới được từ container trên macOS).
- Seed lại: `seed-e2e-catalog.sh` (chạy được, không cần sửa), reset 12 user `otp-e2e-*` (chưa xác thực, mật khẩu `matkhau-123`), `qa-t05-e2e-*`, `qa-t27-e2e-*`. Redis limiter DB 4 chỉ có 1 khoá `coupon-fail-ip`, không có khoá đăng ký/OTP nên không phải xoá.

## Kết quả e2e (backend thật, `--workers=1 --retries=0`)
| Lượt | Pass | Fail | Skip |
|---|---|---|---|
| Toàn bộ spec cũ (auth, home, danh-muc-real, otp, password, session) | 64 | 3 | 1 |
| Chạy lại "chưa xác thực: Đăng ký -> /xac-thuc-otp" | 1 | 0 | |
| Spec mới `e2e/fw-v2-qa.spec.ts` (header, đăng xuất/đăng nhập lại, ngăn kéo 375, sheet lọc, lỗi đăng ký 409/422/403/429) | 7 | 0 | 2 (chụp ảnh, cần `SHOTS=1`; đã chạy riêng, 2 pass) |

Tổng hiệu lực: 72 pass, 2 fail (cùng một bug, BUG-1), 1 skip (test chỉ chạy trên `next start`).
- Fail `danh-muc-real` "chưa xác thực": chạy lại pass (lần đầu quá 5 giây do dev compile `/xac-thuc-otp`). Không phải bug app, chỉ là timeout hơi chặt khi dev server nguội.
- Fail 2 test `otp.spec.ts` (xem BUG-1).

## Độ phủ tiêu chí
| Hạng mục | Test / cách kiểm | Kết quả |
|---|---|---|
| Header đăng nhập: tên + Đăng xuất (desktop) | `fw-v2-qa` desktop | PASS |
| Ngăn kéo 375px: nút "Mở menu" >= 44px, Đăng xuất >= 44px, Esc đóng, đăng xuất ra khách | `fw-v2-qa` 375 | PASS |
| Đăng xuất rồi đăng nhập lại, không còn trạng thái cũ (khách thấy link đăng ký, F5 vẫn khách, đăng nhập lại có nút đăng ký) | `fw-v2-qa` desktop | PASS |
| Đăng ký miễn phí thật -> chờ duyệt, F5 giữ | `danh-muc-real` | PASS |
| 403 ACCOUNT_NOT_VERIFIED -> /xac-thuc-otp | `danh-muc-real` (thật) | PASS |
| 409 ENROLLMENT_PENDING, 422 COURSE_NOT_FREE, 403 khác, 429 (mock phản hồi) | `fw-v2-qa` | PASS |
| Khoá có phí, thanh toán tắt: giá + "Sắp mở bán", không nút mua (khách, đã đăng nhập, mobile sticky bar) | `danh-muc-real` | PASS |
| Thanh toán bật | Không có cách bật an toàn ở local (`features.paid_checkout` đọc từ env backend, cần đổi env + khởi động lại). Chỉ có unit test `CourseCta.test.tsx` | KHÔNG KIỂM ĐƯỢC e2e |
| Khoá đã sở hữu: "Tiếp tục học" vô hiệu, bài học là hàng tĩnh | `danh-muc-real` | PASS |
| `/lop-99`, `/lop-06`, `/abc`, slug lạ, `ABC_x` -> 404 thật | e2e + curl trên build | PASS |
| JSON-LD có nonce khớp CSP | e2e + curl trên build | PASS |
| DOMPurify: mô tả seed có `<script>`, `<img onerror>`, `href=javascript:` | e2e (không có `window.__xss`) + HTML build: 0 `__xss`, 0 `onerror`, 0 `href="javascript`, giữ `<strong>` | PASS |
| Tìm không dấu, phân trang 25/trang (/lop-10), lọc giữ trên URL, sắp xếp, tham số sai | `danh-muc-real` | PASS |
| Sheet lọc 375px: mở/đóng bằng Esc, trả focus về nút Bộ lọc, hàng checkbox và các nút/select >= 44px, không cuộn ngang | `fw-v2-qa` | PASS |
| robots/sitemap | `danh-muc-real` + curl | PASS |

## Bản build `next start` (NODE_ENV=production, `next build` sạch, `NODE_OPTIONS=--max-old-space-size=1536`)
| Kiểm | Kết quả |
|---|---|
| `/v2`, `/v2/khoa-hoc`, `/%76%32/khoa-hoc`, `/V2` (có và không `next-router-prefetch: 1`) | 404, có CSP nonce, không lộ "Xem trước". Ngoại lệ đã biết: `/V2` + header prefetch -> 404 không CSP (không lộ nội dung) |
| `//v2/khoa-hoc` | 308 rồi 404 |
| RSC `-H 'RSC: 1'` tới `/v2/khoa-hoc` | 200 payload trang 404 chung, 0 lần "PreviewBar"/"Xem trước" |
| `/sitemap.xml` | 200, `Cache-Control: public, s-maxage=3600, stale-while-revalidate=600`, 41 `<loc>`; `/robots.txt` 200 (prerender, revalidate 1h) |
| Route thật: `/`, `/khoa-hoc`, `/lop-9`, chi tiết, `/dang-nhap`, `/dang-ky` | 200 |
| R14 `isomorphic-dompurify` trên build | Chạy tốt (lọc đúng, không lỗi jsdom trong log `next start`) |
Sau build, dev server :3000 vẫn 200 (build ghi chung `.next`, dev server không bị ảnh hưởng).

## Bug phát hiện
### BUG-1: Màn "Đổi email/SĐT" ở `/xac-thuc-otp` luôn lỗi 422 (thiếu `current_password`)
- Mức độ: Major (học sinh gõ nhầm email lúc đăng ký không sửa được trên giao diện; tài khoản chưa xác thực bị kẹt). Không thuộc phạm vi code FW-V2/FW2 nhưng làm 2 test e2e OTP đỏ.
- Bước tái hiện: đăng nhập user chưa xác thực -> `/xac-thuc-otp` -> "Đổi email/SĐT" -> nhập SĐT hoặc email mới -> "Lưu và gửi mã mới".
- Mong đợi: "Đã cập nhật thông tin liên hệ...".
- Thực tế: `PUT /api/v1/auth/contact` trả 422 `{"errors":{"current_password":["Vui lòng nhập mật khẩu hiện tại."]}}`; UI chỉ hiện alert chung "Dữ liệu gửi lên không hợp lệ." Form không có ô mật khẩu hiện tại.
- Nguyên nhân: Bảo mật cụm 1 (2026-10-06, H1) bắt buộc `current_password` (api-contract dòng 176, `backend/app/Http/Requests/Auth/UpdateContactRequest.php:44`), frontend chưa cập nhật.
- Vị trí: `frontend/apps/web/components/auth/OtpVerifyForm.tsx` (form đổi liên hệ). Test e2e bị ảnh hưởng: `otp.spec.ts:182` và `otp.spec.ts:269`; sau khi sửa app thì sửa test điền mật khẩu hiện tại. Chuyển `nextjs-dev`.

## Rủi ro và đề xuất
- Trang `/xac-thuc-otp` bản v2 (`components/v2/auth/OtpVerifyForm.tsx`, bản xem trước) cũng nên có ô mật khẩu hiện tại khi nối API.
- Timeout `toHaveURL` 5 giây ở test "chưa xác thực" dễ rớt khi dev server nguội; nên nâng lên 15 giây hoặc warm-up (test, không phải app).
- Thanh toán bật (`paid_checkout_enabled=true`) chưa kiểm e2e; cần kiểm trên staging khi bật cờ.
- `/V2` kèm header prefetch trả 404 không CSP (không lộ nội dung, chỉ lệch header); R11 (load test cache ấm) vẫn là nợ trước production như review đã ghi.
- `otp.spec.ts` yêu cầu `E2E_MAILPIT_URL` khi chạy trong container trên macOS; `playwright.sh` chưa truyền biến này (nên thêm `host.docker.internal:8025` cho Darwin).

## Tệp tạo/sửa
- Thêm `frontend/apps/web/e2e/fw-v2-qa.spec.ts`.
- Ảnh chụp thực tế (375 + 1280: trang chủ, danh mục, chi tiết khoá có phí, đăng nhập): `docs/design/mockups/v2/thuc-te/web/*.png`.
- Môi trường dev sau QA: seed `e2e-fw2-*` mới; user `fw2-hs-ok` đang chờ duyệt khoá miễn phí (seed lại trước khi chạy lại); 12 user `otp-e2e-*` chưa xác thực. Không flush Redis.

## Xác nhận lại sau khi dev sửa BUG-1 (2026-10-07)
- Đọc diff: `ChangeContactForm.tsx` thêm ô "Mật khẩu hiện tại" (bắt buộc phía client, gửi `current_password`, xoá ô sau lỗi, lỗi 422 hiện dưới ô, 429 hiện thông báo theo Retry-After); `otp.spec.ts:182,:269` điền mật khẩu; timeout test "chưa xác thực" 15 giây.
- `otp.spec.ts` chạy lại với backend thật: 11/11 pass (lần chạy gộp có 1 test đỏ do chính spec QA mới của tôi đổi email user `otp-e2e-11`, không phải lỗi app; đã chuyển spec QA sang user riêng `qa-fwv2-ct-1` và chạy lại test đó: pass).
- "chưa xác thực -> /xac-thuc-otp": pass (một lần đỏ do cache dữ liệu 60 giây của Next còn giữ id khoá cũ sau khi có người seed lại giữa chừng; chạy lại sau 60 giây pass).
- Kiểm tay tự động (`fw-v2-qa.spec.ts`, "Đổi liên hệ cần mật khẩu hiện tại"): thiếu mật khẩu -> lỗi dưới ô; sai mật khẩu -> "Mật khẩu hiện tại không đúng" và không có mail tới email mới; đúng mật khẩu -> "Đã cập nhật ... gửi mã xác thực mới" và có mail tới email mới. Pass.
- Seed lại user `qa-fwv2-ct-1@example.com` (chưa xác thực, SĐT 0970000301) trước mỗi lần chạy spec này. Kết quả e2e hiện tại: 0 fail đã biết (otp 11/11, fw-v2-qa 8 pass, danh-muc-real "chưa xác thực" pass).

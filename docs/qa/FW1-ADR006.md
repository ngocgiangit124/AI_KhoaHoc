# QA: FW1-ADR006 (apps/web, bỏ đồng ý phụ huynh)
**Kết quả:** PASS (sau khi Dev sửa BUG-1; vòng 2)

## Độ phủ
| Hạng mục | Test | Kết quả |
|---|---|---|
| Vitest apps/web | `vitest run` (50 file, 421 test) | PASS |
| AC10 khối phụ huynh không bắt buộc, tự mở <18, thu gọn khi đủ tuổi | auth.spec AC10 | PASS |
| AC10b có email phụ huynh, không banner, /cho-phu-huynh 404 | auth.spec AC10b | PASS |
| AC10c [T29] dưới 18 bỏ trống phụ huynh vẫn đăng ký | auth.spec (E2E_T29=1) | PASS |
| AC10d [T29] email phụ huynh = email học sinh -> 422 dưới ô | auth.spec (E2E_T29=1) | PASS |
| R1 nhập -> "Bỏ qua" -> gửi: payload không có `parent_*`, Mailpit không có thư phụ huynh | qa-fw1-adr6.spec | PASS |
| R1b nhập sai định dạng -> "Bỏ qua" -> gửi được | qa-fw1-adr6.spec | PASS |
| Đổi ngày sinh qua ngưỡng 18 sau mở/đóng tay: đóng tay thì giữ đóng, mở tay thì giữ mở (cả hai chiều) | qa-fw1-adr6.spec | PASS |
| Dưới 18 có email phụ huynh: trước OTP 0 thư; sau OTP đúng 1 thư "thông báo về tài khoản học", tên "Nguyễn Văn A**" (không chữ số), link huỷ `/phu-huynh/huy-nhan-thong-bao?t=...` | qa-fw1-adr6.spec | PASS |
| `parent_consent_status:"pending"` (mock /auth/me): không banner/hộp thoại phụ huynh; `/cho-phu-huynh` 404 | qa-fw1-adr6.spec | PASS |
| fw1-v2-qa.spec | 11/12 | 1 FAIL (375px, cùng BUG-1) |
| auth.spec | 22/24 | 2 FAIL: BUG-1 và WRONG_PORTAL (môi trường, xem dưới) |
| 375px /dang-ky | auth.spec + fw1-v2-qa | FAIL (BUG-1) |

## Bug phát hiện
### BUG-1: /dang-ky tràn ngang 79px ở 375px (nút phụ huynh)
- Mức độ: Major (yêu cầu 375px không tràn; hai e2e đang đỏ)
- Tái hiện: mở /dang-ky ở viewport 375px. `scrollWidth - innerWidth` = 79 (thu gọn, nút "Thêm thông tin phụ huynh (không bắt buộc)") và 81/79 khi mở (nút "Bỏ qua, không nhập thông tin phụ huynh"). Bisect: ẩn khối phụ huynh thì overflow = 0.
- Mong đợi: không tràn, nút xuống dòng.
- Nguyên nhân: `Button` v2 có `whitespace-nowrap` (packages/ui/src/v2/Button.tsx:29), nhãn dài làm container flex rộng 422px. Cần cho nút `whitespace-normal`/`h-auto`/`text-left` hoặc rút nhãn.
- Vị trí: `frontend/apps/web/components/auth/RegisterForm.tsx` ~dòng 278-291 (nút thêm) và ~271-273 (nút Bỏ qua).
- Ghi chú: test 375 sẵn có trong auth.spec `fill` ngày sinh trước hydrate nên thực tế chỉ kiểm trạng thái thu gọn; cả hai trạng thái đều tràn.

## Ghi nhận khác (không phải bug FW1)
- WRONG_PORTAL (auth.spec:232) fail vì tài khoản `teacher@vitaminvui.test` không có trong DB dev (không được chạy db:seed); lỗi môi trường, không liên quan thay đổi.
- Link huỷ nhận trong thư phụ huynh trỏ `/phu-huynh/huy-nhan-thong-bao` (FW7 chưa có) -> 404, theo R4.
- Trang chủ có mục marketing "Dành cho phụ huynh" (hợp lệ, không phải banner).
- Dữ liệu e2e tạo ra: user `e2e-fw1adr6-*` và `qa-<run>-*` (chưa dọn; qa-* dọn thủ công nếu cần).

## Lệnh đã chạy
- `frontend/scripts/pnpm.sh --filter web exec vitest run`
- `frontend/apps/web/e2e/seed-e2e-auth.sh`
- docker playwright (`--workers=1 --trace=off --retries=0`, `E2E_T29=1`, dev server riêng `NEXT_DIST_DIR=.next-qa-fw1`): `e2e/auth.spec.ts`, `e2e/fw1-v2-qa.spec.ts`, `e2e/qa-fw1-adr6.spec.ts` (spec mới do QA thêm).

## Vòng 2 (sau khi Dev sửa BUG-1)
- BUG-1 đã đóng: 375px /dang-ky không tràn ở cả trạng thái thu gọn lẫn mở (overflow open=0 closed=0; auth.spec 375px và fw1-v2-qa:29 đạt).
- Chạy lại `auth.spec.ts`, `fw1-v2-qa.spec.ts`, `qa-fw1-adr6.spec.ts` (E2E_T29=1, E2E_REAL_BACKEND=1, workers=1, trace=off): 41/41 đạt. Lần chạy gộp có AC1 đỏ một lần ở test đầu tiên (dev server biên dịch lạnh), chạy riêng lại thì đạt.
- WRONG_PORTAL: nguyên nhân thật là test dùng mật khẩu `password` (sai), không phải thiếu tài khoản; tài khoản `teacher@vitaminvui.test` có trong DB dev. Đã đổi test sang `Demo-VitaminVui-2026`, nay đạt.
- ESLint `e2e`: sạch (đã bỏ 3 `any` trong qa-fw1-adr6.spec.ts bằng kiểu MailItem/MailFull).
- Lưu ý khi chạy lặp: limiter `register` là 30 lần/giờ/IP; chạy lại nhiều vòng sẽ nhận "Bạn thao tác quá nhanh" (429) khiến các ca đăng ký và ?next đỏ giả. Đã chờ hết cửa sổ rồi chạy lại.
- Dọn dữ liệu: KHÔNG xoá được user `e2e-fw1adr6-*` vì FK `consents.user_id` ON DELETE RESTRICT (lỗi 1451); các user `qa-*` cũng chung tình trạng. Giữ lại, cần PO/Dev quyết định cách dọn (xoá consents trước) hoặc bỏ qua ở DB dev.

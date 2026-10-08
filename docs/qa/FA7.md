# QA: FA7 (US-013 quản lý mã giảm giá, apps/admin)
**Kết quả:** PASS (0 Critical/Major; 1 bug Minor + 2 ghi nhận)

## Độ phủ
| AC / kịch bản | Test | Kết quả |
|---|---|---|
| AC1-AC8, S18, 403 GV, 375px | e2e dev `ma-giam-gia-real.spec.ts` 16 test (chạy lại sau sửa R1/R3/R4/R5) | PASS 16/16 |
| Vitest toàn admin | 38 file / 462 test | PASS |
| typecheck + eslint admin | sạch | PASS |
| Mã trùng khác hoa/thường | QA-1 (API gửi chữ thường -> 422 errors.code) | PASS |
| Mã 50 / 51 ký tự, có khoảng trắng | QA-2 (51: client + server chặn; 50 tạo được) | PASS |
| Hạn 23:59 +07:00 / 00:00, trình duyệt UTC & Los_Angeles | QA-3 (API trả 2031-03-01T23:59+07:00 = 16:59Z; lưu từ múi giờ khác không làm lệch mốc) | PASS |
| COUPON_LOCKED trên form | QA-4 (route mock bằng body 422 thật + GET mới) | PASS (xem BUG-1) |
| Bật/tắt 2 tab đồng thời, activate/deactivate lặp | QA-5 | PASS (200, tab thua hiển thị "Đã tắt") |
| Xoá mã có đơn (COUPON_IN_USE) | QA-6 (409 thật + hộp thoại giải thích) | PASS |
| 100% / fixed lớn cần max_uses + valid_until | QA-7 (UI chặn; server 422 cho 100% thiếu; 100% đủ -> 201; fixed 300.000 thiếu giới hạn -> 422) | PASS |
| Xoá chuyên đề đang trong phạm vi | QA-8 (xoá 204; mã còn `is_restricted=true`, `subjects=[]`; trang sửa không vỡ) | PASS (xem ghi nhận 1) |
| Mã cũ khóa + chuyên đề, "Kết hợp" | QA-9 (R3 ok: radio còn khi chuyển; PUT giữ nguyên số khóa/chuyên đề) | PASS |
| Mất mạng khi lưu | QA-10 (báo lỗi mạng, giữ dữ liệu, lưu lại được) | PASS |
| Hết phiên giữa form | QA-11 (về `/dang-nhap?next=...&reason=expired`) | PASS (xem ghi nhận 2) |

File test mới: `frontend/apps/admin/e2e/ma-giam-gia-qa-real.spec.ts` (11 test; không còn biến thừa `nav`/`loginTeacher`, tsc/lint sạch).

## Bug
### BUG-1: COUPON_LOCKED làm mất mọi chỉnh sửa dở (Minor)
- Tái hiện: mở sửa mã chưa dùng, đổi tên + giá trị; lúc lưu server trả 422 COUPON_LOCKED -> màn hình tải lại.
- Mong đợi (tài liệu dev + comment "giữ các thay đổi khác"): mã/loại/giá trị về bản server, tên và các trường khác giữ nguyên.
- Thực tế: toàn bộ form reset về bản server (tên về "FA7 đang dùng"). Nhánh `seenLocked` không bao giờ chạy vì nhánh `seenVersion !== version` chạy trước và `setValues(initial)`. Bản server mới được nạp đúng (R5 đã sửa), chỉ mất dữ liệu khác. Hiếm.
- Vị trí: `components/coupons/CouponForm.tsx:84-95`.

## Ghi nhận / rủi ro
1. Xoá chuyên đề trong phạm vi: mã có `is_restricted=true` nhưng không còn chuyên đề/khóa nào (backend). Nếu API/cổng học sinh coi đó là "áp cho tất cả" thì mở rộng ngoài ý muốn; nếu coi là "không áp cho khóa nào" thì mã vô dụng. Cần kiểm hành vi áp mã ở US-004 (ngoài FA7).
2. Hết phiên: chuyển về đăng nhập, dữ liệu nhập dở mất (chấp nhận được; có `next`).
3. `seed-e2e-coupons.sh --reset` từng lỗi 1 lần do Faker trùng email (flaky, `users_email_unique`), chạy lại là qua.
4. Chưa test được: dùng mã song song làm COUPON_LOCKED thật (không có đường ghi used_count ngoài tinker nên mô phỏng bằng route), >200 khóa đang bán, 429 thật.
5. Backend không đụng, không migrate/seed ngoài script; không build.

## Lệnh đã chạy
`scripts/pnpm.sh --filter ./apps/admin exec vitest run`; `seed-e2e-coupons.sh --reset|--clean`; `e2e/run-real.sh e2e/ma-giam-gia-real.spec.ts --workers=1 --trace=off` (16/16); `e2e/run-real.sh e2e/ma-giam-gia-qa-real.spec.ts --workers=1 --trace=off --retries=0` (11/11); `pnpm --filter @vitaminvui/admin run typecheck` và `run lint` (sạch).

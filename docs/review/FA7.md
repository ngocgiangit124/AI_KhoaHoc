# FA7 — Quản lý mã giảm giá (US-013)

## Dev

**Ngày:** 2026-10-08 · **App:** `frontend/apps/admin` · **Phụ thuộc:** T15 (API đã có)

### Đã làm
- Route `/quan-tri/ma-giam-gia` (danh sách), `/tao` (tạo), `/{id}` (sửa + tình trạng + bật/tắt + xoá). Menu "Mã giảm giá" (nhóm Bán hàng) bật `ready`; hiện theo `permissions.manage_coupons` hoặc vai trò admin/quản lý trang. Giáo viên: ẩn menu, mở thẳng route -> trang 403 (API cũng 403).
- Danh sách: bộ lọc trên URL `state` (tab: Tất cả / Đang hoạt động / Sắp diễn ra / Hết hạn / Hết lượt / Đã tắt, đúng 5 giá trị `state` của contract), `q` (mã hoặc tên, debounce 300ms), `page`, `per_page` 25/50. Cột: mã (+tên), giảm, phạm vi, hiệu lực (giờ VN), thanh lượt dùng "45/100" kèm chữ, trạng thái bằng chữ. Rỗng / không khớp bộ lọc / lỗi tải (Thử lại) / 403.
- Form tạo/sửa (một component dùng chung): mã tự viết hoa; loại % / số tiền; giá trị; tên gợi nhớ; bắt đầu/kết thúc (`datetime-local`, luôn hiểu là giờ Việt Nam +07:00, không phụ thuộc múi giờ máy); tổng lượt tối đa; ghi chú "mỗi học sinh 1 lần"; phạm vi (Toàn bộ / Theo chuyên đề / Theo khóa, `CouponScopeSelector`; chuyên đề ẩn vẫn chọn được và có nhãn "đang ẩn"; khóa tìm theo tên, tối đa 200).
- Validate client khớp `CouponRequest`: mã 4-50 `^[A-Z0-9_-]+$`; % 1-100 (AC7); tiền 1-100.000.000; max_uses 1-1.000.000 và không nhỏ hơn lượt đã dùng; kết thúc >= bắt đầu (AC5; bắt đầu trống khi tạo so với "bây giờ"); tên <= 255 không `<>`; chọn "theo chuyên đề/khóa" mà không chọn gì -> lỗi (không âm thầm thành toàn bộ); S18: giảm 100% hoặc giảm cố định >= giá khóa rẻ nhất đang bán -> bắt buộc max_uses + valid_until (giá rẻ nhất lấy từ `GET /admin/courses?status=published`, tối đa 4 trang x 50, chỉ tải khi chọn loại cố định; lỗi tải thì bỏ qua và để server chốt).
- Lỗi server: 422 `errors.<field>` (kể cả `course_ids.N`) -> dưới đúng ô + hộp tóm tắt đầu form (có liên kết nhảy tới ô, focus vào hộp); 422 `COUPON_LOCKED` -> banner nêu trường bị đổi + tải lại bản mới (mã/loại/giá trị trả về giá trị server, thay đổi khác giữ nguyên); 404 -> trang "Không tìm thấy mã giảm giá"; 403 -> trang không có quyền / banner; 429 -> "thao tác quá nhanh" kèm số giây (`Retry-After`); lỗi mạng -> thông điệp mạng. Dữ liệu đã nhập luôn được giữ.
- Chặn bấm kép (ref + nút khoá "Đang lưu…"; bật/tắt và xoá cũng có ref). Xác nhận rời trang bằng `useUnsavedChangesGuard` (click link, nút Back, F5/đóng tab).
- Trang sửa: mã đã dùng (`used_count` > 0) khoá mã/loại/giá trị kèm giải thích; thanh tiến độ lượt dùng + trạng thái "Hết lượt" (AC4); khối "Phạm vi đang lưu" liệt kê chuyên đề/khóa (AC8); Vô hiệu hoá (AC3) và Bật lại (có hộp xác nhận); Xoá (nút khoá + lý do khi đã dùng; 409 `COUPON_IN_USE` -> hộp thoại giải thích + tải lại).
- Mốc thời gian không đổi được giữ nguyên chuỗi ISO cũ khi PUT (`valid_from` gửi null = giữ nguyên) để không sinh diff audit giả.
- 375px: mọi ô, nút, radio, checkbox >= 44px; bảng dưới 768px gộp giảm/trạng thái/lượt vào dòng phụ; không tràn ngang.

### File
- Mới: `lib/coupons/{types,permissions,query,api,form,errors,options}.ts` + `coupons.test.ts`; `components/coupons/{CouponsScreen,CouponForm,CouponScopeSelector,CouponCreateScreen,CouponEditScreen,CouponStateBadge}.tsx` + 3 file test; `app/quan-tri/ma-giam-gia/{page,tao/page,[id]/page}.tsx`; `e2e/ma-giam-gia-real.spec.ts`, `e2e/seed-e2e-coupons.sh`.
- Sửa: `lib/nav.ts` (ready), `components/shell/shell.test.tsx`.
- Không đụng `packages/ui`, `apps/web`, backend, lockfile. Không có biến môi trường mới.

### Chênh lệch contract / thiếu API / quyết định cần PO
1. Không lệch contract ở các route đã dùng (list/show/store/update/activate/deactivate/destroy đều chạy thật trong e2e, kể cả `COUPON_LOCKED` và S18 trả 422 đúng trường).
2. **Kích hoạt lại**: design US-013 ghi "chờ PO xác nhận" nhưng contract T15 đã có `/activate` -> FE dựng nút "Bật lại mã" (có xác nhận). Nếu PO không muốn, ẩn nút này.
3. **Phạm vi kết hợp**: contract cho chọn cả khóa lẫn chuyên đề (hợp). UI theo design chỉ chọn một kiểu; riêng mã cũ đã có cả hai thì hiện thêm lựa chọn "Kết hợp chuyên đề và khóa học" để PUT không làm mất dữ liệu. Chờ PO nếu muốn cho tạo mới kiểu kết hợp.
4. **S18 phía client chỉ chắc chắn với 100%**; với giảm cố định, giá khóa rẻ nhất lấy xấp xỉ từ danh sách khóa đang xuất bản (tối đa 200 khóa). Thiếu endpoint trả "giá khóa rẻ nhất đang bán" (hoặc server nên trả thêm trong `/admin/coupons` meta) để FE khỏi quét danh sách. Server vẫn là nguồn chốt (422 trên đúng ô, FE hiện dưới ô).
5. **Tìm khóa cho phạm vi**: dùng `GET /admin/courses?q=` (25 kết quả đầu, gồm cả khóa nháp/ngừng bán có nhãn). Contract coupon không có endpoint riêng.
6. Danh sách coupon không có `courses`/`subjects` (chỉ `*_count`) nên cột Phạm vi hiện số lượng, không hiện tên (đúng contract).
7. "Thanh toán trực tuyến đang tạm khoá (V2)": dòng ghi chú ở cuối danh sách theo bản xem trước; lượt dùng chỉ tăng khi đơn `paid`.

### Kiểm tra
- `tsc --noEmit` (kèm `next typegen` vào `.next-fa7`) sạch; `eslint .` sạch; vitest admin 35 file / 435 test pass (mới: 20 test logic `lib/coupons` + 13 CouponForm + 7 CouponsScreen + 6 CouponEditScreen; sửa 1 test menu).
- `next build` với `NEXT_DIST_DIR=.next-fa7` thành công (3 route mới); đã xoá thư mục build tạm.
- e2e thật `e2e/ma-giam-gia-real.spec.ts` (`run-real.sh`, `--workers=1`, `--trace=off`): 16/16 pass trên dev :3001 + backend :8000. Seed: `e2e/seed-e2e-coupons.sh --reset` trước mỗi lần chạy, `--clean` sau (đã dọn).
- Lưu ý chạy e2e: `--trace=off` (trace mặc định `retain-on-failure` từng làm hỏng test đầu do lỗi zip trace trong thư mục test-results dùng chung); đợi >= 60s giữa các lần chạy vì OTP MFA 1/phút; lần đầu mở `/ma-giam-gia/{id}` dev server biên dịch route nên timeout dài.

### Luồng laravel-qa nên kiểm kỹ
- Tạo mã trùng khác hoa/thường (`toan2026` vs `TOAN2026`), mã có khoảng trắng, mã dài 50/51 ký tự.
- Múi giờ: tạo/sửa với hạn kết thúc sát nửa đêm, kiểm hiển thị trong danh sách và sau F5 (FE gửi +07:00).
- Sửa mã đang có lượt dùng đồng thời (COUPON_LOCKED), bật/tắt hai tab cùng lúc (idempotent), xoá mã vừa có đơn tham chiếu (409).
- Mã 100% / giảm cố định lớn: S18 từ cả client lẫn server; chuyên đề ẩn trong phạm vi; xoá cứng chuyên đề đang nằm trong phạm vi mã (phạm vi hẹp lại).
- Rate limit 429 trên ghi (nếu có throttle), mất mạng khi lưu, hết phiên giữa lúc đang sửa form dài.

---

## Review (laravel-reviewer, 2026-10-08)

**Kết luận:** APPROVE (0 BLOCKER, 1 SHOULD, 5 NIT)
**Phạm vi:** diff chưa commit dưới `frontend/apps/admin/` (lib/coupons, components/coupons, app/quan-tri/ma-giam-gia, nav.ts, shell.test.tsx, e2e + seed). Đối chiếu `CouponRequest`, `CouponService`, api-contract T15. Chạy lại vitest coupons + shell: 5 file / 66 test pass.

### Tổng quan
Bám sát contract và server: luật mã/%/tiền/max_uses/ngày (+07:00), `valid_from` null = giữ nguyên, giữ nguyên ISO cũ khi không đổi (tránh audit diff giả), COUPON_LOCKED / COUPON_IN_USE / 404 / 403 / 429 / mạng đều có xử lý; PUT luôn gửi đủ `course_ids`/`subject_ids` nên không làm mất phạm vi. Chặn bấm kép bằng ref, có guard rời trang, giáo viên ra trang 403 mà không gọi API. Không lộ dữ liệu, không có HTML thô, múi giờ hiển thị tường minh.

### Phát hiện
**R1 [SHOULD] Quét `GET /admin/courses?status=published` để tìm giá rẻ nhất** (`lib/coupons/options.ts` `cheapestPublishedPrice`, `CouponForm.tsx` effect `needCheapest`)
- Độ đúng: chấp nhận được. Quét là tập con của "khóa đang bán" nên giá FE >= giá thật; nếu FE báo "giảm hết" thì server chắc chắn cũng báo (không có chặn nhầm). Sai chiều ngược (FE không báo, server báo) thì server trả 422 đúng ô và FE hiện dưới ô, nên an toàn. Khi >200 khóa đang bán thì FE chỉ là gần đúng (đã ghi trong mục 4 của Dev).
- Chi phí: tối đa 4 request tuần tự mỗi lần mở form có loại cố định, kể cả trang sửa mã chưa khoá (mở là bắn ngay). Khi không có khóa nào (`null`) thì mỗi lần đổi qua lại loại giảm lại quét lại. Lỗi 429 bị nuốt.
- Đề xuất: (a) cache theo module (hoặc `useRef` mức màn hình) cả kết quả `null`, TTL ngắn ~60s; (b) chỉ tải khi ô giá trị đã có số (vẫn đủ báo sớm); (c) dài hạn thêm `meta.cheapest_selling_price` vào `GET /admin/coupons` hoặc endpoint nhỏ ở backend (ghi backlog-v2). Không chặn merge.

**R2 [NIT] Nút "Bật lại mã"** — contract T15 đã có `/activate`, API idempotent, FE có hộp xác nhận, không rủi ro dữ liệu. Design ghi "chờ PO" nên cần PO chốt khi duyệt commit; nếu PO không muốn chỉ cần ẩn nút ở `CouponEditScreen.tsx` (khối `aside`). Giữ được.

**R3 [NIT] Lựa chọn "Kết hợp"** (`CouponScopeSelector.tsx`) — hợp lý để PUT không làm mất phạm vi của mã cũ. Nhưng khi người dùng bấm sang radio khác thì tuỳ chọn "Kết hợp" biến mất (điều kiện `scope === "both"`), không quay lại được trừ khi bấm "Huỷ thay đổi". Gợi ý: truyền cờ `legacyBoth` (từ `base`) để luôn hiện lựa chọn này khi mã gốc là kết hợp. Mã tạo mới không có lựa chọn này là đúng design.

**R4 [NIT] Ô "Bắt đầu" xoá trống khi sửa** (`form.ts` `buildCouponBody`, `CouponForm.tsx`) — xoá trống được gửi `null` = giữ nguyên, nhưng `dirty` vẫn true (guard rời trang cảnh báo, nút Lưu gửi PUT không đổi gì). Gợi ý: khi sửa, ô trống thì khôi phục về giá trị cũ ở `onBlur`, hoặc `required` ở chế độ sửa.

**R5 [NIT] Sau COUPON_LOCKED, form reset mã/loại/giá trị về `initial` (= `base` cũ), không phải bản server mới** (`CouponForm.tsx` khối `seenLocked`). Nếu admin khác đổi giá trị mã trong lúc đó thì lần lưu kế lại 422 COUPON_LOCKED. Hiếm; sửa bằng cách reset từ `coupon` mới nhất (truyền prop) hoặc tải lại cả `base` khi stale.

**R6 [NIT] Cột "Hiệu lực" chỉ hiện ngày** (`CouponsScreen.tsx`) — mã hết hạn lúc 23:59 và 00:00 trông giống nhau; chi tiết sửa đã có giờ. Cân nhắc `formatDateTime` ở dòng tooltip/`title`. Ngoài ra FE chặn `<>` còn server (`PlainText`) chặn thêm ký tự điều khiển/ẩn; khác biệt này vô hại vì 422 hiện đúng ô `name`.

### Đối chiếu acceptance criteria
| AC | Code đáp ứng | Ghi chú |
|---|---|---|
| AC1 tạo mã active | `CouponForm` mode create -> POST | OK |
| AC2 trùng mã | 422 `errors.code` hiện dưới ô mã | server là nguồn chốt, đúng |
| AC3 vô hiệu hoá | `CouponEditScreen.toggle` + ConfirmDialog + toast | OK, bấm kép chặn bằng ref |
| AC4 hết lượt | ProgressBar `used/max` + `CouponStateBadge` | OK |
| AC5 kết thúc < bắt đầu | `validateCouponForm` (tạo: so với "bây giờ" VN) + 422 server | OK |
| AC6 lọc trạng thái | LinkTabs `state` trên URL, 5 giá trị đúng contract | OK |
| AC7 % > 100 | client + server | OK |
| AC8 phạm vi | selector + khối "Phạm vi đang lưu" | OK; PUT thay thế đủ mảng |
| AC9/AC10 | phía học sinh (US-004), ngoài phạm vi FA7 | không áp dụng |
| S18 | client (100% chắc chắn, cố định gần đúng) + server | xem R1 |

### Gợi ý cho QA
- Mở trang sửa mã đã dùng, rồi dùng mã ở tab khác (đơn paid) trước khi lưu: thử đổi giá trị để thấy COUPON_LOCKED và form không mất dữ liệu khác.
- Mã cũ có cả khóa lẫn chuyên đề: sửa tên rồi lưu, kiểm phạm vi giữ nguyên (và R3).
- Giảm cố định đúng bằng giá khóa rẻ nhất, bỏ trống hạn/lượt: FE chặn + server 422; thử với >200 khóa đang bán nếu có dữ liệu.
- Hạn kết thúc sát 00:00 +07:00, F5 so giờ hiển thị; trình duyệt đặt múi giờ khác (ví dụ UTC).
- Hai tab bật/tắt/xoá cùng lúc; xoá mã vừa có đơn tham chiếu (409).
- Đăng nhập giáo viên: menu ẩn, vào thẳng URL ra 403 và không gọi `/admin/coupons`.
- 375px: bảng không tràn ngang, nút/radio >= 44px; Tab/Enter bằng bàn phím trên tóm tắt lỗi và hộp xác nhận.
- Lưu ý `seed-e2e-coupons.sh` ghi DB qua tinker (chỉ chạy khi APP_ENV local/testing); không chạy trên môi trường dùng chung.

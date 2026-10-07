# QA: FW8 + FW9 (trang chủ thật, khu giáo viên trang chủ) — web học sinh
**Kết quả:** FAIL (1 Major, 1 Major ngoài phạm vi FW8, 1 Minor). Phần trang chủ `/` đạt toàn bộ; lỗi nằm ở danh mục `/khoa-hoc` (ca xấu tên giáo viên 150 ký tự) và chi tiết khóa.

## Kiểm tĩnh
- `tsc --noEmit` sạch; `lint` sạch; unit toàn bộ web 42 file / 350 test pass.
- Build production riêng (`NEXT_DIST_DIR=.next-qa`, `--env-file apps/web/.env.local`) thành công, đã xoá. `.next` không bị đụng.

## E2E thật (backend Laravel thật, `--workers=1`, `next start` bản build production)
- `e2e/run-home-real.sh` (dev): 6/6 xanh (A1, A2, B, C, D, E).
- Spec QA `e2e/home-qa-real.spec.ts` (chạy bằng `e2e/run-home-qa.sh` + `e2e/qa-fw8-control.py` trên host để đổi dữ liệu; proxy ghi log `e2e/fw8-qa-proxy.mjs`).

| Yêu cầu | Test | Kết quả |
|---|---|---|
| Ảnh giáo viên thật hiển thị, CSP img-src không chặn, không lỗi CSP (US-020 AC12-15) | Q1 (ảnh PNG thật từ uploads, `context.route` cho STATIC_URL; 4/4 ảnh `naturalWidth>0`; origin lạ bị CSP chặn làm đối chứng; trang có nonce không bị cache chung) | PASS |
| Rút đồng ý / khoá / hết khóa đang bán -> HTML thô (view-source) trang chủ không còn họ (US-020 AC7, BR4) | Q2 | PASS: 60 s / 60 s / 59 s (đúng biên 60 s; chạm Data Cache `revalidate: 60`); đồng ý lại/mở khoá -> hiện lại |
| Khóa ngừng bán biến khỏi "nổi bật" (US-019 AC6) | Q2 | PASS: 59 s |
| `/khoa-hoc?teacher_id=` giáo viên chưa đồng ý / khoá / hết khóa / không tồn tại / rác (0, -1, abc, số quá dài, 1e3, SQL, HTML, `[]`, rỗng) | Q3 | PASS: luôn 200, không 500, không lộ bio/ảnh; id rác bị bỏ qua êm; id không tồn tại/gv hết khóa -> không có tên. Ghi nhận: tên GV chưa đồng ý/bị khoá hiện ở chip và thẻ khóa vì họ tên công khai (US-020 BR5), đúng thiết kế |
| R2: SSR có `teacher_id` gắn X-Client-IP; quét 60 `teacher_id` không 429 | Q4 | PASS: XFF hợp lệ -> X-Client-IP đúng; XFF sai định dạng -> không gắn; trang chủ không gắn IP; 0 phản hồi 429/5xx; trang chủ vẫn 200 sau khi quét |
| 375px trang chủ: không cuộn ngang, vùng chạm >= 44px, không lỗi CSP | Q5, home-real E | PASS cho `/` (cả 320px) |
| 375px `/khoa-hoc` với tên GV 150 ký tự: không cuộn ngang | Q5 | FAIL, xem BUG-1 |
| Bàn phím qua poster và thẻ giáo viên (thứ tự DOM, vòng focus 2px, Enter -> `?teacher_id=`) | Q6 | PASS |
| Khóa ngừng bán ở trang chi tiết (FW2) | Q2b | FAIL, xem BUG-2 |
| AC10 chữ >= 16px, dòng phụ 14px CourseCard/TeacherCard | không đo | Chờ PO, không tính bug |

## Bug phát hiện
### BUG-1: Danh mục `/khoa-hoc` cuộn ngang ở 375px khi tên giáo viên rất dài
- Mức độ: Major
- Bước tái hiện: seed `seed-e2e-home.sh --reset` (GV1 tên 150 ký tự dạy 2 khóa published); mở `/khoa-hoc`, `/khoa-hoc?grade=9`, `/khoa-hoc?teacher_id=<GV1>` ở viewport 375px (và 320px).
- Mong đợi: `scrollWidth <= 375` (US-019 AC10, design §18). Thực tế: `scrollWidth` 1378 (`?teacher_id=...&grade=10`: 1259). Trang chủ `/` không bị (dev đã thêm `grid-cols-1` + `min-w-0`), chi tiết khóa không bị.
- Vị trí nghi ngờ: `components/catalog/CatalogView.tsx:203-205` (`ul` `grid` không có `grid-cols-1`, `li` `flex` thiếu `min-w-0`; dòng "Giáo viên: ..." dùng `truncate`/nowrap). Cùng nguyên nhân dev đã sửa ở trang chủ (review mục 7 đã nêu chưa kiểm).
- Spec: `Q5`.

### BUG-2: Trang chi tiết khóa đã ngừng bán vẫn trả 200 + nội dung rất lâu sau 60 s
- Mức độ: Major (FW2, ngoài phạm vi FW8; ảnh hưởng quy tắc "khóa ngừng bán biến mất sau <= 60 s")
- Bước tái hiện: mở `/khoa-hoc/e2e-fw8-khoa-3` (200, vào cache); ngừng bán khóa (backend ngay lập tức trả 404 `NOT_FOUND`, kiểm bằng curl); gọi lại trang mỗi 5 s.
- Mong đợi: 404 sau <= 60 s. Thực tế: vẫn 200 với đầy đủ nội dung sau 303 s (không bao giờ làm mới). Nghi Data Cache của Next giữ bản cũ khi lần revalidate trả 404 (không ghi đè), nên 404 không thay thế được entry cũ.
- Vị trí nghi ngờ: `lib/catalog/api.ts:57-66` (`fetchCourse` với `CATALOG_CACHE`), `lib/api.server.ts`. Cần dev xác nhận nguyên nhân; đề xuất: không dùng Data Cache cho 404, hoặc `revalidateTag`, hoặc cho backend trả 200 trạng thái ngừng bán rồi FE tự 404.
- Spec: `Q2b` (đã chuyển thành annotation để không chặn các ca sau).

### BUG-3: Liên kết breadcrumb dưới 44px
- Mức độ: Minor (FW2/FW1, không phải code FW8)
- Ở 375px: "Trang chủ" 71x20 trên `/khoa-hoc`, `/khoa-hoc?...`; "Lớp 9" 40x20 trên chi tiết khóa. Trang chủ `/` đạt (không có vùng nhỏ).

## Rủi ro & đề xuất
- R2 đã giảm nhẹ đúng: IP gắn khi có `teacher_id`; chưa thấy 429 khi quét 60 giá trị từ 60 IP khác nhau. Khi chạy thật sau Nginx cần đảm bảo `X-Forwarded-For` đáng tin (Next tự thêm địa chỉ socket nếu thiếu).
- Dev server in cảnh báo LCP: poster `nguoi-sang-lap-minh-hoa.svg` bị phát hiện là LCP ở một viewport, đang `loading=lazy`; kiểm lại trên bản thật (chỉ cảnh báo dev).
- Thời gian ẩn đúng biên 59-60 s: nếu PO cần chặt hơn 60 s phải giảm `revalidate` hoặc gọi `revalidateTag` khi đổi dữ liệu.
- Ảnh giáo viên thật chưa mở bằng Safari iOS thật (chỉ Chromium giả lập). Mục này nên PO xem tay.
- Dọn: seed `--clean`, `.next-qa`, `.next-e2e-fw8`, `test-results`, ảnh tạm trong uploads, container Playwright, control server đã dọn. Spec/proxy/control QA nằm trong `frontend/apps/web/e2e/` và `playwright.fw8qa.config.ts`.

## Sửa sau QA (nextjs-dev)
- **BUG-1:** `CatalogView` lưới thẻ: `grid-cols-1` + `li min-w-0`; chi tiết khóa: `grid-cols-1`, cột phải `lg:grid-cols-[minmax(0,1fr)_360px]`; `TeacherList`: `grid-cols-1` + `li min-w-0`. E2E mới (`e2e/course-detail-real.spec.ts`, ca "375/320px"): `/khoa-hoc`, `?grade=9`, `?teacher_id=<GV tên 150 ký tự>`, `/khoa-hoc/e2e-fw8-khoa-1` ở 375 và 320px không cuộn ngang. Q5 của QA xanh.
- **BUG-2:** nguyên nhân xác nhận bằng thử thật (e2e với proxy trả 404 cho chi tiết khóa, chạy trên code cũ: vẫn 200 sau 130 s): Data Cache của Next giữ bản cũ khi lần làm mới trả 404 (không lưu response lỗi, cũng không xoá mục cũ). Sửa: `fetchCourse` dùng `revalidate: false` (no-store) để 404 của backend có hiệu lực ngay; backend vẫn có cache/ETag công khai 60 giây và throttle nhóm SSR. Đánh đổi: mỗi lượt xem chi tiết gọi backend 1 lần (cần đo lại load test FW2 nếu còn yêu cầu p95). Unit test mới trong `lib/catalog/api.test.ts` (no-store, 404 -> null, lỗi khác ném lên); e2e ca "chi tiết khóa đã ngừng bán" xanh (404 sau ~12 s).
- **BUG-3:** `packages/ui/src/v2/Breadcrumb.tsx` (thay đổi ở packages/ui, dùng chung): liên kết `inline-flex min-h-11 min-w-11 items-center`, chữ không đổi; desktop cao hơn một chút. E2E kiểm chiều cao ≥ 44px ở 375/320px; Q5 (cả rộng lẫn cao) xanh.
- Kiểm: tsc, lint sạch; unit web 42 file / 353 test pass; `run-home-real.sh` 8/8; `run-home-qa.sh` Q1 (cần file `backend/storage/app/uploads/qa-fw8-avatar.png` tự tạo tạm), Q2/Q2b, Q5, Q6 xanh. Đã `--clean` seed, xoá `.next-qa`, file ảnh tạm, dừng `qa-fw8-control.py`. Tôi cũng xoá hàm `noHorizontalScroll` không dùng trong `home-qa-real.spec.ts` (tsc báo lỗi).

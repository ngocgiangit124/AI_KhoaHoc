# QA: FW6 (web "Khóa học của tôi" + tiến độ, US-008)
**Kết quả:** PASS (0 Critical/Major; 1 Minor, 2 NIT — không chặn commit)

## Độ phủ
| AC / điểm kiểm | Test | Kết quả |
|---|---|---|
| AC1 % tiến độ, Học tiếp tới đúng bài (bài 3) | dev e2e "học sinh có khóa" (7/7 pass ở lượt dev; reviewer chạy lại vitest 21/21, QA chạy lại 21/21) | PASS |
| AC3 rỗng | dev e2e "chưa có khóa" | PASS |
| AC4 phân trang 12/trang, F5 giữ `?trang=` | dev e2e "nhiều khóa"; QA1 | PASS |
| AC5 điểm cao nhất, số lượt, link quiz FW5 / kết quả | dev e2e "chi tiết tiến độ" | PASS |
| 3 nhóm (đang học / chờ duyệt / bị từ chối có lý do, lý do HTML hiện dạng text) | QA1 | PASS |
| >12 khóa kèm pending: trang 2 có 1 thẻ; `?trang=abc/0/-1/1.5` không vỡ | QA1 | PASS |
| Khóa 0 bài ("Chưa có nội dung", không NaN, không nút học), ngừng bán (ghi chú), 100% ("2/2 bài · 100%", "Đã hoàn thành", "Xem lại"/"Xem lại bài học") | QA2 | PASS |
| Hai học sinh không thấy khóa của nhau; đổi id URL -> 403 "chưa sở hữu", không lộ tên khóa; khóa thu hồi không có trong danh sách, vào URL -> 403 | QA4 | PASS |
| Thu hồi giữa phiên (API 403 khi reload) và 401 giữa chừng ("Phiên đăng nhập đã kết thúc") | QA5 | PASS |
| 375px, tiêu đề rất dài: không tràn ngang; nút chính >= 44px | QA3 + dev e2e 375px | PASS |
| Bàn phím: tab mũi tên/Home, `<details>` mục lục Enter/Space | QA6 | PASS |
| Lỗi mạng / 429 / Thử lại, khách -> đăng nhập | dev e2e | PASS |
| Thứ tự câu quiz mới (SLN7) | Không ảnh hưởng: FW6 chỉ đọc tổng hợp (điểm/lượt) từ API, không đọc thứ tự câu | PASS (suy luận từ code) |

## Bug phát hiện
### BUG-1: `?trang=` vượt last_page khi có pending/rejected thì không hiện "Trang này không có khóa học"
- Mức độ: Minor
- Tái hiện: học sinh có 13 khóa + 1 chờ duyệt, vào `/tai-khoan/khoa-hoc-cua-toi?trang=99`.
- Mong đợi: thông báo trang không có khóa + nút "Về trang đầu" (như khi không có pending/rejected).
- Thực tế: nhánh "Trang này không có khóa học" chỉ chạy khi data, pending, rejected đều rỗng. Có pending nên mở tab "Chờ duyệt"; tab "Đang học 13" ghi "Chưa có khóa nào đang học" + "Khám phá khóa học", không có phân trang để quay lại (sai sự thật, total=13).
- Vị trí: `frontend/apps/web/components/my/MyCoursesScreen.tsx:107-110` (điều kiện) và `:131-140` (tab Đang học rỗng). Gợi ý: khi `data.data.length===0 && meta.total>0 && page>1` thì tab "Đang học" hiện EmptyState "Trang này không có khóa học" + "Về trang đầu".

### NIT-1: Trang chi tiết khóa chưa học (0/2 bài) nút ghi "Tiếp tục học", trong khi danh sách ghi "Bắt đầu học"
- `components/my/CourseProgressScreen.tsx:52` (dùng cùng nhãn với `lib/my/format.ts:14`).

### NIT-2: Link tiêu đề khóa trong thẻ danh sách cao 23px ở 375px (< 44px)
- Chỉ link tiêu đề; nút chính "Tiếp tục học/Xem tiến độ" đủ 44px. Thẻ có nút lớn làm đường vào chính nên không chặn. `components/my/MyCourseCard.tsx` (tiêu đề h3).

## Rủi ro & đề xuất
- Đã xác nhận R1 (nhãn "Xem kết quả lượt gần nhất") và R2 (DataTable `sr-only`) là backlog-v2, không chặn.
- Nhánh ảnh bìa thật (`thumbnail_url`) vẫn chưa có e2e (đã có ở danh mục).
- Lượt quiz seed bằng factory không có chi tiết từng câu nên chỉ kiểm điều hướng sang màn kết quả FW5.
- Không kiểm riêng: N+1/index (backend không đổi trong FW6).

## Lệnh đã chạy
- `frontend/apps/web/e2e/seed-qa-fw6.sh --reset` / `--clean` (tinker, tiền tố `e2e-fw6q-`, `fw6q-*@example.com`; đã dọn).
- `E2E_FW6Q="full=196 long=197 unpub=198 zero=199 revoked=202 other=203" frontend/apps/web/e2e/run-qa-fw6.sh` (build `.next-qa-fw6`, `--workers=1`; lần cuối: QA2..QA6 5/5 pass, QA1 pass ở lượt trước; `.next-qa-fw6` và test-results đã xoá).
- `frontend/scripts/pnpm.sh --filter @vitaminvui/web exec ./node_modules/.bin/vitest run components/my lib/my` -> 21/21 pass.
- Không chạy migrate/db:seed/db:wipe; không build vào `.next`; không sửa app/admin/backend/lock.
- File test thêm: `frontend/apps/web/e2e/fw6-qa.spec.ts`, `e2e/seed-qa-fw6.sh`, `e2e/run-qa-fw6.sh`, `playwright.fw6qa.config.ts`.

# QA: FA4 (tab "Chương & bài", app quản trị)
**Kết quả:** PASS (không có bug Critical/High/Major; 2 bug Minor về giao diện 375px, ghi vào backlog)
**Ngày:** 2026-10-07 · **QA:** laravel-qa · Phạm vi: `frontend/apps/admin` (không kiểm `apps/web`/FW4)

## Kiểm tự động
| Hạng mục | Kết quả |
|---|---|
| `eslint .` (admin) | Sạch |
| `vitest run` (admin) | 235/235 (19 file) |
| `tsc --noEmit` trên cây dev | Chỉ lỗi cú pháp ở `.next/dev/types/routes.d.ts` (file sinh tự động của dev server, đã hỏng; không thuộc FA4) |
| `tsc --noEmit` trên bản copy sạch (bỏ `.next`, `next typegen`) | Sạch |
| `next build` production trên bản copy sạch | Thành công (tất cả route biên dịch); đã xoá bản copy. `.next` của dev server không bị đụng |
| e2e thật `chuong-bai-real.spec.ts` (`--workers=1`) | 5/5 pass (4.3 phút, seed `--reset` trước) |
| e2e QA bổ sung `chuong-bai-qa-real.spec.ts` (spec mới do QA thêm) | 9/9 đúng kỳ vọng (QA-1..QA-8, QA-7b là `test.fail` có chủ đích cho BUG-1) |

Ghi chú: build chạy trên bản copy thay vì `NEXT_DIST_DIR=.next-qa`, kết quả tương đương. Chưa gọi Bunny thật (theo yêu cầu).

## Độ phủ acceptance criteria / luồng reviewer lưu ý
| AC / luồng | Test | Kết quả |
|---|---|---|
| US-009 AC8: thêm/đổi tên/xoá chương, bài; kéo-thả chuột; Lên/Xuống; lưu thứ tự | `chuong-bai-real` #1 | Pass |
| US-009 AC11: tải video lên / dán link YouTube; link ngoài chỉ khi xem thử; 422 xuống field | `chuong-bai-real` #1, #2 | Pass |
| Giới hạn 1 GB và định dạng: chặn ở UI, không có request tới API/TUS | `chuong-bai-real` #2 | Pass |
| TUS: tiến độ %, khóa nút Lưu, xử lý → Sẵn sàng, resume sau cắt mạng (HEAD + đúng offset), huỷ, thay video | `chuong-bai-real` #2, #3 | Pass |
| AC6/AC7: giáo viên chỉ khóa của mình, 403 UI và API, QLT sửa khóa của GV khác | `chuong-bai-real` #4, #5 | Pass |
| R1: đang tải 24 MB mà sang menu "Khóa học" (điều hướng SPA, `window.__spa` giữ nguyên), rồi Back: PATCH vẫn tiếp tục khi ở menu khác, quay lại xong tới "Sẵn sàng", server `ready` | QA-1 | Pass |
| R2: xoá bài đang tải 60 MB: sau xoá còn tối đa 1 PATCH đang bay, 5 giây sau không còn PATCH nào; bài biến mất ở cây và server | QA-2 | Pass |
| R3: giữ GET poll cũ, kéo-thả (PUT 200), rồi trả GET cũ: thứ tự trên UI vẫn đúng với server, không bị ghi đè | QA-3 | Pass |
| Hai tab cùng sửa: tab 1 giữ cây cũ, tab 2 thêm bài, tab 1 kéo thả nhận `CURRICULUM_MISMATCH`, có thông báo, cây tải lại thấy bài mới, kéo lại thành công | QA-4 | Pass |
| Mất mạng > 40 s: sau hơn 20 s thử lại hiện "Tải lên đang dừng" + nút "Tải tiếp" + `valuetext` "đang dừng", không tự bắn khi vẫn mất mạng; có `online` thì tiếp tục từ offset > 0 (có HEAD), xong "Sẵn sàng" | QA-5 | Pass |
| 409: xoá bài / chương đã có tiến độ học (dữ liệu tiến độ chèn thật vào DB): thông điệp tiếng Việt, cây không đổi; bài không tiến độ vẫn xoá được | QA-6 | Pass |
| 403 giữa phiên: GV bị gỡ khỏi khóa khi đang mở cây: thêm chương và kéo thả đều ra thông báo "Bạn không có quyền sửa nội dung khóa học này", kéo thả được hoàn lại, F5 ra màn 403 | QA-8 | Pass |
| 375px: không tràn ngang; tay cầm kéo, nút Sửa tên/Xoá/Đóng đạt vùng bấm 44 px (đo bằng `elementFromPoint`) | QA-7 | Pass |
| 375px: các nút còn lại đạt 44 px | QA-7, QA-7b | Fail (BUG-1, BUG-2) |

## Bug phát hiện
### BUG-1: Nút "Thêm bài" ở đầu chương lộ ra ở 375px (trùng nút ở cuối chương, cao 36 px)
- Mức độ: Minor
- Bước tái hiện: đăng nhập `e2e-fa4-gv`, đặt viewport 375x800, mở `/quan-tri/khoa-hoc/{id}/sua?tab=chuong-bai`.
- Mong đợi: ở dưới `sm` chỉ có nút "Thêm bài" cuối chương (cao 44 px); nút ở đầu chương ẩn (`hidden sm:inline-flex`).
- Thực tế: mỗi chương có 2 nút "Thêm bài" cùng lúc; nút ở đầu chương cao 36 px. Class `hidden` thua `inline-flex` do thứ tự CSS (display tính ra `flex`). Đo bằng DOM: `className` kết thúc bằng `h-9 px-3 text-sm hidden sm:inline-flex`, `getComputedStyle(...).display` là `flex`, chiều cao 36.
- Vị trí nghi ngờ: `apps/admin/components/curriculum/ChapterTree.tsx:176` (cần thêm `max-sm:hidden` hoặc đổi sang `max-sm:!hidden`/bọc phần tử, không dựa vào `hidden` + `inline-flex` cùng cấp).
- Test: QA-7b trong `chuong-bai-qa-real.spec.ts` (đánh dấu `test.fail`, bỏ dấu khi đã sửa).

### BUG-2: Nhiều nút/ô nhập trong khung sửa bài dưới 44 px ở 375px
- Mức độ: Minor
- Bước tái hiện: như BUG-1, chọn một bài.
- Mong đợi: design-system v2 §252 yêu cầu vùng chạm tối thiểu 44x44.
- Thực tế: cao 36 px: "Thêm chương", "Lên", "Xuống", "Xoá bài", "Huỷ", "Lưu bài học", ô "Tên bài học" (301x36); checkbox "Cho xem thử" 20x20, radio nguồn video 13x13 (chỉ hàng nhãn, đã bấm được qua label nên mức nhẹ); liên kết breadcrumb "Khóa học của tôi" cao 20 (thuộc khung chung FA3, ngoài phạm vi FA4). Dev R4 chỉ sửa tay cầm kéo, nút đóng, IconButton và "Thêm bài".
- Vị trí nghi ngờ: `LessonForm.tsx`, `CurriculumPanel.tsx` (`Button size="sm"`/`size="md"` + `max-sm:h-11`).

## Rủi ro và đề xuất
- **Chưa kiểm được:** kéo-thả bằng cảm ứng thật (touch) và trên máy thật 375px (Playwright chỉ mô phỏng chuột); trình đọc màn hình cho thông báo kéo-thả; Bunny thật (CORS/Origin `https://video.bunnycdn.com/tusupload`, metadata TUS, CSP `connect-src`); tải tệp gần 1 GB qua mạng chậm (chỉ kiểm UI chặn > 1 GB bằng tệp thưa).
- Quan sát (không phải bug): tab tự tải lại cây khi quay lại từ tab trình duyệt khác (hai tab có thể thấy thay đổi nhau khi chuyển tab), nên kịch bản `CURRICULUM_MISMATCH` chỉ xảy ra khi tab cũ thao tác trong lúc dữ liệu cũ; đã mô phỏng bằng cách giữ GET.
- Poll khi bị 403 giữa phiên chỉ ghi `loadError` mà không đổi màn hình (cây cũ vẫn hiện tới khi thao tác ghi gặp 403 hoặc F5). Chấp nhận được, ghi backlog nếu PO muốn khoá ngay.
- File sinh tự động `.next/dev/types/routes.d.ts` của dev server đang hỏng: cần khởi động lại dev server hoặc `next typegen` thì tsc trên cây dev mới sạch.
- `pnpm-lock.yaml` đang bị FW4 và FA4 cùng sửa: khi commit cần gộp đúng.

## Dọn dẹp
Đã `seed-e2e-curriculum.sh --clean` (xoá khóa, chương, bài, video và tài khoản "E2E FA4", kể cả học sinh giả của QA-6; xác nhận 0 khóa, 0 tài khoản còn lại), xoá `test-results/`, thư mục tín hiệu `e2e/.qa-signal`, bản copy build ở scratchpad. Không commit.

## File QA thêm
- `frontend/apps/admin/e2e/chuong-bai-qa-real.spec.ts` (e2e thật; chạy sau `seed-e2e-curriculum.sh --reset`; QA-6 và QA-8 cần một tiến trình ở host nhận tín hiệu qua thư mục `e2e/.qa-signal` để chèn tiến độ học và gán/gỡ giáo viên bằng tinker, vì Playwright chạy trong container không truy cập DB; script theo dõi nằm ở scratchpad, không commit).

## Sửa sau QA (nextjs-dev, 2026-10-07)

- **BUG-1:** nút "Thêm bài" ở đầu chương dùng `max-sm:hidden sm:inline-flex` (thay `hidden sm:inline-flex` thua thứ tự CSS) trong `ChapterTree.tsx`; ở 375px chỉ còn nút ở cuối chương. Đã bỏ `test.fail` của QA-7b.
- **BUG-2:** ở 375px thêm `max-sm:h-11` (cao 44px) cho: Thêm chương, Lên, Xuống, Xoá bài, Huỷ, Lưu bài học, ô Tên bài học và ô Link video, Huỷ tải lên, Tải tiếp, Đóng, Thử lại, nút "Thêm chương đầu tiên". Checkbox/radio đã nằm trong label cao ≥ 44px. Breadcrumb của FA3 ghi nợ, không sửa.
- Kiểm tra: eslint 0 lỗi (3 warning biến chưa dùng trong spec QA), vitest admin 235/235, e2e `chuong-bai-real` 5/5, `chuong-bai-qa-real` QA-7 và QA-7b pass (chạy kèm test "chuẩn bị"); seed --reset trước, --clean sau.

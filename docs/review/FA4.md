# FA4 — Chương & bài, form bài, tải video TUS (apps/admin)

## Dev

**Trạng thái:** xong, chưa commit. Chờ `laravel-reviewer` → `laravel-qa`.
**Ngày:** 2026-10-07 · **Dev:** nextjs-dev

### Phạm vi
Tab "Chương & bài" trong màn sửa khóa (`/quan-tri/khoa-hoc/{id}/sua?tab=chuong-bai&bai={lessonId}`), đúng design v2 (§14, bản xem trước `v2/quan-tri/khoa-hoc/[id]/sua`):
- Cây chương/bài kéo-thả bằng `@dnd-kit` (chuột/chạm; kéo chương, kéo bài trong chương, kéo bài sang chương khác, thả vào chương rỗng). Đường bàn phím: nút **Lên/Xuống** trong khung sửa bài (qua ranh giới chương). Lưu bằng `PUT curriculum/order` (lạc quan, hoàn lại khi lỗi; `CURRICULUM_MISMATCH` → tải lại cây + thông báo). Khoá kéo-thả khi đang lưu.
- Thêm/sửa tên/xoá chương, thêm/xoá bài (hộp thoại xác nhận; `CHAPTER_HAS_PROGRESS`, `LESSON_HAS_PROGRESS`, `COURSE_LAST_LESSON` có thông điệp tiếng Việt).
- Form bài: tên, "Cho xem thử", nguồn video (tải lên / link YouTube-Vimeo; link ngoài chỉ khi cho xem thử, lý do hiện ngay). 422 xuống đúng field. Cảnh báo khi đổi bài lúc form còn thay đổi chưa lưu.
- Tải video lên bằng `tus-js-client` (dùng nguyên `tus_endpoint` + `headers` do API trả, nên dùng chung VideoLab và Bunny; không màn riêng): chặn ở UI **> 1 GB** (`MAX_VIDEO_BYTES`, thông báo tiếng Việt) và sai định dạng trước khi gọi API; `chunkSize` = 4 MB (≤ 8 MB); tiến độ % + ước lượng thời gian; tự thử lại khi rớt mạng (tus `retryDelays`, tiếp tục từ offset do HEAD trả); hết lượt thử → "Tải lên đang dừng" + nút **Tải tiếp**, và tự tiếp tục khi trình duyệt báo `online`; **Huỷ tải lên** (terminate phiên TUS); cảnh báo `beforeunload` khi đang tải.
- Trạng thái video: nhãn trên cây và trong form (Đang tải lên %, Đang xử lý, Sẵn sàng, Lỗi video, Chưa có video, link ngoài). Lỗi hiện `message` của API (`VIDEO_INVALID`...). Polling `GET chapters` 3 s → 5 s (sau 30 s) → 10 s (sau 2 phút), chỉ khi có bài đang xử lý (hoặc vừa tải xong mà server chưa chuyển trạng thái), bỏ qua khi tab ẩn; chi tiết tên tệp/lý do lỗi lấy từ `GET .../video`.
- Quản lý lượt tải đặt ở `CourseEditScreen` (hook `useUploadManager`), nên đổi bài hoặc đổi tab không làm mất lượt tải đang chạy; rời trang thì dừng.
- Quyền như FA3: giáo viên được gán và staff; quyền thật do API (403 → thông báo, khóa lạ: `course-forbidden`).

### File
Mới:
- `apps/admin/lib/curriculum/{types,api,order,video,errors,tusUpload}.ts` (+ `curriculum.test.ts`)
- `apps/admin/components/curriculum/{CurriculumPanel,ChapterTree,LessonForm,VideoPanel,NameDialog}.tsx`, `useUploadManager.ts` (+ `CurriculumPanel.test.tsx`, `useUploadManager.test.tsx`)
- e2e: `apps/admin/e2e/chuong-bai-real.spec.ts`, `e2e/seed-e2e-curriculum.sh`, `e2e/run-real.sh`, `e2e/fixtures/mini.mp4` (44 KB, ffmpeg testsrc 3 s), `apps/admin/playwright.real.config.ts`

Sửa:
- `components/courses/CourseEditScreen.tsx` (tab theo URL, gắn panel, form thông tin vẫn mounted khi ở tab kia để không mất phần đang sửa; đoạn chữ "sẽ có ở bản tiếp theo" thay bằng chỉ dẫn), `CourseEditScreen.test.tsx` (tab Chương & bài nay là link: 2 link + 1 "Sắp có"), `CourseForm.tsx` (một câu hướng dẫn)
- `components/v2/CourseStatusBadge.tsx` (`VideoStatusBadge` nhận `Pick<…>` thay vì cả `AdminLesson`, dùng chung bản xem trước và bản thật)
- `next.config.ts`: thêm `distDir: process.env.NEXT_DIST_DIR || ".next"` để build kiểm tra không đụng `.next` của dev server (mặc định giữ nguyên).
- `package.json` + `pnpm-lock.yaml`: **thêm 3 package đã có trong danh sách duyệt của tasks.md (G2)**: `tus-js-client@4.3.1`, `@dnd-kit/core@6.3.1`, `@dnd-kit/sortable@10.0.0` (ghim đúng phiên bản như các dependency khác). Lưu ý `pnpm-lock.yaml` cũng đang bị FW4 sửa (apps/web): khi commit cần gộp cho đúng.
- Không sửa `packages/ui`, không đụng `apps/web`, không sửa backend.

### Biến môi trường
Không thêm biến mới. `NEXT_PUBLIC_VIDEO_UPLOAD_URL` đã có trong `env.ts`, `.env.example` và đã nằm trong CSP `connect-src` của `proxy.ts`; khi dùng Bunny đặt `https://video.bunnycdn.com` (chưa thử Bunny thật theo yêu cầu).

### Cách test
- `frontend/scripts/pnpm.sh --filter @vitaminvui/admin exec tsc --noEmit` · `… exec eslint .` · `… exec vitest run`
- Build kiểm tra: `… exec env NODE_OPTIONS=--max-old-space-size=1536 NEXT_DIST_DIR=.next-check ./node_modules/.bin/next build` (rồi xoá `.next-check`).
- e2e thật (VideoLab nội bộ, `VIDEO_PROVIDER=internal`, queue `worker-video` đang chạy, Mailpit):
  1. `frontend/apps/admin/e2e/seed-e2e-curriculum.sh --reset` (bắt buộc trước MỖI lần chạy spec: spec thêm/xoá/sắp xếp lại dữ liệu)
  2. `frontend/apps/admin/e2e/run-real.sh e2e/chuong-bai-real.spec.ts` (Playwright trong Docker, dùng dev server admin :3001 và backend :8000 đang chạy, không dựng server mới; `--workers=1 --retries=0` đã đặt trong `playwright.real.config.ts`)
  3. Dọn: `seed-e2e-curriculum.sh --clean` (xoá khóa/chương/bài/video VideoLab/tài khoản "E2E FA4").
- Tài khoản riêng FA4 (mật khẩu `Password123!`): `e2e-fa4-gv` (giáo viên được gán), `e2e-fa4-gv2` (giáo viên khác), `e2e-fa4-qlt1..2` (QLT, MFA; spec dùng 1 lần đăng nhập MFA/lần chạy, đổi bằng `FA4_STAFF=fa4-qlt2` nếu bị giới hạn OTP). Không dùng tài khoản học sinh của FW4.

### Kết quả đã chạy (2026-10-07)
- tsc sạch · eslint sạch · vitest toàn admin **229/229** (19 file; mới: 24 + 8 + 23 test) · `next build` thành công.
- e2e thật `chuong-bai-real.spec.ts` **5/5 pass** (~5 phút): cây + thêm/đổi tên/xoá, kéo-thả chuột thật (bài trong chương, sang chương khác, vào chương rỗng, kéo chương), Lên/Xuống, form bài + link YouTube + 422, F5 giữ bài đang sửa, 375px không tràn ngang; tải video thật lên VideoLab qua TUS (tệp thưa > 1 GB và `.avi` bị chặn không có request nào tới API/TUS; tiến độ %, khoá nút Lưu, xử lý → "Sẵn sàng" 0:03 qua polling; tệp `.mp4` giả → thông báo của API; 503 `VIDEO_PROVIDER_UNAVAILABLE`); **TUS resume** (cắt mạng 2 lần PATCH, tus gửi HEAD rồi tiếp tục đúng offset, không về 0); huỷ tải; đổi bài giữa lúc tải không mất lượt; thay video có xác nhận; QLT sửa khóa của GV khác; GV khác bị 403 (UI + API).

### Đối chiếu AC (US-009, phần giao diện)
AC8 (thêm/sắp xếp chương, bài, lưu và áp dụng ngay): đạt. AC11 (chọn "Tải video lên hệ thống" hoặc "Dán link video ngoài", lưu đúng hình thức): đạt (tải lên thật + link YouTube). AC3 (xuất bản khi chưa có chương/bài) vẫn do FA3; sau khi thêm/xoá chương-bài màn sửa tải lại số liệu khóa. AC6/AC7 (giáo viên chỉ khóa của mình, 403): đạt, đã e2e.

### Điều chưa làm / lưu ý cho reviewer, QA
- **Chưa thử Bunny thật** (theo yêu cầu). Mã không phân nhánh theo nhà cung cấp; cần thử khi có thư viện staging: CORS/`Origin` của `https://video.bunnycdn.com/tusupload`, metadata TUS (đang gửi `filename`, `filetype`, `title`), CSP `connect-src` với `NEXT_PUBLIC_VIDEO_UPLOAD_URL=https://video.bunnycdn.com`.
- `ApiError` của api-client không lộ `context` nên 422 `VIDEO_QUOTA_EXCEEDED` chỉ có thông điệp chung (không nêu `remaining_bytes`).
- Sau **Huỷ tải lên**, VideoLab terminate phiên nên bài về "Chưa có video" (đã e2e). Trường hợp phiên dở do đóng tab: bài giữ trạng thái `uploading` ở server; UI báo "Tải lên chưa hoàn tất" và cho chọn lại tệp (đã có unit test; pruner `videos:prune-orphans` dọn asset cũ).
- Kéo-thả bằng bàn phím trực tiếp trên tay cầm (KeyboardSensor của dnd-kit) có bật nhưng chỉ chắc chắn trong cùng danh sách; đường bàn phím đầy đủ là nút Lên/Xuống. QA a11y nên thử bằng trình đọc màn hình (thông báo kéo-thả đã Việt hoá).
- Không có nút "Lên/Xuống" cho chương (chỉ kéo bằng chuột/chạm hoặc bàn phím trên tay cầm); thiết kế v2 không vẽ nút này. Nếu PO muốn, thêm sau.
- Cây chỉ polling khi có bài `processing`/vừa tải xong; bài đang `processing` do người khác tải từ máy khác cũng được poll (theo trạng thái server).
- `eslint-disable react-hooks/refs` ở đầu `ChapterTree.tsx` (false positive của rule với `useSortable`/`useDroppable`) và một dòng `set-state-in-effect` ở `CurriculumPanel.tsx` (hàm tải bất đồng bộ), đều có giải thích.
- Luồng `laravel-qa` nên kiểm kỹ: kéo-thả thật trên màn cảm ứng (touch) và 375px; hai tab cùng sửa một khóa (422 `CURRICULUM_MISMATCH`); tải tệp gần 1 GB (chunk 4 MB, mạng chậm); mất mạng > 40 s (trạng thái "đang dừng" + `online`); giáo viên bị bỏ khỏi khóa giữa lúc đang sửa (403 giữa phiên); xoá chương/bài đã có tiến độ học (409).

## Review

**Kết luận:** APPROVE (không có BLOCKER; 4 SHOULD, 4 NIT) · **Người review:** laravel-reviewer · 2026-10-07
**Đã chạy:** tsc sạch; vitest curriculum 55/55 (3 file). Không chạy build/e2e.

### Tổng quan
Làm kỹ, bám contract: `PUT curriculum/order` gửi mảng gốc đủ ID, lạc quan + hoàn lại, `CURRICULUM_MISMATCH`/404 tải lại cây, 403 màn riêng, 409 có thông điệp riêng. TUS dùng đúng endpoint/header API trả, chunk 4 MB, `storeFingerprintForResuming:false` nên KHÔNG có URL upload trong localStorage (không rò sang tài khoản khác); header chỉ nằm trong bộ nhớ, không log. CSP `connect-src` đã có `NEXT_PUBLIC_VIDEO_UPLOAD_URL` (proxy.ts:22). Không có `iframe`/`dangerouslySetInnerHTML`/`localStorage`/`console` trong code FA4; link ngoài chỉ gửi lên API kiểm (admin không nhúng). Polling có dọn timer/abort, bỏ qua tab ẩn, dừng khi hết bài đang xử lý.

### Phát hiện
**R1 [SHOULD] Rời màn bằng điều hướng trong ứng dụng (menu, nút Back) khi đang tải: mất lượt tải im lặng**
- Vị trí: `components/curriculum/useUploadManager.ts:171-188`
- `beforeunload` chỉ bắt đóng tab/F5, không bắt điều hướng SPA. Cleanup unmount gọi `abort()` (không terminate) và xoá map mà không báo. Giáo viên bấm sang menu khác giữa lúc tải 800 MB là mất, bài kẹt `uploading` ở server.
- Đề xuất: chặn link nội bộ khi `hasActive` (confirm trong layout/Link wrapper, hoặc `popstate` guard), hoặc đưa manager lên layout của `/quan-tri` để lượt tải sống qua điều hướng. Ít nhất thêm confirm.

**R2 [SHOULD] Xoá bài/chương đang có lượt tải cục bộ: lượt tải vẫn chạy**
- Vị trí: `components/curriculum/CurriculumPanel.tsx:435-451, 464-481`
- Sau khi xoá thành công không gọi `manager.cancel(lessonId)`; TUS tiếp tục đẩy dữ liệu lên asset đã xoá, `onFinished` sau đó đặt vào `watched` bài không còn.
- Đề xuất: trước/sau `deleteLesson`/`deleteChapter`, `manager.cancel` cho mọi bài bị xoá (hoặc khoá nút Xoá khi bài đang tải).

**R3 [SHOULD] Poll cũ có thể ghi đè thứ tự vừa lưu**
- Vị trí: `CurriculumPanel.tsx:88-100, 144-163`
- `load()` chỉ bỏ kết quả khi `savingRef` đang true lúc nhận. Yêu cầu GET gửi TRƯỚC khi bấm kéo thả nhưng về SAU khi PUT xong sẽ `commit` dữ liệu cũ → cây nhảy về thứ tự cũ dù server đã lưu (rồi lần poll sau mới sửa lại).
- Đề xuất: ghi `loadSeq` tăng mỗi lần `reorder`/mutation, `load` bỏ kết quả nếu seq đổi giữa lúc gửi và lúc nhận (hoặc abort GET đang bay khi bắt đầu `reorder`).

**R4 [SHOULD] Vùng chạm 36px, thiết kế yêu cầu 44px**
- Vị trí: `ChapterTree.tsx:60` (`GRIP` = `size-9`), `CurriculumPanel.tsx:279` (nút đóng `size-9`), `IconButton size="sm"` ở `ChapterTree.tsx:146-153`, nút "Thêm bài" `size="sm"`.
- design-system-v2 §252: tối thiểu 44×44. Tay cầm kéo trên 375px là thao tác chính, 36px dễ chạm nhầm sang link bài.
- Đề xuất: GRIP `size-11` (hoặc `min-h-11 min-w-11` ≤ sm), IconButton `size="md"` ở mobile.

**R5 [NIT] `NEXT_DIST_DIR` chưa kiểm giá trị; `.next-check` chưa nằm trong .gitignore**
- Vị trí: `next.config.ts:9`; `frontend/.gitignore:6`
- Chỉ kiểm soát lúc build nên không phải lỗ hổng, nhưng giá trị như `../x` hoặc `/` sẽ ghi/xoá ngoài dự án. Và `.next-check` sẽ lọt vào `git status`/commit.
- Đề xuất: `const dist = process.env.NEXT_DIST_DIR; distDir: dist && /^\.next[\w-]*$/.test(dist) ? dist : ".next"`; thêm `.next-*/` vào .gitignore. Kiểm Dockerfile production không đặt biến này.

**R6 [NIT] Kéo-thả bàn phím/đọc màn hình còn mỏng**
- Vị trí: `ChapterTree.tsx:62-68`
- `onDragOver` trả `undefined` nên người dùng đọc màn hình không biết đang ở vị trí nào; thông báo thả không nói đích. Chương không có Lên/Xuống (đã nêu trong ghi chú Dev). KeyboardSensor giữa hai `SortableContext` lồng có thể không qua được chương khác (Dev đã nói). Chấp nhận cho v1, QA a11y xem lại.
- Đề xuất: `onDragOver`/`onDragEnd` đọc "vị trí N/M trong «tên chương»"; cân nhắc Lên/Xuống cho chương.

**R7 [NIT] Tab "Thông tin chung" khi form bài còn thay đổi: mất không hỏi**
- Vị trí: `CourseEditScreen.tsx` (nav tab) + `CurriculumPanel` unmount. Chọn bài khác thì có hỏi (`dirtyRef`), nhưng đổi tab thì không. Lượt tải thì giữ được (manager ở màn cha) nên chỉ mất phần tên/xem thử chưa lưu.

**R8 [NIT] Bài đã tải xong nhưng server kẹt `created/uploading` thì poll mãi (10 s/lần)**
- Vị trí: `CurriculumPanel.tsx:112-139`. `watched` chỉ thoát khi hết trạng thái pending. Nên có trần (ví dụ 10 phút) rồi dừng và báo "Kiểm tra lại sau". Ngoài ra poll nuốt lỗi 5xx/mạng im lặng: chấp nhận được.

### Đối chiếu
| Yêu cầu | Kết quả |
|---|---|
| AC8 thêm/sắp xếp chương, bài | Đạt (order.ts, reorder, mismatch) |
| AC11 hai nguồn video | Đạt; link ngoài chỉ khi xem thử (UI), server là nguồn quyền |
| Giới hạn 1 GB, định dạng | Chặn ở UI (`validateVideoFile`), server vẫn kiểm; `VIDEO_TOO_LARGE` có thông điệp |
| TUS chunk ≤ 8 MB, header API | 4 MB, dùng nguyên `session.upload` |
| 403/409/422 | Đạt (có test) |
| Seed script | Từ chối khi APP_ENV không phải local/testing; xoá chỉ theo tiền tố "E2E FA4" |

### Gợi ý cho QA
- Điều hướng SPA (menu/Back) giữa lúc tải (R1); xoá bài đang tải (R2).
- Kéo thả ngay lúc poll đang chạy (R3); chạm 375px trên máy thật.
- Bunny thật: CORS/Origin `https://video.bunnycdn.com/tusupload`, metadata, CSP.
- Tab thứ hai sửa cùng khóa, `CURRICULUM_MISMATCH`; GV bị gỡ giữa phiên (403 khi poll: hiện chỉ lưu `loadError`, màn vẫn hiện cây cũ).

## Dev đã sửa (sau review, 2026-10-07)

| Mục | Cách sửa |
|---|---|
| R1 | `useUploadManager` tách thành lõi + `UploadProvider` đặt ở `app/quan-tri/layout.tsx`; lượt tải sống qua mọi điều hướng trong ứng dụng (menu, Back, đổi tab). Màn sửa khóa chỉ đăng ký sự kiện xong/dừng theo `courseId`. Chỉ khi rời khu quản trị/đăng xuất mới dừng; `beforeunload` giữ nguyên. Test: lượt tải còn chạy khi màn dùng nó bị gỡ. |
| R2 | Xoá bài/chương thành công thì `manager.cancel` cho mọi bài bị xoá (hủy sau khi xoá thành công để lỗi 409 không làm mất lượt tải). 2 test. |
| R3 | `seqRef` tăng khi bắt đầu và kết thúc lưu thứ tự; `load` bỏ kết quả nếu seq đổi. Test: GET bay trước, về sau PUT, không ghi đè. |
| R4 | Tay cầm kéo, nút đóng khung sửa, IconButton sm: vùng chạm 44 px bằng `before:-inset-1` (hình vẫn 36 px); nút "Thêm bài" `max-sm:h-11`. |
| R5 | `next.config.ts`: `NEXT_DIST_DIR` chỉ nhận `^\.next[\w-]*$`, sai thì `.next`; `.next-*/` thêm vào `frontend/.gitignore`. |
| R6 | Thông báo kéo-thả có vị trí/đích ("vị trí 2/3 trong chương «…»"), `onDragOver` đọc đích. Lên/Xuống cho chương để backlog. |
| R7 | Tab "Thông tin chung" khi form bài có thay đổi chưa lưu: hộp xác nhận như khi đổi bài (`dirtyRef` đưa từ màn cha vào panel). Test trong `CourseEditScreen.test.tsx`. |
| R8 | `POLL_MAX_MS` = 10 phút: dừng poll, hiện cảnh báo "Video chưa cập nhật trạng thái" + nút "Kiểm tra lại". Test bằng fake timers. |

Kiểm tra: eslint sạch · vitest admin 235/235 (thêm 6 test) · e2e `chuong-bai-real` 5/5 (seed --reset trước, --clean sau). `tsc`: code FA4 không lỗi; chỉ còn lỗi cú pháp trong file sinh tự động `.next/dev/types/routes.d.ts` của dev server (file bị ghi hỏng, không thuộc FA4, em không sửa vì là tệp dev server đang dùng; khởi động lại dev server/`next typegen` sẽ sinh lại).

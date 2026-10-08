# SLN8 - Dev (nextjs-dev)

Lỗi: trang `/khoa-hoc/{slug}` của học sinh đã sở hữu khóa không bấm được "Tiếp tục học" và không chọn được bài (TODO(FW4) còn sót).

Đã sửa (chỉ `frontend/apps/web`):
- `components/catalog/CourseAction.tsx`: case `owned` là `ButtonLink` tới `model.href` ("Tiếp tục học"), bỏ chữ "Sắp mở trang học"; dùng chung cho bản `compact`.
- `components/catalog/CourseOutline.tsx`: người sở hữu thấy mỗi bài là `AppLink` tới `routes.lesson(courseId, lesson.id)`; `courseId` nay bắt buộc. Outline công khai không có quiz (schema không có) nên không có link quiz.
- Bài "Học thử" của người chưa sở hữu: GIỮ nhãn, chưa bấm được (FW4 chưa có luồng `GET /preview/lessons/{lesson}/playback`). Việc riêng nếu PO muốn.
- `components/v2/course/*` là trang design preview (dữ liệu mẫu), không có TODO(FW4) nên không đổi.
- Test: `CourseCta.test.tsx` (owned -> link `/hoc/7/bai/42`; outline owned -> link `/hoc/7/bai/11`); e2e `e2e/hoc-video-real.spec.ts` ca "SLN8"; thêm `playwright.sln8.config.ts` + `e2e/run-sln8-real.sh`.

Kết quả: tsc sạch, eslint sạch, vitest components/catalog 20/20, e2e SLN8 1/1 (backend thật, seed-e2e-learn, đã --clean).

---

# Review (laravel-reviewer)
**Kết luận:** APPROVE (có 2 SHOULD, 2 NIT)
**Phạm vi:** diff chưa commit apps/web: CourseAction, CourseOutline, CourseCta.test, hoc-video-real.spec, playwright.sln8.config, run-sln8-real.sh · vitest components/catalog 20/20 xanh.

- Đúng: `owned` là ButtonLink tới `model.href` (`learnHref`: `/hoc/{id}/bai/{resume}` hoặc `/hoc/{id}`); bài trong mục lục dùng `routes.lesson(courseId, lesson.id)` qua AppLink (không dính CAPTCHA_PATHS). Bản compact dùng cùng ButtonLink block, chỉ bỏ dòng "đã sở hữu". Link có tên rõ (tiêu đề + thời lượng), `focus-ring`, min-h-12.
- `CourseOutline` chỉ có 1 chỗ gọi thật (`app/(site)/khoa-hoc/[slug]/page.tsx:144`, truyền `courseId={course.id}`); trang v2-preview dùng component khác. Không chỗ nào thiếu prop.
- Quyền: link chỉ hiện khi `owned` (viewer-state từ API, khách/chưa sở hữu vẫn là hàng tĩnh + khóa). Ẩn UI không phải ranh giới bảo mật; trang học gọi `/api/v1/learn/lessons/{id}/playback` ở backend (đã kiểm ở FW4) nên người đoán URL vẫn bị chặn phía server.

## Phát hiện
- R1 [SHOULD] e2e SLN8 dùng `seed-e2e-learn.sh` (tiền tố `e2e-fw4-` / `fw4-*@example.com`): script xóa-tạo-lại cả khóa lẫn user cùng tiền tố, và Dev đã chạy `--clean` sau cùng. Nếu đó là dữ liệu demo dùng chung của PO thì bị xóa. Đề xuất: ca SLN8 dùng seed tiền tố riêng (vd `e2e-sln8-`, `sln8-hs@example.com`) hoặc seed nhẹ (1 khóa free, 2 bài, 1 học sinh ghi danh, không cần video thật), và README ghi rõ không `--clean` dữ liệu demo. Ca này chỉ cần mục lục + điều hướng, không cần video/hls nên không cần VideoLab.
- R2 [SHOULD] Header `e2e/run-sln8-real.sh` và comment `playwright.sln8.config.ts` là bản sao của progress: nhắc `seed-e2e-progress.sh --reset/--clean`, `run-progress-real.sh`, `E2E_FW4` với `qa/pend/rej`, "Next dev chạy trong container". Sai hướng dẫn (seed đúng là seed-e2e-learn.sh; Next chạy bản build). Sửa comment; chạy theo header hiện tại sẽ không ra dữ liệu. Config còn `testMatch` chạy cả file hoc-video-real (toàn bộ ca FW4, timeout 600s) trong khi chỉ cần ca SLN8 — thêm `grep: /SLN8/` hoặc tách spec.
- N1 [NIT] Comment `CourseOutline`: "Bài học thử: nhãn "Học thử". chưa bấm được" — dấu chấm/viết hoa thừa.
- N2 [NIT] Test CourseOutline chỉ phủ link owned; nên thêm assert chưa sở hữu/khách KHÔNG có `role=link` (đang có test ở nhánh khác nhưng không khẳng định cho bài khóa). Cân nhắc thêm ca compact (`CourseAction compact`) cho owned.
- Lưu ý ngoài phạm vi: `tsc --noEmit` báo 2 lỗi ở `app/(site)/tai-khoan/khoa-hoc-cua-toi/**` (typed routes/`params` unknown) — không thuộc diff SLN8, có vẻ do types `.next` cũ; Dev xác nhận tsc sạch với `.next` đúng. ESLint không chạy được trong môi trường review (corepack tải pnpm), tin báo cáo Dev.

## Gợi ý cho QA
- Owned chưa học bài nào (`resume_lesson_id` null) -> "Tiếp tục học" về `/hoc/{id}` có redirect đúng không.
- Mobile 375px: nút compact và hàng bài; Tab/Enter/focus ring.
- Khách và học sinh chưa ghi danh: không link tới /hoc; mở thẳng URL bài -> bị chặn.
- Hết hạn/thu hồi ghi danh sau khi cache viewer-state.

---

# Dev - sửa theo review (R1, R2, N1, N2)
- R1: `e2e/seed-e2e-sln8.sh` (`--reset|--clean`, chỉ tiền tố `e2e-sln8-` / `sln8-*@example.com`, tinker, local/testing); ca e2e chuyển sang `e2e/sln8-real.spec.ts` (biến `E2E_SLN8`), gỡ khỏi `hoc-video-real.spec.ts`. Không còn chạy seed-e2e-learn/quiz.
- R2: sửa header `run-sln8-real.sh`, comment `playwright.sln8.config.ts` (`testMatch` chỉ `sln8-real.spec.ts`).
- N1: sửa dấu câu comment `CourseOutline`.
- N2: thêm test `CourseAction compact` owned -> link; người chưa sở hữu/khách không có link ở bài khoá và bài học thử.
- Kết quả: vitest components/catalog 22/22; eslint sạch; e2e SLN8 1/1 (seed mới, đã --clean). tsc: 3 lỗi typed-routes ở `app/(site)/tai-khoan/khoa-hoc-cua-toi/**` (AppRoutes), không thuộc file SLN8.

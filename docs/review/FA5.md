# FA5 — Soạn quiz (xem trước KaTeX) · apps/admin

## Dev (2026-10-08)

**Trạng thái: xong, chờ review.** Chỉ sửa `frontend/apps/admin` (+ `pnpm-lock.yaml` cho katex). Không đụng apps/web, backend; không commit.

### Phạm vi đã làm
- Tab "Bài tập" trong `/quan-tri/khoa-hoc/{id}/sua?tab=bai-tap` (thay "Sắp có"): bảng bài tập (tên, gắn với, số câu, thời gian), cảnh báo "N bài tập chưa có câu", tạo/sửa thông tin (hộp thoại, ô "Gắn với" gộp chương + bài, 1–300 phút hoặc không giới hạn), xoá có xác nhận. Đổi tab khi form bài còn chưa lưu → hỏi xác nhận (mở rộng cơ chế FA4).
- Trang mới `/quan-tri/khoa-hoc/{id}/bai-tap/{quiz}`: danh sách câu (thứ tự học sinh làm, đáp án đúng, có/chưa lời giải, đếm "n/200 câu", đủ 200 khoá nút thêm); `?cau=ID|moi` mở khung soạn: thanh chèn nhanh, 4 đáp án + radio đáp án đúng, lời giải, xem trước KaTeX dính bên phải (debounce 250 ms), dãy số câu + "Câu trước/sau", xoá câu có xác nhận.
- Kiểm tại chỗ đúng luật `QuizText` của server (thẻ HTML, ký tự điều khiển/bidi/độ rộng 0, độ dài, 4 đáp án, đúng 1 đáp án đúng) + cảnh báo thiếu `$`. 422 hiện dưới đúng ô (`content`, `explanation`, `options.N.content` -> ô đáp án N, `options` -> "Đáp án đúng"), hộp tóm tắt lỗi có liên kết nhảy tới ô và nhận focus; 403/404/409/429/mạng có thông điệp riêng; trang 403 và 404 riêng.
- Copy-on-write: PUT trả `id` khác -> thay câu trong danh sách bằng id mới, `router.replace` sang `?cau=<id mới>`, hiện Alert info "Đã lưu thành bản mới của câu hỏi"; mở lại URL id cũ -> thông báo "Không tìm thấy câu hỏi này" (không trắng trang). Câu chưa ai làm: giữ id, "Đã lưu lúc HH:MM" (múi giờ VN).
- Chặn bấm kép bằng ref (lưu/xoá/tạo quiz), xác nhận rời trang chưa lưu (mọi liên kết trong khung, kể cả breadcrumb, + `beforeunload`), 44px ở 375px (nút, chip số câu, thanh chèn nhanh).

### KaTeX (cùng web)
- `katex@0.19.0` ghim đúng, `pnpm-lock.yaml` còn `--frozen-lockfile` được (lockfile đã gộp với thay đổi katex của FW5 ở web, chưa commit).
- Sao chép có ghi nguồn: `lib/quiz/math.ts` (cấu hình `KATEX_OPTIONS`: trust:false, strict warn, maxSize 10, maxExpand 1000, throwOnError:false, giới hạn 2000 ký tự), `components/quiz/MathText.tsx`, `components/quiz/quiz.css` từ `apps/web` (FW5). `eslint.config.mjs` thêm allowlist `components/quiz/MathText.tsx` cho `dangerouslySetInnerHTML` (đúng cách web làm) và bỏ qua `.next-*/**`.
- **Đề xuất** (không tự làm vì FW5 chưa commit): chuyển 3 file trên vào `packages/ui` để hết bản sao; sửa một bên phải sửa bên kia.

### File
- Mới: `lib/quiz/{types,api,errors,logic,math}.ts`, `lib/quiz/quiz.test.ts`, `components/quiz/{MathText,QuestionEditor,QuizSettingsDialog,QuizListPanel,QuizComposerScreen}.tsx` (+ `.test.tsx` cho 4 cái cuối trừ MathText), `components/quiz/quiz.css`, `app/quan-tri/khoa-hoc/[id]/bai-tap/[quiz]/page.tsx`, `e2e/soan-quiz-real.spec.ts`, `e2e/seed-e2e-quiz.sh`.
- Sửa: `components/courses/CourseEditScreen.tsx` (+ test), `eslint.config.mjs`, `package.json`, `pnpm-lock.yaml`.
- Không đổi biến môi trường. `components/v2/Quiz*.tsx` và `lib/mock/v2/quizzes.ts` (bản xem trước của Designer) giữ nguyên, vẫn phục vụ `/v2/...`.

### Chênh lệch / thiếu API (chuyển laravel-dev / architect)
1. **Không có API đổi thứ tự câu** (contract T21 đã ghi ngoài MVP): yêu cầu "sắp xếp câu" chưa làm được; UI ghi rõ "chưa đổi được thứ tự, câu mới luôn thêm vào cuối" (đúng mockup). Cần endpoint kiểu `PUT .../questions/order` nếu PO muốn.
2. **`QuizQuestionResource` không báo câu đã có lượt làm** (câu hỏi mở số 17 của Designer): cảnh báo copy-on-write chỉ hiện SAU khi lưu (dựa vào `id` đổi). Muốn báo TRƯỚC khi lưu cần `has_attempts`/`attempts_count`.
3. **Admin không đọc được cờ `quiz_time_limit_enabled`** (chỉ có ở `GET /api/v1/config/public` của host học sinh, khác origin). Hệ quả: ô thời gian luôn hiện; khi cờ tắt, server bỏ qua giá trị, FE phát hiện bằng so sánh response và hiện toast "Giới hạn thời gian đang tắt... chưa được áp dụng" + gợi ý trong hint. Nếu muốn ẩn hẳn ô như thiết kế, cần field trong `/admin/auth/me` hoặc endpoint cấu hình admin.
4. Xoá câu KHÔNG đánh số lại `position` (khoảng trống), nên UI đánh số theo vị trí trong danh sách (1..n), không dùng `position`. Văn bản design ghi "các câu phía sau được đánh số lại" -> đúng về hiển thị.
5. Giới hạn 200 câu: dùng đếm phía client (`questions.length`) + `QUIZ_QUESTION_LIMIT` từ server làm chuẩn khi đua nhau thêm.

### Kiểm tra đã chạy
- `tsc --noEmit` sạch; `eslint .` sạch; `vitest run` admin: 29 file, 350 test pass (mới: 16 + 7 + 7 + 11 = 41 test cho quiz, sửa 1 test CourseEditScreen).
- `next build` với `NEXT_DIST_DIR=.next-check` thành công, đã xoá thư mục sau khi build; không đụng `.next` của dev server.
- e2e thật `soan-quiz-real.spec.ts` (Playwright `--workers=1`, backend thật, MFA qua Mailpit): 5/5 pass. Seed `e2e/seed-e2e-quiz.sh [--reset|--clean]` (tiền tố `E2E FA5`, tài khoản `e2e-fa5-*`, chỉ local/testing). Lưu ý OTP: 1/phút/tài khoản; trước khi chạy lại nên `--reset` và xoá `otp_codes` của `e2e-fa5-%` + `redis-cli -n 4 flushdb`.
  - Phủ: tab/bảng/cảnh báo, tạo (kiểm tại chỗ rồi tạo thật, có số phút), sửa thông tin, xoá quiz, 404 (quiz không tồn tại / quiz khóa khác), thêm câu (hộp tóm tắt lỗi + focus, chặn `<b`, chèn nhanh, xem trước KaTeX + `.katex-error`), copy-on-write thật (id đổi, id cũ 404, vị trí giữ nguyên), sửa tại chỗ, rời trang chưa lưu, 422 (mô phỏng phản hồi Laravel bằng `page.route` vì client chặn trước các lỗi thật), thêm/xoá câu, trần 200 (API trả `QUIZ_QUESTION_LIMIT`), giáo viên được gán soạn ở 375px (không tràn ngang, nút >= 44px), giáo viên không được gán 403 ở API và UI.

### Luồng `laravel-qa` nên kiểm kỹ
- Copy-on-write khi hai tab cùng sửa một câu có lượt làm (tab thứ hai lưu vào id cũ -> 404 -> "không còn tồn tại, đã tải lại danh sách").
- Quiz đang có học sinh làm dở khi admin sửa/xoá câu; xoá quiz rồi mở link cũ.
- Xem trước với công thức độc hại/lỗi: `\href`, `\includegraphics`, macro đệ quy, công thức > 2000 ký tự, `$` lẻ; so sánh ảnh xem trước với trang làm quiz của học sinh (FW5) cho cùng nội dung.
- Văn bản có `<`/`>` đứng riêng (`x > 2`) được chấp nhận, `<b` bị chặn cả ở client lẫn server (thông điệp khớp nhau).
- Giáo viên khóa khác, QLT ở khóa của giáo viên, chuyển vai trò giữa phiên.

---

## Review (laravel-reviewer, 2026-10-08)

**Kết luận: APPROVE** (0 BLOCKER, 2 SHOULD, 3 NIT). Phạm vi: toàn bộ file FA5 chưa commit trong `frontend/apps/admin` (lib/quiz, components/quiz, trang `bai-tap/[quiz]`, CourseEditScreen + test, eslint, package.json, e2e + seed). Đã chạy lại: `tsc --noEmit` sạch, eslint (quiz + courses) sạch, vitest 5 file / 56 test pass.

### Tổng quan
Làm kỹ: KaTeX đúng cấu hình bản FW5, copy-on-write dùng id trong response, chặn bấm kép bằng ref ở cả 3 nơi, ánh xạ 422 về từng ô khớp contract, luật kiểm client khớp `App\Rules\QuizText` (đã đối chiếu từng dải ký tự bidi/độ rộng 0 và `\p{Cc}`). Không có `any`, không token/localStorage/NEXT_PUBLIC.

### An toàn KaTeX
- `lib/quiz/math.ts` so với `apps/web/lib/quiz/math.ts`: `KATEX_OPTIONS` (trust:false, strict warn, maxSize 10, maxExpand 1000, throwOnError:false, errorColor), `MAX_TEX_LENGTH` 2000 và `splitMath` GIỐNG HỆT; chỉ khác comment. `MathText.tsx` khác web ở import css; `quiz.css` chỉ khác dòng chú thích. Không lệch cấu hình.
- `dangerouslySetInnerHTML` chỉ xuất hiện trong `components/quiz/MathText.tsx`, chỉ nhận chuỗi từ `katex.renderToString`; allowlist eslint đúng 1 file (như web). Chữ thường đi qua React. Đạt.

### Phát hiện
**R1 [SHOULD] Xác nhận rời trang chưa phủ liên kết ngoài khung nội dung và nút Back**
- Vị trí: `components/quiz/QuizComposerScreen.tsx` (`guardClick` gắn ở div bọc, `onClickCapture`).
- Vấn đề: chỉ chặn liên kết bên trong div. Sidebar/header của shell admin và nút Back/Forward của trình duyệt đi qua điều hướng mềm của Next: `beforeunload` không bắn, nên câu đang soạn dở (tới 5000 ký tự + 4 đáp án) mất không cảnh báo. Mô tả của Dev "mọi liên kết trong khung" đúng, nhưng rủi ro mất dữ liệu người dùng vẫn còn.
- Đề xuất: bắt `click` ở cấp document (capture, lọc `a[href]` nội bộ, trừ `#`/`_blank`) khi `dirtyRef.current`, hoặc đưa cơ chế dirty vào shell admin dùng chung với FA4; Back: thêm `popstate` + `history.pushState` guard. Nếu chưa làm trong story này thì ghi backlog và nêu rõ trong AC của QA.

**R2 [SHOULD] Cảnh báo "thiếu $" dùng prop `error` của Field**
- Vị trí: `QuestionEditor.tsx` (`error={errors.content ?? warn(draft.content)}` và 3 chỗ tương tự).
- Vấn đề: cảnh báo không chặn lưu nhưng hiển thị như lỗi (màu danger, thường kèm `aria-invalid`/`role=alert` của Field), gây hiểu nhầm là bị chặn và đọc sai với trình đọc màn hình. Công thức hợp lệ có `\$` đã được loại, nhưng văn bản tiền tệ như "giá 5$" vẫn báo "lỗi".
- Đề xuất: truyền qua `hint`/prop warning riêng (màu warning, không `aria-invalid`), hoặc đổi chữ thành "Lưu ý:" và dùng tông warning.

**R3 [NIT] Bản sao 3 file KaTeX**
- `lib/quiz/math.ts`, `MathText.tsx`, `quiz.css` trùng với apps/web. Đồng ý đề xuất chuyển vào `packages/ui` sau khi FW5 commit; ghi vào backlog-v2 (một test so sánh cấu hình hai bên là cách rẻ để chống lệch trong lúc chờ).

**R4 [NIT] `pnpm-lock.yaml` đang trộn thay đổi katex của FW5 và FA5 (chưa commit)**
- Khi PO commit, commit FW5 trước hoặc cùng FA5 để `--frozen-lockfile` không vỡ ở CI. Không cần sửa code.

**R5 [NIT] Xem trước `JSON.stringify/parse` toàn bộ draft mỗi lần render**
- `QuestionEditor.tsx` (`previewKey`, `JSON.parse(debouncedKey)`): chi phí nhỏ, nhưng có thể debounce trực tiếp object draft. Danh sách 200 câu render 200+ lần `MathText` (đã `useMemo`): chấp nhận được, theo dõi nếu chậm trên máy yếu.

### Đánh giá 4 điểm thiếu API
Không điểm nào chặn APPROVE.
1. Đổi thứ tự câu: contract T21 đã ghi "ngoài MVP"; UI nói rõ giới hạn. Không chặn. PO cần xác nhận hoặc mở task backend riêng (ghi board).
2. `has_attempts`: copy-on-write đã an toàn về dữ liệu, thông báo sau khi lưu đủ cho MVP. Không chặn; nên làm ở v2 (cảnh báo trước khi lưu).
3. Cờ `quiz_time_limit_enabled` cho admin: phương án phát hiện bằng so sánh response + toast chấp nhận được (contract: POST lưu null, PUT giữ nguyên; logic `timeIgnored` của dialog khớp cả hai nhánh). Không chặn; kiến nghị thêm field vào `/admin/auth/me` ở v2 để ẩn ô theo thiết kế.
4. `position` không đánh số lại sau xoá: UI đánh số theo vị trí trong mảng, đúng ý design; không phụ thuộc `position`. Không chặn.

### Đối chiếu AC (tasks.md FA5 + contract admin quiz)
| AC | Code đáp ứng | Ghi chú |
|---|---|---|
| Danh sách / tạo / sửa / xoá quiz, gắn chương XOR bài, 1–300 phút hoặc null | `QuizListPanel`, `QuizSettingsDialog`, `parentFromValue` | Khớp contract; `QUIZ_PARENT_INVALID` hiện dưới ô "Gắn với" |
| Soạn câu: 4 đáp án, đúng 1 đáp án đúng, ≤200 câu, văn bản thuần | `QuestionEditor`, `validateDraft`, `QUIZ_LIMITS` | Luật client khớp `QuizText`; 422 `QUIZ_QUESTION_LIMIT` xử lý riêng |
| Xem trước KaTeX giống học sinh | `MathText` + `KATEX_OPTIONS` | Cấu hình giống FW5 |
| Copy-on-write (PUT trả id mới) | `QuestionEditor.onSubmit` + `QuizComposerScreen.onSaved` | Thay theo `replacedId`, `router.replace` sang id mới, id cũ -> "Không tìm thấy câu hỏi" |
| Chặn bấm kép | `savingRef`/`deletingRef`/`busyRef` | Đạt |
| Xác nhận rời trang | `guardClick`, `beforeunload`, mở rộng cơ chế tab | Thiếu liên kết ngoài khung/Back (R1) |
| Phân quyền | Server 403 -> trang/panel riêng; không giả lập quyền ở client | Đúng nguyên tắc; giáo viên không gán thấy 403 |
| Lỗi 403/404/409/422/429/mạng | `quizError`, `questionFieldErrors`, `quizFieldErrors` | 404 khi lưu -> `onGone` tải lại; 404 khi xoá coi như đã xoá |
| Sắp xếp câu | Chưa làm (không có API) | Đã nêu rõ trên UI |

### Gợi ý cho QA
- Hai tab cùng sửa một câu đã có lượt làm (tab 2 -> 404 -> tải lại danh sách).
- Soạn dở rồi bấm sidebar / nút Back (R1): hiện đang mất dữ liệu im lặng.
- Công thức độc hại (`\href`, `\includegraphics`, macro đệ quy, >2000 ký tự, `$` lẻ, `5$`); so ảnh xem trước với trang học sinh.
- Thêm câu thứ 200 từ hai tab cùng lúc (`QUIZ_QUESTION_LIMIT`), giáo viên chuyển vai trò giữa phiên, quiz bị xoá khi đang mở trang soạn.
- e2e của Dev đã chạy 5/5 trên backend thật; QA cần chạy lại sau `--reset` (OTP 1/phút).

---

## Review vòng 2 (BUG-1, BUG-2, kéo thả sắp xếp câu / SLN7)
**Kết luận:** APPROVE (0 BLOCKER, 1 SHOULD, 2 NIT)
**Phạm vi:** `useUnsavedChangesGuard.tsx` (rearm), `QuizComposerScreen.tsx`, `QuestionList.tsx`, `lib/quiz/{api,errors,order}.ts`, test + 2 e2e. Đã chạy: vitest quiz/unsaved (6 file, 77 test xanh), typecheck và eslint sạch.

### Đúng
- BUG-2: `rearm()` chỉ hạ cờ sentinel, gọi sau `dirtyRef=false` và `router.replace` (cả nhánh tạo mới lẫn copy-on-write đổi id); lần bẩn kế tiếp đẩy mục đệm mới. Có test.
- BUG-1: 404/403 khi tải lại gọi `setQuiz(null)` nên ra màn 404/403, không giữ trang cũ; lỗi khác (mạng/5xx) vẫn giữ dữ liệu cũ + `error` nhưng `!quiz && error` không đúng nên không che trang: hợp lý. Có test.
- Reorder khớp contract SLN7: PUT `/questions/order` gửi đủ `question_ids`, dùng `data` trả về (position 1..n) thay state; 422 `QUIZ_QUESTIONS_MISMATCH`/403/404 hoàn lại + tải lại (`tick`); `reorderingRef` + `locked` chặn bấm chồng; khoá cả tay kéo lẫn nút Lên/Xuống. Reorder không đổi id nên không đụng copy-on-write; xoá câu client không dựa vào `position` nên việc server đánh lại position liền mạch không ảnh hưởng.
- Dirty guard: kéo thả chỉ có ở chế độ danh sách (không có QuestionEditor mounted) nên không có "câu soạn dở" cùng lúc; rời editor đang bẩn qua liên kết vẫn qua guard.
- a11y: handle là `<button>` có nhãn, KeyboardSensor, announcements tiếng Việt, nút Lên/Xuống thay thế (không chỉ phụ thuộc kéo), ≥44px ở max-sm (size-11 + vùng bấm mở rộng).

### R6 [SHOULD] Race: reorder đang bay + thao tác khác ghi đè state bằng ảnh chụp cũ
- Vị trí: `QuizComposerScreen.tsx` `reorder()` (`setQuiz(... questions: saved)` ở nhánh thành công, `questions: prev` ở nhánh lỗi) và liên kết "Sửa" trong `QuestionList` không bị khoá khi `locked`.
- Vấn đề: trong lúc PUT order chưa về, người dùng vẫn bấm "Sửa"/"Thêm câu hỏi" vào editor, lưu (thêm câu mới, hoặc copy-on-write đổi id) hoặc xoá. Khi reorder về, `saved`/`prev` (danh sách tại thời điểm bấm) ghi đè: câu vừa thêm biến mất khỏi state, câu vừa đổi id quay về id cũ (id cũ đã 404; liên kết `?cau=` mới thành "Không tìm thấy câu hỏi"), câu vừa xoá hiện lại. Dữ liệu server không hỏng (chỉ hiển thị sai tới khi tải lại), và cửa sổ hẹp (một request), nên không chặn. Nếu server xử lý reorder sau thao tác kia thì còn dính `QUIZ_QUESTIONS_MISMATCH` hợp lệ.
- Đề xuất (chọn một):
  ~~~tsx
  // (a) đơn giản: sau reorder xong (thành công hay lỗi) luôn đồng bộ lại từ server
  setTick((n) => n + 1);          // thay cho việc tin vào `saved`/`prev`
  // (b) hoặc khoá điều hướng: truyền `locked` vào QuestionList để Link "Sửa" thành aria-disabled,
  //     và ẩn/khoá "Thêm câu hỏi" khi `reordering`.
  ~~~
  Nếu giữ `saved`, nên merge theo id hiện tại (`setQuiz(q => ...)` chỉ lấy thứ tự từ `saved` cho các id còn tồn tại) thay vì thay nguyên mảng.

### NIT
- R7 [NIT] `QuestionList` `onDragEnd` thông báo "Đã thả câu N" dùng `indexOf(active.id)` theo thứ tự trước khi đổi; đọc ổn nhưng sau khi state lạc quan đổi, số N của câu có thể lệch vì thông báo phát sau. Chấp nhận được.
- R8 [NIT] Thành công mỗi lần di chuyển bật toast "Đã lưu thứ tự câu": nhiều lần bấm Lên/Xuống liên tiếp sẽ chồng toast; cân nhắc gộp hoặc chỉ dùng live region.

### Gợi ý cho QA
- Bấm Xuống rồi lập tức bấm "Sửa" câu và lưu (mạng chậm, throttle) -> kiểm R6.
- Hai tab: tab A xoá/thêm câu, tab B kéo thả -> 422 mismatch, danh sách tải lại, banner đúng.
- Bàn phím: cách -> mũi tên -> cách; Esc huỷ; trình đọc màn hình đọc thông báo.
- 375px: handle và nút Lên/Xuống chạm được; quiz 200 câu kéo thả không giật.
- Soạn dở -> Back/sidebar (dirty guard) sau một lần copy-on-write (BUG-2): Back chỉ hỏi một lần, không thoát im lặng.

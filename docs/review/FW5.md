# REVIEW: FW5 — Làm bài trắc nghiệm (web học sinh)

## Dev
**Trạng thái:** xong; kết quả kiểm tra ở mục "Cách test". Phụ thuộc backend T21/T22/T23 đã xong, không sửa backend.

### Phạm vi
- Route thật (màn học yên tĩnh dùng chung `app/(learn)/layout.tsx`: không header site/footer/bottom-nav, không nền ô ly, `noindex`):
  - `/hoc/{course}/quiz/{quiz}`: màn giới thiệu (số câu, thời gian, số lần đã làm, điểm cao nhất, "Xem kết quả lần trước") rồi mới `POST /learn/quizzes/{quiz}/attempts`. Có lượt dở dang thì nút là "Làm tiếp" (API trả lại đúng lượt, 200). Tách bước giới thiệu để F5/prefetch/mở link không tự chạy đồng hồ.
  - `/hoc/{course}/quiz/{quiz}/ket-qua?lan=<attemptId>&loc=sai|bo-trong`: điểm (vòng điểm + chữ), "Đúng x/y · Sai · Bỏ trống", đáp án đúng + "Bạn chọn" + lời giải từng câu, lọc câu theo URL, "Học bài tiếp theo", "Làm lại". Không có `lan` thì lấy lượt đã nộp mới nhất từ `GET /quizzes/{quiz}/attempts`; chưa có lượt nào hoặc lượt còn đang làm thì chuyển về trang làm bài.
- Tên quiz, số câu, giới hạn giờ, bài kế tiếp lấy từ `GET /learn/courses/{course}` (API `/attempts` không trả tiêu đề).
- `LessonOutline` (mục lục) và khối "Bài tập của bài này" ở `LessonScreen` nay là liên kết tới quiz (nút "Làm bài" chỉ hiện khi `can_track`).
- Nối API 100% từ trình duyệt (`authFetch`, cookie phiên host API, `X-Device-Id`, CSRF), giống FW4. Response parse bằng zod (`lib/quiz/schemas.ts`); điểm DECIMAL có thể là chuỗi `"7.50"` nên schema chấp nhận cả số lẫn chuỗi; `answers` là `[]` khi rỗng (PHP) cũng được chuẩn hoá thành object.

### KaTeX
- Cài `katex@0.19.0` (ghim đúng, đã duyệt ở tasks.md; có sẵn type, không cần `@types/katex`), chỉ ở `@vitaminvui/web`; `pnpm install --frozen-lockfile` chạy được.
- `lib/quiz/math.ts`: tách `$...$`/`$$...$$` bằng `splitMath` của ui, rồi `katex.renderToString` với `trust:false, strict:'warn', maxSize:10, maxExpand:1000, throwOnError:false` (api-contract §4). Công thức > 2000 ký tự không vào KaTeX (hiện như chữ). Lỗi cú pháp hiện nguyên văn TeX màu `danger`.
- Chữ thường đi qua React (tự escape; `<b>` trong nội dung hiện đúng là chữ). `components/quiz/MathText.tsx` là file DUY NHẤT dùng `dangerouslySetInnerHTML` (thêm vào allowlist ESLint S8) và chỉ nhận chuỗi do KaTeX sinh. Công thức riêng dòng nằm trong khung cuộn ngang `tabIndex=0` `role=group`.
- CSS + font KaTeX nạp ở `quiz/layout.tsx` (chỉ route quiz), font được Next phát ra `_next/static/media` cùng origin → CSP hiện tại (`font-src 'self'`, `style-src 'self' 'unsafe-inline'`) đủ, KHÔNG phải sửa `proxy.ts` (e2e kiểm không có vi phạm CSP và font KaTeX `loaded`).
- Ngoại lệ trong code designer: `components/v2/quiz/ResultView.tsx` `ResultQuestion` nhận thêm prop tuỳ chọn `MathText` (mặc định vẫn là bản MathML xem trước nên trang xem trước `/v2` không đổi).

### Đồng hồ, autosave, nộp bài
- Đồng hồ: `Countdown` của ui đếm theo `remaining_seconds` do server trả (đồng hồ đơn điệu của trình duyệt, không tin giờ máy); khi tab hiện lại thì `GET /quiz-attempts/{id}` để đồng bộ (đã tự nộp → sang kết quả). Aria: chỉ báo ở mốc 5 phút và 1 phút (đã có sẵn trong `Countdown`), không đọc từng giây. Không giới hạn giờ → ẩn đồng hồ.
- Hết giờ: khoá form, hộp thoại "Đã hết giờ làm bài", gửi nốt đáp án (`flushNow`), `POST submit` (thử lại tối đa 5 lần cách 3 giây khi lỗi mạng, sau đó nút "Thử nộp lại"), rồi sang kết quả. Server luôn chấm theo đáp án đã autosave (ân hạn 30 giây).
- Autosave (`lib/quiz/autosave.ts`, `AnswerSaver`, test bằng fake timers): debounce 400 ms; mỗi câu tối đa 1 request đang bay (đổi ý khi đang gửi thì gửi giá trị cuối ngay sau đó, không gửi trùng); trạng thái "Đang lưu… / Đã lưu / Chưa lưu được, sẽ thử lại" cạnh câu (`aria-live`); mất mạng → giữ đáp án trong bộ nhớ, banner "Mất kết nối mạng", gửi lại theo lịch 2/4/8/15 giây hoặc ngay khi sự kiện `online`; 409 (đã nộp/hết hạn) → sang kết quả; 403/404 → báo mất quyền; 422 → báo câu không lưu được; 401 → `SessionEndedGate` lo, saver tạm dừng, đồng hồ hiển thị chuyển thành "Đồng hồ tạm ẩn", đăng nhập lại (`?next=`) rồi "Làm tiếp".
- Rời trang: `pagehide`/`visibilitychange→hidden`/unmount (nút Thoát) gọi `flushKeepalive` (PUT `keepalive`, CSRF đã warm sẵn); `beforeunload` hỏi lại nếu còn đáp án chưa lưu.
- Nộp: luôn gửi nốt đáp án trước; còn câu trống → `ConfirmDialog` "Bạn còn N câu chưa trả lời"; nếu chưa lưu được vì mất mạng thì CHẶN nộp thủ công và báo (hết giờ thì vẫn nộp).
- Bàn phím: mỗi câu là `fieldset` + radio thật (Tab vào nhóm, mũi tên đổi đáp án, Space chọn); hàng đáp án ≥ 56px, bảng câu hỏi nút 44px, nút Nộp bài mobile dính đáy; có liên kết "Bỏ qua tới nội dung".
- Giới hạn lượt: contract/US-007 BR4 là KHÔNG giới hạn số lần làm lại (throttle POST start/submit 30/phút/người, 429 hiện "thao tác hơi nhanh"); UI không có khái niệm hết lượt.

### File
- Mới: `app/(learn)/hoc/[course]/quiz/layout.tsx`, `.../quiz/[quiz]/page.tsx`, `.../quiz/[quiz]/ket-qua/page.tsx`; `components/quiz/{MathText,QuizNotice,QuizResultScreen,QuizRunner,QuizScreen}.tsx`, `useLoaded.ts`, `quiz.css`; `lib/quiz/{api,autosave,errors,math,outline,schemas,useAnswerSaver}.ts`.
- Test mới: `lib/quiz/{autosave,math,outline,schemas}.test.ts`, `components/quiz/QuizRunner.test.tsx`; e2e `e2e/lam-quiz-real.spec.ts`, `e2e/seed-e2e-quiz.sh`, `e2e/run-quiz-real.sh`, `playwright.fw5.config.ts`.
- Sửa: `package.json` + `pnpm-lock.yaml` (katex), `eslint.config.mjs` (allowlist MathText), `lib/routes.ts` (`quiz`, `quizResult`), `components/learn/{LessonOutline,LessonScreen}.tsx` (liên kết quiz), `components/v2/quiz/ResultView.tsx` (prop `MathText`).
- Biến môi trường mới: không. `packages/ui` không sửa.

### Cách test
- `frontend/scripts/pnpm.sh --filter @vitaminvui/web run typecheck|lint|test` — tsc sạch, lint sạch, unit 385/385 (47 file; mới: `autosave` 10, `math` 8, `outline` 6, `schemas` 3, `QuizRunner` 5).
- `pnpm install --frozen-lockfile` chạy được sau khi thêm `katex`.
- Build: `next build` (Turbopack, `NEXT_DIST_DIR=.next-e2e-fw5`, nạp `.env.local`) thành công khi dựng bản chạy e2e; có `/hoc/[course]/quiz/[quiz]` và `/hoc/[course]/quiz/[quiz]/ket-qua`. Thư mục build đã xoá. Không chạy thêm lần `.next-check` thứ hai vì cùng cấu hình và máy dùng chung rất chậm.
- E2E thật (backend + Next production build, Playwright `--workers=1`, 13/13 pass, ~2 phút): `e2e/seed-e2e-quiz.sh --reset` (khóa `e2e-fw5-quiz`; q1 4 câu có phân số/căn/công thức riêng dòng/công thức rất dài/ký tự `<b>`; q2 giới hạn 1 phút; q3 chưa có câu; q4 quiz chương; học sinh `fw5-hs-own|other|none@example.com`, mật khẩu `matkhau-123`; chỉ chạy ở local/testing) rồi `E2E_FW5="course=.. l1=.. q1=.. q2=.. q3=.. q4=.." frontend/apps/web/e2e/run-quiz-real.sh` (`E2E_REUSE_BUILD=1` để dùng lại bản build), xong `seed-e2e-quiz.sh --clean`. Mỗi lần chạy lại PHẢI seed lại (test mong lượt làm mới).
- Ca e2e: từ bài học sang quiz qua mục lục/nút (KaTeX hiện, không còn `$`, `<b>` hiện như chữ, không `<a>/<img>` trong công thức, font KaTeX `loaded`, không vi phạm CSP, không footer/nền ô ly); API start không lộ `is_correct`/`correct_option_id`; autosave đổi ý nhanh chỉ gửi 1 PUT, "Đã lưu", F5 → "Làm tiếp" còn đáp án; bàn phím (Space/mũi tên chọn đáp án, hàng ≥ 44px); mất mạng (Playwright `setOffline`) → banner, có mạng lại tự gửi; rời bằng nút Thoát và rời bằng điều hướng cứng (keepalive) vẫn lưu được; nộp đủ → 10 điểm, đáp án đúng, lời giải KaTeX, lọc theo URL giữ sau F5; làm lại (lượt mới sạch, hộp xác nhận 3 câu trống, 2,5 điểm, lọc "Bỏ trống"); kết quả không `?lan=` dùng lượt mới nhất, lượt của người khác → "Không tìm thấy"; 375px không cuộn ngang; quiz chưa có câu; chưa ghi danh → về trang khóa học; id `abc` → 404 thật; quiz chương; đồng hồ 1 phút: `remaining_seconds` ≤ 60, vùng `aria-live` của đồng hồ rỗng, hết giờ tự nộp và sang kết quả.

### Đối chiếu AC (US-007)
BR1–BR3 (4 đáp án, 1 đúng, chỉ khóa đã sở hữu: 403 → chuyển về trang khóa) ✔; BR2 điểm thang 10 chấm tự động khi nộp ✔; BR4 làm lại không giới hạn, điểm cao nhất ở màn giới thiệu ✔ (tiến độ khóa/`best_score` ở "Khóa học của tôi" là FW6); BR5 xem đáp án đúng từng câu + lời giải sau khi nộp ✔; BR6 giới hạn giờ, hết giờ tự nộp các câu đã chọn ✔; BR7 quiz không chặn tiến trình ✔ (không khoá bài). US-014: mất phiên → dừng autosave và đồng hồ hiển thị, `SessionEndedGate` báo, đăng nhập lại rồi "Làm tiếp" (kiểm bằng unit/đọc code, chưa có e2e thật vì cần ép phiên bị thay thế).

### Điều chưa làm / lệch cần quyết
1. **CORS `max_age = 0`** (`backend/config/cors.php`): mọi PUT autosave đều cần preflight OPTIONS, tăng độ trễ và số request. Đề nghị `laravel-dev` đặt `max_age` (ví dụ 600) — chỉ là tối ưu, không sai chức năng. Việc flush `keepalive` khi đóng tab đã được e2e xác nhận hoạt động với preflight trên Chromium; Safari/Firefox chưa thử.
2. **Đồng hồ khi máy ngủ / tab nền**: dùng đồng hồ đơn điệu của trình duyệt; khi tab hiện lại có gọi `GET /quiz-attempts/{id}` để đồng bộ, nhưng độ trễ mạng lúc nhận `remaining_seconds` (vài trăm ms) chưa được bù. Server luôn là bên quyết định (ân hạn 30 giây).
3. Hết giờ phía trình duyệt có thể đến TRƯỚC `expires_at` của server vài trăm ms nên lượt có thể được ghi `auto_submitted=false` dù nộp do hết giờ; điểm không đổi. Nếu PO muốn nhãn "tự nộp khi hết giờ" luôn đúng thì cần backend coi nộp trong ±1 giây quanh hạn là tự nộp, hoặc FE chờ thêm ~1 giây trước khi nộp.
4. Chưa có e2e cho 409 `QUIZ_ATTEMPT_EXPIRED` ở PUT (chỉ unit `AnswerSaver`), cho 401/403 giữa phiên, và cho hộp thoại "Đã hết giờ làm bài" (chỉ unit, hộp thoại hiện thoáng qua trước khi chuyển trang).
5. Lượt người khác / quiz không thuộc khóa trong URL: trang báo "Không tìm thấy bài kiểm tra" (khóa trong URL phải khớp mục lục). Không có API tra tiêu đề quiz theo id nên tên quiz lấy từ mục lục khóa học.
6. Giao diện chưa chụp ảnh 375px thủ công (chỉ kiểm bằng đo `scrollWidth` trong e2e); chưa kiểm tương phản màu KaTeX ở giao diện tối.
7. Môi trường: trong lúc làm, máy dev dùng chung bị quá tải kéo dài (docker CLI chậm 10–15 phút, PostCSS của dev server cổng 3000 bị chết). Dev server của máy host KHÔNG bị tôi khởi động lại; nếu `/dang-nhap` trên cổng 3000 báo 500 "postcss failed to receive message" thì cần PO/ai đó khởi động lại thủ công.

### Luồng `laravel-qa` nên kiểm kỹ
Hết giờ khi đang nộp lệch nhau giữa 2 tab; nộp trùng ở 2 tab (idempotent); autosave khi chuyển mạng wifi↔4G; đóng tab/đóng trình duyệt ngay sau khi chọn (trình duyệt khác Chromium); thu hồi enrollment giữa lúc làm; công thức nhập lạ từ quản trị (FA5): `\href`, `\def` đệ quy, công thức rất dài, `$` đơn lẻ; câu bị sửa copy-on-write khi học sinh đang làm; 375px trên điện thoại thật với bàn phím ảo; trình đọc màn hình đọc công thức KaTeX (MathML ẩn/hiện) và mốc 5 phút/1 phút của đồng hồ.

## Review
**Kết luận:** APPROVE (0 BLOCKER · 4 SHOULD · 3 NIT)
**Phạm vi:** diff chưa commit `frontend/apps/web` + `pnpm-lock.yaml` (bỏ apps/admin) · ~30 file. Đã chạy `tsc --noEmit` (sạch) và eslint (không báo lỗi); vitest không chạy được trong lượt này (corepack không tải được pnpm, không có mạng) nên tin số liệu 385/385 của Dev.

### Tổng quan
Chất lượng tốt: lớp `AnswerSaver` tách khỏi React, có test fake timers, mỗi câu 1 request đang bay, không ghi đè đáp án mới bằng đáp án cũ. KaTeX dùng an toàn. Màn giới thiệu trước POST start đúng yêu cầu. Các điểm dưới đây là SHOULD, không chặn.

### Bảo mật KaTeX: đạt
- `lib/quiz/math.ts:13-20`: `trust:false` (chặn \href/\url/\includegraphics/\htmlClass...), `maxExpand:1000`, `maxSize:10`, `throwOnError:false`, công thức > 2000 ký tự không vào KaTeX, `try/catch` bắt RangeError do lồng sâu.
- `components/quiz/MathText.tsx:35-39`: `dangerouslySetInnerHTML` chỉ nhận `p.html` từ `katex.renderToString`; chữ thường đi qua React (tự escape, `<b>` hiện là chữ). Allowlist ESLint chỉ đúng file này (`eslint.config.mjs:17`). Lỗi cú pháp: KaTeX tự escape input trong span lỗi. CSP: font/CSS cùng origin là đủ. katex 0.19.0 đã ghim và có trong node_modules.

### Phát hiện
**R1 [SHOULD] Nhãn "tự động nộp khi hết giờ" gần như không bao giờ xuất hiện**
- Vị trí: `components/quiz/QuizRunner.tsx:156` + `backend/app/Services/Quiz/QuizAttemptService.php:285-307`.
- Vấn đề: FE tự nộp bằng cùng endpoint submit như nộp tay. Backend chỉ coi là `auto` khi `expires_at < now - ân hạn 30 giây` (hoặc không phải manual). Đồng hồ FE nổ sau `expires_at` vài trăm ms (`remaining_seconds` là floor, client nhận trễ nên deadline client >= server) nên lượt nằm trong ân hạn => `auto_submitted=false`. Mô tả Dev ("lệch vài trăm ms") đánh giá thấp: lệch tới 30 giây, tức luôn sai ở luồng bình thường. Điểm không bị ảnh hưởng, chỉ nhãn ở `QuizResultScreen.tsx:108` và dữ liệu thống kê.
- Đề xuất: backend coi submit khi `now >= expires_at` là auto (chỉ giữ ân hạn cho việc chấp nhận autosave); FE không cần đổi. Nếu PO không quan tâm nhãn thì ghi backlog-v2.

**R2 [SHOULD] Câu bị 422 vẫn hiện đã chọn và được đếm là đã trả lời**
- Vị trí: `QuizRunner.tsx:99,135-136` và `lib/quiz/autosave.ts:148-150`.
- Vấn đề: `answers` (state UI) cập nhật ngay khi chọn; khi PUT bị 422, saver bỏ đáp án nhưng UI vẫn tô đáp án và tính vào "Đã trả lời N/M", làm hộp xác nhận "còn N câu" sai so với server.
- Đề xuất: khi `snap.questions[qid]==="rejected"` thì loại khỏi tập đếm/tô (hoặc xoá khỏi `answers` trong effect).

**R3 [SHOULD] Mất phiên (401): đáp án chọn sau đó hiện "Đang lưu…" mãi và mất khi đăng nhập lại**
- Vị trí: `autosave.ts:96,207` + `QuizRunner.tsx:227-229`.
- Vấn đề: sau `pause()` vẫn cho chọn, nhãn kẹt "Đang lưu…", `beforeunload` cứng hỏi; sau đăng nhập lại, "Làm tiếp" mount mới từ server nên đáp án chọn lúc mất phiên mất, mà UI không cảnh báo.
- Đề xuất: khi `sessionLost` khoá chọn (hoặc nhãn "Chưa lưu, hãy đăng nhập lại") và nói rõ trong thông báo.

**R4 [SHOULD] Rời bằng nút Thoát khi mất mạng: mất đáp án chưa lưu, không cảnh báo**
- Vị trí: `lib/quiz/useAnswerSaver.ts:47-48`.
- Vấn đề: cleanup gọi `flushNow()` rồi `dispose()` (huỷ retry). Soft navigation không kích hoạt `beforeunload`, nên nếu flush lỗi do offline thì đáp án mất im lặng; `void` nuốt kết quả `false`.
- Đề xuất: ở link Thoát (`QuizRunner.tsx:214`) chặn/hỏi xác nhận khi `snap.unsaved > 0`.

**R5 [NIT]** `QuizRunner.tsx:124`: `remaining_seconds` sau resync chưa trừ độ trễ mạng (vài trăm ms, thiên về an toàn); có thể đo `performance.now()` quanh `fetchAttempt`.
**R6 [NIT]** `QuizRunner.tsx:268`: `legend` chỉ là "Câu N", nội dung câu hỏi không gắn với nhóm radio; thêm `aria-describedby` trỏ tới khối nội dung để trình đọc màn hình đọc đề khi vào nhóm.
**R7 [NIT]** `frontend/apps/web/qa-shots/` (ảnh `q1-teachers-1280.png`) không thuộc FW5; đừng commit, nên thêm vào .gitignore.

### Đối chiếu acceptance criteria
| AC | Code đáp ứng | Ghi chú |
|---|---|---|
| BR1-3 quyền/403 | `errors.ts`, `QuizNotice` | OK |
| BR2 điểm thang 10 | `QuizResultScreen`, `schemas.ts` (decimal) | OK |
| BR4 làm lại không giới hạn | `QuizScreen.tsx:128` | OK |
| BR5 đáp án + lời giải | `ResultQuestion` + `MathText` | OK |
| BR6 hết giờ tự nộp | `Countdown` + `doSubmit(true)` | OK về điểm; nhãn auto sai, xem R1 |
| Đồng hồ server, đồng bộ khi hiện tab | `QuizRunner.tsx:118-131` | OK |
| Không nộp trùng | `submittingRef`, submit idempotent | OK |
| Màn giới thiệu, F5 không chạy đồng hồ, "Làm tiếp" | `QuizScreen.tsx` | OK |
| Autosave debounce/thứ tự/keepalive/409/403/mất mạng | `autosave.ts`, `useAnswerSaver.ts` | OK; xem R2-R4 |
| a11y, 44px, noindex | radio thật, hàng >= 56px, `(learn)/layout.tsx:6` | OK; xem R6 |
| US-014 mất phiên | `SessionEndedGate` + pause | Xem R3 |

### Gợi ý cho QA
- Hết giờ và kiểm tra nhãn `auto_submitted` (R1); 2 tab nộp cùng lúc; đổi đáp án nhanh rồi tắt mạng; Thoát khi offline (R4).
- Công thức lạ: `\href`, `\def` đệ quy, `{{{{...}}}}` lồng sâu, `$` lẻ; 375px với bàn phím ảo; Safari/Firefox với keepalive + preflight (CORS max_age=0).

## Dev đã sửa (sau Review)
- **R2** (`QuizRunner.tsx`): câu bị PUT 422 (`rejected`) bị loại khỏi tập đáp án hiển thị: bỏ tô, không tính vào "Đã trả lời N/M", bảng câu hỏi và hộp xác nhận "còn N câu" khớp server; nhãn "Không lưu được câu này, hãy chọn lại"; chọn lại thì tô lại. Unit mới.
- **R3**: mất phiên (401 hoặc `forced-logout`/`login-required`) → khoá toàn bộ chọn đáp án, `Alert` "Mất phiên: đăng nhập lại để tiếp tục" (nói rõ bài đã lưu còn nguyên, bấm "Làm tiếp" sau khi đăng nhập), nhãn câu "Chưa lưu: mất phiên" thay cho "Đang lưu…". Gửi lại sau đăng nhập KHÔNG làm được: `SessionEndedGate` chuyển cả trang tới đăng nhập (tải lại tài liệu) nên bộ nhớ mất; đã cảnh báo rõ số câu chưa lưu sẽ mất và phải chọn lại (còn `beforeunload`). Unit mới.
- **R4**: nút Thoát khi còn đáp án chưa lưu được (offline, mất phiên) → `ConfirmDialog` "Còn N câu trả lời chưa lưu được" (Ở lại / Vẫn thoát). Đang online mà chỉ chờ debounce thì thoát luôn (unmount gửi nốt). Unit mới.
- **R5**: đồng bộ đồng hồ khi tab hiện lại trừ nửa RTT (đo `performance.now()` quanh `fetchAttempt`).
- **R6**: `fieldset` có `aria-describedby` trỏ khối nội dung câu hỏi.
- **R7**: `/qa-shots/` thêm vào `apps/web/.gitignore`.
- **R1**: backend làm riêng; FE đã hiện nhãn khi `auto_submitted=true`, không đổi.
- Kiểm tra lại: tsc sạch, lint sạch, unit 388/388 (47 file), e2e thật 13/13 (`--workers=1`, seed trước, `--clean` sau). Build/e2e chỉ vào `NEXT_DIST_DIR=.next-e2e-fw5` (đã xoá), không đụng `.next` của dev server. Lần chạy e2e đầu sau sửa R4 fail 1 ca (nút Thoát hiện hộp xác nhận cả khi chỉ chờ debounce) và đã chỉnh điều kiện như trên.

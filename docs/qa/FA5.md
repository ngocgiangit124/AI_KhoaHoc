# QA: FA5 (Soạn quiz, xem trước KaTeX · apps/admin)
**Kết quả:** PASS (kèm 2 lỗi Medium/Low ghi backlog; không có Critical/High)
Ngày 2026-10-08. Phạm vi: `lib/quiz`, `lib/unsaved`, `components/quiz`, `app/quan-tri/khoa-hoc/[id]/bai-tap`, `CourseEditScreen.tsx`, e2e.

## Độ phủ acceptance criteria
| AC (tasks.md FA5 + design quiz authoring) | Test | Kết quả |
|---|---|---|
| Danh sách/tạo/sửa/xoá bài tập, gắn chương XOR bài, 1-300 phút | `soan-quiz-real.spec.ts` #1 (dev), `QuizListPanel.test.tsx` | PASS |
| Soạn câu: 4 đáp án, đúng 1 đáp án đúng, văn bản thuần | `soan-quiz-real.spec.ts`, `quiz.test.ts`, `QuestionEditor.test.tsx` | PASS |
| `<b` bị chặn client lẫn server, `x > 2` được | `soan-quiz-qa-real.spec.ts` #7 (không có POST khi `<b`; server 422 cho `a<b`, `</p>`, `<!--`, bidi U+202E, U+200B, NUL, BEL; thông báo đúng ô `options.0.content`), `qa-fa5-parity.test.ts` (biên dải ký tự) | PASS |
| Xem trước KaTeX giống trang học sinh | `qa-fa5-parity.test.ts`: import cả `apps/web/lib/quiz/math` (chỉ đọc), so đầu ra 16 đầu vào (kể cả độc hại): bằng nhau; `KATEX_OPTIONS` bằng nhau | PASS |
| Công thức độc hại: `\href`, `\url`, `\includegraphics`, `\htmlClass/Style`, `\verb`, macro đệ quy, `\rule` khổng lồ, >2000 ký tự | Vitest (phân tích DOM: không `a/img/script/iframe/svg`, không thuộc tính `on*/href/src`) + e2e #6 (xem trước không có liên kết/ảnh/`.pwn`/`position:fixed`, không hộp thoại JS, trang vẫn gõ được sau macro đệ quy; công thức đúng 2000 ký tự vẫn render, 2001 hiện nguyên văn) | PASS |
| `$` lẻ chỉ là lưu ý (R2) | e2e #6: `dollar-warning` hiện, ô đáp án không `aria-invalid`; unit `hasOddDollar` (`5$`, `5\$`, `$$x$$`) | PASS |
| Xác nhận rời trang (R1): sidebar/header/breadcrumb/Back; Ở lại giữ chữ; Bỏ thay đổi rời | e2e #2 (sidebar, Esc = Ở lại, Back hai lần liên tiếp vẫn chặn, Bỏ thay đổi rời; chưa gõ thì không hỏi), #3 (sau khi lưu không hỏi) | PASS (trừ BUG-2) |
| FA4 (tab Chương & bài) vẫn đúng với hook mới | e2e #4: form bài chưa lưu -> tab Thông tin chung / tab Bài tập / sidebar đều hỏi đúng 1 hộp; Ở lại giữ chữ; Bỏ thay đổi sang tab; tab Bài tập không dirty thì không hỏi. `chuong-bai-real.spec.ts` không chạy lại (không đổi logic ngoài tab) | PASS |
| Copy-on-write; hai tab cùng sửa câu có lượt làm; mở lại link id cũ | `soan-quiz-real.spec.ts` #2 (dev) + e2e #5: tab A lưu -> id mới; tab B (id cũ) lưu -> toast "không còn tồn tại", tải lại danh sách, DB không có nội dung tab B; link id cũ -> `question-missing`, còn lối về | PASS |
| Trần 200 câu | `soan-quiz-real.spec.ts` #3 (dev): câu 200 thêm được, nút khoá, API `QUIZ_QUESTION_LIMIT` | PASS |
| Quiz bị xoá khi đang mở | e2e #8, #9: lưu vào quiz đã xoá không trắng trang, không tạo câu mồ côi, có toast; mở link quiz đã xoá/id 999999 -> "Không tìm thấy bài tập" + lối về; id `abc` -> 404 Next | PASS (trừ BUG-1) |
| Phân quyền: giáo viên không được gán -> 403 (API + UI), giáo viên được gán soạn ở 375px | `soan-quiz-real.spec.ts` #4, #5 (dev): 403 đọc/ghi, `course-forbidden`, `quiz-forbidden`, không tràn ngang, nút >= 44px | PASS |

## Bug phát hiện
### BUG-1: Quiz bị xoá ở nơi khác, lưu câu -> trang vẫn hiện bài tập đã xoá
- Mức độ: Low (Medium nếu gộp với thông báo gây hiểu nhầm)
- Tái hiện: mở `/bai-tap/{quiz}?cau=moi` ở tab B; tab A xoá quiz (DELETE 204); tab B điền câu và bấm "Thêm câu hỏi".
- Mong đợi: chuyển sang trạng thái "Không tìm thấy bài tập" (quiz-not-found).
- Thực tế: toast "Câu hỏi không còn tồn tại. Đã tải lại danh sách." (sai đối tượng), trang vẫn hiện tiêu đề, "0/200 câu", nút "Thêm câu hỏi"; chỉ khi tải lại trang mới thấy không tìm thấy. Không mất dữ liệu, không tạo câu mồ côi.
- Vị trí nghi ngờ: `components/quiz/QuizComposerScreen.tsx` ~dòng 138 (`if (!quiz && error)`: khi đã có `quiz` thì lỗi 404 của lần tải lại bị bỏ qua) và `onGone` (~dòng 262) luôn nói "Câu hỏi". Cách sửa: khi tải lại gặp 404/403 thì `setQuiz(null)`.
- Test ghi nhận: e2e #8 (`test.fail`), khi sửa xong bỏ dòng `test.fail`.

### BUG-2: Back sau khi vừa thêm/lưu câu (URL đổi sang ?cau=<id>) làm mất chữ đang sửa không hỏi
- Mức độ: Medium (mất dữ liệu soạn dở, nhưng cần đúng chuỗi: thêm câu mới hoặc lưu thành bản mới -> sửa tiếp -> bấm Back)
- Tái hiện: `?cau=moi`, điền và "Thêm câu hỏi" (URL thành `?cau=<id>`), sửa tiếp ô nội dung, bấm Back của trình duyệt.
- Mong đợi: hộp "Còn thay đổi chưa lưu"; "Ở lại" giữ nguyên chữ.
- Thực tế: không có hộp; URL về `?cau=moi`, khung soạn dựng lại (key đổi) và chữ mất.
- Nguyên nhân nghi ngờ: `useUnsavedChangesGuard` đẩy mục đệm (`pushState` cùng URL) khi bắt đầu dirty; sau đó `router.replace(?cau=<id>)` ở `QuizComposerScreen.onSaved` thay chính mục đệm đó, nên Back không còn là "cùng URL" và `popstate` làm Next đổi trang trước khi hook kịp chặn; `dirtyRef` cũng về false khi editor unmount. Vị trí: `lib/unsaved/useUnsavedChangesGuard.tsx` (setDirty/onPop, ~dòng 40-75) và `QuizComposerScreen.tsx` ~dòng 233-243. Hướng sửa: đặt lại `sentinel.current=false` khi đổi URL bằng `router.replace` (và đẩy mục đệm mới khi dirty lần sau), hoặc dùng `useEffect` theo `searchParams` để dựng lại sentinel.
- Ngoài lề (Low, cùng gốc): Back đầu tiên sau khi lưu quay về `?cau=moi` (khung "Câu mới" trống), phải Back lần nữa mới rời; không mất dữ liệu.
- Test ghi nhận: e2e #11 (`test.fail`).

## Rủi ro & đề xuất
- Môi trường: trong lúc QA, bảng `users`/`courses` của DB dev `vitaminvui` bị xoá sạch (0 dòng) bởi tiến trình khác (nhiều khả năng agent QA backend dùng nhầm DB dev); lần chạy e2e đầu có 1 test fail vì đăng nhập (`Thông tin đăng nhập không đúng`), sau `seed-e2e-quiz.sh --reset` chạy lại đều PASS. Cần chắc agent backend chỉ dùng `vitaminvui_testing_*`.
- `redis-cli -n 4 flushdb` cần mật khẩu (NOAUTH); chỉ xoá `otp_codes` theo cột `destination` (không có cột `login`) là đủ cho OTP.
- SLN7 (đổi thứ tự câu, xoá đánh lại position): không ảnh hưởng FA5 vì UI đánh số theo vị trí trong mảng và không phụ thuộc `position`. Khi làm kéo thả cần nhớ giữ `?cau=<id>` ổn định.
- Ba file KaTeX trùng giữa admin và web (R3): đã có test `qa-fa5-parity.test.ts` chống lệch cấu hình/đầu ra; nên chuyển vào `packages/ui` sau khi FW5 commit.
- `pnpm-lock.yaml` trộn katex của FW5 và FA5: commit FW5 trước hoặc cùng FA5 (R4).
- Chưa kiểm: copy-on-write cho trường hợp học sinh đang làm dở trong lúc admin sửa (phía backend, thuộc QA T21/T22); hai tab cùng thêm câu thứ 200 (chỉ kiểm bằng API trần 200 của dev).

## Lệnh đã chạy
- `frontend/scripts/pnpm.sh --filter admin exec tsc --noEmit`: sạch. `... exec eslint .` (và `eslint e2e lib/quiz` sau khi thêm file QA): sạch.
- `... exec vitest run`: 30 file, 356 test pass (trước khi thêm file QA); `vitest run lib/quiz/qa-fa5-parity.test.ts`: 23 test pass.
- `frontend/apps/admin/e2e/seed-e2e-quiz.sh --reset`, xoá `otp_codes` của `e2e-fa5-%`, rồi `frontend/apps/admin/e2e/run-real.sh e2e/soan-quiz-real.spec.ts --workers=1 --retries=0`: 5/5 pass (lần đầu fail do DB bị xoá, xem trên). `e2e/soan-quiz-qa-real.spec.ts --workers=1 --retries=0`: 11/11 (2 test `test.fail` = lỗi đã biết BUG-1, BUG-2).
- Không build (không đụng `.next`), không restart dev server.

## File QA thêm
- `frontend/apps/admin/lib/quiz/qa-fa5-parity.test.ts`
- `frontend/apps/admin/e2e/soan-quiz-qa-real.spec.ts`

---

## QA vòng 2 (BUG-1, BUG-2, kéo thả sắp xếp câu / SLN7, R6, R8)
**Kết quả:** PASS (0 bug chặn; 1 Minor a11y, 2 ghi nhận)

### Độ phủ
| Hạng mục | Test | Kết quả |
|---|---|---|
| BUG-1 đã hết | `soan-quiz-qa-real.spec.ts` #8 không còn `test.fail`, 11/11 pass | PASS |
| BUG-2 đã hết | `soan-quiz-qa-real.spec.ts` #11 không còn `test.fail`; `soan-quiz-qa2-real.spec.ts` #7 (Back sau copy-on-write) | PASS |
| Kéo thả bằng chuột (danh sách 199 câu) | qa2 #4: kéo câu 3 lên đầu, server đúng thứ tự, position 1..199 liền mạch | PASS |
| Bàn phím: cách, mũi tên, cách; Esc huỷ | qa2 #2: đổi đúng thứ tự, Esc không gọi PUT/đổi gì, hướng dẫn trình đọc (`aria-describedby`) có chữ "Nhấn phím cách để nhấc" | PASS (xem BUG-3, N1) |
| Thông báo trình đọc màn hình | qa2 #2 ghi lại vùng `aria-live`: "Đang ở trên vị trí 1/3" -> "2/3" -> "Đã thả câu 1 tại vị trí 2/3." / "Đã huỷ kéo câu." | PASS (xem N1) |
| Nút Lên/Xuống | qa2 #3: nút ở rìa bị vô hiệu; 2 lần bấm cùng tick chỉ 1 PUT; 6 lần bấm nhanh chỉ 1-2 toast (R8 đạt) | PASS |
| 375px, 199 câu | qa2 #4: không tràn ngang, mọi nút/liên kết trong danh sách >= 43,5px, "Chuyển câu 100 xuống" đúng; PUT + cập nhật UI ~1,7-2,1s | PASS |
| R6 khoá khi đang lưu thứ tự | qa2 #5: PUT bị giữ lại -> "Sửa" thành `span aria-disabled` (bấm không điều hướng), "Thêm câu hỏi" disabled, `aria-busy`, nút Lên/Xuống disabled; xong thì danh sách khớp server | PASS |
| Hai tab lệch danh sách | qa2 #6 (chạy lặp 6 lần): tab A thêm 1 + xoá 1 câu, tab B kéo thả -> "Đã tải lại danh sách mới", danh sách khớp server, không mất câu, server không đổi sau lần bị từ chối; tab B kéo lại thành công | PASS |
| Reorder rồi sửa câu có lượt làm | qa2 #7: câu (ở vị trí 2) sửa -> id mới ở vị trí 2, id cũ 404, position 1..2; Back chỉ hỏi một lần mỗi lần bấm, "Ở lại" giữ chữ, "Bỏ thay đổi" không hỏi lại, chữ chưa lưu không lọt vào server | PASS (xem N2) |
| Học sinh | qa2 #8 (API học sinh thật, `/learn/...`): lượt đang dở không đổi thứ tự (đọc lại và "bắt đầu" lại), nộp xong làm lượt mới thì theo thứ tự mới | PASS (kiểm ở API, chưa mở UI web, xem Rủi ro) |
| DB (SELECT) | `quiz_questions` chưa xoá mềm: quiz 94 (3 câu) 1..3, quiz 95 (2) 1..2, quiz 96 (199) 1..199, không trùng position | PASS |

### Bug / ghi nhận
#### BUG-3: Sau khi thả bằng bàn phím, tiêu điểm rơi về `<body>`
- Mức độ: Minor (a11y, WCAG 2.4.3)
- Tái hiện: focus tay nắm "Kéo để đổi thứ tự: câu 1", cách -> mũi tên xuống -> cách. Khi lưu, `locked` làm mọi nút `disabled` nên Chrome bỏ tiêu điểm; sau khi lưu xong `document.activeElement` là `BODY` (e2e qa2 #2 in "QA2 tiêu điểm sau thả bằng phím: BODY"). Người dùng bàn phím phải Tab lại từ đầu trang (với 199 câu rất bất tiện), tương tự khi dùng nút Lên/Xuống.
- Mong đợi: tiêu điểm ở lại tay nắm/nút của câu vừa di chuyển.
- Vị trí nghi ngờ: `components/quiz/QuestionList.tsx` ~dòng 106, 113, 119 (`disabled={locked}`); dùng `aria-disabled` + chặn click trong khi `locked`, hoặc khôi phục focus theo id câu sau khi lưu xong.

#### N1 (ghi nhận, không phải lỗi của app): thông báo "Đã nhấc câu N/M" không bao giờ được đọc
dnd-kit ghi đè ngay bằng "Đang ở trên vị trí 1/3" (cùng lượt cập nhật), nên trình đọc màn hình nghe "Đang ở trên vị trí 1/3" thay vì "Đã nhấc câu 1/3". Vẫn đủ hiểu; có thể bỏ `onDragStart` hoặc đổi chữ cho đồng nhất.

#### N2 (ghi nhận): "Bỏ thay đổi" bằng Back sau khi vừa lưu
Sau Back + "Bỏ thay đổi", URL vẫn là `?cau=<id>` mới (Back đầu tiên chỉ gỡ mục đệm, như đã ghi ở vòng 1). Không hỏi lần hai, không mất dữ liệu đã lưu, chữ chưa lưu bị bỏ. Không lỗi.

#### N3 (ghi nhận): cửa sổ ngắn danh sách cũ sau thông báo "Đã tải lại danh sách mới"
Toast xuất hiện trước khi danh sách mới được vẽ (đã đo: một lần chạy thấy danh sách cũ ngay sau toast). Tự khớp server sau đó; chỉ là trình bày.

### Rủi ro & đề xuất
- Chưa mở UI web học sinh (apps/web do agent FW6 đang làm, không đụng); phần học sinh kiểm bằng API `/learn/*` (cùng nguồn dữ liệu FW5). Nên để QA FW6/FW5 xác nhận trên giao diện.
- Test tiêu điểm/phím phải đợi ~400ms giữa các phím (dnd-kit đo layout sau "nhấc"); nhấn quá nhanh thì mũi tên không đổi vị trí và thả về chỗ cũ (đúng thiết kế, không PUT).
- Seed `seed-e2e-quiz.sh --reset` không xoá `enrollments`: nếu đã ghi danh `e2e-fa5-hs` vào khóa FA5 thì `--reset` lỗi FK (`enrollments_course_id_foreign`, RESTRICT); phải xoá ghi danh của khóa "E2E FA5 %" trước. Nên thêm bước xoá enrollments vào hàm WIPE của script.
- Ghi chú dữ liệu: khóa "E2E FA5 Khóa soạn quiz" hiện ở trạng thái đã xuất bản + có ghi danh của `e2e-fa5-hs` (do QA đặt để kiểm phía học sinh); `seed-e2e-quiz.sh --clean` sau khi xoá ghi danh sẽ dọn sạch.

### Lệnh đã chạy
- `uptime` (load 3-6, dưới ngưỡng 15). Chuẩn bị mỗi lần: xoá enrollments khóa `E2E FA5 %` (tinker, chỉ dữ liệu E2E), `seed-e2e-quiz.sh --reset`, xoá `otp_codes` của `e2e-fa5-%`, publish khóa + ghi danh `e2e-fa5-hs` (tinker, chỉ dữ liệu E2E FA5). Không chạy migrate/db:wipe/db:seed; DB chỉ SELECT.
- `frontend/scripts/pnpm.sh --filter admin exec tsc --noEmit`: sạch. `... exec eslint e2e/soan-quiz-qa2-real.spec.ts`: sạch.
- `frontend/apps/admin/e2e/run-real.sh e2e/soan-quiz-qa2-real.spec.ts --workers=1 --retries=0`: 8/8 pass (lần cuối sau reset); chạy lặp `--repeat-each=4` (bàn phím) và `--repeat-each=6` (hai tab) đều pass sau khi thêm chờ trong test.
- `run-real.sh e2e/soan-quiz-qa-real.spec.ts --workers=1 --retries=0`: 11/11 pass, không còn `test.fail`. (`soan-quiz-real.spec.ts` của Dev không chạy lại trong vòng này; test sắp xếp bên trong đã được qa2 bao phủ.)
- Không build, không đụng `.next`, không restart dev server.

### File QA thêm
- `frontend/apps/admin/e2e/soan-quiz-qa2-real.spec.ts`

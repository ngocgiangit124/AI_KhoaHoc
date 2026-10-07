# REVIEW: FW4 — Trang học video (web học sinh)

## Dev
**Trạng thái:** xong phần làm được với VideoLab nội bộ; CHƯA thử Bunny thật (không gọi Bunny, theo yêu cầu).

### Phạm vi
- Route thật `/hoc/{course}/bai/{lesson}` (màn học yên tĩnh: không header site/footer/bottom-nav, không nền ô ly, `noindex`) và `/hoc/{course}` (chuyển tới `resume_lesson_id`, US-006 AC6). Id sai định dạng → 404 thật.
- Mọi dữ liệu `/learn/*` lấy từ TRÌNH DUYỆT (`authFetch`, cookie Sanctum host API), KHÔNG SSR/Route Handler (token phát ràng IP, security T37 S6).
- Một trình phát hls.js cho VideoLab và Bunny (nạp động; Safari/iOS dùng HLS gốc). Điều khiển: phát/dừng, tua (`aria-valuetext`), tắt tiếng, tốc độ 0,75–2×, toàn màn hình (câu hỏi 9 = CÓ).
- Link phát: làm mới TRƯỚC `expires_at` (xin sớm 60 giây, link ngắn thì 1/4 thời hạn, sàn 10 giây), single-flight; gặp 403 từ CDN → xin lại ngầm, đổi nguồn giữ vị trí + trạng thái phát/dừng, tối đa 3 lần 403 liên tiếp rồi mới báo lỗi; lỗi mạng khi làm mới theo lịch → thử lại 3 lần/10 giây.
- Heartbeat mỗi 20 giây khi đang phát, thêm khi pause/ended/ẩn tab/đóng trang (`keepalive`); `position_seconds`, `watched_delta_seconds` luôn số nguyên (giây VIDEO, tua không tính, trần 60); lỗi tạm thời gửi bù; 403 → dừng video + báo thu hồi; không gọi khi `can_track=false`.
- Hoàn thành ≥ 90%: toast không chặn + icon mục lục và tiến độ khóa đổi ngay.
- Hộp thoại mất phiên: player nghe cùng sự kiện `forced-logout`/`login-required` của api-client → `pause()`, ngừng heartbeat và làm mới link.
- Link ngoài (`kind: embed`): iframe `sandbox="allow-scripts allow-same-origin allow-presentation"`, chỉ https tới `www.youtube-nocookie.com` / `player.vimeo.com` (khớp `frame-src`), referrerpolicy.
- Trạng thái: đang tải, lỗi AC5 + Thử lại, video đang xử lý (409 hoặc `video_ready=false`), chưa có video (404 `VIDEO_NOT_AVAILABLE`), bị chặn (403 `COURSE_NOT_OWNED`), 404, lỗi tải bài + Thử lại.
- `NEXT_PUBLIC_VIDEO_HOSTS` đã được `proxy.ts` đưa vào `connect-src`/`media-src` (có test); cần ghi kèm scheme, local `http://video.localhost:8000`, Bunny `https://<BUNNY_CDN_HOST>`.

### File
- Mới: `app/(learn)/layout.tsx`, `app/(learn)/hoc/[course]/page.tsx`, `app/(learn)/hoc/[course]/bai/[lesson]/page.tsx`; `components/learn/{LessonScreen,VideoPlayer,LessonOutline,ResumeRedirect}.tsx`; `lib/learn/{schemas,api,errors,embed,linkManager,heartbeat,hlsEngine,sessionPause,progress}.ts`.
- Test mới: `lib/learn/{linkManager,heartbeat,embed,sessionPause}.test.ts`, `components/learn/VideoPlayer.test.tsx`, thêm 1 ca vào `proxy.test.ts`; e2e `e2e/hoc-video-real.spec.ts`, `e2e/seed-e2e-learn.sh`.
- Sửa: `lib/routes.ts` (`learn`, `lesson`), `next.config.ts` (`NEXT_DIST_DIR` hợp lệ `.next*`, giống admin), `eslint.config.mjs` (bỏ qua `.next-*`), `.env.example` (`NEXT_PUBLIC_VIDEO_HOSTS`), `package.json` + `pnpm-lock.yaml` (thêm `hls.js` 1.7.3, nằm trong danh sách duyệt G2; lockfile còn kéo theo thay đổi deps của FA4). Không đụng `packages/ui` và `apps/admin`.

### Cách test
- `frontend/scripts/pnpm.sh --filter @vitaminvui/web run typecheck|lint|test` — tsc, lint sạch; unit 267/267 (30 file). `next build` (vào `.next-check`) thành công, có `/hoc/[course]` và `/hoc/[course]/bai/[lesson]`.
- E2E thật (VideoLab, 8/8 pass, `--workers=1`): `e2e/seed-e2e-learn.sh` (tạo khóa `e2e-fw4-hoc-video`, 2 video 24 giây transcode thật qua worker-video, 1 bài link ngoài, 1 bài chưa có video, học sinh `fw4-hs-own` đã ghi danh / `fw4-hs-none` chưa) rồi chạy spec với `E2E_REAL_BACKEND=1 E2E_FW4="course=.. l1=.. l2=.. l3=.. l4=.."` (số in ra từ seed). Dev server của spec cần `NEXT_PUBLIC_VIDEO_HOSTS=http://video.localhost:8000` (dev server đang chạy trên cổng 3000 để trống biến này nên video bị CSP chặn: phải khởi động lại với biến đó hoặc chạy server riêng như tôi đã làm: container Playwright + `next dev` với `NEXT_DIST_DIR=.next-e2e-fw4`). Bài xem hết đổi trạng thái → seed lại trước mỗi lần chạy.
- Ca e2e: ghi danh xem được (playback gọi từ trình duyệt, video chạy), chưa ghi danh bị chặn, CDN 403 → làm mới ngầm vẫn phát, làm mới trước hạn (đồng hồ giả +14:30), heartbeat 2× → hoàn thành + toast + mục lục, đăng nhập thiết bị khác → hộp thoại + video pause, link ngoài sandbox + bài chưa video, `/hoc/{course}` resume + 404.

### Điều chưa làm / lệch cần quyết
1. **AC3 chuyển hướng về chi tiết khóa**: API 403 `COURSE_NOT_OWNED` không trả `slug`/tên khóa và catalog không tra theo id, nên trang chỉ hiện màn "Bạn chưa sở hữu khóa học này" + nút về danh mục (không tự chuyển hướng). Đề xuất cho Architect/`laravel-dev`: thêm `course:{id,slug,title}` vào body 403 của `/learn/lessons/{id}` (hoặc cho `/courses/{id}`).
2. **Link ngoài không ghi tiến độ**: iframe không báo vị trí, API chỉ hoàn thành qua heartbeat → bài link ngoài không bao giờ tự hoàn thành (trang có ghi chú rõ). Cần PO/Architect quyết (nút "Đánh dấu đã học"? API mới).
3. **Bài trắc nghiệm trong mục lục** chỉ hiện thông tin, không có liên kết (màn quiz là FW5, chưa có route; tránh liên kết chết).
4. Nút "Khóa học của tôi" chưa có (FW6): liên kết quay lại trỏ chi tiết khóa `/khoa-hoc/{slug}`.
5. **Chưa thử Bunny thật** (chữ ký token, token trong URL query/path với hls.js, CORS/Referrer). Với token dạng `bcdn_token=` cần xác nhận sub-playlist/segment mang token; nếu không, `hlsEngine` sẽ cần `xhrSetup`.
6. Không có test trình duyệt thật cho Safari (HLS gốc) và fullscreen; mobile 375px chưa chụp màn hình.
7. Dev StrictMode gọi playback 2 lần ở lần mở đầu (effect chạy đúp); production 1 lần.
8. Lần chạy đầu của tôi lỡ chạy cả bộ e2e web (cấu hình scratch thiếu lọc file): `otp`/`password`/`fw-v2-qa` hỏng do thiếu Mailpit và có thể đã tiêu hạn mức OTP/đổi dữ liệu của tài khoản e2e `qa-t27-*`/`e2e-*`; cần seed lại (`seed-e2e-auth.sh`) và reset OTP trước khi QA chạy các spec đó.

### Luồng `laravel-qa` nên kiểm kỹ
Thu hồi enrollment giữa phiên (heartbeat/playback 403); bài > 15 phút qua mốc làm mới (kiểm tay hoặc nâng/hạ `VIDEO_PLAYBACK_TTL_MINUTES` xuống 1–2 phút); 2 tab cùng bài (heartbeat 429); rời trang giữa chừng (tiến độ cuối được gửi); bật/tắt `VIDEO_BIND_IP`; mạng chập chờn; Bunny staging khi có CDN hostname.

## Review
**Kết luận:** REQUEST CHANGES (1 BLOCKER, 5 SHOULD, 3 NIT)
**Phạm vi:** diff chưa commit của `frontend/apps/web` (app/(learn), components/learn, lib/learn, routes, next.config, eslint, package.json, e2e) · không xét apps/admin. `tsc` sạch, `eslint` sạch, vitest `lib/learn` + `components/learn` + `proxy.test.ts` 49/49. Không chạy e2e/build.

### Tổng quan
Phần lõi chắc: link phát chỉ gọi từ trình duyệt, `PlaybackLinkManager` single-flight + generation + trần 403 liên tiếp (3) + sàn 10 giây chống vòng lặp, heartbeat toàn số nguyên/trần 60/gửi bù/keepalive, tách thuần TS nên test được. Bảo mật đạt: CSP (`connect-src`/`media-src` thêm host video, `frame-src` chỉ youtube-nocookie/vimeo), iframe sandbox tối thiểu + kiểm host https lần hai, không `dangerouslySetInnerHTML`, không localStorage/log URL ký, `noindex`, regex `NEXT_DIST_DIR` đúng như admin, id URL kiểm `^[1-9]\d{0,9}$`. Còn một lỗi nhánh HLS gốc và vài chỗ mất tiến độ/giật.

### Phát hiện
**R1 [BLOCKER] Nhánh HLS gốc (Safari/iPhone cũ không có MSE) kẹt ở màn "Đang tải video…", không có nút phát**
- Vị trí: `lib/learn/hlsEngine.ts:84-93,108` + `components/learn/VideoPlayer.tsx:136-139,276,303`.
- Vấn đề: `phase` chỉ chuyển `loading → ready` trong `onPlaying`. `nativeEngine` gắn `onPlaying` vào sự kiện `playing`, mà sự kiện này chỉ bắn SAU khi video bắt đầu phát; engine tạo với `autoplay=false`, còn điều khiển (`showControls`) chỉ hiện khi `phase==="ready"` và lớp phủ loading che `<video>` (không có `controls`). Học sinh không thể bấm phát. (Nhánh hls.js ổn vì `FRAG_BUFFERED` bắn khi đã nạp đoạn đầu.) Dev đã nêu chưa thử Safari; đây là lỗi logic đọc code là thấy.
- Đề xuất: ở `nativeEngine` gọi `cb.onPlaying()` (đổi tên thành `onReady`) khi `loadedmetadata`/`canplay`, và để `reportPlaying` (xoá streak 403) tách riêng với `playing`. Thêm test với `canPlayType` giả.

**R2 [SHOULD] Đổi bài bằng liên kết (Bài trước/tiếp theo, mục lục) làm mất tiến độ chưa gửi (tối đa ~20 giây + vị trí cuối)**
- Vị trí: `VideoPlayer.tsx:235-240` (cleanup) — chỉ `pagehide`/`visibilitychange` mới `flush`; điều hướng mềm (remount do `key`) không bắn hai sự kiện đó và sự kiện `pause` của `<video>` bị gỡ listener trước khi bắn.
- Hậu quả: bấm "Bài tiếp theo" khi đang ở ~85–90% thì bài không được tính hoàn thành; "tiếp tục học" quay về vị trí cũ.
- Đề xuất: trong cleanup của effect heartbeat gọi `flush({ force: true, keepalive: true })` (kiểm tra `lessonId`/`canTrack` của closure cũ, đúng bài cũ).

**R3 [SHOULD] Sau khi đăng nhập lại trong hộp thoại mất phiên, heartbeat chết vĩnh viễn trên trang**
- Vị trí: `VideoPlayer.tsx:244-251` đặt `endedRef.current = true`, không nơi nào đặt lại (`flush` thoát ở dòng 158).
- Vấn đề: `login-required` (hết phiên rồi đăng nhập lại cùng trang) → video chỉ được pause, học xong tiếp vẫn không ghi tiến độ, không có thông báo. Làm mới link cũng đã `pause()` và không được hẹn lại (tạm ổn nhờ 403 → `reportForbidden`).
- Đề xuất: đừng dùng `endedRef` cho "mất phiên"; dùng cờ riêng và đặt lại ở sự kiện `play` tiếp theo (người dùng bấm phát = phiên đã khôi phục) kèm gọi `manager.start()` làm mới link; `endedRef` chỉ dành cho 403/404 heartbeat.

**R4 [SHOULD] Làm mới theo lịch dựng lại hls.js giữa lúc đang phát (giật/mất buffer mỗi ~14 phút)**
- Vị trí: `hlsEngine.ts:71-75` (`setUrl` → `build` mới), gọi từ `VideoPlayer.tsx:112` cho cả `reason==="scheduled"`.
- Vấn đề: yêu cầu là "không gây giật". Token thư mục nằm trong path nên các segment đã nạp/đang nạp vẫn dùng token cũ cho tới `expires`; hủy instance làm rỗng buffer và tải lại. Dev đã chứng minh phát được (e2e) nhưng không đo gián đoạn.
- Đề xuất: giữ nguyên `build` cho 403 (cần ngay), còn `scheduled`: chỉ lưu URL mới và đổi khi video đang `paused`/ngay trước 403, hoặc dùng `xhrSetup`/`pLoader` thay token trong path cho request kế tiếp (không dựng lại). Ít nhất QA đo thời gian ngưng hình khi qua mốc làm mới (hạ `VIDEO_PLAYBACK_TTL_MINUTES` xuống 2).

**R5 [SHOULD] Mobile 375px/vùng chạm/toàn màn hình**
- `VideoPlayer.tsx:371` thanh tua `h-6` (24px) < 44px, khó kéo bằng ngón tay (nút khác đã 44px); nên bọc vùng chạm cao 44px (padding dọc hoặc `h-11` với track mảnh).
- `VideoPlayer.tsx:272` `frame.requestFullscreen` không có trên iPhone Safari → nút "Toàn màn hình" im lặng không làm gì. Thêm fallback `video.webkitEnterFullscreen?.()` (hoặc ẩn nút khi `!document.fullscreenEnabled`).
- `VideoPlayer.tsx:388` nút tắt tiếng bị `hidden sm:inline-flex` ở 375px (chấp nhận được vì dùng nút cứng của máy; ghi vào design note).

**R6 [SHOULD] Seed script không có chốt môi trường**
- Vị trí: `e2e/seed-e2e-learn.sh` — mã tinker `CLEAN` xoá cứng (`forceDelete`, xoá user `fw4-%@example.com`, `Video::where(...)->delete()`) không kiểm `app()->environment()`. Hiện chỉ an toàn vì chạy qua `infra/docker-compose` local.
- Đề xuất: đầu chuỗi tinker `if (! app()->environment(['local','testing'])) { throw new RuntimeException('Chỉ chạy ở local/testing'); }` (cả nhánh `--clean`).

**R7 [NIT]** `VideoPlayer.tsx:382-383`: `aria-pressed` cùng nhãn đổi theo trạng thái ("Bật tiếng"/"Tắt tiếng") là báo trạng thái hai lần; chọn một (nhãn cố định "Tắt tiếng" + `aria-pressed`).
**R8 [NIT]** `proxy.ts:35-37`: `enableWorker: true` của hls.js tạo worker từ `blob:`; `script-src` có `'strict-dynamic'` nên có thể chặn và hls.js lặng lẽ chạy trên luồng chính. Kiểm tra console CSP; nếu có thì thêm `worker-src 'self' blob:` (hoặc `enableWorker:false`). Ngoài ra `env.ts:14-17` chưa kiểm từng phần tử `NEXT_PUBLIC_VIDEO_HOSTS` là origin hợp lệ (ký tự `;`/khoảng trắng sẽ chèn directive CSP) — cấu hình tin cậy nên chỉ NIT; có thể `z.string().url()` từng phần.
**R9 [NIT]** `ResumeRedirect.tsx:49-52` format JSX lệch (Pint/prettier của dự án không bắt nhưng khó đọc); `fail()` không `dispose` manager nên khi lỗi hls fatal vẫn còn hẹn giờ làm mới đến khi bấm Thử lại/rời trang (vô hại, nhưng tốn playback throttle 30/phút nếu để tab nhiều giờ) — nên `manager.pause()` trong `fail`.

### Đánh giá các điểm Dev nêu
1. **AC3 thiếu slug trong 403**: chấp nhận cho FW4 (không phải BLOCKER), màn hiện tại hợp lý. Tạo việc nhỏ cho `laravel-dev`: thêm `course:{id,slug,title}` vào body 403 của `/learn/lessons/{id}` — hoặc đơn giản hơn, cho catalog tra theo id. Sau đó trang tự `router.replace(routes.course(slug))`. Ghi vào backlog.
2. **Bài link ngoài không hoàn thành được**: không sửa ở FW4, nhưng đây là lỗ hổng nghiệp vụ (khóa có bài embed sẽ không bao giờ 100%). Cần PO quyết: nút "Đánh dấu đã học" (API mới `POST /learn/lessons/{id}/complete`) hoặc cấm bài embed ở khóa tính tiến độ. Ghi chú trên trang hiện có là đủ tạm thời.
3. **Bunny `bcdn_token`**: định dạng của `BunnySigner` là token nằm TRONG PATH (`/bcdn_token=…&expires=…&token_path=%2F{guid}%2F/{guid}/playlist.m3u8`), nên URL tương đối của sub-playlist/segment kế thừa path chứa token khi hls.js resolve — không cần `xhrSetup` nếu playlist dùng đường dẫn tương đối (Bunny Stream dùng tương đối). Rủi ro còn lại cần staging: playlist có URL tuyệt đối `/{guid}/…`, Referrer/CORS, IP IPv6, và Safari native. Không chặn merge.
4. StrictMode gọi playback 2 lần ở dev: chấp nhận (manager đầu bị `dispose`, kết quả bỏ qua nhờ `generation`); production 1 lần.

### Đối chiếu acceptance criteria
| AC | Code đáp ứng | Ghi chú |
|---|---|---|
| US-006 AC6 resume | `ResumeRedirect` → `resume_lesson_id`, empty khi null | OK |
| US-006 AC3 chưa sở hữu → chi tiết khóa | `BlockedView` + nút danh mục | Lệch có chủ ý, chờ API (mục 1) |
| US-008 phát video, hồi vị trí | `resume_at_seconds` → `startPosition`, tua/tốc độ/toàn màn hình | R1 (HLS gốc), R5 (iPhone fullscreen) |
| US-008 AC5 lỗi + Thử lại | `phase==="error"` + `retry()` | OK |
| US-014 tiến độ ≥90%, toast, mục lục | heartbeat → `completed` → toast/`done` | R2, R3 làm mất tiến độ ở vài đường |
| T37 S6 playback từ trình duyệt, làm mới trước hạn, tự lấy lại khi 403 | `linkManager` + `hlsEngine` | R4 (giật khi làm mới) |
| design v2 §12.3 | layout không header/footer/ô ly | OK |

### Gợi ý cho QA
- Safari desktop + iPhone (cả hls.js/MMS và HLS gốc): nút phát hiện sau khi tải, tua, toàn màn hình.
- Xem tới ~85% rồi bấm "Bài tiếp theo": bài trước có completed không; quay lại bài: vị trí hồi đúng không.
- Hạ TTL xuống 2 phút: đo gián đoạn khi qua mốc làm mới (đang phát/đang pause); xem tab nền nhiều giờ có spam playback không.
- Hết phiên (đăng nhập thiết bị khác) → đăng nhập lại trong hộp thoại → phát tiếp → heartbeat có ghi tiến độ không.
- Thu hồi enrollment giữa phiên; 2 tab cùng bài (429); `VIDEO_BIND_IP` bật/tắt; ngắt mạng 30 giây; 375px (thanh tua bằng ngón tay).

## Dev đã sửa (sau review)
- **R1 (BLOCKER)** `lib/learn/hlsEngine.ts`: callback tách `onReady` (hls.js: `FRAG_BUFFERED`; HLS gốc: `loadedmetadata`/`canplay`) và `onPlaying` (sự kiện `playing`). `phase` chuyển `loading → ready` ở `onReady`, nên Safari/iPhone có nút phát ngay sau khi tải metadata. Test `lib/learn/hlsEngine.test.ts` (hls.js giả không hỗ trợ + `canPlayType` giả).
- **R2** cleanup của effect heartbeat gọi `flush({ force: true, keepalive: true })` (đổi bài bằng liên kết mềm không mất tiến độ). Thêm cờ `touched` trong `HeartbeatTracker`: mở bài mà chưa phát thì rời đi không gửi gì. Test trong `VideoPlayer.test.tsx`.
- **R3** tách `sessionEndedRef` (hộp thoại mất phiên, tạm dừng) khỏi `endedRef` (403/404 heartbeat, dừng hẳn). Sự kiện `play` tiếp theo xoá cờ, bật lại heartbeat và gọi `manager.resume()` (xin link mới, hẹn giờ lại). Có test.
- **R4** làm mới THEO LỊCH khi đang phát chỉ cất link mới (`setUrl(url, false)`), đổi khi video tạm dừng; khi CDN 403 mà đã có link cất thì dùng luôn (không gọi API, không `onForbidden`). 403 không có link cất và khôi phục phiên vẫn dựng lại ngay như cũ. Test engine + player. Vẫn nên đo gián đoạn thật khi hạ TTL (QA).
- **R5** thanh tua cao 44px (`h-11`); toàn màn hình dùng `requestFullscreen` của khung, không có (iPhone) thì `video.webkitEnterFullscreen()`; nút tắt tiếng hiện ở 375px (nhãn cố định "Tắt tiếng" + `aria-pressed`, đồng thời R7).
- **R6** `seed-e2e-learn.sh`: cả 3 đoạn tinker (dọn, tạo, hoàn tất) bắt đầu bằng chốt `app()->environment(['local','testing'])`, nếu không thì ném lỗi.
- **R8** `proxy.ts` thêm `worker-src 'self' blob:` (hls.js tạo worker từ blob; `strict-dynamic` không cho blob qua `script-src`), có test. `env.ts` kiểm từng phần tử `NEXT_PUBLIC_VIDEO_HOSTS` là origin (`[http(s)://]host[:port]`), chuỗi chèn `;`/khoảng trắng/đường dẫn làm khởi động báo lỗi rõ tên biến; có test.
- **R9** `fail()` gọi `manager.pause()` (không còn hẹn giờ làm mới sau lỗi); `ResumeRedirect.tsx` format lại.

### Việc thêm của PO
- **Nút "Đánh dấu đã học"** (`POST /api/v1/learn/lessons/{id}/complete`, không body): `lib/learn/api.ts` (`completeLesson`), `components/learn/CompleteButton.tsx`. Chỉ hiện cho bài link ngoài (`kind: embed`) khi `can_track`; đã học thì hiện "Đã học". Kết quả đi qua cùng `onProgress` như heartbeat (mục lục, tiến độ khóa, toast lần đầu). 422 `LESSON_COMPLETION_NOT_MANUAL`, 429 và lỗi chung có thông báo dưới nút; 403 → báo thu hồi quyền; 401 để `SessionEndedGate` lo.
- **403 kèm `errors.course {id, slug, title}`**: `courseRefFromError` (kiểm slug theo định dạng catalog để không thành điểm chuyển hướng tuỳ ý). `LessonScreen` và `ResumeRedirect` `router.replace('/khoa-hoc/{slug}')`; không có `errors.course` (khóa nháp/ẩn) thì ở lại màn "Bạn chưa sở hữu khóa học này" + nút về danh mục. Xoá mục "điều chưa làm 1 và 2" ở phần Dev phía trên (đã giải quyết).
- Test mới: `CompleteButton.test.tsx`, `LessonScreen.test.tsx` (chuyển hướng/không chuyển/slug lạ), `courseRefFromError`, `hlsEngine.test.ts`, thêm ca vào `VideoPlayer.test.tsx`, `env.test.ts`, `proxy.test.ts`. E2E (`hoc-video-real.spec.ts`, 10 ca): thêm chuyển hướng về chi tiết khóa khi chưa ghi danh, nhánh 403 không có `errors.course` (chặn bằng `page.route`), "Đánh dấu đã học" cập nhật mục lục + tải lại vẫn "Đã học", bài video thường không có nút.
- Không kiểm được trên Chromium: nhánh HLS gốc (chỉ có unit test) và `webkitEnterFullscreen`.

## Re-review
**Kết luận:** APPROVE (0 BLOCKER, 0 SHOULD, 2 NIT)
**Phạm vi:** diff hiện tại `frontend/apps/web` (đã chạy `tsc --noEmit` sạch, `eslint .` sạch, vitest web 33 file / 289 test pass; không build, không e2e).

### Kiểm R1–R9
- R1 OK: `hlsEngine.ts` tách `onReady` (hls.js `FRAG_BUFFERED`; native `loadedmetadata`/`canplay`) khỏi `onPlaying`; có test.
- R2 OK: cleanup heartbeat `flush({force, keepalive})` (`VideoPlayer.tsx:251`), cờ `touched` tránh gửi khi chưa phát.
- R3 OK: `sessionEndedRef` tách `endedRef`; `play` kế tiếp xoá cờ + `manager.resume()` (lý do `resume` → `setUrl` ngay, `VideoPlayer.tsx:116`).
- R4 OK: làm mới theo lịch khi đang phát chỉ cất `pendingUrl`, đổi khi `pause` hoặc khi CDN 403 (dùng luôn, không gọi API); `forbidden`/`resume` đổi ngay. Vẫn cần QA đo thực tế.
- R5 OK (thanh tua h-11, fallback `webkitEnterFullscreen`, tắt tiếng hiện ở 375px), R6 OK (3 đoạn tinker có chốt môi trường), R7 OK, R8 OK (`worker-src`, validate host trong `env.ts`), R9 OK.

### Phần mới
- `CompleteButton`: chỉ hiện khi `videoKind==="embed" && can_track` (`LessonScreen.tsx:~268`); đã học → "Đã học"; 403 → báo thu hồi, 422 `LESSON_COMPLETION_NOT_MANUAL`/429/lỗi chung có thông báo `role=alert`, 401 để `SessionEndedGate` lo; `busy` chặn bấm đúp; kết quả đi qua `onProgress` (toast lần đầu, mục lục). Đạt.
- `courseRefFromError`: chỉ nhận 403, slug phải qua `isValidCourseSlug` (`^[a-z0-9]+(-[a-z0-9]+)*$`, ≤255) rồi đưa vào `routes.course(slug)` là đường dẫn nội bộ cố định tiền tố `/khoa-hoc/` → không thành open redirect; slug lạ → ở lại màn chặn. `LessonScreen`/`ResumeRedirect` chuyển hướng bằng `router.replace`, có nhánh không có `errors.course`. Đạt.

### Còn lại
- **R10 [NIT]** `CompleteButton.tsx:42`: mọi 403 đều báo "đã thu hồi quyền" kể cả 403 khác COURSE_NOT_OWNED; chấp nhận vì endpoint chỉ trả 403 này.
- **R11 [NIT]** Nhánh HLS gốc và `webkitEnterFullscreen` mới chỉ có unit test; QA nên thử thiết bị Safari/iPhone thật.

Bước tiếp theo: chuyển `laravel-qa`.

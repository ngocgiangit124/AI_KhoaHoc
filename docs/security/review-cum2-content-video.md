# SECURITY: Cụm 2 — Nội dung, upload, video, học tập (T08–T14, T21–T23) | 2026-10-06
**Kết luận:** PASS có điều kiện

Không có Critical/High. Không có Medium mới. Có 5 Low và 4 Info, đều không chặn go-live nhưng nên đưa vào `backlog-v2.md`. Điều kiện của "PASS": các mục go-live đã biết của T12 (T12-4, T12-5, T12-6/T12-10) và T08-2 phải xong trước production.

**Phạm vi:** commit `2cf9de0` trên `main`, cộng phần working tree của "Sửa lỗi nhỏ 3" đang làm song song (T12-2: `createVideo($title, $size)`, `TusUploadService` ném `DomainException`). Đã đọc: `routes/{api,admin,videolab}.php`, `app/VideoLab/**`, `Services/Video/**`, `Services/Learning/**`, `Services/Quiz/**`, `Services/Content/**`, các policy `Course/Lesson/Enrollment`, các FormRequest của T08/T09/T11/T21/T22, `config/{video,videolab,learning,quiz,purifier}.php`, `infra/docker-compose.yml` (worker-video), `infra/nginx/**`. Đối chiếu: ADR-002 (§3a, §4, §5), api-contract, `backlog-v2.md` (không báo lại các mục đã có).

**Cách kiểm:** phân tích tĩnh, kết hợp thử thật bằng curl trên stack local (nginx :8000, 3 host). Dữ liệu tạo có tiền tố `sec-test-`: 2 giáo viên, 2 học sinh, khóa A/B, 6 bài, 2 quiz, 6 video. **Đã dọn hết** (DB, `videolab/{source,hls}`, ảnh thumbnail, file seed). Chỉ còn các dòng `audit_logs` do thao tác thử sinh ra, vì bảng này không cho xoá (đúng thiết kế). Video thử 7,6 KB (`testsrc 160x120`, 2 giây). File độc hại đều dưới 150 byte. Mỗi lần chỉ chạy 1 job transcode, load máy lúc chạy khoảng 3–4.

## Các điểm đạt (đã thử thật)

| Nhóm | Kết quả |
|---|---|
| **IDOR giáo viên** | GV-A với khóa B: GET/PUT khóa, xem chương, xem quiz → 403. Gắn bài/quiz của B vào đường dẫn khóa A (scopeBindings) → 404. `video-uploads` và `playback` của bài B → 403 hoặc 404. Tạo quiz trỏ `lesson_id` của B → 422. Reorder có id bài của B → 422 `CURRICULUM_MISMATCH`. GV gọi `publish` và `teachers` → 403. Danh sách `enrollment-requests` chỉ hiện khóa mình dạy, email đã che (`s***@`). |
| **IDOR học sinh** | HS chưa sở hữu khóa: bài trả phí, playback, heartbeat, outline, `/me/courses/{id}/progress`, bắt đầu quiz, lịch sử quiz → 403 `COURSE_NOT_OWNED`. HS-2 xem, ghi đáp án hoặc nộp lượt làm của HS-1 → 404. Heartbeat vào bài của khóa khác → 403. `/preview/.../playback` của bài không phải preview → 404. Outline công khai không chứa ID hay URL video. |
| **HS gọi route admin** | Đăng nhập HS ở admin-api → 403 `WRONG_PORTAL`. Dùng lại cookie phiên HS ở host admin, hoặc cookie GV ở host api → 401. |
| **XSS lưu trữ** | Mô tả khóa: Purifier lọc khi ghi và cả khi trả ra, ở admin lẫn catalog công khai. Đã thử `onerror`, `javascript:`, `jav&#x09;ascript:`, `data:`, `<svg><script>`, `style`, `<iframe>`, `<form>`, MathML `xlink:href`, comment: tất cả bị loại. Link còn lại được gắn `rel="nofollow noreferrer noopener"`. `title`/`short_description` có thẻ hoặc `\u0000` → 422. Câu hỏi/lời giải quiz có `<img`, `<b>` → 422. `rejection_reason` từ chối `<>`. Không có `{!! !!}` trong views. |
| **Link ngoài / SSRF** | `javascript:`, host lạ, `youtube.com.evil.com`, `youtube.com@evil.com`, cổng 444, `169.254.169.254`, `v[]=`, Vimeo có hash → 422. URL nhúng luôn dựng lại từ ID đã qua regex. Không có request nào ra ngoài. Bài không preview không nhận link ngoài. |
| **Upload ảnh** | SVG đặt tên `.png`, `GIF89a<?php` đặt tên `.jpg`, PNG 4001 px, tên file `../../../public/shell.php` → 422. PNG polyglot (`<?php`/`<script>` nối đuôi) → được nhận nhưng mã hoá lại thành WebP 64x64, file lưu không còn payload. Tên lưu là `{uuid}.webp`. Ảnh cũ bị xoá khi thay. |
| **TUS** | Sai chữ ký, sửa `AuthorizationExpire`, sửa `LibraryId`, PATCH vào guid khác → 403. `Upload-Length` vượt kích thước khai báo → 413 (T12-2 của Sửa lỗi nhỏ 3 đã có tác dụng). Tạo lại phiên → 403. Offset lệch → 409. PATCH vượt length → 413. PATCH sau khi đã xong → 403. Tên file `../../x<script>.mp4` chỉ lưu `basename` để hiển thị, không bao giờ dùng làm đường dẫn. |
| **ffmpeg / file độc** | `#EXTM3U` có `file:///etc/passwd` → 422 ngay ở bước magic bytes. `ftyp`+HLS (tham chiếu `file://` và `http://169.254…`), `ftyp`+`ffconcat`, `1A45DFA3`+HLS → status 5 trong khoảng 0,1 giây. Không có output HLS, không truy cập file hay mạng. Lý do: `Process` dùng mảng tham số, `-protocol_whitelist file`, `-f mov/matroska` theo magic bytes, `-format_whitelist`, env sạch. Sandbox worker-video (kiểm bằng `docker inspect`): rootfs chỉ đọc, `cap_drop ALL`, `no-new-privileges`, 2 GB, `pids 256`, chỉ network `internal: true`, uid 33, `.env` là `/dev/null`. ffmpeg 7.1.5. |
| **Token CDN** | Sửa `expires`, dùng token của guid này cho guid khác, `expires` đã qua → 403. Gọi từ IP khác (container php) → 403. Thêm `X-Forwarded-For` giả từ IP không tin cậy không làm đổi IP. `..`, `%2f..`, `source/`, guid viết hoa, token có `=`, `expires` 11 chữ số → 404 (không khớp route). Origin lạ không được CORS. `Cache-Control: private`. |
| **Webhook** | Chữ ký sai → 204 nhưng không làm gì (không lộ asset có hay không). `fake`/`bunny`/tên lạ → 404. Body 20 KB → 413. Trạng thái luôn lấy lại bằng `getVideo()` có AccessKey. |
| **Heartbeat** | `watched_delta_seconds` 61 hoặc âm → 422. Lần đầu cộng tối đa 45 giây. Gửi lại ngay (elapsed ≈ 0) → cộng 0. Throttle 6/phút theo từng bài (lần 7 → 429). Hoàn thành chỉ khi đạt 90% `duration_seconds` của server. |
| **Quiz** | Lượt đang làm không có `is_correct`, `explanation`, `result`, `correct_*`. Option thuộc câu khác → 422 `QUIZ_OPTION_INVALID`. Câu không thuộc lượt → 422. `option_id` dạng chuỗi hoặc mảng → 422. Nộp 2 lần → cùng kết quả (idempotent). Sửa đáp án sau khi nộp → 409. Quá hạn và quá ân hạn → lượt đã được tự nộp với `submitted_at = expires_at`, `auto_submitted=true`. Copy-on-write: GV sửa câu 1 (đổi đáp án đúng) khi HS đang làm → câu cũ bị xoá mềm, lượt đang làm vẫn chấm theo câu cũ và đáp án cũ. |
| **Mass assignment** | `Lesson` không có `video_asset_id/course_id/chapter_id` trong `$fillable`. Request khóa học bỏ qua `status/slug/created_by/manual_order`. GV không có `price/teacher_ids`. Quiz lấy `course_id` từ route. `VideoUploadService` đặt `provider*`, `created_by` ở server. |
| **SQL injection** | Mọi `whereRaw/selectRaw/orderByRaw` là chuỗi tĩnh hoặc có binding. `JSON_SET` đường dẫn ghép từ số nguyên. Sắp xếp catalog dùng `match` whitelist. |
| **composer audit** | Không có advisory. |

## Phát hiện

### L1 [Low] Heartbeat giới hạn theo từng bài, không theo người học: phát song song nhiều bài thì hoàn thành cả khóa trong thời gian khoảng 1 bài — OWASP A04
- **Vị trí:** `backend/app/Services/Learning/ProgressService.php:98-109` (tính `elapsed` từ `last_heartbeat_at` của **dòng bài đó**). `AppServiceProvider.php:206-211` (throttle `heartbeat` khoá theo `user:lesson`).
- **Mô tả:** T13-1 đã chấp nhận mức tua khoảng 2,5 lần thời gian thật, nhưng ngầm hiểu là cho **một** bài. Mỗi bài có đồng hồ riêng và bộ đếm throttle riêng, nên một script gửi heartbeat cho N bài cùng lúc được cộng 2,5 lần thời gian thật **cho từng bài**. Nghĩa là cả khóa 50 bài × 10 phút có thể "hoàn thành" trong khoảng 4 phút thay vì 500 phút. Mức này nặng hơn T13-1 ở độ lớn, nhưng tác động vẫn chỉ là toàn vẹn số liệu tiến độ của chính học sinh gian lận: không lộ dữ liệu, không vượt quyền.
- **Tái hiện:** HS sở hữu khóa gửi `POST /api/v1/learn/lessons/{L1}/heartbeat` và `/{L2}/heartbeat` (`watched_delta_seconds=60`) xen kẽ, mỗi 10 giây. Đã thử: 2 bài cùng tăng (bài 600 giây tăng từ 45 lên 210 trong khoảng 50 giây thật; bài 2 giây chuyển `completed`), không lần nào bị 429.
- **Đề xuất (v2, cùng hướng T13-1):** thêm trần theo người học: tổng giây được cộng trên **mọi bài** trong một cửa sổ ≤ (thời gian thật trôi qua × 2 + slack). Có thể dùng một khoá `AtomicCounter` theo user, hoặc cột `users.last_learning_heartbeat_at`. Throttle thêm một lớp `heartbeat-user` khoảng 8/phút/user cho mọi bài. Muốn chặt hơn thì cấp "phiên phát" ở `/playback` và chỉ cộng cho bài đã có phiên phát gần nhất (đúng như đề xuất của T13-1).
- **Kiểm chứng:** test gửi heartbeat xen kẽ 2 bài trong 60 giây (Carbon `travel`) → tổng `watched_seconds` của 2 bài ≤ 60 × 2 + slack.

### L2 [Low] Không kiểm tỉ lệ khung hình và luôn phóng to lên 360p: file nhỏ ép ffmpeg mã hoá khung rất rộng — OWASP A04 (DoS tài nguyên)
- **Vị trí:** `backend/app/VideoLab/Services/MediaToolkit.php:202-207` (chỉ kiểm `width ≤ 3840`, `height ≤ 2160`); `:140` (`scale=-2:{height}`); `TranscodeService.php:105-109` (nguồn thấp hơn mọi bậc vẫn mã hoá bậc 360p, tức là phóng to).
- **Mô tả:** nguồn 3840×N với N nhỏ hợp lệ, nhưng `scale=-2:360` biến nó thành khung rộng `3840×360/N`. Đã thử trong worker-video với 1 giây và `timeout`: 3840×2 → 691200×360 và 3840×22 → 62836×360 đều bị libx264 từ chối ngay (khoảng 1 giây). Tuy vậy, khoảng N ≈ 85–360 tạo khung tới khoảng 16000×360 (khoảng 5,8 triệu điểm ảnh, xấp xỉ 4K) mà x264 vẫn nhận. Một file vài MB, thời lượng khai báo 180 phút, buộc worker mã hoá "4K" cho tới `encode_timeout` 1500 giây. Lỗi hạ tầng còn được thử lại thêm 1 lần (`tries 2`). Đã chạy thì chiếm hết queue `video` (1 worker) trong khoảng 50 phút. Chỉ GV/staff upload được, và có hạn mức ngày, nên mức Low.
- **Đề xuất:** trong `validateProbe` từ chối tỉ lệ ngoài khoảng [1:4; 4:1], hoặc tính `outWidth = round(w × h_target / h)` và từ chối khi lớn hơn 3840. Bỏ phóng to: chỉ mã hoá bậc ≤ chiều cao nguồn, nguồn < 360 thì giữ nguyên độ phân giải (`scale=-2:'min(ih,360)'`). Khi lỗi là do tham số encoder thì ném `VideoRejectedException` để không thử lại.
- **Kiểm chứng:** unit test `MediaToolkit::validateProbe` với `3840x100` → `VideoRejectedException`. Test `encodeCommand` cho nguồn 160x120 không phóng lên 360.

### L3 [Low] File gốc của video lỗi (status 5) được giữ vô thời hạn — OWASP A04 / thời hạn lưu dữ liệu
- **Vị trí:** `backend/app/VideoLab/Console/VideoLabCleanupCommand.php:37-48` (chỉ xoá `source` của video `FINISHED`); `TranscodeService.php:75-88` (`markFailed` xoá `hls` nhưng không xoá `source`).
- **Mô tả:** cả 3 file độc thử nghiệm đều nằm lại ở `videolab/source/*.bin` sau khi bị từ chối. Chúng chỉ mất khi asset thành mồ côi (bài đổi sang video khác, rồi `videos:prune-orphans` chạy). Nếu bài vẫn trỏ vào asset `failed` thì file độc hoặc file hỏng (tối đa 2 GB mỗi file) nằm mãi trên đĩa. Rủi ro thấp vì thư mục `source` không bao giờ được phục vụ ra ngoài.
- **Đề xuất:** `markFailed()` xoá luôn `source/{guid}.bin`, vì lỗi 5 là cuối và không cho upload lại vào cùng guid. Nếu không làm thế thì `videolab:cleanup` xoá `source` của status 5/6 sau 1 ngày.
- **Kiểm chứng:** test job transcode với file bị từ chối → không còn `source/{guid}.bin`.

### L4 [Low] `PlainText` cho qua ký tự định dạng Unicode (bidi, zero-width): giả mạo hiển thị tên khóa/chương/bài — OWASP A03 (giả mạo giao diện)
- **Vị trí:** `backend/app/Rules/PlainText.php:15` (chỉ chặn `\p{Cc}`, không chặn `\p{Cf}`). Rule này dùng cho `title`/`short_description` của khóa, chương, bài và tên quiz. `DecideEnrollmentRequest` (lý do từ chối) cũng mắc lỗi tương tự.
- **Mô tả:** `PUT .../lessons/{id}` với `title = "sec-test ‮gnp.exe"` → 200, lưu và trả lại nguyên ký tự U+202E (RLO). Tên hiển thị trên trang công khai bị đảo chiều. `QuizText` đã chặn nhóm ký tự này (T21-5), `PlainText` thì chưa. Không chạy được script.
- **Đề xuất:** dùng chung regex của `QuizText`: `[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200B}-\x{200F}\x{2060}\x{FEFF}]` → 422 (hoặc gỡ bỏ ký tự).
- **Kiểm chứng:** test 422 cho tên bài/khóa có U+202E và U+200B.

### L5 [Low] Webhook VideoLab không có mốc thời gian/nonce; module VideoLab bắt đầu import lớp nghiệp vụ — OWASP A08
- **Vị trí:** `backend/app/Services/Video/Providers/InternalVideoProvider.php:117-129`. `backend/app/VideoLab/Services/TusUploadService.php:5,186` (working tree "Sửa lỗi nhỏ 3": `use App\Exceptions\DomainException`).
- **Mô tả:**
  1. Chữ ký HMAC chỉ phủ body `{VideoLibraryId, VideoGuid, Status}`, không có thời điểm, nên body đã ký có thể phát lại mãi. Hiện không gây hại vì webhook chỉ kích hoạt `getVideo()` (pull-verify) và có throttle 120/phút/IP. Nhưng nếu sau này tin `Status` trong payload thì thành lỗ hổng.
  2. ADR-002 §1 cấm module VideoLab import code nghiệp vụ (để tách được thành app riêng hoặc thay bằng Bunny). Thay đổi đang làm của Sửa lỗi nhỏ 3 phá ranh giới này. Không phải lỗ hổng, nhưng làm yếu cô lập module.
- **Đề xuất:**
  1. Thêm header `X-VideoLab-Timestamp`, ký `timestamp + "." + body`, từ chối khi lệch quá 5 phút. Comment rõ trong `VideoWebhookService` rằng "không bao giờ tin `Status`".
  2. VideoLab tự ném `HttpException`/`abort(422, ...)` kèm JSON riêng của module (ví dụ trả `response()->json(['code' => 'VIDEO_INVALID', ...], 422)`), không dùng `App\Exceptions\DomainException`. Có thể thêm test kiến trúc (Pest `arch()`): `App\VideoLab` không phụ thuộc `App\Models`, `App\Services`, `App\Exceptions`.
- **Kiểm chứng:** webhook có timestamp cũ → bỏ qua (không gọi `getVideo`). Test arch cho namespace VideoLab.

### Info
- **I1:** `length_seconds` (từ đó là `lessons.duration_seconds`, mốc 90%) lấy từ `format.duration` trong header container, nên có thể khai sai. GV vốn đã sửa trực tiếp được `duration_seconds` của bài, nên không tạo thêm quyền mới. Trần 180 phút dựa vào header, còn thời gian mã hoá thật được chặn bởi `encode_timeout`.
- **I2:** Trong ân hạn 30 giây sau `expires_at`, học sinh vẫn đổi được đáp án, và lượt nộp tay được ghi `submitted_at = now` (> `expires_at`), `auto_submitted=false`. Đây là đúng T22-3 đã ghi, nhắc lại để FE/GV không hiểu nhầm "nộp sau hạn".
- **I3:** Trên host `video.localhost`, URL không khớp route trả trang HTML 404 khoảng 6,5 KB của Laravel (`ForceJson` chỉ gắn trong nhóm route VideoLab). Local đang `APP_DEBUG=true`. Production phải `false` (thuộc cụm 4 / T31). Có thể thêm `location / { return 404; }` cho server `video.*` ở Nginx.
- **I4:** Các mục đã biết vẫn đúng như mô tả, không nặng hơn: T08-1, T09-1, T21-1 (chưa throttle route ghi admin); T12-3/T12-4/T12-5/T12-6/T12-10 (go-live VideoLab); T13-3, T13-5 (token CDN dùng lại được trong TTL, preview không ràng IP); T21-2 (KaTeX `\href{javascript:…}` lưu được; đã thử: được lưu, FE phải để `trust:false`); T22-1 (lộ đáp án sau lượt đầu); T11-6/T12-1 (webhook chưa throttle theo guid).

## Kết quả công cụ
- `composer audit` (Docker): không có advisory.
- Không chạy Pest (không đổi code). Kiểm chứng bằng curl trên stack local và chạy `ffmpeg` có `timeout` trong worker-video.
- `docker inspect worker-video`: `ReadonlyRootfs=true`, `CapDrop=[ALL]`, `no-new-privileges`, `Memory=2g`, `PidsLimit=256`, network `vitaminvui_internal (internal=true)`.

## Việc chuyển `laravel-dev` (đưa vào backlog-v2, không chặn)
1. **L2:** kiểm tỉ lệ khung hình, không phóng to, lỗi encoder do tham số thì không retry (`MediaToolkit`, `TranscodeService`).
2. **L3:** xoá `source` khi video lỗi (`TranscodeService::markFailed` hoặc `videolab:cleanup`).
3. **L4:** `PlainText` (và rule lý do từ chối) chặn ký tự bidi/zero-width giống `QuizText`.
4. **L5:** bỏ `use App\Exceptions\DomainException` khỏi `App\VideoLab` trước khi commit Sửa lỗi nhỏ 3. Thêm timestamp cho chữ ký webhook (v2).
5. **L1:** trần cộng tiến độ theo người học (v2, cùng T13-1, PO quyết mức chặt).

## Test `laravel-qa` nên thêm
- Heartbeat xen kẽ 2 bài trong 60 giây → tổng giây được cộng ≤ 125 (sau khi sửa L1).
- `validateProbe` với 3840x100 → bị từ chối. Nguồn 160x120 không có bậc 360p phóng to (L2).
- Transcode file bị từ chối → không còn `source/{guid}.bin` (L3).
- Tên bài/khóa/chương có U+202E, U+200B → 422 (L4).
- Pest `arch()`: `App\VideoLab` không phụ thuộc `App\Models|App\Services|App\Exceptions` (L5).
- Giữ các test hồi quy cho những điểm đã đạt: GV-A với khóa B (403/404), HS-2 với lượt làm của HS-1 (404), lượt đang làm không có `is_correct/explanation`, token CDN từ IP khác → 403, file `#EXTM3U` → 422.

## Điểm cần pháp chế / PO quyết
- **L1:** chấp nhận mức "song song nhiều bài" như T13-1 hay siết theo người học. Nếu tiến độ hoặc điểm hoàn thành được dùng để báo phụ huynh/GV hoặc cấp chứng nhận thì nên siết.
- **L3, I1:** thời hạn giữ file video gốc và file bị từ chối (dữ liệu do GV tải lên, có thể chứa hình ảnh người thật): đề xuất kỹ thuật là xoá ngay khi lỗi và xoá sau 7 ngày khi thành công (đúng như config hiện tại). **Cần bộ phận pháp chế xác nhận** nếu video có hình ảnh học sinh.

# QA: FW4 — Trang học video (web học sinh)
**Kết quả:** FAIL (0 Critical, 3 Major, 0 Minor chặn). Ngày: 2026-10-07. Chưa commit code FW4 trước khi sửa 3 bug Major dưới đây.

Phạm vi: `/hoc/{course}/bai/{lesson}`, `/hoc/{course}`, nút "Đánh dấu đã học" (bài link ngoài), chuyển về `/khoa-hoc/{slug}` khi 403 `COURSE_NOT_OWNED` có `errors.course`. Backend SLN5 (VideoLab nội bộ) chạy thật; KHÔNG gọi Bunny.

## Kết quả chạy tự động
| Kiểm tra | Kết quả |
|---|---|
| `tsc` (typecheck web) | sạch |
| `eslint` web | sạch |
| Unit web (vitest) | 33 file / 289 test pass |
| `next build` production (`NEXT_DIST_DIR=.next-qa`, NODE_OPTIONS 1536MB, đã xoá thư mục build) | thành công, có route `/hoc/[course]` và `/hoc/[course]/bai/[lesson]` |
| E2E `hoc-video-real.spec.ts` (dev viết, 10 ca, `--workers=1`, seed mới) | 10/10 pass |
| E2E QA mới `e2e/hoc-video-qa.spec.ts` (15 ca chạy, 1 ca TTL chạy riêng) | 11 pass, 4 FAIL (xem Bug) + QA2 (TTL) pass |

Không chạy otp/password/fw-v2-qa (FW4 không đụng; không cần seed-e2e-auth/reset OTP).

## Độ phủ acceptance criteria / yêu cầu
| Yêu cầu | Test | Kết quả |
|---|---|---|
| US-008 phát video, hồi vị trí, tua, tốc độ, toàn màn hình, mục lục | real #1, #6 | PASS |
| US-006 AC3 chưa sở hữu → chuyển `/khoa-hoc/{slug}` | real #2 | PASS |
| 403 không có `errors.course` → màn "chưa sở hữu" + nút danh mục | real #3, QA8b (375px) | PASS |
| US-006 AC6 `/hoc/{course}` resume; id sai → 404 | real #10 | PASS |
| T37 S6: playback gọi từ trình duyệt, CDN 403 → xin link mới ngầm | real #1, #4 | PASS |
| T37 S6: làm mới link trước hạn (đồng hồ giả) | real #5 | PASS |
| US-014 heartbeat số nguyên, hoàn thành ≥90%, toast, mục lục đổi ngay | real #6 | PASS |
| Mất phiên (thiết bị khác): hộp thoại + video pause | real #7, QA6 | PASS |
| QA6: sau hộp thoại không còn heartbeat/playback; "Đăng nhập lại" → về đúng bài → phát → heartbeat 200 | QA6 | PASS |
| Thu hồi ghi danh giữa phiên | QA1, QA1b | PASS (xem ghi chú 1) |
| Link ngoài: iframe sandbox, "Đánh dấu đã học" | real #8 | PASS |
| "Đánh dấu đã học": bấm đúp = 1 request, 429 (giả lập và THẬT) báo dưới nút và bấm lại được, tải lại vẫn "Đã học", bài video không có nút | QA7, QA7b, real #8, #9 | PASS |
| 2 tab cùng bài: heartbeat 429 không làm hỏng trang, tự gửi lại 200 | QA3 | PASS |
| Đổi bài bằng liên kết mềm: tiến độ cuối được ghi (keepalive), quay lại hồi đúng vị trí | QA4 (phần soft) | PASS (pos=8, resume=8) |
| Rời trang CỨNG (đổi URL, F5, đóng tab): tiến độ được ghi | QA4 (phần hard), QA4x | FAIL (BUG-2) |
| Mạng chập chờn khi đang phát (offline 15 giây) | QA5a | PASS |
| Mạng chập chờn khi TẢI: segment `.ts` lỗi 3 lần | QA5d | PASS (tự phục hồi) |
| Mạng chập chờn khi TẢI: playlist `.m3u8` lỗi | QA5b, QA5c | FAIL (BUG-1) |
| Qua mốc làm mới link, TTL 2 phút, video lặp 200 giây | QA2 | PASS: đo khựng tối đa 117ms, không dừng; xem ghi chú 2 |
| 375px: không cuộn ngang (bài video, link ngoài, chưa có video, màn chặn) | QA8, QA8b | PASS |
| 375px: thanh tua 44px, nút phát/tắt tiếng/toàn màn hình 44x44, ô tốc độ cao 44px, nút tắt tiếng hiện | QA8 | PASS |
| 375px: nút phát lớn giữa khung chạm được | QA8 | FAIL (BUG-3) |

## Bug phát hiện

### BUG-1: Playlist HLS lỗi mạng một lần → kẹt mãi ở "Đang tải video…", không có lỗi/"Thử lại"
- Mức độ: Major (vi phạm AC5 "lỗi + Thử lại"; học sinh phải tải lại trang; mạng di động chập chờn lúc mở bài là tình huống thật)
- Bước tái hiện: đăng nhập `fw4-hs-own`, chặn request đầu tiên tới `**/videolab/cdn/**/*.m3u8` bằng `route.abort("connectionreset")` (các request sau cho qua), mở `/hoc/{course}/bai/{l1}`. Test: `QA5c`; biến thể `QA5b` (mọi request CDN lỗi 10 giây rồi có mạng lại).
- Mong đợi: tự thử lại và phát được, hoặc hiện "Không tải được video" + nút "Thử lại".
- Thực tế: lớp phủ "Đang tải video…" mãi (40 giây+), không có request CDN nào sau đó (đo `cdnRequestSauKhiCóMạng=0`), không có nút nào.
- Vị trí nghi ngờ: `frontend/apps/web/lib/learn/hlsEngine.ts:60-66`. Với lỗi NETWORK fatal ở tầng manifest (`manifestLoadError`), `instance.startLoad()` không nạp lại playlist (chưa có manifest nào) nên 3 lần "thử lại" đều vô nghĩa và `onFatal` không bao giờ được gọi. Gợi ý: với `details` là `manifestLoadError`/`manifestLoadTimeOut`/`levelLoadError` gọi lại `instance.loadSource(url)` (sau trễ ngắn, có trần), hết trần thì `onFatal()`. Thêm test engine cho nhánh này (hls.js giả).

### BUG-2: Rời trang cứng (đổi URL, F5/reload, đóng tab) không gửi heartbeat cuối
- Mức độ: Major (mất tối đa ~20 giây tiến độ và vị trí; xem tới ~85–90% rồi đóng tab thì bài không hoàn thành)
- Bước tái hiện: xoá tiến độ `fw4-hs-own`, mở bài 1, phát ~9 giây (chưa tới nhịp 20 giây), rồi (a) `page.goto("/")`, (b) `page.reload()`, (c) `page.close({runBeforeUnload:true})`; mở lại bài 1. Test: `QA4` phần "hard".
- Mong đợi: `resume_at_seconds` >= 7 (heartbeat cuối được gửi bằng keepalive).
- Thực tế: cả 3 cách `resume_at_seconds = 0`; nhật ký nginx không có POST `/heartbeat` nào lúc rời trang (chỉ thấy các GET playback của lần mở lại). Đổi bài bằng liên kết mềm thì ghi đúng (pos=8), nên chỉ lỗi ở đường pagehide/visibilitychange.
- Vị trí nghi ngờ: `frontend/apps/web/components/learn/VideoPlayer.tsx:240-244` (`onHide` chỉ flush khi `document.visibilityState === "hidden"`). Đo (QA4x): tại `pagehide` trạng thái vẫn là `visible`, còn `visibilitychange(hidden)` bắn sau `pagehide` ở cùng mili giây nên gần như không còn kịp gửi. Thêm vào đó `authFetch` (`packages/api-client/src/authFetch.ts:36`) có `await getCsrfToken` trước `fetch`. Gợi ý: `pagehide` flush không điều kiện; đường keepalive nên gọi `fetch` đồng bộ (CSRF token đã có sẵn trong bộ nhớ) hoặc dùng `navigator.sendBeacon`; chạy lại `QA4` để xác nhận.

### BUG-3: Nút phát lớn giữa khung bị thanh điều khiển che ở 375px: chạm vào không làm gì
- Mức độ: Major (nút phát nổi bật nhất trên màn hình điện thoại không dùng được; nút "Phát" nhỏ ở thanh dưới vẫn dùng được)
- Bước tái hiện: viewport 375x667, mở bài 1 video, đo `document.elementFromPoint` tại giữa/trên/dưới nút "Phát video" (64px) hoặc `tap()` vào nút. Test: `QA8`; ảnh `test-results/qa-375-l1.png` (đã xoá cùng thư mục tạm; chạy lại QA8 để tạo lại).
- Mong đợi: phần tử tại điểm chạm là chính nút. Thực tế: cả 3 điểm là `div.absolute.inset-x-0.bottom-0` (lớp thanh điều khiển có gradient), Playwright báo "intercepts pointer events", tap thất bại sau 120 giây.
- Vị trí nghi ngờ: `frontend/apps/web/components/learn/VideoPlayer.tsx:361-372` (nút lớn, đặt trước trong DOM) và `:374` (thanh điều khiển cao ~80px phủ lên khung cao ~211px). Gợi ý: thanh điều khiển `pointer-events-none` với các con `pointer-events-auto`, hoặc nút lớn có `z-10`, hoặc đẩy nút lên trên thanh.

### Ghi chú không phải bug / hành vi cần PO biết
1. Thu hồi ghi danh giữa phiên (QA1): heartbeat kế tiếp (~16 giây) trả 403, video dừng và gỡ khỏi trang, hiện "Quyền học khóa này đã bị thu hồi", ngừng heartbeat/playback hẳn. Màn học KHÔNG tự chuyển về `/khoa-hoc/{slug}` (chỉ lần mở bài mới 403 mới chuyển). Phù hợp lời đề xuất của dev ("báo thu hồi"), nhưng PO nên xác nhận có muốn chuyển trang trong trường hợp này không.
2. QA2 (TTL 2 phút, `VIDEO_PLAYBACK_TTL_MINUTES=2` đặt tạm trong `backend/.env`, đã khôi phục, `config:show` về 15): link được làm mới ở 93 giây và 183 giây (cách hạn ~30 giây), link mới chỉ được áp khi tạm dừng (1 lần đổi `src`, giữ đúng vị trí). Khựng tối đa 117ms, không có lỗi trang. Chưa thử được phần "link cũ hết hạn rồi CDN trả 403 giữa lúc phát": video nguồn chỉ 24 giây nên khi lặp trình phát dùng bộ đệm, không tải lại segment sau 120 giây (không có response CDN lỗi nào). Nhánh 403 đã được real #4 phủ bằng route giả.
3. QA3: hai tab cùng bài nhận 429 liên tiếp (6 lần) rồi tự gửi bù, trang không hỏng, không có hộp thoại hay lỗi. Heartbeat mỗi tab bị giới hạn 10 giây/lần (`HEARTBEAT_MIN_GAP_MS`) nên một tab không bao giờ tự gây 429; chỉ có 2 tab mới gây.
4. QA5a: offline 15 giây khi đang phát (bộ đệm đã đủ cả video 24 giây): phát tiếp bình thường, sau khi có mạng có heartbeat 200. Trường hợp hết bộ đệm giữa chừng chưa đo được vì video quá ngắn.
5. Dev mode gọi playback 2 lần ở lần mở đầu (StrictMode), đã biết; production 1 lần (không xác minh ở QA vì e2e chạy trên `next dev`).

## Không kiểm được
- Safari desktop/iPhone thật: nhánh HLS gốc (`nativeEngine`), `webkitEnterFullscreen`, thanh tua bằng ngón tay thật. Chỉ có unit test + giả lập mobile của Chromium (375px, `hasTouch`).
- Bunny thật (chữ ký token, CORS/Referer, `bcdn_token` với sub-playlist), `VIDEO_BIND_IP` bật/tắt (mặc định bật, e2e chạy cùng IP nên không phát hiện lệch).
- Toàn màn hình thật (chỉ kiểm nút hiện).
- Tab nền nhiều giờ (spam playback), mạng chập chờn khi bộ đệm cạn.
- E2E chạy trên `next dev`, không chạy trên bản build production (chỉ build để kiểm biên dịch).

## Rủi ro & đề xuất
- Sửa BUG-1/2/3 (Dev), rồi chạy lại `QA4`, `QA5b`, `QA5c`, `QA8` (các ca này hiện FAIL có chủ đích và sẽ PASS khi sửa xong).
- BUG-1 và BUG-2 nên có thêm unit test (engine giả lập lỗi manifest; handler `pagehide` với `visibilityState: "visible"`).
- Trước khi đưa lên staging: thử Bunny staging và một iPhone thật cho nhánh HLS gốc.

## Cách chạy lại
```
frontend/apps/web/e2e/seed-e2e-learn.sh        # in course=.. l1=.. l2=.. l3=.. l4=..
# tiến trình phụ trên host (thu hồi ghi danh/xoá tiến độ qua tinker), nghe test-results/qa-signal/*.req
# E2E_FW4="course=.. l1=.. ..." E2E_REAL_BACKEND=1 playwright test --workers=1 e2e/hoc-video-qa.spec.ts
# QA2 (TTL): thêm VIDEO_PLAYBACK_TTL_MINUTES=2 vào backend/.env + E2E_QA_TTL=1, xong gỡ dòng đó
frontend/apps/web/e2e/seed-e2e-learn.sh --clean
```
Spec QA: `frontend/apps/web/e2e/hoc-video-qa.spec.ts` (cần tiến trình phụ trên host làm việc DB vì container Playwright không có docker; tiến trình đó là script tạm đã xoá khỏi repo, nằm ngoài thư mục dự án).

## Sửa sau QA (Dev, 2026-10-07)
- **BUG-1** `lib/learn/hlsEngine.ts`: lỗi fatal tải playlist (`manifestLoadError`/`manifestLoadTimeOut`/`levelLoadError`/`levelLoadTimeOut`) không còn gọi `startLoad()` (vô nghĩa khi chưa có manifest). Nay nạp lại bằng `loadSource(url)` với backoff 1s/2s/4s/8s (tối đa 4 lần, không dựng lại instance); hết lượt gọi `onFatal` → "Không tải được video, vui lòng thử lại" + nút Thử lại. Bộ đếm về 0 khi nạp được đoạn đầu; `destroy` huỷ hẹn giờ. Test: `lib/learn/hlsEngine.test.ts` (2 ca, timer giả).
- **BUG-2** `components/learn/VideoPlayer.tsx`: `pagehide` flush vô điều kiện (`force` + `keepalive`), `visibilitychange(hidden)` vẫn flush. CSRF token được lấy sẵn (`warmCsrf()` trong `lib/learn/api.ts`, cache trong api-client) ngay khi mở bài có `can_track`, nên `authFetch` POST `keepalive` đi được trong sự kiện đóng trang mà không phải `await` request mới. Không dùng `sendBeacon` (không đặt được header `X-CSRF-TOKEN`/`X-Device-Id` theo contract) và không sửa backend. Test: `VideoPlayer.test.tsx` (`pagehide` khi `visibilityState` còn "visible"; preview không gửi).
- **BUG-3** thanh điều khiển `pointer-events-none`, thanh tua và hàng nút `pointer-events-auto`: nút phát lớn ở giữa khung nhận được chạm (kiểm `elementFromPoint` ở QA8); bấm vào vùng gradient trống chuyển xuống video (bật/tắt phát).
- **Thêm:** heartbeat/`complete`/playback 403 `COURSE_NOT_OWNED` có `errors.course` → thông báo "Quyền học khóa này đã bị thu hồi" kèm nút "Xem khóa học" tới `/khoa-hoc/{slug}`; không tự chuyển trang; không có `errors.course` thì chỉ thông báo. Test `LessonScreen.test.tsx`, `VideoPlayer.test.tsx`.
- Sửa kiểu TypeScript trong `e2e/hoc-video-qa.spec.ts` (`noUncheckedIndexedAccess`; không đổi nội dung kiểm thử). Spec QA không có `test.fail`; chỉ còn `test.skip` theo biến môi trường (thiếu backend thật/E2E_FW4, ca TTL cần E2E_QA_TTL=1).
- Bổ sung: `beforeunload` cũng flush (đóng tab bằng `close({runBeforeUnload})`), và `pagehide`/`beforeunload`/`visibilitychange` bắn liền nhau chỉ gửi MỘT heartbeat (cửa sổ 2 giây) để không đốt throttle 6/phút/bài. Nút phát lớn đặt cao hơn thanh điều khiển (`top-[calc((100%-5.75rem)/2)]`) để không chồng lên thanh tua. Kịch bản QA4 hard chạy "dong-tab" ĐẦU vì trước đó nó nhận 429 do 3 ca liên tiếp trong một phút (lỗi kịch bản).
- Kết quả sau sửa: `tsc` sạch, `eslint` sạch, unit web 297/297 (33 file); e2e `hoc-video-real` (10) + `hoc-video-qa` (14 chạy, QA2 TTL bỏ qua theo biến môi trường) = 24 pass, `--workers=1`, seed trước, `seed-e2e-learn.sh --clean` sau. Tiến trình phụ cho các tín hiệu `revoke/restore/reset` được dựng lại ngoài repo (scratchpad).

# SECURITY: T37 (US-021 Kết nối Bunny Stream) | Audit 2026-10-07
**Kết luận:** PASS có điều kiện (kiểm lại 2026-10-07 sau khi dev sửa: S1, S2(b), S4, S7, S8, S9 đã đóng; còn mở S3, S2(a), S5, S6, xem mục "Kiểm lại" ở cuối)

**Critical/High:** không có trong code. Có một điều kiện go-live mà nếu bỏ sót sẽ thành mức Critical: cấu hình bảo mật ở trang quản trị Bunny (S3). Lý do là guid của video trả phí đang nằm trong URL phát của mọi học sinh, còn guid của bài preview thì công khai. Nếu Bunny chưa bật Token Authentication hoặc Embed View Token Authentication, ai có guid cũng xem được video mãi mãi. Hiện tại chỉ có checklist kiểm tay, chưa có cơ chế kiểm tự động.

**Điều kiện để chuyển `VIDEO_PROVIDER=bunny` ở production:**
1. Sửa S1 (webhook ẩn danh kích hoạt gọi API Bunny) và S4 (`withoutRedirecting`).
2. Hoàn tất S3 (cấu hình Bunny và kiểm 403 thật), cùng mục "PHẢI ĐỐI CHIẾU" trong `docs/tech/US-021.md` (review R1).
3. PO quyết S2 (hạn mức lách được).

**Phạm vi:** `BunnyStreamProvider`, `Bunny/BunnySigner`, `VideoProviderManager`, `VideoUploadService`, `VideoWebhookService` + route `/webhooks/video/{provider}`, `VideoAssetSyncService`, `OrphanVideoPruner`, `PlaybackService`/`PlaybackController`/`LessonAccessService` (T13), `ProductionConfigGuard::guardBunny`, `config/video.php`, khối `BUNNY_*` trong `backend/.env.example` và `infra/production/.env.production.example`, `docs/ops/production-checklist.md` §2.1, `tests/Feature/T37/*`. Phương pháp: phân tích tĩnh, có chạy test.

## Điểm đạt (không cần sửa)
- **Secret không lộ:** thông điệp exception do ta tự viết. Ngoại lệ HTTP gốc bị nuốt (`BunnyStreamProvider.php:174-179`). Log 401 chỉ nêu tên biến. Guard và `assertConfigured` chỉ in tên biến. Response upload chỉ trả `AuthorizationSignature` (SHA-256 có trộn khoá, không suy ngược ra khoá được), `AuthorizationExpire`, `VideoId`, `LibraryId`. Queue job (`SyncVideoAssetStatusJob`, `ShouldBeUnique`) chỉ serialize model, không có khoá. `zend.exception_ignore_args = On` (`infra/php/conf.d/zz-vitaminvui.ini:7`) nên stack trace không chứa tham số. Không có Telescope, Debugbar hay hook log HTTP client.
- **Injection vào URL API:** guid được kiểm regex UUID có cờ `D` ở mọi điểm vào (`:39`, `:115`, `:146`, `:161`, `:246`). `libraryId` được `rawurlencode`.
- **Webhook không tin payload:** chỉ đọc `VideoGuid`, tra asset theo cặp (`provider`, `provider_video_id`) rồi luôn gọi `getVideo()`. Guid lạ trả 204 và không gọi mạng. Asset của provider khác không bị chạm tới. Trạng thái chỉ tiến về phía trước, `ready`/`failed` là trạng thái cuối.
- **IDOR:** upload dùng `scopeBindings` + `manageContent` theo khoá. `provider*`, `lesson_id`, `created_by` chỉ được đặt ở service. Chữ ký TUS ràng `VideoId` do server tạo, nên không ghi được vào video của khoá khác. Playback lấy asset từ `lesson->videoAsset`, và người gọi phải qua `assertCanWatch`, `isPublicPreview` hoặc `Gate::authorize('watch')`.
- **Token phát:** TTL 15 phút, ràng IP cho bài trả phí (`PlaybackService.php:40`), phạm vi thư mục `/{guid}/`. Response có `Cache-Control: no-store, private`.
- **Guard production:** bắt buộc đủ 4 biến. `api_base`/`tus_endpoint` phải https. CDN host phải là tên miền (không IP, cổng, đường dẫn), không trùng host app/web/admin/api/admin-api và không nằm dưới `SESSION_DOMAIN`. Kết hợp cookie `__Host-`, cookie phiên không thể bị gửi sang CDN.
- **Xoá video:** pruner gọi Bunny xoá trước, rồi mới xoá dòng. Lỗi 5xx thì giữ dòng để lần sau thử lại.
- Worker video (`.env.worker-video.example`) không có `BUNNY_*`. Đây là đặc quyền tối thiểu, nên giữ nguyên.

## Phát hiện

### S1 [Medium] Webhook ẩn danh kích hoạt gọi API Bunny đồng bộ, kể cả với asset đã ở trạng thái cuối (OWASP A04)
- Vị trí: `backend/app/Services/Video/VideoWebhookService.php:45-49`, `backend/app/Services/Video/VideoAssetSyncService.php:26-29` (gọi `getVideo` trước khi xét `isFinal` ở `:83`), route `backend/routes/api.php:219-223`, limiter `backend/app/Providers/AppServiceProvider.php:245` (120/phút/IP).
- Mô tả và tác động: Bunny không ký webhook, nên ai cũng POST được. Kẻ tấn công dễ có guid hợp lệ: gọi `GET /api/v1/preview/lessons/{id}/playback` (công khai, không đăng nhập) là thấy guid trong URL HLS. Học sinh cũng thấy guid của mọi bài mình học. Mỗi POST `{"VideoGuid": "<guid đó>"}` sinh ra một lời gọi `GET` tới Bunny có `AccessKey`. Lời gọi chạy đồng bộ trong PHP-FPM với timeout 10 giây, và vẫn diễn ra khi asset đã `ready`. Với 120 request/phút/IP nhân nhiều IP, hậu quả có thể là:
  - (a) cạn rate limit API của thư viện Bunny, làm `createVideo` của giáo viên lỗi 503;
  - (b) chiếm worker FPM khi Bunny chậm;
  - (c) spam log 401/5xx.

  Kẻ tấn công không đổi được trạng thái sai (trạng thái luôn lấy từ API) và không enumerate được asset (luôn trả 204).
- Cách sửa (chọn cả 1 và 2, cân nhắc thêm 3):
  1. Bỏ qua asset đã ở trạng thái cuối trước khi gọi nhà cung cấp, và gom các webhook trùng của cùng asset:
     ```php
     // VideoWebhookService::handle
     $asset = VideoAsset::query()->where('provider', $provider->name())->where('provider_video_id', $videoId)->first();
     if ($asset !== null && ! in_array($asset->status, [VideoAssetStatus::Ready, VideoAssetStatus::Failed], true)
         && Cache::add('video-webhook:'.$asset->getKey(), 1, 30)) {
         $this->sync->sync($asset);
     }
     ```
     (hoặc `SyncVideoAssetStatusJob::dispatch($asset)`: job đã `ShouldBeUnique` và chạy ngoài request web).
  2. Thêm bí mật trên URL webhook, vì Bunny cho phép đặt URL tuỳ ý: `.../webhooks/video/bunny?k=<BUNNY_WEBHOOK_TOKEN>`. So bằng `hash_equals`, sai thì trả 404. Guard bắt buộc biến này ≥ 32 ký tự khi bunny bật, và đưa nó vào danh sách biến nhạy cảm.
  3. Kiểm lại tài liệu Bunny hiện hành. Nếu Bunny Stream đã hỗ trợ ký webhook thì bật lên và xác minh HMAC hằng thời gian.
- Cách kiểm chứng: test gửi webhook cho asset `ready` thì `Http::assertNothingSent()`; gửi 2 webhook liên tiếp cho asset `processing` thì chỉ có 1 lời gọi; thiếu hoặc sai `k` thì 404 và không gọi mạng.

### S2 [Medium] Hạn mức 2 GB/video và 20 GB/ngày lách được khi upload thẳng lên Bunny (OWASP A04)
- Vị trí: `backend/app/Services/Video/Providers/BunnyStreamProvider.php:79-90` (bỏ qua `$maxBytes`), `:92-111` (chữ ký TUS không ràng kích thước), `backend/app/Services/Video/VideoUploadService.php:157-166` (hạn mức tính trên các dòng `video_assets` còn tồn tại), `backend/app/Services/Video/OrphanVideoPruner.php:74` (xoá cứng; `VideoAsset` không dùng `SoftDeletes`).
- Mô tả và tác động:
  - (a) Kích thước chỉ là số do client khai (`size`). Bunny không ép `Upload-Length` theo chữ ký, nên khai 1 MB vẫn tải được tệp hàng chục GB. Story đã ghi đây là rủi ro chấp nhận (US-021, dòng 59).
  - (b) **Điểm mới:** tải video khác đè lên bài thì asset cũ thành mồ côi. Khoảng 10 phút sau (pruner chạy hằng giờ), dòng asset bị xoá cứng và phần hạn mức đã dùng trong ngày được "hoàn lại". Một tài khoản staff hoặc GV (kể cả tài khoản bị chiếm) có thể lặp vòng upload/đè để vượt 20 GB/ngày nhiều lần.

  Tác động là chi phí lưu trữ và mã hoá ở Bunny, không lộ dữ liệu. Chỉ người có `manageContent` mới làm được, và endpoint đã có throttle 20/phút.
- Cách sửa:
  - (b) Ghi mức dùng vào một sổ riêng không bị pruner xoá (bảng `video_upload_usages(user_id, date, bytes)`, cộng dồn trong pha 1 dưới cùng khoá), hoặc để pruner chỉ xoá dòng có `created_at` < đầu ngày.
  - (a) Khi sync sang `ready`, đọc `storageSize` (hoặc trường kích thước gốc nếu Bunny có) từ `getVideo`. Nếu vượt `declared_size_bytes` × hệ số mà PO chốt, đánh `failed`, xoá ở Bunny và ghi audit log. Thêm cảnh báo dung lượng và chi phí trên Bunny (checklist §2.1 đã có mục cảnh báo).
- Cách kiểm chứng: test tải 2 video đè lên nhau, chạy `videos:prune-orphans`, rồi xin upload mới vượt phần còn lại trong ngày thì phải nhận 422 `VIDEO_QUOTA_EXCEEDED`.

### S3 [Medium, cổng go-live; thành Critical nếu bỏ sót] Kiểm soát truy cập video phụ thuộc hoàn toàn vào cấu hình ở trang quản trị Bunny, chưa được kiểm tự động (OWASP A05/A01)
- Vị trí: `docs/ops/production-checklist.md` §2.1, dòng 106 và 111.
- Mô tả và tác động: app chỉ ký token. Việc chặn URL không có token là do Bunny làm. Guid không phải bí mật, vì nó có trong mọi URL phát. Bunny có 2 cơ chế độc lập: **CDN Token Authentication** (cho `https://{cdn}/{guid}/...`) và **Embed View Token Authentication** (cho `iframe.mediadelivery.net/embed/{libraryId}/{guid}`). Checklist đã ghi bật Token Authentication và tắt Direct Play/embed công khai, nhưng chưa ghi rõ tên Embed View Token Authentication và chưa có bước thử URL iframe. `LibraryId` cũng không phải bí mật: nó được trả cho trình duyệt staff và là số ngắn. Chỉ cần sót một công tắc là toàn bộ video trả phí xem được vĩnh viễn, không cần mua khoá học.
- Cách sửa:
  - Bổ sung checklist §2.1: bật cả hai cơ chế token, tắt "Keep original files" hoặc xác nhận tệp gốc không truy cập được qua CDN, và kiểm tay thêm `https://iframe.mediadelivery.net/embed/<LIB>/<GUID>`, `https://<cdn>/<GUID>/playlist.m3u8`, `/<GUID>/thumbnail.jpg`, `/<GUID>/play_720p.mp4` khi không có token: tất cả phải 403.
  - Nên có thêm lệnh `php artisan videos:bunny-selfcheck` chạy sau mỗi deploy. Lệnh lấy một asset `ready`, gọi URL playlist không token và URL embed, và báo lỗi nếu nhận khác 403.
  - Allowed Referrers đang chỉ gồm `FRONTEND_URL`, như vậy sẽ chặn chức năng xem thử ở admin (`/admin/.../playback`). Cần thêm host của `ADMIN_URL`, đừng bỏ hẳn Referrer.
- Cách kiểm chứng: biên bản QA staging (AC16) ghi đủ các URL trên kèm mã 403.

### S4 [Low, sửa trước go-live] Request mang `AccessKey` vẫn theo redirect, kể cả từ https xuống http (OWASP A02/A10)
- Vị trí: `backend/app/Services/Video/Providers/BunnyStreamProvider.php:169-172`.
- Mô tả: Guzzle mặc định theo tối đa 5 redirect và cho phép giao thức `http`. Khi redirect sang host khác, Guzzle chỉ gỡ `Authorization`/`Cookie`, không gỡ header tuỳ biến `AccessKey`. Nếu API Bunny (hoặc `BUNNY_API_BASE` cấu hình nhầm) trả 3xx, khoá API sẽ đi sang host khác, có thể ở dạng rõ qua http. Khả năng xảy ra thấp, nhưng nếu xảy ra thì lộ khoá toàn thư viện. Review R4 xếp mức NIT; tôi nâng lên Low vì có đường hạ xuống http. MoMo đã làm đúng (`MoMoGateway.php:326`).
- Cách sửa: `->connectTimeout(5)->withoutRedirecting()`. Coi 3xx là lỗi (`VideoProviderException('Bunny trả HTTP 3xx.')`). Có thể ghim host `api_base` và `tus_endpoint` vào `video.bunnycdn.com` trong guard (Info).
- Cách kiểm chứng: `Http::fake` trả 302 kèm `Location: http://evil.test` thì không có request thứ hai và ném `VideoProviderException`.

### S5 [Low] Chữ ký TUS 6 giờ dùng lại được sau khi bị gỡ quyền hoặc sau khi video đã `ready`
- Vị trí: `BunnyStreamProvider.php:36,96`, `config/video.php:34`.
- Mô tả: chữ ký chỉ ràng `VideoId` và thời hạn. GV bị gỡ khỏi khoá, hoặc người lấy được bộ header (từ log của proxy hay DevTools), vẫn dùng được trong 6 giờ. Nếu Bunny cho upload lại vào video đã có, nội dung bài có thể bị thay mà không có audit log. Trạng thái `ready` là cuối nên app sẽ không biết. Phạm vi chỉ gói gọn trong video vừa tạo, không chạm được video khác.
- Cách sửa: kiểm trên staging xem Bunny có từ chối TUS mới vào video đã ở status ≥ 1 không. Nếu không từ chối, cân nhắc TTL theo kích thước khai báo (ví dụ `max(30 phút, size / 1 MB/s)`, trần 6 giờ), và ghi rõ trong ADR-002 rằng không thu hồi được chữ ký đã cấp ngoài cách xoay `BUNNY_API_KEY`.

### S6 [Low] Ràng IP dễ hỏng ngoài thực tế dẫn tới áp lực tắt `VIDEO_BIND_IP`
- Vị trí: `BunnySigner.php:34`, `PlaybackService.php:40-41`.
- Mô tả:
  - (a) Dual-stack: trình duyệt gọi `api.` qua IPv4 nhưng tới CDN qua IPv6 (hoặc ngược lại), hoặc dạng IPv6 không chuẩn hoá (review R1), thì học sinh gặp 403.
  - (b) Frontend player chưa được dựng lại (`apps/web` chưa có code gọi `/learn/.../playback`). Nếu FE gọi playback từ SSR hoặc Route Handler, IP ký sẽ là IP của server Next.
  - (c) Token 15 phút áp cho mọi segment (review R2).

  Nếu vận hành tắt `VIDEO_BIND_IP` để chữa cháy, link trả phí chia sẻ được trong 15 phút với bất kỳ ai.
- Cách sửa: chuẩn hoá `inet_ntop(inet_pton($ip))`. Với IPv6 có thể ràng theo /64 nếu Bunny hỗ trợ, nếu không thì bỏ ràng riêng IPv6 và log lại. Giao FE: gọi playback từ trình duyệt (Client Component, có cookie Sanctum) và làm mới URL trước `expires_at`. QA thử dual-stack trên staging.

### S7 [Low] `VIDEO_PLAYBACK_TTL_MINUTES` không có trần
- Vị trí: `backend/config/video.php:37`.
- Mô tả: TTL upload đã bị kẹp `min(360, …)`, còn TTL phát thì không. Đặt nhầm 1440 thì link phát chia sẻ được trong 24 giờ.
- Cách sửa: `'playback_ttl_minutes' => max(1, min(60, (int) env(...)))`, thêm guard production báo lỗi nếu > 60.

### S8 [Low] Video có thể nằm lại ở Bunny mà DB không còn dấu vết (xoá dữ liệu)
- Vị trí: `BunnyStreamProvider.php:144-155,194-197` (luôn dùng `BUNNY_LIBRARY_ID` hiện hành), `OrphanVideoPruner.php:66-74`.
- Mô tả: sau khi đổi hoặc xoay thư viện (review R3), `deleteVideo` gọi nhầm thư viện, nhận 404 và coi như xong, rồi pruner xoá dòng. Video thật (có thể chứa hình ảnh hoặc giọng nói của GV/HS) nằm lại ở thư viện cũ, không còn bản ghi nào để truy ra. Điều này ảnh hưởng nghĩa vụ xoá dữ liệu.
- Cách sửa: khi `asset->provider_library_id !== libraryId()` thì không gọi xoá, giữ dòng, log warning (kèm asset_id). Hoặc mở rộng contract để truyền asset vào. Checklist vận hành ghi "không đổi `BUNNY_LIBRARY_ID` khi còn video".

### S9 [Info] Phòng thủ chiều sâu cho secret
- Gắn `#[\SensitiveParameter]` cho `$apiKey` (`BunnySigner.php:26`) và `$tokenKey` (`:32`, `:40`). Hiện đã an toàn nhờ `exception_ignore_args`, nhưng image PHP dựng kiểu khác sẽ mất lớp bảo vệ này.
- `php artisan config:show video` và `tinker` in khoá ở dạng rõ. Chỉ người có shell production mới chạy được; hãy nêu điều này trong quy trình cấp quyền SSH.

### S10 [Info, PO quyết] Không có DRM
Người có quyền xem vẫn tải được toàn bộ HLS (ffmpeg) trong 15 phút từ đúng IP của mình. Referrer giả được. Nếu cần chống tải lậu mạnh hơn thì xem MediaCage DRM của Bunny (có chi phí).

## Đánh giá rủi ro công thức chưa xác nhận với Bunny thật
- **TUS sai:** upload hỏng (401 ở Bunny), không lộ gì, lỗi hiện ra ngay khi thử.
- **Directory token sai:** mọi lượt phát lỗi 403, đây là lỗi "đóng an toàn".
- **Rủi ro thật nằm ở cấu hình chứ không ở công thức:** nếu Token Authentication chưa bật thì URL vẫn phát được dù token sai. Một bài test chỉ kiểm "URL hợp lệ phát được" sẽ PASS mà không phát hiện ra. Vì vậy AC16 bắt buộc kiểm cả chiều phủ định (không token, hết hạn, sai IP, URL embed đều 403) trước khi coi công thức là đúng (xem S3).
- Vector test trong `BunnySignerTest` do ta tự tính, chỉ chống thay đổi ngoài ý muốn, không chứng minh công thức khớp với Bunny.

## Kết quả công cụ
- `pest -c phpunit.local-e.xml tests/Feature/T37`: **97 passed (318 assertions)**, 22,5 giây (tải máy 2,68).
- `composer audit`: No security vulnerability advisories found.
- `npm audit`: không chạy (T37 không đổi dependency frontend).

## Test `laravel-qa` nên thêm
1. Webhook với asset `ready`/`failed` thì không gọi Bunny; webhook lặp cho cùng asset trong 30 giây chỉ gọi 1 lần; thiếu hoặc sai token URL thì 404 (S1).
2. Upload, đè, prune, upload lại: hạn mức ngày không được hoàn lại (S2).
3. `Http::fake` trả 302 sang host khác hoặc http: không có request thứ hai, ném `VideoProviderException` (S4).
4. `VIDEO_PLAYBACK_TTL_MINUTES=1440` thì `expires_at` ≤ 60 phút, hoặc guard báo lỗi (S7).
5. Asset có `provider_library_id` khác thư viện hiện hành: pruner không xoá dòng (S8).
6. Staging thật (AC16): URL CDN/embed/thumbnail/mp4 không token đều 403, sai IP 403, dual-stack, URL hết hạn giữa bài, TUS vào video đã `ready` (S3, S5, S6).

## Chuyển `laravel-dev`
S1, S2(b), S4, S7, S8 (sửa code); S9 (tuỳ chọn). Thêm vào checklist §2.1: Embed View Token Authentication, tắt Keep original files, thêm `ADMIN_URL` vào Allowed Referrers, các URL kiểm 403 (S3). Giao FE: gọi playback từ trình duyệt và làm mới URL trước khi hết hạn (S6).

## Điểm cần pháp chế / PO quyết
- **Chuyển dữ liệu ra nước ngoài:** video bài giảng (hình ảnh, giọng nói của GV, có thể có HS) lưu ở Bunny (BunnyWay d.o.o., EU) và phát qua CDN toàn cầu. IP học sinh đi tới các edge của Bunny. Cần xác định nghĩa vụ hồ sơ chuyển dữ liệu xuyên biên giới và hợp đồng xử lý dữ liệu (DPA) với Bunny theo Luật Bảo vệ dữ liệu cá nhân 2025 và Nghị định 356/2025/NĐ-CP. Cần bộ phận pháp chế xác nhận.
- **Thời hạn lưu và xoá:** cần làm rõ Bunny có giữ bản sao hoặc backup sau `DELETE` không, và bao lâu. Liên quan S8. Cần pháp chế xác nhận.
- **Thu thập tối thiểu:** title gửi Bunny là `lesson-{id}`, không có PII. Đạt.
- **PO quyết:** chấp nhận S2(a) (kích thước thật không bị ép, chỉ dựa trên khai báo) hay làm đối chiếu `storageSize`; có cần DRM không (S10); ngưỡng cảnh báo chi phí Bunny (A4).

## Kiểm lại (2026-10-07, sau khi dev sửa)
**Kết luận sau kiểm lại:** PASS có điều kiện. Không còn phát hiện Medium nào trong code. Điều kiện còn lại đều nằm ngoài code:
- S3: cấu hình Bunny và kiểm 403 thật trên staging (AC16).
- S2(a): PO quyết chấp nhận rủi ro kích thước thật không bị ép theo khai báo.
- S5, S6: kiểm trên staging.

Test đã chạy: `pest tests/Feature/T37 tests/Feature/T11`, **160 passed (688 assertion)**, gồm `BunnyHardeningTest`.

| Mục | Trạng thái | Ghi chú kiểm lại |
|---|---|---|
| S1 webhook | Đóng | Xem chi tiết bên dưới |
| S2(b) sổ hạn mức | Đóng | Xem chi tiết bên dưới |
| S2(a) kích thước thật | Mở (PO) | Đã có trong backlog-v2 |
| S3 cấu hình Bunny | Mở (go-live) | Checklist §2.1 đã cập nhật; còn phải kiểm thật trên staging |
| S4 redirect | Đóng | `withoutRedirecting()` (`BunnyStreamProvider.php:183`); 3xx thành `VideoProviderException` (`:192-194`), nên `deleteVideo` không còn coi 3xx là "xoá xong". Có test 302 sang http host lạ |
| S5 TUS 6 giờ | Mở (staging) | Chưa đổi; kiểm upload đè vào video đã `ready` |
| S6 ràng IP | Mở (FE/staging) | Chưa đổi; FE gọi playback từ trình duyệt |
| S7 TTL phát | Đóng | `config/video.php:38` kẹp 1–60 phút |
| S8 thư viện khác | Đóng | Pruner giữ dòng và log warning (không in khoá) khi `provider_library_id` khác thư viện hiện hành. Lệnh chạy hằng giờ sẽ lặp warning cho tới khi vận hành xử lý tay. Chấp nhận được |
| S9 SensitiveParameter | Đóng | `BunnySigner.php:26,32,40` |

### S1: chi tiết kiểm lại
- **So khớp token:** `BunnyStreamProvider::verifyWebhookRequest` (`:159-165`) dùng `hash_equals`. Token chưa cấu hình, token rỗng, hoặc `k` ở dạng mảng đều bị từ chối (đóng an toàn). Bước kiểm nằm trước `parseWebhook`/truy vấn DB (`VideoWebhookService.php:43-45`), nên sai token thì trả 404 và không truy vấn, không gọi mạng. Throttle `webhook` (120/phút/IP) chạy trước bước kiểm. Token ≥ 32 ký tự từ `openssl rand -hex 32` (128 bit trở lên) nên không dò được bằng brute force. Guard bắt buộc ≥ 32 ký tự (`ProductionConfigGuard.php:537-543`) và đã thêm vào danh sách biến nhạy cảm.
- **Token trong query string bị ghi vào log Nginx.** Log mặc định `combined` ghi `$request` kèm `?k=` vào access log của host api, và `infra/production/nginx/conf.d/vitaminvui.conf` chưa có `location` riêng cho webhook. Checklist §2.1 dòng 108 đã ghi rủi ro này và quy trình xoay token. Phía Laravel không ghi URL đầy đủ vào log (không có `fullUrl()`/`REQUEST_URI` trong `app/` và `bootstrap/`).
  - Đề xuất **[Low, R-1]**: thêm vào mẫu Nginx một khối riêng ghi log theo `$uri`, không kèm query:
    ```nginx
    # http {}: log_format vv_noargs '$remote_addr - [$time_local] "$request_method $uri" $status $body_bytes_sent';
    location = /api/v1/webhooks/video/bunny {
        access_log /var/log/nginx/vv-webhook.access.log vv_noargs;
        # ... include cấu hình fastcgi giống location ^~ /api/v1/
    }
    ```
    Mức độ: người đọc được log Nginx thường đã có quyền cao hơn. Lộ token chỉ cho phép gọi webhook, và webhook vẫn không đổi được trạng thái sai (trạng thái luôn lấy từ API). Không chặn go-live.
- **Cửa sổ gom 10 giây** (`VideoWebhookService.php:20,62`). Với kẻ tấn công không có token, việc gom trùng không còn tác dụng (đã bị chặn từ bước token). Với kẻ có token (lộ từ log), mỗi asset chưa xong chỉ bị gọi Bunny tối đa 1 lần/10 giây, asset đã xong thì không bị gọi. Như vậy đủ chặn việc làm cạn rate limit API. Nhược điểm là về chức năng, không phải bảo mật: nếu Bunny gửi `Finished` trong vòng 10 giây sau webhook trước, webhook đó bị bỏ (204, Bunny không gửi lại), và asset chờ `videos:check-stuck` (khoảng 15 phút).
  - Đề xuất **[Low, R-2]**: khi `Cache::add` trả false thì `SyncVideoAssetStatusJob::dispatch($asset)->delay(now()->addSeconds(15))`. Job đã `ShouldBeUnique`, nên đường webhook không bao giờ mất trạng thái cuối, và lời gọi chạy ngoài FPM.
- **Race và nhả khoá.** `Cache::add` trên Redis là thao tác nguyên tử (NX + TTL), production đặt `CACHE_STORE=redis`. Khoá chỉ được nhả khi `VideoProviderException` (`:65-68`). Lỗi khác (ví dụ `QueryException` trong `apply`) giữ khoá tối đa 10 giây rồi tự hết hạn, không bị kẹt lâu. Chấp nhận được (Info). Hai webhook đồng thời không ghi đè sai nhau vì `apply()` khoá dòng và chỉ cho trạng thái tiến về phía trước.

### S2(b): chi tiết kiểm lại
- **Migration** `2026_10_19_100000_create_video_upload_usages_table.php` an toàn: chỉ tạo bảng mới, không sửa hay khoá bảng đang có. PK `(user_id, usage_date)`, FK `cascadeOnDelete`, `down()` gỡ được. Bảng chỉ chứa `user_id`, ngày và số byte, không có PII khác. Info: bảng tăng dần theo thời gian, có thể dọn các dòng cũ hơn 30 ngày.
- **Có lách được sổ không?** Không, với các luồng đã thử:
  - Sổ được đọc và ghi trong pha 1, dưới `lockForUpdate` hàng `users` của người tạo (`VideoUploadService.php` `enforceDailyQuota`), nên các upload song song của cùng người bị tuần tự hoá. Upsert nằm trong cùng transaction, nếu pha 1 rollback thì sổ cũng rollback.
  - `used = max(sổ, tổng asset)`: pruner xoá asset không làm giảm `used`. Có test `S2b: upload, de, prune, upload lai`.
  - Hoàn hạn mức (`refundQuota`) chỉ chạy trong `abandon()`, tức khi lỗi phía nhà cung cấp ở pha 2 hoặc lỗi gắn bài ở pha 3. Cả hai trường hợp đều ném exception trước khi trả chữ ký TUS cho client, nên người dùng không có chữ ký để tải lên. Người dùng có thể tự gây lỗi ở pha 3 bằng cách xoá bài đồng thời, nhưng cũng chỉ nhận lại hạn mức của phiên không có chữ ký. Không lợi dụng được.
  - Truy vấn hoàn dùng `DB::raw('GREATEST(bytes - '.(int) ...)')`. Giá trị được ép `int` từ cột DB, không có injection.
  - Còn mở, theo thiết kế: hạn mức tính theo tài khoản (nhiều tài khoản staff thì nhiều hạn mức), và S2(a) (kích thước khai ≠ kích thước thật).
- Info: ngày hoàn lấy từ `asset->created_at`, trong khi ngày ghi sổ lấy từ `now()`. Nếu phiên vắt qua nửa đêm thì có thể hoàn nhầm ngày. `GREATEST(..., 0)` giữ cho số không âm, và sai lệch chỉ làm hạn mức chặt hơn. Không cần sửa.

### Test `laravel-qa` nên thêm sau kiểm lại
- Webhook hợp lệ thứ hai trong 10 giây với trạng thái `Finished`: asset phải `ready` trong thời gian hợp lý (sau R-2), hoặc ghi nhận độ trễ hiện tại nếu chưa làm R-2.
- Khi `apply()` ném lỗi không phải `VideoProviderException`: khoá hết hạn sau 10 giây và webhook tiếp theo được xử lý.
- Hai request upload song song của cùng người ở sát ngưỡng 20 GB: chỉ một request thành công.
- Staging: access log host api không chứa `k=` (sau R-1).

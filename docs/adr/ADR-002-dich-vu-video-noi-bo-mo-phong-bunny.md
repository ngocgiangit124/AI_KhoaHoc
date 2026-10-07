# ADR-002: Dịch vụ video nội bộ "VideoLab" mô phỏng Bunny Stream, sau lớp trừu tượng `VideoProvider`

**Trạng thái:** Accepted (đã sửa theo Security S3, S13) · **Ngày:** 2026-09-25
**Story:** US-006 (BR2, BR5), US-009 (BR10, AC11), US-003 (preview), US-014 · **Liên quan:** [api-contract.md](../architecture/api-contract.md) §2.4, §2.5, §2.7

## Bối cảnh

- Dài hạn dùng dịch vụ streaming chuyên dụng (Bunny Stream / Cloudflare Stream). Giai đoạn dev: tự xây hệ thống Laravel nội bộ **mô phỏng Bunny** (upload, xử lý, signed URL có kiểm soát quyền), sau đó **đổi sang Bunny thật mà không sửa nghiệp vụ**.
- Bài học có 2 nguồn video: tải lên hệ thống, hoặc dán link ngoài (US-009 BR10).
- Tự đánh dấu hoàn thành khi xem ≥ 90% (US-006 BR2); gian lận tua bằng dev tool là hạn chế chấp nhận được.
- Học sinh chỉ có 1 phiên; bị thu hồi quyền phải bị chặn ở lần gọi API tiếp theo (US-006 AC7, edge case).
- Frontend là Next.js: player chạy trong trình duyệt.

Mô hình Bunny Stream cần mô phỏng: API quản lý video (`AccessKey` header, `POST /library/{id}/videos` → `guid`, `GET` trạng thái số 0–6 và `length`), upload resumable TUS có chữ ký trước (`AuthorizationSignature = sha256(library_id + api_key + expire + video_id)`), mã hoá sang HLS nhiều độ phân giải, webhook báo trạng thái, phát qua CDN với **token authentication** (token + expires, có `token_path` để các segment HLS dùng chung token).

## Các phương án

| Phương án | Ưu | Nhược |
|---|---|---|
| A. Lưu file MP4 trên disk Laravel, phát bằng signed route (`URL::temporarySignedRoute`), không lớp trừu tượng | Rất nhanh | Không mô phỏng Bunny (không HLS, không async, không webhook) → khi đổi sang Bunny phải viết lại upload, trạng thái, player |
| B. Dùng Bunny thật ngay từ đầu (thư viện dev giá rẻ) | Không phải tự xây | Trái quyết định PO; phụ thuộc tài khoản/chi phí từ giai đoạn dev |
| **C. Contract `VideoProvider` + module VideoLab độc lập nói chuyện qua HTTP/webhook giống Bunny** | Frontend và nghiệp vụ dùng đúng luồng sẽ dùng với Bunny (TUS upload thẳng, trạng thái async, webhook, HLS + token) → đổi provider = đổi config + adapter | Phải viết module VideoLab (~3 ngày); cần ffmpeg; phát file qua PHP tốn tài nguyên nếu dùng thật ở production |

## Quyết định

Chọn **C**.

### 1. Ranh giới

```
Nghiệp vụ (Lesson, LessonAccessService, ProgressService)
      │  chỉ biết video_assets + PlaybackInfo
      ▼
VideoProvider (contract) ── VideoProviderManager (config video.provider)
      ├── InternalVideoProvider ──HTTP──▶ Module VideoLab (/videolab/*)   ← giai đoạn dev
      └── BunnyStreamProvider   ──HTTP──▶ video.bunnycdn.com + CDN        ← sau này
ExternalLinkPlayback (link YouTube/Vimeo — không qua provider)
```

- **Module VideoLab** nằm trong cùng repo (`app/VideoLab`, route `routes/videolab.php`, migration riêng, bảng tiền tố `vl_`, disk riêng `videolab`, queue riêng `video`). **Không được import model/service nghiệp vụ và ngược lại**; nghiệp vụ không FK vào `vl_*`. Giao tiếp duy nhất: HTTP API + webhook + URL phát. Nhờ vậy có thể tách thành app riêng hoặc tắt hẳn khi dùng Bunny.
- Chỉ đăng ký route/migration VideoLab khi `VIDEO_PROVIDER=internal` (hoặc `VIDEOLAB_ENABLED=true`).
- Lưu ý dev local: `InternalVideoProvider` gọi HTTP vào chính app → `php artisan serve` phải chạy nhiều worker (`PHP_CLI_SERVER_WORKERS=4`) hoặc dùng Sail/Nginx, nếu không sẽ tự khoá (deadlock request).

### 2. Contract

```php
namespace App\Services\Video\Contracts;

interface VideoProvider
{
    public function name(): string;                                   // 'internal' | 'bunny'
    public function createVideo(string $title): ProviderVideo;        // guid, status
    public function uploadTarget(ProviderVideo $video, int $ttlSeconds): UploadTarget;
    // UploadTarget: protocol='tus', endpoint, headers (AuthorizationSignature, AuthorizationExpire, VideoId, LibraryId), expiresAt
    public function getVideo(string $providerVideoId): ProviderVideo; // status chuẩn hoá + durationSeconds
    public function playback(VideoAsset $asset, PlaybackContext $ctx): PlaybackInfo;
    // PlaybackContext: userId, ip (tuỳ chọn ràng IP), ttlSeconds
    // PlaybackInfo: kind='hls'|'embed', url, expiresAt
    public function deleteVideo(string $providerVideoId): void;
    public function parseWebhook(Request $request): ?string;          // chỉ trả provider_video_id; trạng thái lấy lại bằng getVideo()
}
```

- Trạng thái chuẩn hoá ở nghiệp vụ: `created → uploading → processing → ready | failed` (ánh xạ từ mã 0–6 kiểu Bunny trong adapter).
- **Webhook không được tin**: `VideoWebhookService` chỉ lấy `provider_video_id` rồi gọi `getVideo()` qua API có xác thực để lấy trạng thái/thời lượng thật (webhook Bunny không đảm bảo có chữ ký). VideoLab webhook vẫn kèm HMAC header để thực hành.
- Frontend upload bằng **tus-js-client** tới `UploadTarget.endpoint` với headers được cấp → cùng một mã frontend chạy cho VideoLab và Bunny.
- Frontend phát bằng **hls.js** khi `kind = hls`; Bunny adapter cũng trả HLS qua CDN token (không dùng iframe embed) để frontend và theo dõi tiến độ không đổi. `kind = embed` dành cho link ngoài.

### 3. Module VideoLab

| Thành phần | Mô tả |
|---|---|
| Bảng `vl_videos` | `guid` (U), `library_id`, `title`, `status` (0 created, 1 uploaded, 2 processing, 3 transcoding, 4 finished, 5 error, 6 upload_failed — như Bunny), `length_seconds`, `max_bytes` (kích thước khai báo, ≤ `max_upload_mb`), `upload_length`, `upload_offset`, `upload_expires_at` (≤ 6 giờ), `created_by_ref` (id người tạo phía nghiệp vụ, để tính hạn mức), `source_path` (thư mục `videolab/source/`), `renditions` json, `error`, timestamps |
| API quản lý | `/videolab/library/{lib}/videos` (header `AccessKey` = `video.internal.api_key`, so bằng `hash_equals`). **Chỉ mở trong mạng nội bộ** (Nginx `allow <IP app server>; deny all;`) |
| Upload | Tập con **TUS 1.0** (Creation + Core), kiểm chữ ký + các giới hạn ở §3a. Ghi chunk append vào file tạm trong `videolab/incoming/{guid}`; đủ `upload_length` → kiểm magic bytes → status 1 → dispatch `TranscodeVideoJob` |
| Xử lý | `TranscodeVideoJob` (queue `video`, worker sandbox §3a, `timeout` 3600s, `tries` 2, idempotent theo `guid` — xoá output dở trước khi chạy lại): `ffprobe` (tham số khoá cứng §3a) → `ffmpeg` ra HLS 360p/720p, segment 6s, vào `videolab/hls/{guid}/` → status 4 → `SendVideoLabWebhookJob` (retry backoff 10s/60s/300s) |
| Phát | `GET /videolab/cdn/{token}/{expires}/{guid}/{path}`; `token = base64url(HMAC_SHA256(token_key, "/{guid}/" . expires . ip))` — ký theo **thư mục** `/{guid}/` như `token_path` của Bunny; `ip` có mặt khi bật ràng IP (mặc định bật cho bài không preview — §4). Hết hạn/sai token/sai IP → 403. Dev: `response()->file()`; môi trường giống production: `X-Accel-Redirect` tới `location /_protected_hls/ { internal; }` |
| Dọn dẹp | Command `videolab:cleanup` xoá upload dở > 24h và file gốc sau khi transcode xong > 7 ngày (giữ lại nếu cấu hình `keep_source`) |

### 3a. Bảo mật VideoLab (Security S3 — bắt buộc trong T12)

1. **Kiểm magic bytes trước mọi lệnh ffprobe/ffmpeg:**
   - Chỉ nhận MP4/MOV: chuỗi `ftyp` ở byte 4–7.
   - Matroska/WebM: `1A 45 DF A3` ở byte đầu.
   - Mọi thứ khác (văn bản, `#EXTM3U`, `ffconcat`, ảnh...) → status 6, xoá file.
2. **Gọi ffprobe/ffmpeg bằng `Symfony\Component\Process\Process` với mảng tham số (không qua shell)**, luôn khoá cứng demuxer và protocol:
   ```php
   new Process([$ffprobe, '-v', 'error',
       '-protocol_whitelist', 'file',
       '-format_whitelist', 'mov,mp4,m4a,3gp,3g2,mj2,matroska,webm',
       '-show_format', '-show_streams', '-of', 'json', $sourcePath]);
   // ffmpeg: cùng '-protocol_whitelist file' và '-f <định dạng đã xác định>' cho đầu vào
   ```
   Kiểm `format.format_name` thuộc allowlist, có ≥ 1 stream video, thời lượng ≤ `video.max_duration_minutes` (180), độ phân giải ≤ 3840×2160.
3. **Worker queue `video` chạy trong container riêng** (`infra/worker-video/Dockerfile`):
   - User không phải root; **không mount `.env` hay mã nguồn ngoài những gì job cần**.
   - Chỉ mount volume `videolab` (đọc/ghi).
   - Không có mạng ra ngoài: network Docker `internal: true`, chỉ nối được Redis/DB nội bộ.
   - Giới hạn CPU/RAM (`cpus: 2`, `mem_limit: 2g`) và `timeout` job.
4. **Ràng buộc route phát:**
   - `->where('guid', '[0-9a-f-]{36}')->where('path', 'playlist\.m3u8|[0-9]{3,4}p/[A-Za-z0-9_]{1,40}\.(m3u8|ts)')`.
   - Khi phục vụ, `realpath()` của file phải nằm trong `videolab/hls/{guid}/`, sai → 404.
   - File gốc nằm ở thư mục khác (`videolab/source/`), không bao giờ phục vụ được.
5. **TUS:**
   - `Upload-Length` ≤ `max_bytes` (= kích thước khai ở `POST /admin/lessons/{id}/video-uploads`, ≤ `max_upload_mb` = 1024 (PO 2026-10-07, trước đây 2048)). PATCH làm vượt `Upload-Length` → 413.
   - Chỉ nhận upload khi `status ∈ {0}` và chưa có upload khác cho `guid` (1 upload/guid); video đã `finished` → 403.
   - Chữ ký hết hạn sau ≤ 6 giờ; kiểm `AuthorizationExpire > now()`, `VideoId` khớp bản ghi, so chữ ký bằng `hash_equals`. VideoLab dùng `hash_hmac('sha256', library_id.expire.video_id, api_key)` (Bunny adapter giữ công thức Bunny).
   - `Upload-Metadata.filename` chỉ để hiển thị, **không bao giờ dùng làm đường dẫn**.
   - Hạn mức: ≤ 20 GB/người tạo/ngày (`video.daily_quota_gb`), kiểm ở bước tạo phiên upload phía nghiệp vụ.
6. **Route & CORS:**
   - `routes/videolab.php` **không** dùng nhóm `web` (không session/CSRF), có CORS riêng:
     - origin = `ADMIN_URL` (upload) và `FRONTEND_URL` + `ADMIN_URL` (phát), `supports_credentials=false`;
     - allowed headers `Tus-Resumable, Upload-Length, Upload-Offset, Upload-Metadata, AuthorizationSignature, AuthorizationExpire, VideoId, LibraryId, Content-Type`;
     - expose `Location, Upload-Offset, Tus-Resumable`.
   - Chỉ `/videolab/tus*` và `/videolab/cdn/*` được mở ra Internet (host `video.vitaminvui.vn`).
7. **Test bắt buộc:**
   - File `.mp4` thực chất là `#EXTM3U` → bị từ chối trước ffprobe.
   - `{path}` chứa `..%2f`, `%2e%2e`, đường dẫn tuyệt đối → 404.
   - PATCH vượt `Upload-Length` → 413.
   - Upload lại vào `guid` đã `finished` → 403.
   - Chữ ký hết hạn → 403.

### 4. Luồng upload (Admin/GV) và phát (HS)

```mermaid
sequenceDiagram
  autonumber
  actor A as Admin/GV (Next.js)
  participant API as Laravel API (nghiệp vụ)
  participant P as VideoProvider (VideoLab | Bunny)
  participant Q as Queue video
  A->>API: POST /admin/lessons/{id}/video-uploads {filename,size}
  API->>API: authorize manageContent
  API->>P: createVideo(title) → guid
  API->>API: INSERT video_assets(status=uploading); lesson.video_source=upload
  API->>P: uploadTarget(guid, ttl)
  API-->>A: {video_asset_id, tus endpoint + headers}
  A->>P: TUS upload trực tiếp (không đi qua API nghiệp vụ)
  P->>Q: TranscodeVideoJob (ffmpeg → HLS)
  Q->>API: POST /webhooks/video/internal {VideoGuid}
  API->>P: getVideo(guid) (xác thực AccessKey)
  API->>API: video_assets=ready, duration; lessons.duration_seconds
  A->>API: GET /admin/lessons/{id}/video (poll trạng thái)
```

```mermaid
sequenceDiagram
  autonumber
  actor HS as Học sinh (Next.js + hls.js)
  participant API as Laravel API
  participant P as VideoProvider
  participant CDN as VideoLab /cdn | Bunny CDN
  HS->>API: GET /learn/lessons/{id}/playback
  API->>API: single-session middleware + LessonPolicy@watch (enrollment active)
  API->>P: playback(asset, ttl=video.playback_ttl_minutes)
  API-->>HS: {kind:hls, url(token, expires), resume_at_seconds}
  HS->>CDN: playlist.m3u8 + segments (cùng token)
  loop mỗi 20 giây khi đang phát
    HS->>API: POST /learn/lessons/{id}/heartbeat {position, watched_delta}
    API->>API: kiểm phiên + quyền mỗi lần; cập nhật lesson_progress
    API-->>HS: {completed, course_percent} hoặc 401 SESSION_REPLACED / 403 COURSE_NOT_OWNED → dừng player
  end
  Note over HS,CDN: URL hết hạn → player gọi lại /playback (hls.js reload tại vị trí hiện tại)
```

**Giảm rò nội dung trả phí (Security S13 — mặc định an toàn, chờ PO xác nhận):**
- `video.playback_ttl_minutes` = **15**.
- **Ràng IP bật mặc định cho bài không preview** (`video.bind_ip=true`). Khi đổi mạng (Wi-Fi ↔ 4G), segment trả 403 → player tự gọi lại `/playback` và tiếp tục tại vị trí hiện tại. Heartbeat bị chặn ngay khi mất quyền, nên UI dừng ngay.
- `throttle:playback` **30 lần/phút/user**.
- Mỗi lần cấp URL ghi log channel `playback` (user_id, lesson_id, ip, user-agent rút gọn), giữ 90 ngày.
- Cảnh báo (log `warning`) khi 1 user nhận URL từ > 3 IP khác nhau trong 1 giờ hoặc > 60 bài trong 1 giờ.
- Watermark động trên player (mã HS rút gọn): **hoãn**, chờ PO.
- Preview chỉ phục vụ bài chưa xoá mềm, thuộc khóa `published`, có `is_preview = true`.
- **Link ngoài (`external_link`):**
  - Mặc định **chỉ cho phép khi `is_preview = true`** (validate ở `LessonRequest`; chờ PO).
  - Chỉ lưu `external_provider` + `external_video_id` bắt bằng regex chặt: YouTube `^[A-Za-z0-9_-]{11}$`, Vimeo `^\d{6,12}$`. URL embed **dựng lại từ ID**: `https://www.youtube-nocookie.com/embed/{id}`, `https://player.vimeo.com/video/{id}?dnt=1`.
  - Frontend nhúng bằng iframe `sandbox="allow-scripts allow-same-origin allow-presentation"`, `referrerpolicy="strict-origin-when-cross-origin"`.
  - Outline công khai **không bao giờ** chứa URL/ID video của bài không preview.
  - Host ngoài whitelist hoặc `javascript:` → 422.

### 5. Tiến độ & quy tắc 90% (US-006 BR2)

`ProgressService::heartbeat(user, lesson, position, watchedDelta)` — đọc dòng `lesson_progress` bằng `lockForUpdate()` rồi mới tính (tránh lost-update khi 2 heartbeat trùng do client retry — DBA góp ý 2.5):
1. `elapsed = now - last_heartbeat_at` (lần đầu coi là 20s). `credited = min(watchedDelta, elapsed × 2 + 5)` (cho phép tốc độ phát tối đa 2x, chống cộng dồn ảo khi gửi dồn dập).
2. `watched_seconds = min(duration, watched_seconds + credited)`; `last_position_seconds = position`; `last_accessed_at = now`.
3. Nếu `status = in_progress` và `watched_seconds >= 0.9 × duration` → `completed`, `completed_at = now` (UPDATE có điều kiện `WHERE status='in_progress'`). Không bao giờ quay lại `in_progress`.
4. `enrollments.last_accessed_at` cập nhật tối đa 1 lần/5 phút.
5. Bài chưa có `duration_seconds` (link ngoài chưa nhập) → không tự hoàn thành; hiển thị cảnh báo cho admin khi lưu bài.

Tiến độ được lưu mỗi heartbeat → bị đăng xuất giữa chừng chỉ mất tối đa 20 giây (US-014 AC4).

### 6. Chuyển sang Bunny thật (checklist)

1. Viết `BunnyStreamProvider` (API `video.bunnycdn.com`, TUS `https://video.bunnycdn.com/tusupload`, HLS qua pull zone bật Token Authentication, `token_path=/{guid}/`).
2. Cấu hình webhook thư viện Bunny → `/api/v1/webhooks/video/bunny`.
3. `.env`: `VIDEO_PROVIDER=bunny`, `BUNNY_LIBRARY_ID`, `BUNNY_API_KEY`, `BUNNY_CDN_HOST`, `BUNNY_TOKEN_KEY`; tắt VideoLab.
4. Di chuyển video cũ: command `videos:migrate-provider` tải file gốc từ VideoLab lên Bunny, tạo `video_assets` mới (provider=bunny), đổi `lessons.video_asset_id` theo lô. Không sửa nghiệp vụ, không sửa frontend.

## Hệ quả

- (+) Nghiệp vụ, frontend và dữ liệu tiến độ không đổi khi đổi provider; test bằng `FakeVideoProvider`.
- (+) Upload video lớn không đi qua API nghiệp vụ (không chiếm PHP worker của API chính khi dùng Bunny).
- (−) Phải cài `ffmpeg/ffprobe` trong image và chạy worker queue `video` riêng (timeout dài).
- (−) **VideoLab không thiết kế để chạy production quy mô thật** (băng thông, dung lượng, không CDN). Nếu go-live trước khi có Bunny: bắt buộc Nginx `X-Accel-Redirect`, ổ lưu trữ đủ lớn, giám sát — **cần PO chốt mốc chuyển Bunny trước go-live**.
- (−) Không có DRM/mã hoá HLS: người có quyền vẫn có thể tải segment bằng công cụ; token ngắn hạn + 1 phiên giảm chia sẻ link, không chống quay màn hình (chấp nhận ở MVP).
- Cần `laravel-security` review code T11–T13 theo §3a và §4 (S3, S13).
- Hạ tầng mặc định Nginx (chờ PO). Nếu chọn IIS: cần phương án tương đương cho `X-Accel-Redirect` (vd URL Rewrite + ARR hoặc phục vụ qua PHP có giới hạn), `internal` location, giới hạn body và chặn đường dẫn.

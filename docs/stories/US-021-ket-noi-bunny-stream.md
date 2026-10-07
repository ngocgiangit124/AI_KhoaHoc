# US-021: Kết nối Bunny Stream làm nơi chứa và phát video

**Trạng thái:** Draft (chờ PO trả lời câu hỏi mở nhóm A trước khi chuyển Ready)
**Ưu tiên:** Must (bắt buộc trước khi mở bán thật; VideoLab không thiết kế cho production, xem ADR-002 mục Hệ quả)

## User story
Là Admin/Quản lý trang/Giáo viên phụ trách khóa, tôi muốn video bài giảng được tải lên và lưu trên Bunny Stream, để học sinh xem mượt ở mọi nơi mà website không phải tự gánh dung lượng và băng thông.

Là học sinh đã ghi danh, tôi muốn xem video bằng đường dẫn có hạn dùng, để chỉ người có quyền mới xem được và link không dùng lại được sau khi hết hạn.

Là PO, tôi muốn đổi từ VideoLab sang Bunny chỉ bằng cấu hình, để nghiệp vụ, tiến độ học và giao diện không phải làm lại.

## Bối cảnh
- Quyết định có từ trước (ADR-002): nghiệp vụ chỉ nói chuyện với lớp `VideoProvider`. VideoLab (tự xây, T12) là bản mô phỏng Bunny dùng khi phát triển. Đổi sang Bunny = viết thêm adapter + đổi cấu hình.
- Hiện trạng (kiểm trong code ngày 2026-10-06):
  - Chưa có `BunnyStreamProvider`. `VideoProviderManager::createBunnyDriver()` luôn báo lỗi "chưa cài đặt".
  - Đặt `VIDEO_PROVIDER=bunny`: tạo phiên upload trả 503 `VIDEO_PROVIDER_UNAVAILABLE`; webhook `/webhooks/video/bunny` trả 404.
  - `config/video.php` chưa có mục `providers.bunny`. `.env.example` chưa có biến `BUNNY_*`.
  - Phần còn lại đã dùng chung cho mọi nhà cung cấp, không phải viết lại: tạo phiên upload + hạn mức 20 GB/ngày + trần 2 GB (T11), webhook không tin payload và gọi lại `getVideo()` (T11), đồng bộ trạng thái và quét video kẹt `videos:check-stuck` (T11), dọn video mồ côi `videos:prune-orphans` (T11), phát video có token, ràng IP, throttle, log, tiến độ 90% (T13).
  - `video_assets.provider` lưu nhà cung cấp của từng video. Khi phát, hệ thống dùng đúng nhà cung cấp của video đó. Nên VideoLab và Bunny chạy song song được: video cũ vẫn phát qua VideoLab, video mới đi Bunny.
  - Chỗ cần chỉnh nhỏ: `VideoUploadService` đang ghi `provider_library_id` từ `config('video.library_id')` (mặc định `default`, dùng cho VideoLab). Bunny có Library ID riêng nên phải lấy từ cấu hình của Bunny.
- Frontend (FA4 tải video, FW4 học video) cùng gọi một API cho mọi nhà cung cấp, nên không cần màn riêng cho Bunny. Chỉ phải cấu hình tên miền được phép (xem Ghi chú).

## Business rules
- BR1: Nhà cung cấp mặc định cho video mới do `VIDEO_PROVIDER` quyết định. Production dùng `bunny`. Không có công tắc trên giao diện.
- BR2: Upload là TUS trực tiếp từ trình duyệt lên Bunny. File video không đi qua server VitaminVui. Chữ ký upload hết hạn tối đa 6 giờ (như hiện tại).
- BR3: Khoá API Bunny (`AccessKey`) và khoá ký token phát (Token Authentication key) chỉ nằm ở `.env` phía server. Không bao giờ trả cho trình duyệt, không ghi vào log, không nằm trong thông báo lỗi.
- BR4: Chỉ trạng thái "Finished" của Bunny mới là `ready`. Các trạng thái đang xử lý (kể cả mã mới ngoài 0–6) là `processing`, không bao giờ tự thành `failed`. Mã lỗi/upload hỏng của Bunny là `failed`.
- BR5: Trạng thái và thời lượng luôn lấy bằng cách gọi lại API Bunny có khoá. Webhook của Bunny không có chữ ký nên chỉ dùng để biết "video nào có thay đổi" (quy tắc đã có ở T11).
- BR6: URL phát là HLS qua CDN của Bunny, có token và hết hạn sau `video.playback_ttl_minutes` (mặc định 15 phút). Token ký theo thư mục `/{guid}/` để mọi đoạn video dùng chung. Bài không preview ràng IP khi `video.bind_ip` bật (như hiện tại).
- BR7: Thư viện Bunny phải cấu hình: bật Token Authentication; chỉ cho phép các tên miền của web học sinh (Allowed Referrers); chặn truy cập trực tiếp vào file; tắt MP4 fallback (không cho tải file mp4); tắt Direct Play/embed công khai. Đây là việc cấu hình trên trang Bunny, ghi thành checklist trong `docs/ops/production-checklist.md`.
- BR8: Xoá video (xoá bài, thay video khác, dọn mồ côi) phải xoá cả bản ở Bunny. Video không còn ở Bunny thì coi như đã xoá, không báo lỗi.
- BR9: Mọi lời gọi HTTP tới Bunny có timeout tối đa 10 giây (quy tắc của contract). Bunny lỗi hoặc chậm thì trả 503 `VIDEO_PROVIDER_UNAVAILABLE` với thông điệp tiếng Việt, không treo request.
- BR10: Khi `VIDEO_PROVIDER=bunny` và `VIDEO_ENABLED_PROVIDERS=bunny,internal`, video cũ ở VideoLab vẫn phát, đồng bộ và xoá đúng nhà cung cấp của nó. Không có bước tự chuyển video cũ (xem Ngoài phạm vi).
- BR11: Production: thiếu một trong `BUNNY_LIBRARY_ID`, `BUNNY_API_KEY`, `BUNNY_CDN_HOST`, `BUNNY_TOKEN_KEY` khi Bunny đang bật thì ứng dụng không khởi động (cùng cơ chế `ProductionConfigGuard` đang cấm `fake`). `BUNNY_CDN_HOST` phải là https và khác tên miền của app/web/admin.

## Acceptance criteria
- AC1: Given `VIDEO_PROVIDER=bunny` và đủ 4 khoá cấu hình, When Admin/GV bấm tải video cho một bài, Then hệ thống tạo video ở Bunny, lưu `video_assets` (provider=`bunny`, `provider_library_id` = Library ID của Bunny, `provider_video_id` = guid) và trả endpoint TUS của Bunny cùng chữ ký có hạn, không có khoá API trong response.
- AC2: Given trình duyệt đã nhận chữ ký, When tải xong tệp lên Bunny, Then bài hiện "Đang xử lý". Khi Bunny báo Finished (qua webhook hoặc lần đồng bộ định kỳ) thì bài chuyển "Sẵn sàng" và `duration_seconds` của bài bằng thời lượng Bunny trả về.
- AC3: Given webhook `POST /webhooks/video/bunny` có `VideoGuid` của một video đang xử lý, When Bunny gửi, Then hệ thống gọi lại API Bunny để lấy trạng thái thật rồi trả 204. Trạng thái trong payload không được tin.
- AC4: Given webhook có `VideoGuid` không tồn tại trong hệ thống hoặc payload sai định dạng, When nhận, Then trả 204, không tạo/sửa dữ liệu, không lộ video có tồn tại hay không.
- AC5: Given Bunny báo upload thất bại hoặc lỗi mã hoá, When đồng bộ, Then `video_assets.status = failed`, `error_message` bằng tiếng Việt dễ hiểu (không chứa khoá hay URL nội bộ), và Admin/GV chọn được tệp khác.
- AC6: Given học sinh có ghi danh active và bài đã `ready`, When gọi `GET /learn/lessons/{id}/playback`, Then nhận URL HLS có token và `expires`. URL phát được ở trình duyệt học sinh đó trong thời hạn.
- AC7: Given URL phát đã quá hạn, When trình phát tải đoạn video, Then Bunny trả 403 và trình phát tự xin URL mới như cơ chế đang có (FW4).
- AC8: Given bài không preview và `video.bind_ip` bật, When URL được dùng từ IP khác IP lúc cấp, Then Bunny từ chối (403).
- AC9: Given một ai đó mở thẳng URL playlist/đoạn video không có token, hoặc có token nhưng từ trang web có tên miền không nằm trong danh sách cho phép, When tải, Then bị Bunny từ chối. (Kiểm bằng tay trên thư viện Bunny thật, ghi kết quả vào báo cáo QA.)
- AC10: Given Admin xoá bài (hoặc thay video khác) và job dọn mồ côi chạy, When xoá, Then video bị xoá ở Bunny. Nếu Bunny đã không còn video đó (404) thì vẫn coi là xoá xong.
- AC11: Given Bunny không trả lời trong 10 giây hoặc trả lỗi 5xx khi tạo phiên upload, When Admin/GV bấm tải video, Then nhận 503 `VIDEO_PROVIDER_UNAVAILABLE` kèm thông điệp tiếng Việt, hạn mức 20 GB/ngày không bị trừ oan, không để lại video mồ côi ở Bunny.
- AC12: Given Bunny trả 401 (sai/hết hạn khoá API), When bất kỳ thao tác nào gọi Bunny, Then báo 503 thông thường cho người dùng. Log nội bộ ghi "Bunny từ chối khoá" (không ghi khoá) để vận hành biết.
- AC13: Given `VIDEO_ENABLED_PROVIDERS=bunny,internal`, When học sinh mở bài có video VideoLab cũ, Then vẫn phát được bằng VideoLab. Khi mở bài có video Bunny, phát bằng Bunny. Hai loại không lẫn nhau.
- AC14: Given môi trường production thiếu `BUNNY_TOKEN_KEY` (hoặc khoá khác) mà Bunny đang bật, When khởi động ứng dụng, Then ứng dụng từ chối chạy với thông báo nêu đúng tên biến thiếu (không in giá trị).
- AC15: Given Bunny trả mã trạng thái chưa biết (ví dụ 7, 8), When đồng bộ, Then asset giữ `processing`, không chuyển `failed`, không ném lỗi 500.
- AC16: Given công thức ký token, When chạy bộ test với giá trị mẫu cố định (khoá, guid, thời điểm hết hạn, IP), Then token sinh ra khớp tuyệt đối với kết quả mẫu tính độc lập theo tài liệu Bunny. Có 1 test đối chiếu với URL do Bunny thật chấp nhận (chạy tay một lần, ghi vào báo cáo).

## Trường hợp biên & lỗi
- Dữ liệu rỗng/lỗi: Bunny trả JSON thiếu `guid` hoặc `length` → lỗi `VideoProviderException`, không lưu dữ liệu nửa vời.
- Trùng lặp: webhook gửi nhiều lần cho cùng video (Bunny retry) → xử lý idempotent, trạng thái chỉ tiến, `ready` và `failed` là cuối (quy tắc T11).
- Webhook tới sớm hơn lúc hệ thống ghi xong asset (race) → bỏ qua; lần đồng bộ định kỳ 15 phút bắt lại.
- Tệp khai báo nhỏ nhưng tải lên lớn hơn: Bunny không ép trần theo từng video như VideoLab. Cần dev xác nhận Bunny trả được kích thước tệp gốc không. Nếu có thì so với `declared_size_bytes`, vượt thì đánh `failed` và xoá. Nếu không, trần chỉ được chặn ở frontend và hạn mức theo khai báo (rủi ro chấp nhận vì chỉ staff có quyền tải; ghi vào backlog).
- Video quá dài hoặc quá nặng: Bunny vẫn nhận. Chặn theo thời lượng sau khi mã hoá nếu PO yêu cầu (câu hỏi mở).
- Quyền: GV chỉ tải được cho khóa mình phụ trách; khóa khác 403/404 như hiện tại (không đổi).
- Lỗi hệ thống: Bunny sập khi học sinh đang xem → trình phát báo "Không tải được video, vui lòng thử lại" (US-006 AC5), không màn hình trắng.
- Dữ liệu lớn: thư viện nhiều video không ảnh hưởng vì mọi thao tác theo từng guid; không có lệnh nào liệt kê toàn bộ thư viện Bunny.
- Đổi mạng Wi-Fi sang 4G khi bật ràng IP → 403 đoạn video, trình phát xin lại URL (như VideoLab).
- Xoá thư viện/đổi khoá Bunny: video cũ không còn truy cập được; quy trình đổi khoá nằm trong checklist vận hành (xoay khoá không làm hỏng video đang có, chỉ làm token cũ hết hạn).

## Phân quyền
| Vai trò | Xem (phát video) | Tải lên (tạo) | Sửa (thay video) | Xoá |
|---|---|---|---|---|
| Admin | Có (qua playback admin) | Có, mọi khóa | Có | Có |
| Quản lý trang | Có | Có, mọi khóa | Có | Có |
| Giáo viên | Có, khóa mình phụ trách | Có, khóa mình phụ trách | Có, khóa mình phụ trách | Có, khóa mình phụ trách |
| Học sinh | Có, khi ghi danh active (hoặc bài preview) | Không | Không | Không |
| Khách | Chỉ bài preview công khai | Không | Không | Không |

Cấu hình khoá Bunny: chỉ người vận hành server qua `.env`. Không có màn nhập khoá trên giao diện quản trị.

## Ảnh hưởng dữ liệu
- Không có bảng/migration mới. `video_assets` đã có `provider`, `provider_library_id`, `provider_video_id` (guid dạng UUID nằm vừa cột 64 ký tự).
- Cấu hình mới trong `config/video.php` mục `providers.bunny`: `library_id`, `api_key`, `cdn_host`, `token_key`, `api_base` (mặc định `https://video.bunnycdn.com`), `tus_endpoint` (mặc định `https://video.bunnycdn.com/tusupload`). Thêm vào `.env.example`: `BUNNY_LIBRARY_ID`, `BUNNY_API_KEY`, `BUNNY_CDN_HOST`, `BUNNY_TOKEN_KEY`.
- `VideoUploadService`: lấy `provider_library_id` từ nhà cung cấp đang dùng thay vì `video.library_id`. `video.library_id` giữ nguyên cho VideoLab.
- `ProductionConfigGuard`: thêm kiểm tra khoá Bunny (BR11).
- Webhook route đã chấp nhận tên `bunny`, không cần route mới.
- Tài liệu: `docs/ops/production-checklist.md` thêm mục cấu hình thư viện Bunny (BR7) và webhook; `docs/architecture/api-contract.md` §2.7 ghi mô tả webhook Bunny.

## Ngoài phạm vi
- Chuyển video đã có trên VideoLab sang Bunny (lệnh `videos:migrate-provider` ở ADR-002 §6): tách thành T37-1 (~1,5 ngày), chỉ làm nếu PO có video thật cần giữ.
- DRM, mã hoá HLS, watermark động: không làm ở MVP (ADR-002). Người có quyền vẫn có thể quay màn hình.
- Phụ đề, nhiều ngôn ngữ âm thanh, ảnh bìa video (thumbnail) của Bunny.
- Màn quản trị cấu hình khoá Bunny trên giao diện.
- Thống kê băng thông/chi phí trong trang quản trị (xem số liệu ở trang Bunny).
- Tự động chuyển nhà cung cấp khi Bunny sập (failover sang VideoLab).
- Tắt/gỡ module VideoLab khỏi mã nguồn (chỉ tắt bằng cấu hình khi hết video cũ).

## Câu hỏi mở
Nhóm A, cần trả lời trước khi dev bắt đầu bước kiểm thật:
- [ ] A1. PO đã có tài khoản Bunny chưa? Cần tạo 1 Video Library cho production và 1 cho staging/dev (để test không đụng video thật). Cần cung cấp (gửi qua kênh an toàn, không dán vào chat hay commit): Library ID, API key của thư viện (Stream API key), CDN hostname (dạng `vz-xxxx.b-cdn.net` hoặc tên miền riêng như `video.vitaminvui.vn`), Token Authentication key, và URL webhook sẽ khai trên Bunny. Mặc định đề xuất cho webhook: `https://api.<tên-miền-thật>/api/v1/webhooks/video/bunny`.
- [ ] A2. Tên miền phát video: dùng tên miền Bunny cấp sẵn hay tên miền riêng (ví dụ `video.vitaminvui.vn`, đẹp và dễ đổi nhà cung cấp sau này, cần chỉnh DNS)? Mặc định đề xuất: dùng tên miền Bunny cấp sẵn trước, đổi sau.
- [ ] A3. Gói và vùng lưu trữ: học sinh chủ yếu ở Việt Nam. Chọn vùng lưu trữ chính ở châu Á (Singapore/Hồng Kông) và có sao lưu vùng khác không? Mặc định đề xuất: 1 vùng chính ở châu Á, không sao lưu, vì sao lưu tính thêm tiền.
- [ ] A4. Ngân sách băng thông hằng tháng tối đa là bao nhiêu? Cần để cài cảnh báo ở Bunny khi gần chạm ngưỡng (Bunny tính tiền theo dung lượng lưu và băng thông phát). BA ước lượng sơ bộ được nếu PO cho: số khóa, số video mỗi khóa, thời lượng trung bình, số học sinh dự kiến năm đầu.
- [ ] A5. Hiện đã có video thật nào trên VideoLab cần giữ không? Nếu không (chỉ là video thử): bỏ T37-1, đơn giản hơn. Nếu có: cần làm T37-1 trước go-live. Mặc định đề xuất: chưa có, làm lại video khi lên Bunny.

Nhóm B, có mặc định, PO không trả lời thì dùng mặc định:
- [ ] B1. Có cần DRM (chống tải/quay lậu mạnh hơn token) không? Mặc định: không, như ADR-002; DRM của Bunny tính thêm phí và làm phức tạp trình phát.
- [ ] B2. Giữ tệp gốc trên Bunny hay xoá sau khi mã hoá? Mặc định: giữ (để dễ đổi nhà cung cấp hoặc mã hoá lại), tốn thêm dung lượng lưu.
- [ ] B3. Giới hạn thời lượng một video (ví dụ 180 phút như ADR-002)? Mặc định: có, 180 phút; video dài hơn bị đánh `failed` với thông báo rõ.
- [ ] B4. Thời hạn URL phát giữ 15 phút và ràng IP cho bài trả phí? Mặc định: giữ như hiện tại.
- [ ] B5. VideoLab: giữ cho local/staging, production chỉ dùng Bunny? Mặc định: có; tắt `VIDEOLAB_ENABLED` ở production khi không còn video cũ.

## Ghi chú cho Designer / Dev / QA
- Ước lượng sơ bộ:
  - **T37 backend ~3 ngày** [SEC]: adapter + test bằng `Http::fake` ~1,5 ngày; cấu hình, `ProductionConfigGuard`, chỉnh `provider_library_id` ~0,5 ngày; kiểm trên Bunny thật (cần A1), ghi checklist cấu hình thư viện ~0,5 ngày; dự phòng 0,5 ngày vì Bunny ngoài tầm kiểm soát. Phần dùng `Http::fake` làm được ngay khi chưa có tài khoản.
  - **T37-1 chuyển video cũ ~1,5 ngày**, chỉ khi PO trả lời A5 là có.
  - Frontend không có task riêng: cộng khoảng +0,25 ngày vào FA4 và +0,25 ngày vào FW4 (cấu hình tên miền, thử thật), xem tasks.md.
- Dev (Laravel):
  - Làm theo đúng hợp đồng `App\Services\Video\Contracts\VideoProvider`. Không đổi chữ ký hàm.
  - Công thức Bunny cần đối chiếu tài liệu Bunny hiện hành, không suy từ trí nhớ: chữ ký TUS (`AuthorizationSignature` = SHA256 của `LibraryId + ApiKey + AuthorizationExpire + VideoId`), token phát (SHA256 của khoá + đường dẫn + thời điểm hết hạn + IP nếu ràng IP, dùng `token_path`), mã trạng thái, tên field `length`.
  - Điều chỉnh khi test thật: Bunny có thể cần `Origin` đúng trên TUS; thử từ trình duyệt thật, không chỉ curl.
  - Không đưa `AccessKey` vào exception, log, hay audit.
  - Test phải có: ký token khớp giá trị mẫu, mã trạng thái ánh xạ đủ (kể cả mã lạ), timeout, 401, 404 khi xoá, webhook idempotent, `VIDEO_ENABLED_PROVIDERS=bunny,internal` phát đúng nhà cung cấp, guard production.
- Designer / Frontend: không thay đổi giao diện. Màn tải video (FA4) và trình phát (FW4) giữ nguyên.
  - Admin: biến `NEXT_PUBLIC_VIDEO_UPLOAD_URL` trỏ `https://video.bunnycdn.com` (CSP `connect-src` phải cho phép).
  - Web: `NEXT_PUBLIC_VIDEO_HOSTS` thêm CDN hostname của Bunny (CSP `connect-src`/`media-src`).
  - `chunkSize` của tus-js-client giữ ≤ 8 MB (như VideoLab).
- QA:
  - Chạy e2e trên thư viện Bunny staging: tải video thật, xem tiến trình xử lý, phát, hết hạn token, thử mở link không token, thử từ tên miền lạ, xoá bài rồi kiểm video bị xoá ở Bunny.
  - Ghi chú: e2e hiện có (VideoLab sandbox 5/5) không thay được phần này.
  - Dọn video thử trên Bunny sau khi chạy xong để khỏi tốn dung lượng.
- [SEC]: khoá API, khoá ký token, Allowed Referrers, webhook không chữ ký (đã giảm nhẹ bằng gọi lại API), log không chứa khoá. Cần `laravel-security` duyệt cùng cụm T12/T37.

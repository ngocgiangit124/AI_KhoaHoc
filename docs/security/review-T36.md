# SECURITY: T36 (Hồ sơ giáo viên công khai, US-020 backend) | 2026-10-06
**Kết luận:** PASS có điều kiện

Không có Critical/High. Có 1 Medium (M1: ảnh đã rút đồng ý vẫn tải được qua URL tĩnh cũ), 3 Low và một số Info. Phân quyền, IDOR, chốt đồng ý `PublicTeacher`, mass assignment, upload ảnh, giới hạn 6 khi chạy song song, audit và `images:prune-orphans` đều đạt khi thử thật. Điều kiện của "PASS": M1 phải được sửa (hoặc PO ghi nhận chấp nhận rủi ro bằng văn bản) trước production; L1–L3 đưa vào `backlog-v2.md`.

**Phạm vi:** mọi thay đổi chưa commit trong `backend/`, `docs/`, `infra/production/` của T36: migration `teacher_profiles`, `TeacherProfile`, `PublicTeacher`, `StaticUrl`, `TeacherProfileService/Reader/Eligibility`, `HomepageTeacherQuery`, `MyTeacherProfileController`, `TeacherProfileController`, `HomeTeacherController`, `CourseCatalog`/`CourseDetailResource`/`CourseSearchRequest`, 6 FormRequest `TeacherProfile/*`, Gate + limiter trong `AppServiceProvider`, `PlainText`, `ImageUploadService`, `OrphanImagePruner` + `images:prune-orphans`, `ProductionConfigGuard::guardStaticUrl`, `routes/{api,admin}.php`, `.env.worker-video.example`, `production-checklist.md`. Đối chiếu: US-020, `docs/tech/US-020.md`, ADR-005, api-contract §2.9, `backlog-v2.md` (không báo lại mục đã có), `review-cum2-content-video.md` (upload T08).

**Cách kiểm:** phân tích tĩnh, kết hợp thử thật bằng curl trên stack local (nginx :8000, `api.localhost`, `admin-api.localhost`, đăng nhập thật kèm MFA qua mailpit). Dữ liệu tạo có tiền tố `sec-t36-`: 10 giáo viên, 1 admin, 1 QLT, 1 học sinh, 1 khóa `published`. Pruner thử trên thư mục tạm riêng (`/tmp/sec-t36-uploads`, config disk ghi đè trong tinker), không đụng `storage/app/uploads` của dev. Test chạy trên DB f (`phpunit.local-f.xml`). **Đã dọn hết** (users, course, course_teacher, consents, teacher_profiles, 2 file ảnh còn lại, email mailpit, cookie jar). Chỉ còn khoảng 75 dòng `audit_logs` do thao tác thử sinh ra (bảng không cho xoá, đúng thiết kế).

## Các điểm đạt (đã thử thật)

| Hạng mục | Kết quả |
|---|---|
| **GV A với hồ sơ GV B** | GV A gọi `GET/PATCH /admin/teacher-profiles/777`, `POST/DELETE .../avatar`, `PATCH .../homepage` (cả cho chính mình), `GET /admin/teacher-profiles` → 403 `FORBIDDEN`. Body sai (`<b>`) vẫn 403, tức Gate chạy trước validate. Id không tồn tại cũng 403, không dò được id. `/admin/me/teacher-profile?user=777` kèm body `user_id:777` chỉ sửa hồ sơ của A. Đường "Hồ sơ của tôi" không có `{user}` nên không có IDOR. |
| **Admin/QLT đồng ý thay** | `/admin/me/teacher-profile` (GET/PATCH/consent/withdraw) → 403. `POST`/`PUT /admin/teacher-profiles/{id}/consent` → 404 (route không tồn tại). `PATCH /admin/teacher-profiles/777` kèm `public_consent_at`, `public_consent_version`, `show_on_homepage`, `avatar_path:"../../x.webp"`, `profile_updated_by` → chỉ `bio` được ghi. `PATCH .../homepage` kèm `public_consent_at` → bị bỏ qua. Đã kiểm DB: không có dòng `consents`, `public_consent_at` vẫn NULL. |
| **Học sinh/khách** | Chưa đăng nhập → 401 ở mọi route. Học sinh đăng nhập `admin-api` → 403 `WRONG_PORTAL`, sau đó vẫn 401. Các route quản trị không tồn tại trên host `api` (404). Origin lạ → 403 `ORIGIN_NOT_ALLOWED`. |
| **`{user}` lạ** | Id học sinh, admin, QLT, `0`, `-1`, `1e3`, `777abc`, số 19 chữ số → 404 `NOT_FOUND`. `00777` → hồ sơ 777 (vô hại). PATCH/homepage với id học sinh/admin → 404. |
| **Chốt đồng ý (BR5)** | Grep toàn `app/`: chỉ có 2 chỗ xuất ảnh/bio ra API công khai (`CourseDetailResource`, `HomeTeacherResource`), cả hai qua `PublicTeacher`. `GET /courses` và `?teacher_id=` chỉ trả `{id,name}`. `/me/courses`, giỏ hàng, quiz, `CartResource` không trả thông tin giáo viên. `User::$fillable` đã bỏ `bio`, không có chỗ nào serialize nguyên model `User`/`TeacherProfile` ra JSON. Thử thật: chưa đồng ý nhưng đã có ảnh + bio + cờ trang chủ → `/home/teachers` không có, `/courses/{slug}` trả `bio:null, avatar_url:null`. Đồng ý → hiện. Rút → cả hai API trả null **ngay** (Laravel không cache). `teacher_id` của admin → `data: []`. |
| **Upload ảnh** | SVG đặt tên `.png`, HTML/`GIF89a<?php` đặt tên `.jpg`, GIF thật, nội dung là đường dẫn `/etc/passwd`, data-URI base64 → 422. PNG 4001×10 → 422. PNG 3,6 MB → 422. Tên `../../../public/shell.php` (nội dung JPEG) → 422 (Laravel chặn đuôi php). PNG/JPEG polyglot (`<?php`, `<script>`) → nhận nhưng mã hoá lại. JPEG có EXIF GPS + chuỗi đánh dấu → file lưu là WebP 800×800, không có `Exif`, không còn chuỗi đánh dấu/`<?php`/`<script>`, quyền 0644, tên UUIDv4. Ảnh cũ bị xoá khi thay (kiểm 5 lần thay liên tiếp, cả khi QLT thay hộ). Throttle `teacher-avatar` 10/phút/người tính cả lượt 422. PNG 4000×4000 xử lý mất khoảng 1,6 giây (T08-1 đã biết). |
| **Ghi đè ảnh người khác** | Không làm được: tên file do server sinh (`Str::uuid()` = v4), `avatar_path` chỉ đổi bằng `forceFill` trong service. `ImageUploadService::delete` chỉ nhận mẫu `{uuid}.webp`. |
| **`images:prune-orphans`** | Thư mục tạm: file đang được `teacher_profiles.avatar_path` tham chiếu (mtime 3 ngày trước) → giữ. File mồ côi cũ → xoá. File mồ côi mới → giữ. `foo.php`, `UUID-viết-hoa.webp`, `uuid.webp.php`, `sub/uuid.webp`, `.htaccess` → không đụng. `--dry-run` không xoá. Lỗi DB khi kiểm tham chiếu thì nhảy vào `catch` trước khi xoá (an toàn khi lỗi). Không nhận tham số đường dẫn nên không bị lợi dụng để xoá file khác. Symlink trong `uploads`: Flysystem dừng cả lượt (`UnableToListContents`), không xoá đích của symlink. |
| **Giới hạn 6 song song** | Bật sẵn 5. Bắn đồng thời 5 request bật 5 người (admin + QLT) → 1 × 200, 4 × 409, tổng 6. Vòng 2: tắt 2, bắn đồng thời 7 request (có 2 request bật cùng một người, 1 request đổi thứ tự) → đúng 6. Isolation READ COMMITTED (`PDO::MYSQL_ATTR_INIT_COMMAND`), mutex khoá mọi dòng theo PK. Test race T36 cũng xanh. |
| **Bằng chứng đồng ý** | `version` khác config → 409. `version` là mảng → 422. `version` thừa khoảng trắng → được trim bởi `TrimStrings` rồi khớp (vô hại). Body kèm `ip`, `granted_by`, `user_id`, `policy_version` → bị bỏ qua (service tự dựng giá trị). `X-Forwarded-For: 1.2.3.4` từ IP không tin cậy → `consents.ip` vẫn là IP thật. Gửi lại khi đã đồng ý → không thêm dòng. Rút → `revoked_at` được set. UA do client tự khai (bản chất HTTP, không xác minh được), đã cắt 255. |
| **XSS lưu trữ** | `<script>`, `> ` đứng riêng, `"><img onerror>` → 422. `&lt;script&gt;`, `javascript:` → nhận dạng chữ (vô hại khi FE render text). U+202E, U+2066, U+200B, ZWJ lạc chỗ, U+2028, U+2029, NUL, TAB, U+0085 → 422. `\r` trong bio được đổi thành `\n`. BOM U+FEFF ở đầu bị `TrimStrings` cắt. ZWJ trong emoji → nhận. 600 emoji → nhận, 601 → 422. UTF-8 hỏng → 422. Response là JSON có `nosniff`. Phần còn lọt: xem L2. |
| **Mass assignment** | `TeacherProfile::$fillable = [headline, bio]`. Controller chỉ dùng `validated()`. `setHomepage` nhận tham số đã kiểu hoá. Test kiến trúc chặn ghi `teacher_profiles` ngoài `TeacherProfileService`. |
| **Audit** | Đủ 5 action (`update`, `consent`, `consent_withdraw`, `homepage_toggle`, `homepage_order`). `changes` chỉ có tên trường, `on_behalf`, `bio_length`, `avatar: changed/removed`, `version`; không có nội dung bio/headline/ảnh. Double submit không ghi audit trùng. |
| **Cache/cookie** | `/home/teachers`: `Cache-Control: max-age=60, public`, `ETag`, `Vary: Origin`, không có `Set-Cookie`, throttle `catalog`. |
| **SQL** | `orderByRaw`/`selectRaw` là chuỗi tĩnh. `q` dùng `Like::contains`. `teacher_id` có `integer|min:1|max`. |

## Phát hiện

### M1 [Medium] Ảnh đã rút đồng ý vẫn tải được mãi qua URL tĩnh cũ — OWASP A01/A04 (dữ liệu cá nhân)
- **Vị trí:** `backend/app/Services/Teachers/TeacherProfileService.php:236-265` (`withdrawConsent` chỉ đặt `public_consent_at = NULL`, giữ nguyên `avatar_path`/file). `infra/production/nginx/conf.d/vitaminvui.conf:164-189` (vhost tĩnh phục vụ mọi file trong `uploads`, `Cache-Control: public, max-age=86400` ở dòng 184, không có `X-Robots-Tag`).
- **Mô tả & tác động:**
  - Sau khi đồng ý, URL ảnh (`https://static.../{uuid}.webp`) đã xuất hiện công khai: trong response `/home/teachers` và `/courses/{slug}` (có cache public 60 giây), trong HTML trang chủ, trong cache ảnh của Next (`/_next/image`), trong CDN/trình duyệt (24 giờ), và có thể bị bot tìm kiếm ảnh lập chỉ mục (trang chủ công khai).
  - Khi giáo viên rút đồng ý, API ngừng trả URL, nhưng **file vẫn ở nguyên tên cũ và vẫn trả 200** cho tới khi giáo viên thay hoặc xoá ảnh, hoặc tài khoản bị ẩn danh hoá. Ai đã có URL (bot, người chụp màn hình HTML, trang sao chép) vẫn tải được không thời hạn, và chỉ mục ảnh của công cụ tìm kiếm không tự hết vì URL vẫn sống.
  - Đã kiểm: sau khi rút, file `storage/app/uploads/{uuid}.webp` vẫn còn và `avatar_path` không đổi. Local không có vhost tĩnh (T08-2) nên không gọi được URL, nhưng cấu hình Nginx production phục vụ trực tiếp từ thư mục này.
  - URL là UUIDv4 nên **không đoán được**. Rủi ro chỉ đến từ URL **đã từng công khai**. Đây là vấn đề quyền rút đồng ý (BR4, AC7 "ảnh biến mất"), không phải lỗ đoán URL.
  - **Đánh giá: cần sửa** (cách sửa rẻ). Nếu PO chọn chấp nhận rủi ro thì phải ghi rõ "rút đồng ý chỉ gỡ khỏi API, không vô hiệu URL đã công khai".
- **Cách sửa:**
  1. Đổi tên file khi rút đồng ý (và khi đồng ý lại thì không cần). Ví dụ trong `withdrawConsent`: copy sang UUID mới, ghi `avatar_path` mới trong transaction, sau commit xoá file cũ (giống `replaceAvatar`). Với CDN: purge URL cũ, hoặc giảm `max-age` cho ảnh đại diện.
     ```php
     // sau khi khoá $profile và trước save()
     if ($profile->avatar_path !== null) {
         $rotated = (string) Str::uuid().'.webp';
         Storage::disk(ImageUploadService::DISK)->copy($profile->avatar_path, $rotated);
         $oldPath = $profile->avatar_path;   // xoá sau commit
         $profile->forceFill(['avatar_path' => $rotated]);
     }
     ```
     Đặt logic copy/xoá vào `ImageUploadService` (ví dụ `rotate()`) để giữ kiểm mẫu tên ở một chỗ. Lỗi transaction thì xoá file mới.
  2. Thêm `add_header X-Robots-Tag "noindex, noimageindex" always;` cho vhost tĩnh (nhớ lặp lại đủ 3 header bắt buộc, theo ghi chú ADR-004 §6), để ảnh chân dung không vào chỉ mục tìm kiếm ảnh.
  3. FW9 (frontend): ảnh giáo viên không qua `/_next/image`, hoặc đặt `minimumCacheTTL` ≤ 60 giây. Ghi vào checklist.
- **Cách kiểm chứng:** test feature: đồng ý → lấy `avatar_url` từ `/home/teachers` → rút → `Storage::disk('uploads')->exists(<tên cũ>)` là false, `avatar_path` trong DB đã đổi, admin vẫn thấy ảnh (URL mới). Kiểm Nginx staging: `curl -I` URL cũ → 404, có `X-Robots-Tag`.

### L1 [Low] Giáo viên bị đổi vai trò không còn cách nào rút đồng ý hoặc xoá ảnh; admin cũng mất quyền gỡ khi cờ trang chủ tắt — OWASP A01/A04 (quyền của chủ thể dữ liệu)
- **Vị trí:** `backend/app/Providers/AppServiceProvider.php:101` (`own-teacher-profile` = `isTeacher()`). `backend/app/Services/Teachers/TeacherProfileReader.php:27-35` (`managedQuery`: `role = giao_vien` HOẶC `show_on_homepage = true`).
- **Mô tả & tác động:** đã thử thật. GV B đồng ý (có ảnh) → admin đổi B sang `quan_ly_trang` → admin tắt cờ trang chủ của B. Sau đó:
  - B (giờ là QLT) gọi `DELETE /admin/me/teacher-profile/consent` và `DELETE /admin/me/teacher-profile/avatar` → 403.
  - Admin gọi `GET`/`DELETE /admin/teacher-profiles/777/avatar` → 404.
  - DB: `public_consent_at` vẫn có, `consents.revoked_at` vẫn NULL, file ảnh vẫn trên static host.

  API công khai không lộ (vì `PublicTeacher` kiểm `role`). Nhưng người này không thực hiện được quyền rút đồng ý, ảnh vẫn tải được qua URL cũ (cộng dồn với M1), và nếu sau này đổi lại thành giáo viên thì hồ sơ lập tức công khai lại theo đồng ý cũ mà họ không gỡ được trong thời gian là QLT.
- **Cách sửa (chọn 1):**
  - Cho phép `DELETE /admin/me/teacher-profile/consent` và `DELETE /admin/me/teacher-profile/avatar` với **mọi** tài khoản có dòng `teacher_profiles` (Gate mới, ví dụ `withdraw-own-teacher-profile` = `isTeacher() || TeacherProfile::whereKey($user->id)->exists()`). Thêm/đồng ý vẫn chỉ cho giáo viên.
  - Hoặc trong `StaffAccountService::changeRole` khi rời vai trò giáo viên: tự rút đồng ý (revoke `consents`, audit `teacher_profile.consent_withdraw` với `reason: role_changed`). Việc này cần PO xác nhận vì BR9 đang "giữ nguyên đồng ý".
  - Đồng thời mở rộng `managedQuery` cho mọi user có dòng hồ sơ (để admin còn gỡ được ảnh).
- **Cách kiểm chứng:** test: GV đồng ý → `changeRole` sang QLT → gọi `DELETE /admin/me/teacher-profile/consent` → 200, `consents.revoked_at` có giá trị. Admin `DELETE /admin/teacher-profiles/{id}/avatar` → 200 kể cả khi cờ đã tắt.

### L2 [Low] `PlainText` còn cho qua nhiều ký tự vô hình/định dạng, nên tạo được `headline`/`bio` "trống" hoặc chứa chữ ẩn — OWASP A03 (giả mạo hiển thị)
- **Vị trí:** `backend/app/Rules/PlainText.php:27`.
- **Mô tả & tác động:** đã thử thật trên cả `headline` (GV tự sửa) và `bio` (QLT sửa hộ). Các ký tự sau đều được nhận (200):
  - dấu định hướng U+200E (LRM), U+200F (RLM), U+061C (ALM);
  - U+2060 (word joiner), U+2062 (invisible times), U+00AD (soft hyphen), U+034F, U+180E;
  - ký tự tag U+E0041… (chữ ASCII ẩn hoàn toàn), selector U+E0100, U+FE0F đứng lẻ;
  - ký tự "trắng" có độ rộng: U+3164 (Hangul filler), U+2800 (Braille blank);
  - 100 dấu kết hợp chồng lên một chữ (Zalgo), và bio có 200 dòng trống liên tiếp.

  Hệ quả:
  - `bio` chỉ gồm U+3164 vẫn qua điều kiện "có bio" (`HomepageTeacherQuery`, `TeacherEligibility`), nên trang chủ hiện thẻ có giới thiệu trống.
  - `headline` có thể chứa chữ ẩn (ký tự tag) mà người duyệt không thấy, ví dụ để nhồi nội dung cho bot/LLM đọc trang.
  - Zalgo hoặc hàng trăm dòng trống làm vỡ bố cục trang chi tiết khóa (bio hiển thị đầy đủ với `pre-line`).

  Contract §2.9 ghi "bidi hoặc zero-width → 422", nên đây là chặn chưa đủ so với hợp đồng. Không thực thi script được, nên mức Low. Rule dùng chung cho tên khóa/chương/bài nên phạm vi rộng hơn T36. Mục "Cụm 2 L4" trong backlog đã sửa một phần (đã chặn U+202A–202E, U+2066–2069, U+200B/C, U+FEFF, U+2028/2029).
- **Cách sửa:** chặn theo lớp thay vì liệt kê từng ký tự:
  ```php
  // \p{Cf} (gồm LRM/RLM/ALM, U+2060–2064, U+00AD, tag U+E0000–E007F...), trừ ZWJ đã kiểm riêng
  $invisible = '/[<>\p{Cc}\p{Zl}\p{Zp}\x{3164}\x{115F}\x{1160}\x{FFA0}\x{2800}]|(?!\x{200D})\p{Cf}/u';
  // selector (FE00–FE0F, E0100–E01EF) chỉ hợp lệ ngay sau emoji
  $straySelector = '/(?<![\p{Extended_Pictographic}])[\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}]/u';
  // tối đa 3 dấu kết hợp liên tiếp (đủ cho tiếng Việt tổ hợp)
  $zalgo = '/\p{M}{4,}/u';
  ```
  Với `bio`: giới hạn số dòng trống liên tiếp (ví dụ chuẩn hoá `\n{3,}` → `\n\n`) và tổng số dòng (ví dụ ≤ 15). Rà dữ liệu hiện có (tên khóa/chương) trước khi siết vì rule dùng chung.
- **Cách kiểm chứng:** mở rộng `tests/Unit/PlainTextNewlinesTest.php` với từng ký tự ở trên → fail. Chuỗi tiếng Việt tổ hợp (NFD, ví dụ `"Tiếng Việt"` dạng tách dấu) và emoji gia đình/cờ → vẫn pass.

### L3 [Low] `guardStaticUrl` so tên miền theo chuỗi, không theo registrable domain; bỏ sót host `admin-api`/`api` và chưa chuẩn hoá host — OWASP A05
- **Vị trí:** `backend/app/Support/ProductionConfigGuard.php:294-302`.
- **Mô tả & tác động:** đã chạy `guardStaticUrl` bằng reflection với các cấu hình sau:

  | Cấu hình | Guard | Ghi chú |
  |---|---|---|
  | `FRONTEND_URL=https://vitaminvui.vn`, `STATIC_URL=https://static.vitaminvui.vn` | chặn (đúng) | |
  | `FRONTEND_URL=https://www.vitaminvui.vn`, `STATIC_URL=https://static.vitaminvui.vn` | **cho qua** | cùng site với web/api/admin |
  | `FRONTEND_URL=https://www.vitaminvui.vn`, `STATIC_URL=https://admin-api.vitaminvui.vn` | **cho qua** | không so với `APP_ADMIN_API_HOST`/`APP_API_HOST`, chỉ so `APP_URL` |
  | `STATIC_URL=https://vitaminvui.vn.` hoặc `https://api.vitaminvui.vn.` (dấu chấm cuối) | **cho qua** | |
  | `STATIC_URL` có dấu chấm toàn chiều rộng `static．vitaminvui.vn` | **cho qua** | trình duyệt chuẩn hoá thành `static.vitaminvui.vn` |
  | `STATIC_URL=https://127.0.0.1` | **cho qua** | |
  | `SESSION_DOMAIN` phủ host tĩnh | chặn (đúng) | |
  | `http://` | chặn (đúng) | |

  Khi miền tĩnh là anh em cùng registrable domain (`static.` cạnh `www.`/`api.`/`admin.`), cookie host-only không bị gửi sang, nhưng miền tĩnh là **same-site**: `SameSite` không còn bảo vệ, và nếu miền tĩnh từng chạy được script thì có thể ghi cookie `Domain=vitaminvui.vn` (cookie tossing, gây session fixation hoặc lệch XSRF). Rủi ro thực tế thấp: file đều được mã hoá lại thành WebP, vhost tĩnh có `CSP sandbox` + `nosniff`, và env mẫu dùng miền riêng `vitaminvui-media.net`. Mục đích của guard là chặn cấu hình sai của người vận hành, nên nên chặn cả các trường hợp trên.
- **Cách sửa:** chuẩn hoá host (`rtrim('.')`, `idn_to_ascii(..., IDNA_NONTRANSITIONAL_TO_ASCII)`, lowercase), từ chối IP literal, rồi so **registrable domain** (eTLD+1) của `STATIC_URL` với của `APP_URL`, `FRONTEND_URL`, `ADMIN_URL`, `app.api_host`, `app.admin_api_host`. Có thể dùng hàm đơn giản: 2 nhãn cuối, hoặc 3 nhãn nếu nhãn áp chót thuộc danh sách SLD Việt Nam (`com`, `net`, `org`, `edu`, `gov`, `ac`, `info`, `biz`, `name`, `pro`, `health`, `int` … `.vn`). Hoặc dùng `jeremykendall/php-domain-parser` nếu chấp nhận thêm package.
- **Cách kiểm chứng:** thêm vào `tests/Feature/T36/StaticUrlGuardTest.php` 6 trường hợp "cho qua" ở bảng trên → phải ném `RuntimeException`. `https://static.vitaminvui-media.net` vẫn qua.

### Info
- **I1 — Ảnh tải lên trước khi đồng ý đã nằm trên static host công khai.** URL là UUIDv4, chỉ lộ cho chính giáo viên và staff qua API quản trị. **Chấp nhận** (không đoán được URL). Nếu muốn chặt hơn: lưu ảnh chưa đồng ý ở disk private, chỉ chuyển sang `uploads` khi đồng ý. Có thể làm chung với M1.
- **I2 — Test kiến trúc hẹp.** `tests/Arch/PublicTeacherTest.php:11` chỉ quét `app/Http/Resources/Catalog` và 2 file có tên cố định. Một API công khai sau này dựng mảng trong Service (kiểu `MyCoursesService` đang làm cho thumbnail) hoặc Resource ngoài thư mục `Catalog` sẽ không bị bắt. Đề xuất: quét mọi `app/Http/Resources/**` (trừ `Admin/`) và `app/Services/{Catalog,Learning,Cart,Quiz}/**`, cấm `->teacherProfile`, `avatar_path`, `->bio` ngoài `PublicTeacher`.
- **I3 — `ImageUploadService::storeWebp` dùng `ImageManager::read($binary)` với toàn bộ decoder** (`backend/app/Services/Content/ImageUploadService.php:39`). Intervention 3.11.9 thử `FilePathImageDecoder` trước `BinaryImageDecoder`, nên chuỗi giống đường dẫn hoặc data-URI có thể được đọc như file hoặc base64. Hiện validation đã chặn (thử `/etc/passwd`, data-URI → 422), nên chỉ là phòng thủ chiều sâu. Đề xuất: `->read($binary, \Intervention\Image\Decoders\BinaryImageDecoder::class)`. Áp chung cho thumbnail T08.
- **I4 — Đổi `consent_version` thì `giveConsent` không thu hồi dòng `consents` cũ** (`TeacherProfileService.php:205-225`). Khi giáo viên đồng ý lại theo phiên bản mới, sẽ có 2 dòng `revoked_at IS NULL`. Không gây lộ dữ liệu (rút sẽ thu hồi tất cả), nhưng bằng chứng bị chồng. Đề xuất: revoke dòng cũ (hoặc ghi `superseded`) trước khi INSERT.
- **I5 — `TeacherProfileService::erase()` chưa được gọi ở đâu** (T34 chưa làm). Đến khi T34 xong, ẩn danh hoá tài khoản chưa xoá ảnh/bio/đồng ý. Ghi vào định nghĩa "xong" của T34. Hiện `PublicTeacher` cũng không kiểm `anonymized_at`/`status`. Trang chi tiết khóa vẫn hiện ảnh/bio của giáo viên bị khoá nếu họ đã đồng ý. Điều này đúng BR5/BR9 hiện tại, nhưng cần nhớ khi làm T34.
- **I6 — Admin vẫn "đồng ý thay" được bằng đường gián tiếp:** `POST /admin/staff/{id}/reset-password` trả mật khẩu tạm cho admin, giáo viên không có MFA, nên admin đăng nhập được thành giáo viên (phải đổi mật khẩu lần đầu) rồi tự tick đồng ý. Truy vết được (audit `staff.password_reset` + `staff.login` + `consents.ip` là IP của admin), và đây là quyền sẵn có của admin, không phải lỗi T36. Đề xuất (V2): gửi email cho giáo viên khi có `teacher_profile.consent` và khi admin reset mật khẩu.
- **I7 — Symlink trong `uploads` làm `images:prune-orphans` dừng cả lượt** (Flysystem `DISALLOW_LINKS`). Chỉ xảy ra khi có người có shell trên server. Không cần sửa, chỉ cần giám sát log lỗi của scheduler.

## Kết quả công cụ
- `vendor/bin/pest -c phpunit.local-f.xml tests/Feature/T36 tests/Arch/PublicTeacherTest.php tests/Unit/PlainTextNewlinesTest.php`: **166 passed** (1405 assertions), 151 giây.
- `composer.lock`/`composer.json` không đổi trong T36, nên không chạy lại `composer audit` (lần chạy gần nhất ở cụm 2: không có advisory). Intervention Image 3.11.9.
- Thử thật bằng curl: khoảng 150 request (ma trận quyền, 15 file upload, 35 chuỗi Unicode × 2 trường, 2 vòng race), pruner trên thư mục tạm, 13 cấu hình guard.

## Đánh giá lại S* liên quan
| # | Trạng thái | Ghi chú |
|---|---|---|
| S2 (ảnh/miền tĩnh) | ⚠️ | Upload đạt. M1 (URL cũ sau khi rút đồng ý), L3 (guard). T08-2 (vhost tĩnh local) vẫn mở |
| S8 (văn bản thuần) | ⚠️ | HTML, `<>`, bidi chính, zero-width chính, U+2028/2029 đã chặn. Còn L2 |
| S17 (mass assignment) | ✅ | `$fillable` 2 trường, `forceFill` trong service, đã thử body có cột nhạy cảm |
| S7 (đồng ý/dữ liệu cá nhân) | ⚠️ | Chốt `PublicTeacher` đạt, bằng chứng `consents` đạt. M1, L1, I4, I5 |
| A01 (IDOR) | ✅ | Không có `{user}` ở đường của giáo viên. Gate trước validate. 404 cho id ngoài diện quản lý |

## Việc chuyển `laravel-dev`
1. **M1:** đổi tên file ảnh khi rút đồng ý (copy sang UUID mới, xoá cũ sau commit), kèm test. Thêm `X-Robots-Tag` vào vhost tĩnh mẫu và `production-checklist.md`. Ghi chú cho FW9 về `/_next/image`.
2. **L1:** Gate cho phép rút đồng ý/xoá ảnh với user có dòng `teacher_profiles` (hoặc tự rút khi `changeRole`, chờ PO). Mở rộng `managedQuery`.
3. **L3:** chuẩn hoá host + so registrable domain + thêm `api_host`/`admin_api_host` + chặn IP trong `guardStaticUrl`.
4. **L2:** siết `PlainText` theo `\p{Cf}` + filler + selector lạc + Zalgo. Giới hạn dòng trống trong bio. Rà dữ liệu cũ.
5. Info: I2 (mở rộng test kiến trúc), I3 (ép `BinaryImageDecoder`), I4 (revoke consent cũ khi đổi phiên bản). I5 ghi vào DoD của T34.

## Test `laravel-qa` nên thêm
- Đồng ý → rút → file ảnh cũ không còn trên disk, `avatar_path` đổi, `/home/teachers` và `/courses/{slug}` trả null (M1).
- GV đồng ý → `changeRole` sang QLT → rút đồng ý được, `consents.revoked_at` có giá trị. Admin vẫn xoá được ảnh khi cờ đã tắt (L1).
- `PlainText`: LRM/RLM/ALM, U+2060, U+00AD, tag U+E0041, U+3164, U+2800, U+FE0F lẻ, 4 dấu kết hợp liên tiếp → 422. Tiếng Việt NFD và emoji ghép → pass. Bio chỉ gồm U+3164 → 422 (L2).
- `guardStaticUrl`: anh em cùng site (`www` + `static`), trùng `admin-api`, dấu chấm cuối, dấu chấm toàn chiều rộng, IP → chặn (L3).
- Giữ test hiện có: polyglot + EXIF GPS (đã đạt), race giới hạn 6, Gate trước validate, admin gửi `public_consent_at` qua PATCH → bị bỏ qua.

## Điểm cần pháp chế / PO quyết
- **M1:** rút đồng ý có phải làm URL ảnh đã công khai hết hiệu lực không, hay chỉ cần gỡ khỏi API (chấp nhận URL cũ còn sống). Theo Luật Bảo vệ dữ liệu cá nhân 2025 / Nghị định 356/2025, việc ngừng xử lý sau khi rút đồng ý: **cần bộ phận pháp chế xác nhận**. Khuyến nghị kỹ thuật: vô hiệu URL cũ (chi phí thấp).
- **L1:** khi giáo viên đổi vai trò thì đồng ý công khai còn hiệu lực không, và họ có được tự rút không. BR9 hiện giữ nguyên. Người không còn là giáo viên vẫn phải rút được đồng ý: **cần bộ phận pháp chế xác nhận**.
- **Admin/QLT sửa hộ nội dung của giáo viên đã đồng ý (BR6/Q5):** ảnh/bio do admin thay sẽ công khai ngay dưới đồng ý của giáo viên, và giáo viên chỉ biết khi tự mở "Hồ sơ của tôi". Đồng ý có bao gồm nội dung do người khác thay không? Đề xuất kỹ thuật: gửi email cho giáo viên khi có `teacher_profile.update` `on_behalf: true` trong lúc đang đồng ý. **Cần PO/pháp chế xác nhận.**
- **I6:** có cần thông báo cho giáo viên khi có đồng ý mới hoặc khi admin reset mật khẩu (để phát hiện việc đồng ý thay qua tài khoản bị chiếm) không.
- Thời hạn giữ `consents` (IP, UA) sau khi rút hoặc ẩn danh hoá: như I4 của T03, **cần bộ phận pháp chế xác nhận**.

# ADR-004: Nền tảng — Laravel 13 API cho 2 frontend Next.js (học sinh / quản trị), Sanctum SPA cookie theo từng host, phân quyền bằng `role` + Policy

**Trạng thái:** Accepted (sau review Security `docs/security/audit-2026-09-25.md` và DBA `docs/db/design-review.md`) · **Cập nhật:** 2026-09-25
**Phạm vi:** toàn dự án (US-001 → US-014 và các story bổ sung US-015..018)

## Bối cảnh

- **Quyết định của PO (2026-09-25, cập nhật):** dùng **Laravel 13 ngay từ đầu**, PHP **8.3**, MySQL **8.4 LTS** (CLAUDE.md đã cập nhật) — Laravel 11 và MySQL 8.0 đã hết hỗ trợ bảo mật (Security S1); Laravel 12 chỉ còn vá bảo mật tới khoảng 02/2027 nên được bỏ qua.
  - Laravel 13 yêu cầu PHP ≥ 8.3 — khớp với lựa chọn PHP 8.3. Ghi mốc hết hỗ trợ của Laravel 13 vào tài liệu vận hành khi khởi tạo (đối chiếu trang phát hành chính thức).
  - PHP 8.3 còn vá bảo mật tới cuối 2027; MySQL 8.4 LTS còn hỗ trợ tới 2032.
- **Quyết định của PO (2026-09-25):**
  - Trang quản trị Next.js có **origin riêng** là `admin.vitaminvui.vn`.
  - **Staging dùng tên miền hoàn toàn khác**, không chia sẻ cookie với production.
  - UI quản trị làm bằng Next.js, không dùng Filament/Blade.
- Frontend: Next.js + Tailwind + TypeScript. Mockup trong `docs/design/` đặt tên component theo Blade — chỉ là tham chiếu giao diện.
- 4 vai trò cố định: admin, quản lý trang (≈ admin trừ cấu hình hệ thống), giáo viên (phạm vi theo khóa được gán), học sinh (trẻ vị thành niên).
- Hạ tầng chưa chốt → **mặc định Nginx + PHP-FPM trên Ubuntu, chạy bằng Docker** (chờ PO xác nhận; nếu IIS thì các biện pháp Nginx ở ADR-002/§6 cần phương án tương đương).

## Quyết định

### 1. Laravel là API thuần, không có Blade/Livewire/Filament
- `php artisan install:api` (cài Sanctum bản tương thích Laravel 13). Route trong `routes/api.php` (học sinh) và `routes/admin.php` (quản trị), tiền tố `/api/v1`. Response JSON qua API Resource. Blade chỉ dùng cho email.
- 2 app Next.js: **web** (học sinh, SEO) và **admin** (quản trị) — cấu trúc repo ở tasks.md (FE0).

### 2. Topology tên miền, cookie, CORS (thay cho bản cũ — Security S6)

**2.1 Host theo môi trường**

| Vai trò | Production | Staging (tên miền riêng — tên cụ thể chờ PO) | Local |
|---|---|---|---|
| Web học sinh (Next.js) | `https://vitaminvui.vn` | `https://vitaminvui-staging.vn` | `http://localhost:3000` |
| Admin (Next.js) | `https://admin.vitaminvui.vn` | `https://admin.vitaminvui-staging.vn` | `http://admin.localhost:3001` |
| API học sinh (Laravel) | `https://api.vitaminvui.vn` | `https://api.vitaminvui-staging.vn` | `http://api.localhost:8000` |
| API quản trị (cùng app Laravel) | `https://admin-api.vitaminvui.vn` | `https://admin-api.vitaminvui-staging.vn` | `http://admin-api.localhost:8000` |
| File tĩnh do người dùng tải lên | `https://static.vitaminvui-media.net` (**tên miền đăng ký riêng**, không có cookie — S2; chờ PO mua) | `https://static.vitaminvui-staging-media.net` | `http://localhost:8080` |
| Video (VideoLab/Bunny CDN) | `https://video.vitaminvui.vn` (VideoLab) / host CDN Bunny | tương ứng | `http://video.localhost:8000` |

Cùng một ứng dụng Laravel phục vụ 2 host API bằng `Route::domain(config('app.api_host'))` và `Route::domain(config('app.admin_api_host'))`. Route quản trị **chỉ tồn tại** trên host admin-api; route học sinh chỉ trên host api. Webhook thanh toán/video nằm trên host api.

**2.2 Cookie — host-only, tách theo host API**

| | Host api (học sinh) | Host admin-api (quản trị) |
|---|---|---|
| Tên cookie phiên | `vv_session` (prod) / `vvstg_session` (staging) | `vv_admin_session` / `vvstg_admin_session` |
| `SESSION_DOMAIN` | `null` → **host-only** (không gửi sang subdomain khác) | `null` → host-only |
| `Secure` / `HttpOnly` | true / true (local: Secure=false) | true / true |
| `SameSite` | `Lax` | `Strict` |
| Thời hạn | 7 ngày trượt (`SESSION_LIFETIME=10080`) | **Idle 120 phút** (middleware `StaffIdleTimeout` so `last_activity` trong session) + **tối đa 12 giờ** kể từ đăng nhập; `expire_on_close=true` |
| Remember-me | Không | Không |

- Middleware toàn cục `ConfigureHostContext` (prepend trước `HandleCors` và `StartSession`):
  - Đọc host đã được `TrustHosts` xác thực.
  - Đặt `session.cookie`, `session.lifetime`, `session.same_site`, `session.expire_on_close` theo bảng trên.
  - Đặt `cors.allowed_origins` = **đúng 1 origin** (host api ← `FRONTEND_URL`; host admin-api ← `ADMIN_URL`).
  - Host khác danh sách → 404.
- `$middleware->trustHosts(at: [api_host, admin_api_host])` — chống giả header `Host`.
- **CSRF không cần cookie đọc được từ JS:** frontend gọi `GET /api/v1/csrf-token` (trả `{ "token": "..." }`) và gửi header `X-CSRF-TOKEN` cho mọi request thay đổi dữ liệu. Cookie `XSRF-TOKEN` (nếu Laravel vẫn set) là host-only, không cần đọc. Endpoint này chỉ đọc được qua CORS từ đúng origin.
- `SANCTUM_STATEFUL_DOMAINS` theo từng môi trường, **không wildcard, không `localhost` ở production**: prod `vitaminvui.vn,admin.vitaminvui.vn`; local `localhost:3000,admin.localhost:3001`.
- **Chặn chéo origin cho khu quản trị:**
  - Middleware `EnsureAdminOrigin` trên mọi route admin-api: `Origin` (hoặc `Referer` với GET) phải bằng `ADMIN_URL`, nếu không → 403.
  - Đăng nhập ở host api chỉ chấp nhận vai trò `hoc_sinh`; ở host admin-api chỉ chấp nhận `admin`/`quan_ly_trang`/`giao_vien`. Kiểm sau khi mật khẩu đúng, thông điệp không lộ vai trò; sai host → thông báo "Vui lòng đăng nhập tại trang dành cho bạn".
  - Kết quả: phiên staff không bao giờ tồn tại trên host api, nên lỗi XSS ở trang học sinh không dùng được quyền admin.
- **Cam kết vận hành:** mọi subdomain của `vitaminvui.vn` do đội kiểm soát; không trỏ CNAME sang dịch vụ bên thứ ba; rà DNS hằng quý để chống chiếm subdomain (subdomain takeover). Checklist ở T31.

**2.3 CORS**
- `config/cors.php`: `paths: ['api/*']`, `supports_credentials: true`.
  - `allowed_origins` do `ConfigureHostContext` đặt, luôn đúng 1 origin.
  - `allowed_headers: ['Content-Type','Accept','X-CSRF-TOKEN','X-Requested-With','X-Device-Id','X-Request-Id']`.
  - `exposed_headers: ['X-Request-Id']`.
- `/videolab/*` có CORS riêng (ADR-002 §3a), `supports_credentials=false`.

**2.4 Proxy và rate limit (S10)**
- `$middleware->trustProxies(at: [IP load balancer, IP máy chủ Next.js])`, lấy từ `TRUSTED_PROXIES`. **Không bao giờ `*`.**
- Limiter luôn có **2 lớp: theo tài khoản và theo IP** (bảng ngưỡng ở api-contract §1.6).
- Đăng ký và quên mật khẩu dùng **captcha Cloudflare Turnstile** (chờ PO) thay cho ngưỡng IP thấp, vì lớp học dùng chung NAT.

**2.5 Cache — chống lẫn dữ liệu giữa người dùng (S16)**
- Middleware `NoStoreForAuthenticated` cho mọi response đã xác thực (hoặc có cookie phiên): `Cache-Control: no-store, private` + `Vary: Cookie, Origin`.
- Không đặt CDN cache trước `/api/v1/*`, trừ các endpoint công khai GET đã liệt kê (danh mục, chi tiết khóa, subjects, config/public) — những endpoint này trả `Cache-Control: public, max-age=60` và **không đọc cookie**.
- Quy tắc Next.js (bắt buộc cho `nextjs-dev`):
  - Tách 2 hàm: `publicFetch` (không gửi cookie, được dùng Data Cache `next: { revalidate, tags }`) và `authFetch` (`credentials: 'include'`, `cache: 'no-store'`).
  - **Mọi trang HTML đều render động** (hệ quả của CSP nonce — §2.7), nên không có ISR/Full Route Cache toàn trang. Cache chỉ nằm ở tầng dữ liệu. Trang cần đăng nhập vẫn đặt `export const dynamic = 'force-dynamic'`; **cấm chuyển tiếp cookie trong fetch có `revalidate`**.
  - Mặc định lấy dữ liệu cần đăng nhập ở phía client; SSR có đăng nhập chỉ khi thật cần.

**2.6 Bảo mật phía Next.js (S8, S23)**
- CSP có nonce (sinh trong `proxy.ts` của Next.js 16; phạm vi áp dụng ở §2.7):
  - `default-src 'self'; script-src 'self' 'nonce-{n}' 'strict-dynamic'; style-src 'self' 'unsafe-inline'`
  - `img-src 'self' data: {STATIC_URL}; font-src 'self'`
  - `connect-src 'self' {API_URL} {VIDEO_HOSTS}; media-src 'self' blob: {VIDEO_HOSTS}`
  - `frame-src https://www.youtube-nocookie.com https://player.vimeo.com` — chỉ app web; app admin: `frame-src 'none'`
  - `object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'`
- Header khác: `Strict-Transport-Security`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: camera=(), microphone=(), geolocation=()`.
- **Hợp đồng render nội dung** (khớp api-contract §4):
  - Mọi văn bản render bằng React mặc định.
  - `dangerouslySetInnerHTML` **chỉ** dùng cho `courses.description`, và phải qua DOMPurify thêm một lần.
  - Nội dung quiz: `katex.renderToString(tex, { trust: false, strict: 'warn', maxSize: 10, maxExpand: 1000, throwOnError: false })`.
- Tham số `?next=` sau đăng nhập chỉ nhận đường dẫn tương đối bắt đầu bằng `/` và không phải `//`.
- Chỉ chuyển trang tới `pay_url` khi host thuộc allowlist MoMo (`payment.momo.vn`, `test-payment.momo.vn` chỉ ở non-prod).
- JSON-LD: `JSON.stringify(x).replace(/</g,'\\u003c')`.
- `NEXT_PUBLIC_*` chỉ chứa URL công khai và site key Turnstile, không secret.
- `images.remotePatterns` chỉ gồm host `STATIC_URL`.
- **Next.js 16.x** (FE0 đã cài 16.3.6; trong Next 16 file `middleware.ts` đổi tên thành `proxy.ts`), React 19.2, Tailwind 4.3. Luôn giữ bản vá mới nhất của nhánh 16.x; `pnpm audit --prod` chạy ở CI.

### 2.7 CSP nonce và cách render trang công khai — Quyết định 2026-09-25 (xung đột phát hiện ở FE0)

**Bối cảnh:** trong Next.js 16, CSP dùng nonce buộc trang render động theo từng request (nonce sinh lúc có request). Hệ quả: không prerender/ISR được, Full Route Cache bị tắt; PPR/`cacheComponents` cũng không tương thích với nonce. Điều này xung đột với ý định SSR/ISR cho trang danh mục công khai (`/khoa-hoc`, `/lop-{grade}`, `/khoa-hoc/{slug}`) phục vụ SEO.

| Phương án | Bảo mật | Hiệu năng / SEO | Đánh giá |
|---|---|---|---|
| **(a) Giữ nonce cho mọi trang; render động; cache ở tầng dữ liệu (Data Cache) + cache HTTP phía API** | Mạnh nhất: một chính sách CSP `strict-dynamic` + nonce cho toàn origin `vitaminvui.vn` | SEO không đổi (HTML vẫn render đầy đủ phía server). Mất cache toàn trang: mỗi request tốn thời gian render phía Next.js, nhưng dữ liệu không phải gọi lại Laravel nhờ Data Cache | **Chọn** |
| (b) Bỏ nonce cho route công khai, dùng CSP có `'unsafe-inline'` | Yếu: chính trang chi tiết khóa hiển thị **mô tả HTML do giáo viên nhập** — nguồn XSS lưu trữ có khả năng cao nhất. Trang này **chung origin** với trang học sinh đã đăng nhập; CORS cho phép origin web gửi request kèm cookie tới `api.vitaminvui.vn`. XSS ở đây gọi được API bằng phiên của học sinh đang đăng nhập (trẻ vị thành niên) | Tốt nhất (ISR/CDN) | Loại |
| (c1) PPR / `cacheComponents` | — | — | Loại: không tương thích nonce |
| (c2) CSP dựa trên hash/SRI (`experimental.sri`) cho trang tĩnh | Tương đương (a) nếu làm đúng | Cho phép static | Hoãn: tính năng còn thử nghiệm, script inline của RSC khác nhau theo trang nên khó liệt kê hash trong header tĩnh; chi phí bảo trì cao. Chỉ xem xét lại nếu (a) không đạt ngưỡng hiệu năng |

**Quyết định: (a).**
- `proxy.ts` sinh nonce và đặt CSP cho **mọi route HTML** của cả 2 app. Matcher chỉ loại trừ: `/_next/static`, `/_next/image`, `favicon.ico`, `robots.txt`, `sitemap.xml`, file tĩnh trong `public/` — những route này không phải HTML nên không cần nonce.
- Trang công khai (`/`, `/khoa-hoc`, `/lop-{grade}`, `/khoa-hoc/{slug}`) render động; dữ liệu lấy qua `publicFetch(url, { next: { revalidate: 60, tags: ['catalog'] } })` → Next Data Cache giữ dữ liệu 60 giây, Laravel chỉ bị gọi khi cache hết hạn.
- Phía Laravel, các endpoint công khai đã trả `Cache-Control: public, max-age=60` (§2.5). Nginx có thể bật micro-cache 10–60 giây **chỉ cho các endpoint GET công khai của API** (không đọc cookie) — **không** cache HTML của Next.js (vì HTML chứa nonce).
- `robots.txt` và `sitemap.xml` là route handler (không phải trang), không bị ảnh hưởng: `revalidate = 3600`.
- `generateMetadata` (title, description, canonical, Open Graph) giữ nguyên, nên SEO không đổi; JSON-LD dùng `jsonLd()` và gắn `nonce` cho thẻ `<script type="application/ld+json">`.
- **Ngưỡng chấp nhận (đo ở FW2 bằng load test k6/autocannon trên môi trường giống staging):**
  - p95 TTFB ≤ **500 ms** cho `/lop-{grade}` và `/khoa-hoc/{slug}` ở **50 request/giây** với Data Cache ấm;
  - TTFB khi cache lạnh ≤ 1,2 s.
  - Không đạt → mở rộng số instance Next.js trước; nếu vẫn không đạt thì Architect xem lại (c2) bằng một bản sửa ADR. **Không** chuyển sang (b).
- App admin: đã render động hoàn toàn, không bị ảnh hưởng.

**Hệ quả:** (+) một chính sách CSP chặt cho toàn origin; (+) không phải duy trì 2 bộ CSP; (−) tốn CPU phía Next.js cho mỗi lượt xem trang công khai → cần theo dõi và có thể phải mở rộng ngang; (−) không đặt được CDN cache cho HTML.

### 3. Phân quyền: cột `users.role` + PHP enum + Gate/Policy (không dùng package phân quyền)
- 4 vai trò cố định → không cần bảng roles/permissions. Muốn thêm vai trò → ADR mới.
- Helpers trên `User`: `isAdmin()`, `isStaff()` (= admin | quan_ly_trang), `isTeacher()`, `isStudent()`, `teachesCourse(Course)`.
- **Không dùng `Gate::before`**, vì nó bỏ qua cả quy tắc nghiệp vụ.
- Gate `manage-system` chỉ dành cho admin (quản lý tài khoản staff — US-016). Gate `access-admin-area` = staff | giáo viên.
- **Chống IDOR trên route lồng nhau (S5):**
  - Mọi nhóm route cha–con dùng `->scopeBindings()`.
  - Policy luôn kiểm trên **khóa gốc của bản ghi con** (`$lesson->course`, `$question->quiz->course`), không chỉ tham số cha.
  - `course_id`, `chapter_id` (khi tạo), `video_asset_id`, `created_by` **không bao giờ lấy từ request**.
  - Payload chứa danh sách ID (sắp xếp cây, chọn chuyên đề/GV/khóa của mã giảm giá) phải được kiểm từng ID thuộc đúng phạm vi.
- **MFA & tài khoản staff (S15):**
  - Admin và Quản lý trang phải nhập OTP gửi qua email mỗi lần đăng nhập (`staff_login_mfa`) — mặc định bật, chờ PO.
  - Giáo viên nhận email cảnh báo khi đăng nhập từ thiết bị mới.
  - Tài khoản staff tạo bằng `php artisan staff:create` với mật khẩu ngẫu nhiên và `must_change_password=true`; UI quản lý tài khoản staff thuộc US-016.
  - Seeder **không** tạo admin mật khẩu mặc định ở production.

**Ma trận quyền (Policy):**

| Ability | Admin | Quản lý trang | Giáo viên | Học sinh | Khách |
|---|---|---|---|---|---|
| Cấu hình hệ thống / quản lý tài khoản staff (`manage-system`, US-016) | ✓ | ✗ | ✗ | ✗ | ✗ |
| Chuyên đề: xem danh sách quản trị | ✓ | ✓ | ✓ (chỉ active, để chọn) | ✗ | ✗ |
| Chuyên đề: tạo/sửa/ẩn/xoá | ✓ | ✓ | ✗ | ✗ | ✗ |
| Khóa học: xem danh sách quản trị | tất cả | tất cả | chỉ khóa có tên trong `course_teacher` | ✗ | ✗ |
| Khóa học: tạo | ✓ | ✓ | ✓ (tự động thành GV phụ trách, draft; `teacher_ids` gửi lên bị bỏ qua) | ✗ | ✗ |
| Khóa học: sửa thông tin | tất cả | tất cả | khóa phụ trách (không đổi giá/lớp khi đã từng publish — chờ PO) | ✗ | ✗ |
| Khóa học: xoá / publish / unpublish / gán GV | ✓ | ✓ | ✗ | ✗ | ✗ |
| Chương, bài, video, quiz (`manageContent`) | tất cả | tất cả | khóa phụ trách (kiểm theo khóa gốc của bản ghi con) | ✗ | ✗ |
| Xem video bài không preview | ✓ | ✓ | khóa phụ trách | enrollment `active` | ✗ |
| Xem video preview | ✓ | ✓ | ✓ | ✓ | ✓ |
| Duyệt/từ chối đăng ký miễn phí | tất cả | tất cả | khóa phụ trách | ✗ | ✗ |
| Mã giảm giá (mọi thao tác) | ✓ | ✓ | ✗ | ✗ | ✗ |
| Đơn hàng: xem danh sách (PII che) / chi tiết (PII đầy đủ, có audit) / hoàn tiền | ✓ | ✓ | ✗ | ✗ | ✗ |
| Xuất đơn hàng không kèm liên hệ | ✓ | ✓ | ✗ | ✗ | ✗ |
| Xuất đơn hàng **kèm** email/SĐT HS (phải nhập lý do) | ✓ | ✗ | ✗ | ✗ | ✗ |
| Đơn của mình, giỏ hàng, checkout, học miễn phí, làm quiz, tiến độ | ✗ | ✗ | ✗ | ✓ | ✗ |
| Giới hạn 1 phiên | ✗ | ✗ | ✗ | ✓ | — |

**Quy tắc scope dữ liệu:**
- `Course::scopeVisibleTo(User $u)`: staff không bị lọc; giáo viên dùng `whereHas('teachers', user_id = $u->id)`.
- Học sinh: mọi truy vấn đơn/giỏ/tiến độ/attempt lọc `user_id = auth()->id()` ở tầng service; khi truy cập theo id thì Policy kiểm lại.

### 4. Quy ước code
- **Service class theo domain** (`app/Services/<Domain>/`), controller mỏng, Form Request cho mọi input, API Resource cho output, Policy cho mọi truy cập theo id. Không dùng Repository. Event/Listener chỉ dùng cho tác dụng phụ bất đồng bộ.
- Interface chỉ ở những chỗ có lý do rõ: `PaymentGateway` (ADR-001), `VideoProvider` (ADR-002), `OtpSender` (đổi nhà cung cấp SMS), `CaptchaVerifier` (Turnstile/fake).
- **Mass assignment (S17):**
  - `$fillable` tường minh, **cấm `$guarded = []`**.
  - Service chỉ nhận `$request->validated()` hoặc DTO, không bao giờ `$request->all()`.
  - Các cột trạng thái/quyền (data-model §0) chỉ được đổi qua Service chuyên trách.
  - `Model::shouldBeStrict(! app()->isProduction())`.
- **Nhóm middleware chuẩn (S19):**
  - Mọi route cần đăng nhập ở host api: `auth:sanctum, account.active, student.single_session`.
  - Ở host admin-api: `auth:sanctum, account.active, admin.origin, staff.idle, staff.mfa_passed, staff.password_fresh`.
  - Ngoại lệ duy nhất là `POST /auth/logout`.
  - **Test kiến trúc** (Pest `arch()` + test duyệt `Route::getRoutes()`) khẳng định mọi route có `auth:sanctum` cũng có đủ các middleware của nhóm mình.
- **Audit (S15):** thao tác nhạy cảm gọi `AuditLogger::log($action, $subject, $changes)` từ Service (data-model §3.1 `audit_logs`), không dùng Observer chung.
- Lỗi nghiệp vụ: `App\Exceptions\DomainException` với `code` máy đọc được (api-contract §1).
- Feature flag cho các điểm chờ PO: `config/features.php`. `/config/public` chỉ trả **allowlist khoá tường minh**, không trả nguyên config.
- Enum PHP cho mọi trạng thái (lưu `varchar`); múi giờ `Asia/Ho_Chi_Minh`; tiền là số nguyên VNĐ.
- Log (S21):
  - `bootstrap/app.php` `dontFlash` thêm `password`, `password_confirmation`, `code`, `parent_phone`, `parent_email`, `captcha_token`.
  - Không log body của `/auth/*`.
  - Mail/job chứa PII hoặc OTP phải implement `ShouldBeEncrypted`.

### 5. Queue & scheduler
- Queue:
  - `default` — mail, sự kiện.
  - `exports` — xuất file.
  - `video` — ffmpeg, **worker riêng chạy trong container sandbox** (ADR-002 §3a).
- Scheduler khai báo trong `routes/console.php`; 1 cron `schedule:run` mỗi phút; mọi task dùng `withoutOverlapping()->onOneServer()`.

### 6. Checklist cấu hình production (S22 — kiểm ở T01/T31)
- `APP_ENV=production`, `APP_DEBUG=false`.
- Không cài Telescope/Debugbar ở production; nếu có Horizon thì gate chỉ cho admin.
- Nginx:
  - Web root là `public/`; chặn `/.env`, `/.git`, `/storage/*`.
  - Disk `exports`, `videolab`, `uploads` (bản gốc) nằm ngoài `storage/app/public`.
  - Giới hạn `client_max_body_size`: 16k cho `/api/v1/webhooks/*`, 5m cho upload ảnh, riêng cho TUS.
- Header response API: `Strict-Transport-Security`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: DENY`.
- Tên miền tĩnh `STATIC_URL` trả thêm `Content-Security-Policy: default-src 'none'; img-src 'self'; sandbox`, `X-Content-Type-Options: nosniff`, `Content-Disposition: inline`.
- Redis:
  - Có mật khẩu, chỉ nghe mạng nội bộ.
  - Tách DB: session=1, cache=2, queue=3, limiter=4.
- Secret (`APP_KEY`, `MOMO_*`, `DB_*`) nằm trong biến môi trường server hoặc secret manager, không commit.
- Khoá MoMo sandbox và production tách riêng; có quy trình xoay khoá.
- Boot guard: production không được bật gateway/provider `fake` hoặc endpoint sandbox (ADR-001 §1).

## Hệ quả
- (+) Tách hoàn toàn phiên học sinh và phiên quản trị; staging không thể đọc/ghi cookie production; lỗi XSS ở trang học sinh không leo quyền được lên admin.
- (+) Cookie host-only + CSRF qua header, nên không phụ thuộc cookie cấp `.vitaminvui.vn`.
- (−) 4 host (2 web, 2 API) + 1 tên miền tĩnh cần cấu hình TLS/DNS/Nginx; local cần `*.localhost`.
- (−) Đội cần người phụ trách Next.js (agent `nextjs-dev`) cho 2 app.

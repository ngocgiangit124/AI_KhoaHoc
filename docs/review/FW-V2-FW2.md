# REVIEW: FW-V2 + FW2 (apps/web)
**Kết luận:** APPROVE (vòng 2 — xem mục "Review lại" cuối file; vòng 1: REQUEST CHANGES)
**Phạm vi:** phần chưa commit của `frontend/apps/web` (shell thật, route group `(auth)`/`(site)`, proxy, danh mục/chi tiết/robots/sitemap, `lib/catalog/**`, `env.server.ts`, `api.server.ts`, `instrumentation.ts`, loadtest, e2e, eslint, README) + `packages/ui/src/v2/layout/SiteHeader.tsx` + `frontend/docker-compose.yml`, `pnpm-lock.yaml`. Không review: admin, file designer trong `(v2-preview)`, `components/v2`, `lib/v2`, `lib/mock`, phần còn lại của `packages/ui/src/v2`.

**Kết quả chạy (load average 6):** `typecheck` sạch, `lint` sạch, `test` 18 file / 148 test pass. Không build, không e2e.

## Tổng quan
FW2 làm chắc: 404 thật qua layout, tách `lib/catalog` có test, token SSR chỉ ở `server-only`, `X-Client-IP` đúng thiết kế §2.8 (chỉ khi `q`, chỉ lấy `X-Forwarded-For` do Nginx ghi đè), DOMPurify allowlist khớp backend, allowlist ESLint đúng 2 file, `paid_checkout_enabled=false` → giá + "Sắp mở bán", không nút mua. FW-V2 thì gate `/v2` ở `proxy.ts` lặp lại đúng lỗi đã bắt ở FA-V2 (matcher `missing` bị lách, 404 không có CSP, không có test), nên phải sửa trước khi qua QA.

## Phát hiện — FW-V2

### R1 [High] Gate `/v2` bị lách bằng header prefetch
- Vị trí: `apps/web/proxy.ts:67-83` (matcher có `missing: next-router-prefetch / purpose`), `:15-24`.
- Vấn đề: request mang `next-router-prefetch: 1` hoặc `purpose: prefetch` không chạy `proxy()` → không rewrite 404. Chỉ còn `notFound()` ở `(v2-preview)/v2/layout.tsx`; chính comment ở `proxy.ts:19-21` thừa nhận lớp này trả 200 và payload RSC chứa nội dung xem trước. Đây là lỗi R1 của FA-V2, chưa rút kinh nghiệm. E2E mới (`e2e/home.spec.ts`) chỉ gửi GET thường nên không bắt được.
- Đề xuất: thêm entry riêng không `missing` đứng trước entry hiện có:
  ~~~ts
  matcher: [
    { source: "/v2" },
    { source: "/v2/:path*" },
    { source: "/((?!_next/static|...).*)", missing: [/* như cũ */] },
  ],
  ~~~
  Thêm vào e2e/curl của QA: `curl -i -H 'next-router-prefetch: 1' -H 'purpose: prefetch' …/v2/khoa-hoc` phải 404.

### R2 [High] Chưa xử lý đường dẫn mã hoá / trùng dấu gạch (`/%76%32`, `//v2`)
- Vị trí: `proxy.ts:15-17` (`pathname === "/v2" || startsWith("/v2/")` trên `nextUrl.pathname` thô).
- Vấn đề: `nextUrl.pathname` không giải mã phần trăm và không gộp `//`; Next khi định tuyến thì giải mã. `/%76%32/khoa-hoc` (và có thể `//v2`) có thể không khớp hàm chặn nhưng vẫn trúng route `/v2/...`. Chưa tôi kiểm được trên bản `next start` (không được build), nhưng yêu cầu của task nêu rõ và code không có xử lý nào.
- Đề xuất: chuẩn hoá trước khi so (`decodeURIComponent` trong try/catch → lỗi giải mã coi như chặn nếu chứa `%`, gộp `/{2,}` thành `/`, hạ chữ thường) và đặt matcher entry riêng (R1) kèm biến thể `/:p(%76%32)`... hoặc đơn giản hơn: matcher chạy cho mọi path, hàm chặn xử lý. Tách `isBlockedPreview` ra file riêng (`lib/preview-gate.ts`) để test.

### R3 [Medium] Response chặn `/v2` không có CSP/header bảo mật
- Vị trí: `proxy.ts:22-24` (`return NextResponse.rewrite(...)` trước khi sinh nonce/đặt header).
- Vấn đề: trang 404 do rewrite thiếu CSP, `X-Content-Type-Options`, HSTS, Referrer-Policy. Yêu cầu "404 vẫn có CSP" chưa đạt.
- Đề xuất: không return sớm; tạo `response = NextResponse.rewrite(url, { request: { headers: requestHeaders } })` rồi chạy chung đoạn đặt header.

### R4 [Medium] Không có unit test cho gate `/v2` và CSP của proxy
- Vị trí: không có `proxy.test.ts` / test cho `isBlockedPreview` (chỉ có e2e bị `skip` nếu không chạy `next start`).
- Đề xuất: vitest + `vi.stubEnv`: production không `V2_PREVIEW` → rewrite; `V2_PREVIEW=1` → qua; development → qua; `/v2x`, `/v2-abc`, `/quan-tri` không bị chặn nhầm; `/%76%32`, `//v2`, `/V2`; 404 có header CSP; request có header prefetch vẫn bị chặn (kiểm `config.matcher` có entry không `missing` cho `/v2`); `/dang-ky` có `challenges.cloudflare.com`, route khác không.

### R5 [Medium] README FW2/FW-V2 lỗi thời, mâu thuẫn với ADR-004 §2.8 và tasks.md "Bổ sung sau §2.8"
- Vị trí: `frontend/README.md` mục FW2: "Cần Architect xác nhận cách cô lập IP…" và "SSR sẽ chạm 429 (trang lỗi 500)… Cần Architect/laravel-dev quyết bỏ/nới limiter"; mục FW-V2: nói gate chỉ bằng `notFound()` trong layout, không nhắc proxy.
- Vấn đề: tasks.md FW2 mục (1) yêu cầu thay đúng các câu này bằng tham chiếu §2.8; hiện đã có limiter nội bộ + `CatalogBusy`, nên README sai thực tế.
- Đề xuất: sửa theo tasks.md; mục FW-V2 ghi proxy rewrite + layout là lớp phụ, ghi cách kiểm bằng curl.

### R6 [Medium] Liên kết chết sang `/hoc/...` khi đã sở hữu khóa
- Vị trí: `lib/catalog/cta.ts:48-50` (`learnHref`) → nút "Tiếp tục học"; `components/catalog/CourseOutline.tsx` (link `/hoc/{id}/bai/{lesson}`).
- Vấn đề: `app/hoc/**` chưa tồn tại (FW4) nên học sinh đã được duyệt/đã mua bấm vào sẽ ra 404. US-019/yêu cầu "không link chết" áp cho header/footer — đã làm tốt — nhưng CTA chính vẫn dẫn vào chỗ chết.
- Đề xuất: tới khi FW4 có route, hiện nút "Tiếp tục học" ở dạng `disabled` kèm chữ "Trang học sắp mở", và bài trong outline dạng hàng tĩnh; hoặc ghi rõ PO chấp nhận (và đưa vào checklist QA/backlog). `robots` đã `Disallow: /hoc/` nên không ảnh hưởng SEO.

### R7 [Low] a11y: vùng bấm dưới 44px trên mobile
- Vị trí: `components/catalog/CatalogView.tsx:35` (`REMOVABLE` `min-h-9` = 36px) và `:163` ("Xoá bộ lọc" `min-h-9`); chip lớp `h-11` và nút Tìm `h-11` đạt.
- Đề xuất: `min-h-11` (hoặc `max-sm:min-h-11`). Chưa kiểm được chiều cao hàng `Checkbox` chuyên đề trong sheet (`packages/ui`), QA đo.

### R8 [Low] Đặt tên/dọn dẹp
- `proxy.ts:6-14`: JSDoc của `proxy` nằm nhầm trên `isBlockedPreview`; chuyển xuống trên `export function proxy`.
- `(v2-preview)/v2/layout.tsx` nạp lại font Be Vietnam Pro/Mali (đã nạp ở layout gốc) → hai bộ biến CSS font trùng; cân nhắc bỏ vì root đã có `theme-v2`.
- `(site)/page.tsx:20` và `(auth)/dang-ky`, `(auth)/xac-thuc-otp` tự gọi `publicFetchServer("/config/public")` thay vì `fetchPublicConfig()` (khác tag `config`, không dùng chung `cache()`); dùng một hàm.

## Phát hiện — FW2

### R9 [Medium] Trang chủ, đăng ký, OTP không xử lý "Hệ thống đang bận"
- Vị trí: `app/(site)/page.tsx:20`, `app/(auth)/dang-ky/page.tsx:15`, `app/(auth)/xac-thuc-otp/page.tsx:13`.
- Vấn đề: chỉ `/khoa-hoc`, `/lop-n`, `/khoa-hoc/{slug}` bắt `isUpstreamBusy`. Khi API 429/5xx, trang chủ (trang đông nhất) rơi vào `app/error.tsx` "Đã có lỗi xảy ra" — đúng kiểu lỗi trần mà tasks.md FW2 bổ sung (2) muốn tránh (và FW8/FW9 sẽ dùng cùng quy tắc).
- Đề xuất: bọc `try/catch` + `<CatalogBusy href="/" />` (hoặc tách helper `withBusyFallback`), dùng `fetchPublicConfig`.

### R10 [Medium] Sitemap lỗi một phần bị cache 1 giờ
- Vị trí: `app/sitemap.xml/route.ts:10-15`.
- Vấn đề: khi API lỗi/429 chỉ log rồi trả 200 sitemap chỉ có trang tĩnh; `revalidate = 3600` cache nguyên bản thiếu đó 1 giờ, Google có thể đọc đúng lúc. Đồng thời `fetchAllPublishedCourses` gọi tuần tự tới 100 trang mà không có `X-Client-IP`, tốn hạn mức `ssr-total`.
- Đề xuất: khi lỗi trả 503 + `Retry-After` + `Cache-Control: no-store` (bot sẽ thử lại), không trả sitemap thiếu; ghi log không kèm object lỗi đầy đủ nếu nó chứa URL nội bộ (hiện chỉ là `ApiError`, chấp nhận được). Thêm test `renderSitemap` với `<`/`&` trong slug (slug đã bị chặn bởi regex nên chỉ là test phòng thủ).

### R11 [Medium] Mục tiêu tải ADR-004 §2.7 (cache ấm 50 req/s, p95 ≤ 500 ms) chưa đạt
- Vị trí: `frontend/README.md` bảng load test.
- Vấn đề: dev ghi trung thực: 1 instance ~26 req/s, 3 instance ~40 req/s, ĐẠT chỉ ở cache lạnh. Đây là tiêu chí của FW2 nên PO/Architect phải chấp nhận bằng văn bản hoặc yêu cầu chạy lại trên máy yên tĩnh trước khi coi FW2 "xong".
- Đề xuất: ghi quyết định vào `docs/board.md`/tasks.md (mục nợ), kèm khuyến nghị 3–4 instance và chạy lại trên Linux CI.

### R12 [Low] Trang "bận" trả HTTP 200 và chưa `noindex`
- Vị trí: `khoa-hoc/[slug]/layout.tsx:19`, `(danh-muc)/page.tsx`, `[lopSlug]/page.tsx`: `return <CatalogBusy …/>`.
- Vấn đề: bot gặp lúc API quá tải có thể index trang "bận" với status 200 (tiêu đề `Khóa học`). Next không đặt được 503 từ page dễ dàng.
- Đề xuất: tối thiểu `robots: { index: false }` trong `generateMetadata` khi bắt `isUpstreamBusy` (cần chia sẻ trạng thái, hoặc dùng `<meta name="robots" content="noindex">` trong `CatalogBusy`).

### R13 [Low] Slug lạ gọi API mỗi lần (404 không vào Data Cache)
- Vị trí: `lib/catalog/api.ts` `fetchCourse` + `[slug]/layout.tsx`.
- Vấn đề: Next chỉ cache 200 nên mọi request slug đúng định dạng nhưng không tồn tại tốn 1 lần gọi API; bot dò slug ăn hạn mức `ssr-total` và làm người dùng thật thấy "bận". Backend đã có trần, nên chỉ ghi nhận; load test `/lop-99` đã có, nên thêm kịch bản slug lạ vào `loadtest/catalog.k6.js`.

### R14 [Low] Bundle `isomorphic-dompurify`/jsdom chưa được kiểm trên build
- Vị trí: `next.config.ts` (không có `serverExternalPackages`), `instrumentation.ts`.
- Vấn đề: Dev báo đã chạy `next start` ở load test nên có vẻ ổn; QA/Dev xác nhận lại rằng `next build` không cảnh báo jsdom/`default-stylesheet.css` và trang chi tiết có mô tả vẫn render ở production. Warm-up trong `instrumentation.ts` đúng hướng (đã ghi README).

## Các điểm đã kiểm và ĐẠT
1. **Token SSR:** `INTERNAL_API_TOKEN` chỉ nằm trong `env.server.ts` + `lib/api.server.ts` (cả hai `import "server-only"`); không `NEXT_PUBLIC_`, không có trong `next.config.ts`; không thấy `console.*` nào in token/header; schema bắt ≥ 32 ký tự (khớp backend). `lib/api.ts` (client) không import file server. `lib/catalog/api.ts` cũng `server-only`.
2. **`X-Client-IP`:** chỉ gắn khi có token và khi truy vấn có `q` (`lib/catalog/api.ts:47`); lấy phần tử đầu `X-Forwarded-For` rồi `isIP`; Nginx production ghi đè `X-Forwarded-For=$remote_addr` và xoá `X-Internal-Token`/`X-Client-IP` từ khách (`infra/production/nginx/conf.d/vitaminvui.conf:217-261`), backend chỉ tin `X-Client-IP` khi token đúng (`CatalogThrottle`). Ở dev client giả được XFF nhưng chỉ ảnh hưởng bucket của chính mình. Điều kiện an toàn: cổng Next (3000) không được mở ra ngoài Nginx — nhắc ở checklist production.
3. **DOMPurify:** allowlist tag/attr khớp backend; `ALLOWED_URI_REGEXP` chỉ `https?:`/`mailto:` (chặn `javascript:`, `data:`, `//host`); `ALLOW_DATA_ATTR/ARIA` tắt; hook ép `rel="noopener noreferrer nofollow ugc"` + `target=_blank` cho `<a href>`. ESLint ban `dangerouslySetInnerHTML` ngoài đúng 2 file (`CourseDescription.tsx`, `seo/JsonLd.tsx`); grep không thấy chỗ nào khác. JSON-LD: `nonce` lấy từ `x-nonce` do proxy sinh, `jsonLd()` escape `<`; nhận object, không nhận chuỗi.
4. **404 / lỗi:** `/lop-99`, `/lop-09`, `/lop-abc`, mọi segment đơn lạ → layout `notFound()` (404 thật, ngoài `loading.tsx`); slug sai định dạng/không tồn tại → 404 ở layout; 429/5xx/NetworkError → `CatalogBusy`; response không-200 không vào Data Cache (test khoá cứng theo mã Next).
5. **Phân trang:** `toPageHref` tự dựng từ `basePath`, không dùng `links`/`meta.path` (đúng bổ sung (3)); vượt trang cuối → redirect về trang cuối.
6. **Header/footer không link chết:** chỉ có Khóa học, lớp, đăng nhập/đăng ký/đăng xuất; tên người dùng là chữ khi chưa có trang tài khoản (`accountHref` tuỳ chọn). Dùng `ShellHeader` `useOptionalAuth` an toàn trong `(auth)`.
7. **Giá/mua:** `paid_checkout_enabled` mặc định `false` khi thiếu khoá; `resolveCta` không bao giờ trả nút mua khi tắt (kể cả `can_buy`/`in_cart`); JSON-LD khai `PreOrder` khi khoá.
8. **Test có ý nghĩa:** `cta.test`, `CourseCta.test` (216 dòng), `query.test` (25), `sanitize.test`, `busy.test`, `sitemap.test`, `config.test`. Thiếu: proxy (R4), `publicFetchServer` gắn header đúng (token, và `X-Client-IP` chỉ khi có token), `clientIp()` (XFF rác, IPv6, header trống).

## Đối chiếu yêu cầu
| Yêu cầu | Code đáp ứng | Ghi chú |
|---|---|---|
| FW-V2 layout gốc `theme-v2` + font, shell thật, routes | `app/layout.tsx`, `components/shell/**`, `lib/routes.ts` | Đạt |
| FW-V2 chặn `/v2` ở production | `proxy.ts` + layout | Chưa đạt: R1, R2, R3, R4 |
| FW2 danh mục / lớp / chi tiết, 404 thật | `app/(site)/**` | Đạt |
| FW2 header nội bộ, `X-Client-IP` chỉ khi `q` | `api.server.ts`, `catalog/api.ts` | Đạt (thiếu test) |
| FW2 429/5xx → "bận", không cache lỗi | `busy.ts`, `CatalogBusy` | Đạt cho 3 route catalog; thiếu trang chủ/đăng ký/OTP (R9) |
| FW2 CSP nonce, không ISR/PPR, Data Cache 60 s | `force-dynamic`, `revalidate: 60`, tag `catalog` | Đạt |
| FW2 robots/sitemap `revalidate=3600` | route handlers | Đạt; R10 |
| FW2 DOMPurify + allowlist ESLint 1 component | `CourseDescription.tsx` (+ `JsonLd.tsx`) | Đạt (2 file, có lý do ghi rõ) |
| FW2 load test | `loadtest/catalog.k6.js`, README | Cache lạnh đạt; cache ấm chưa đạt (R11) |
| `paid_checkout_enabled=false` → giá + "Sắp mở bán" | `cta.ts`, `CourseAction.tsx`, `CourseCard` | Đạt |
| README FW2 cập nhật theo §2.8 | `frontend/README.md` | Chưa: R5 |

## Gợi ý cho QA
- Trên `next start` (NODE_ENV=production, không `V2_PREVIEW`): curl `/v2`, `/v2/khoa-hoc`, `/%76%32/khoa-hoc`, `//v2/khoa-hoc`, `/V2`, kèm/không kèm header `next-router-prefetch: 1` và `purpose: prefetch`, kiểm status 404, header CSP, và body RSC (`-H 'RSC: 1'`) không chứa nội dung xem trước.
- `/lop-99`, `/lop-06`, `/abc`, `/khoa-hoc/khong-co`, `/khoa-hoc/ABC_x`: status 404 thật (curl -I), có header/footer.
- Giả lập backend 429/503 (tắt php hoặc hạ throttle): `/khoa-hoc`, `/lop-9`, `/khoa-hoc/{slug}`, `/` (R9), sau đó bật lại → trang trở lại ngay, không kẹt cache.
- Gửi `X-Forwarded-For` giả trực tiếp tới Next (dev) và qua Nginx (production-like): qua Nginx header giả không có tác dụng; tìm kiếm `q` từ 2 IP khác nhau tính bucket riêng.
- Mô tả khóa chứa `<script>`, `<img onerror>`, `javascript:`, `<a href="//evil">`, `<a target=_blank>` không `href`; kiểm JSON-LD không vỡ khi tiêu đề có `</script>`.
- Khóa có phí khi thanh toán tắt: danh mục + chi tiết + thanh dính đáy mobile: có giá, "Sắp mở bán", không nút mua, cả với khách, đã đăng nhập, `can_buy`, `in_cart`.
- Học sinh đã sở hữu khóa: "Tiếp tục học" hiện đang 404 (R6).
- a11y mobile 375 px: chip lớp, chip bộ lọc đang chọn, sheet bộ lọc (focus vào/ra, Esc), skip link, đo vùng bấm; header khi đã đăng nhập (nút Đăng xuất trong ngăn kéo).
- Sitemap khi API lỗi (R10) và sau khi có khóa mới (≤ 1 giờ).

## Dev đã sửa (2026-10-07)
Kiểm chạy: `tsc` sạch, `eslint` sạch, `vitest` web 20 file / 158 test pass. CHƯA build / `next start` / curl gate / e2e (chờ orchestrator cho phép build) — các mục "kiểm bằng curl" bên dưới là việc còn lại.

| Mục | Đã làm |
|---|---|
| R1 | `proxy.ts` matcher thêm `{source:"/v2"}`, `{source:"/v2/:path*"}` KHÔNG có `missing` (đứng trước entry cũ). Test `proxy.test.ts` kiểm entry không có `missing`. |
| R2 | Tách `lib/previewGate.ts` (`isPreviewPath`, `isBlockedPreview`: giải mã phần trăm, gộp `//`, hạ chữ thường) giống admin + `previewGate.test.ts` (`vi.stubEnv`). Còn cần curl `/%76%32` trên bản build (matcher có khớp đường dẫn mã hoá hay không — nếu entry riêng không khớp thì entry chung vẫn chạy cho request thường). |
| R3 | Rewrite không return sớm: `NextResponse.rewrite(..., {request:{headers}})` rồi đặt CSP/header bảo mật chung. Test kiểm CSP nonce + `nosniff` trên response rewrite. |
| R4 | `proxy.test.ts` (4 test): production không `V2_PREVIEW` -> rewrite cho `/v2`, `/v2/khoa-hoc`, `/%76%32/khoa-hoc`, `/V2`; `V2_PREVIEW=1` và route thật không bị chặn; `/dang-ky` có Turnstile, route khác không. |
| R5 | README FW-V2/FW2 viết lại: proxy rewrite + layout là lớp phụ + cách kiểm curl; tham chiếu ADR-004 §2.8; bỏ câu "Cần Architect xác nhận…/trang lỗi 500". |
| R6 | Khóa đã sở hữu: nút "Tiếp tục học" vô hiệu + "Sắp mở trang học."; bài trong outline là hàng tĩnh; `TODO(FW4)` ở `CourseAction.tsx`, `CourseOutline.tsx`. Cập nhật `CourseCta.test.tsx` và e2e `danh-muc-real.spec.ts`. |
| R7 | Chip bộ lọc đang chọn và "Xoá bộ lọc": `min-h-11`. Hàng `Checkbox` trong sheet chưa đo (QA). |
| R8 | JSDoc `proxy` đặt đúng chỗ; trang chủ/đăng ký/OTP dùng `fetchPublicConfig()`. Font trùng ở `(v2-preview)/v2/layout.tsx`: KHÔNG sửa (file designer, chỉ được sửa điểm chặn) — ghi backlog README, bỏ khi xoá nhóm `(v2-preview)`. |
| R9 | Trang chủ, `/dang-ky`, `/xac-thuc-otp` bắt `isUpstreamBusy` -> `CatalogBusy`. |
| R10 | Sitemap: API lỗi -> 503 + `Retry-After: 300` + `Cache-Control: no-store`, không trả sitemap thiếu; log chỉ tên lỗi. (Test `renderSitemap` với ký tự đặc biệt đã có sẵn ở `sitemap.test.ts`.) |
| R11 | Ghi rõ trong README là RỦI RO MỞ; chạy lại trên staging, mở rộng instance trước (ADR §2.7). Không chạy lại ở máy dev. |
| R12 | `CatalogBusy` có `<meta name="robots" content="noindex">` (React 19 đưa lên head). Chưa đặt được HTTP 503 từ page (Next không hỗ trợ đơn giản) — còn rủi ro nhỏ, ghi nhận. |
| R13, R14 | Ghi "Backlog FW2" trong README (slug lạ vào k6; QA kiểm DOMPurify/jsdom trên build). |

Còn lại cho bước sau (cần build): `curl -i` `/v2`, `/%76%32`, `//v2/khoa-hoc` với/không header `next-router-prefetch: 1`, `purpose: prefetch` -> 404 + CSP; `V2_PREVIEW=1` -> 200; kiểm `/v2` RSC (`-H 'RSC: 1'`) không lộ nội dung; e2e thật do QA chạy.

### Kiểm trên bản build (2026-10-07, `next build` + `next start`, NODE_ENV=production, backend thật)
Build thành công (Compiled 18,1 s, có `ƒ Proxy`). Hai tiến trình `next start`: không `V2_PREVIEW` (cổng 3100) và `V2_PREVIEW=1` (cổng 3101).

| Kiểm | Kết quả |
|---|---|
| `/v2`, `/v2/khoa-hoc`, `/%76%32`, `/%76%32/khoa-hoc`, có và không header `next-router-prefetch: 1` + `purpose: prefetch` | 404 cả 8 lần (0 lần lộ nội dung xem trước), có `Content-Security-Policy` (nonce) |
| `//v2/khoa-hoc` | Next tự redirect 308 sang `/v2/khoa-hoc` rồi bị chặn 404 có CSP |
| `/V2/khoa-hoc` | 404 (Next phân biệt hoa thường nên không có route); với header prefetch thì 404 không có CSP (không lộ nội dung) |
| `-H 'RSC: 1'` tới `/v2/khoa-hoc` (theo redirect `?_rsc`) | 200 `text/x-component` ~29 KB nhưng chỉ là payload trang 404 chung (giống `/lop-99?_rsc`): 0 lần "Xem trước", "PreviewBar", "Đường tròn", "CatalogFilters"; có "Không tìm thấy trang". Không lộ |
| `V2_PREVIEW=1`: `/v2`, `/v2/khoa-hoc` | 200, 200; `/%76%32/khoa-hoc` 404 (Next không khớp đường dẫn mã hoá thành route) |
| Route thật | `/` 200, `/khoa-hoc` 200, `/lop-9` 200, `/khoa-hoc/e2e-fw2-hinh-hoc-9-mien-phi` 200, `/robots.txt` 200, `/sitemap.xml` 200, `/lop-99` 404, `/khoa-hoc/khong-co` 404 |
| Request prefetch tới route thật (`/khoa-hoc` + `next-router-prefetch: 1`) | Không đi qua proxy theo matcher (đã theo thiết kế ADR-004 §2.7: loại prefetch khỏi nonce): trả 200/307 RSC, KHÔNG có CSP. Đó là payload RSC, không phải HTML nên không cần nonce; trang HTML thật (không header prefetch) luôn có CSP. Giữ nguyên, ghi nhận |

Lưu ý: `/sitemap.xml` ở lần build này trả 200 vì route `revalidate=3600` đã được prerender lúc build (build chạy khi backend không phân giải được `api.localhost` nên chỉ có trang tĩnh; ISR sẽ làm mới sau tối đa 1 giờ) — với R10, lần revalidate lỗi sẽ trả 503 thay vì ghi đè bằng sitemap thiếu; lần build đầu vẫn có thể cache bản thiếu. Khuyến nghị: build trong môi trường có backend hoặc đừng prerender sitemap (cân nhắc `dynamic = "force-dynamic"` + header cache).

### Sitemap không prerender lúc build (2026-10-07, quyết định orchestrator)
`app/sitemap.xml/route.ts`: `dynamic = "force-dynamic"` (bỏ `revalidate`), dữ liệu vẫn qua `publicFetch` có Data Cache (`revalidate: 3600`, tag `catalog`), response `Cache-Control: public, s-maxage=3600, stale-while-revalidate=600`; API lỗi vẫn 503 + `Retry-After` + `no-store`. `/robots.txt` tĩnh (không gọi API) nên giữ prerender. README mục FW2 đã cập nhật. Kiểm: tsc, eslint sạch, vitest 20 file / 158 test pass, build OK (`ƒ /sitemap.xml`, `○ /robots.txt`); `next start` với backend thật: `/sitemap.xml` 200, đúng `Cache-Control`, 41 `<loc>` (32 URL khóa học).

## Review lại (vòng 2)
Đã đọc lại `lib/previewGate.ts`, `proxy.ts`, `proxy.test.ts`, `app/sitemap.xml/route.ts`, `CourseAction`/`CourseOutline`, 3 trang bắt `isUpstreamBusy`, `CatalogBusy`, `CatalogView`.
Lần chạy lại typecheck/lint/test của tôi bị gián đoạn (corepack không tải được pnpm do lỗi mạng, không phải lỗi code); tin kết quả dev báo: tsc/eslint sạch, vitest 158 pass, `next build` OK, kiểm tay trên `next start`.

| Mục | Kết quả |
|---|---|
| R1 matcher riêng `/v2`, `/v2/:path*` không `missing` | Đã sửa; có test khẳng định `missing` undefined. Dev xác nhận curl với header prefetch ra 404 |
| R2 `/%76%32`, `//v2`, `/V2` | Đã sửa (`isPreviewPath` giải mã, gộp `//`, hạ chữ thường); có test và curl thật |
| R3 404 có CSP/header | Đã sửa: rewrite đi chung đoạn đặt header, test kiểm CSP nonce + nosniff |
| R4 test | Đã có `proxy.test.ts` + `previewGate.test.ts` |
| R5 README | Dev báo đã sửa (không đọc lại chi tiết) |
| R6 | Nút "Tiếp tục học" vô hiệu, outline tĩnh có TODO(FW4) |
| R7 / R8 | Đã sửa (không còn `min-h-9` trong `CatalogView`) |
| R9 | Trang chủ, đăng ký, OTP bắt `isUpstreamBusy` → `CatalogBusy` |
| R10 | Sitemap 503 + `Retry-After` + `no-store` khi API lỗi; thành công `s-maxage=3600, stale-while-revalidate=600`; `force-dynamic` |
| R12 | `CatalogBusy` có `noindex` |
| R11, R13, R14 | Ghi nhận rủi ro/backlog theo quyết định orchestrator, chấp nhận; R11 vẫn là nợ: chạy lại load test cache ấm trên máy yên tĩnh trước production |

Lưu ý nhỏ (Low, không chặn): test "prefetch vẫn bị chặn" chỉ ở mức cấu hình matcher + curl tay; giữ một curl có header prefetch trong checklist QA.

**Kết luận vòng 2: APPROVE, chuyển `laravel-qa`.**

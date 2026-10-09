# REVIEW: GL-A2-FE (widget Turnstile ở đăng nhập web + admin) — đóng S2 của security GL-A2

## Dev (nextjs-dev)
### Hành vi
- Web `/dang-nhap` và admin `/dang-nhap`: khi 422 có `captcha_required=true` hoặc code `CAPTCHA_REQUIRED`/`CAPTCHA_INVALID` thì hiện `TurnstileWidget` (dùng lại component trong `packages/ui`), nút Đăng nhập khoá tới khi có token, gửi `captcha_token` ở lần sau. Sau MỖI lần gửi (thành công hay lỗi) widget được mount mới (đổi `key`) và token về null. Token không lưu storage. Cờ sticky tới khi đăng nhập thành công.
- 422 do trần IP đầy cũng là `CAPTCHA_REQUIRED` + `captcha_required:true` nên đi cùng đường, không cần xử lý riêng.
- Thông điệp VN: CAPTCHA_REQUIRED, CAPTCHA_INVALID, 422 kèm cờ (gợi ý xác minh), 429 "Vui lòng thử lại sau N giây/phút/giờ" theo Retry-After. Bị đòi captcha mà thiếu site key: báo "Chưa thể xác minh chống spam lúc này". Widget lỗi (CSP cũ, mạng): nút "tải lại trang".
- Admin: captcha + mật khẩu đúng thì sang `/xac-thuc-mfa` như cũ.
- `packages/api-client`: `ApiError.captchaRequired`, `ApiErrorBody.captcha_required`, hàm `loginNeedsCaptcha(err)`.
### Site key và CSP
- Web: site key từ `/config/public` (`captcha_site_key`), fallback `NEXT_PUBLIC_TURNSTILE_SITE_KEY`; lỗi lấy config không làm hỏng trang đăng nhập. `/dang-nhap` thêm vào `CAPTCHA_PATHS` (proxy cấp CSP Cloudflare; link tới trang này thành điều hướng cứng qua `AppLink`).
- Admin: biến MỚI `NEXT_PUBLIC_TURNSTILE_SITE_KEY` (ghi vào `apps/admin/.env.example`, khoá thử Cloudflare cho local; `env.ts` thêm field tuỳ chọn). Proxy admin: chỉ path `/dang-nhap` thêm `https://challenges.cloudflare.com` vào connect-src và frame-src (script nạp nhờ strict-dynamic); còn lại giữ `frame-src 'none'`.
- CHECKLIST DEPLOY: admin production PHẢI đặt `NEXT_PUBLIC_TURNSTILE_SITE_KEY` (khoá thật, trùng `TURNSTILE_SITE_KEY` backend) lúc BUILD; thiếu thì người bị đòi captcha không đăng nhập được. Release cùng backend GL-A2.
### File
Sửa: `apps/web/components/auth/LoginForm.tsx`, `apps/web/app/(auth)/dang-nhap/page.tsx` (nội dung file có sẵn), `apps/web/lib/auth/api.ts`, `apps/web/lib/routes.ts`, `apps/admin/components/auth/LoginForm.tsx`, `apps/admin/lib/auth/{api,errors}.ts`, `apps/admin/{env,proxy}.ts`, `apps/admin/.env.example`, `packages/api-client/src/{errors,index,types}.ts`.
Test: `LoginForm.test.tsx` (web, admin), `AppLink.test.tsx`, `proxy.test.ts`, `lib/auth/logic.test.ts` (admin, 429 đổi theo Retry-After), `packages/api-client/src/loginCaptcha.test.ts`.
E2E: `apps/web/e2e/{gla2-real.spec.ts,run-gla2-real.sh,seed-e2e-gla2.sh}`, `apps/web/playwright.gla2.config.ts`, `apps/admin/e2e/gla2-real.spec.ts` (chạy bằng `admin/e2e/run-real.sh`). Seed `--reset|--limiter|--clean` (học sinh `gla2-hs-1@example.com`, admin `e2e-gla2-admin1@example.com`; chỉ xoá khoá limiter `login-fail:u:<id>` của 2 tài khoản này). Đã `--clean` xong.
### Kết quả
- lint web + admin sạch; `tsc --noEmit` web + admin sạch; vitest: api-client 41+, admin 586/586, web 593/593.
- e2e thật (backend `CAPTCHA_DRIVER=fake`, khoá thử Cloudflare): web 2/2 (5 lần sai -> `captcha_required` ở lần 5 -> widget -> lần có token -> token đổi mỗi lần -> thành công; token `invalid` ép bằng route -> CAPTCHA_INVALID + thông điệp riêng -> token mới -> 200), admin 1/1 (tới `/xac-thuc-mfa`). Khoá thử không có iframe nên spec chờ input `cf-turnstile-response`.
### Lưu ý
- Admin dev server (:3001) khởi động không có biến site key; để chạy e2e admin tôi tạo TẠM `apps/admin/.env.development.local` (khoá thử công khai), dev server tự nạp lại, rồi đã XOÁ. Muốn widget hiện ở dev admin: thêm `NEXT_PUBLIC_TURNSTILE_SITE_KEY` vào `.env.local` và khởi động lại (tôi không sửa `.env.local`). Hiện admin dev KHÔNG hiện widget (sẽ báo "Chưa thể xác minh...").
- `pnpm typecheck` của tôi chạy `next typegen` một lần (ghi vào `.next` types) trước khi biết quy tắc; các lần sau dùng `tsc --noEmit` trực tiếp.
- Điều hướng mềm (router.push/replace) tới `/dang-nhap` từ chỗ khác (vd AuthGate admin) giữ CSP trang trước nên iframe Turnstile bị chặn; xử lý bằng nút "tải lại trang" khi widget lỗi. Admin có thể cân nhắc đổi redirect về login thành điều hướng cứng.
- 429 chưa e2e thật (cần 100 lần sai); phủ bằng vitest.
### QA nên kiểm kỹ
Turnstile thật (khoá thật, hostname), widget lỗi/CSP cũ sau điều hướng mềm, đổi tab/hết hạn token (expired-callback khoá lại nút), 375px, bộ đếm sau đăng nhập đúng, admin có captcha rồi MFA, deploy admin có biến site key.

---
# Review (laravel-reviewer)
**Kết luận:** REQUEST CHANGES (1 SHOULD bắt buộc sửa trước QA; không BLOCKER)
**Phạm vi:** diff chưa commit phần frontend (web, admin, api-client) + e2e/seed; không xét backend.

## Tổng quan
Logic widget đúng: key đổi sau mỗi lần gửi (cả lỗi), token về null, nút khoá tới khi có token, `expired-callback` -> `onToken(null)` khoá lại nút, token chỉ nằm trong state (không storage/log). CSP admin chỉ mở Cloudflare ở đúng `/dang-nhap` (connect-src + frame-src; script nhờ strict-dynamic, đúng với cách TurnstileWidget chèn script từ code có nonce); các trang khác giữ `frame-src 'none'`. Thông điệp VN ổn, 422 luôn cùng một câu cho mọi tài khoản (cờ `captcha_required` có ở mọi 422) nên không lộ tài khoản tồn tại.

## Phát hiện
### R1 [SHOULD - sửa trước QA] Điều hướng mềm tới /dang-nhap giữ CSP cũ -> Turnstile bị chặn, người bị đòi captcha có thể kẹt
- Vị trí admin (toàn bộ là soft nav): `apps/admin/components/shell/AuthGate.tsx:28`, `SessionWatcher.tsx:~44` (hết phiên/idle), `shell/LogoutButton.tsx:18`, `auth/MfaForm.tsx:80,119`, `auth/ForcePasswordChangeForm.tsx:27,62`. Web: `shell/SessionEndedGate.tsx:65` đã dùng `window.location.assign` (đúng), nhưng `my/RequireUser.tsx:18`, `auth/AccountGateRoute.tsx:24`, `account/AccountView.tsx:26`, `account/ChangePasswordForm.tsx:59`, `privacy/PrivacyDataView.tsx:22`, `auth/OtpVerifyForm.tsx:56`, `auth/LogoutButton.tsx:21`, `catalog/CourseCtaProvider.tsx:143` vẫn `router.push/replace`; `v2/*` dùng `Link` trần (preview, bỏ qua).
- Mức độ: admin là đường vào login phổ biến nhất (hết phiên/idle -> AuthGate/SessionWatcher soft nav). Nhân viên bị đá về login, gõ sai mật khẩu vài lần (hoặc IP chung văn phòng chạm trần) -> bị đòi captcha -> iframe bị `frame-src 'none'`; nút đăng nhập khoá. Chỉ thoát khi widget bắn `error-callback` (đến sau timeout của Cloudflare, chưa có e2e xác nhận) hoặc người dùng tự F5. Hồi phục được nên không phải BLOCKER, nhưng là đường chính chứ không hiếm. Web nhẹ hơn (đi từ trang đã bị đá thường qua SessionEndedGate, hard nav) nhưng các gate khác vẫn soft.
- Đề xuất (ưu tiên 1 điểm sửa gốc, tránh sửa ~12 chỗ): trong cả hai `LoginForm`, khi `loginNeedsCaptcha` và trang này được tới bằng điều hướng mềm thì tải lại tài liệu, chỉ giữ query:
  ~~~ts
  // CSP gắn lúc tải tài liệu: nếu đã soft-nav tới đây thì tài liệu gốc không phải /dang-nhap
  const nav = performance.getEntriesByType("navigation")[0] as PerformanceNavigationTiming | undefined;
  const stale = nav ? new URL(nav.name).pathname !== window.location.pathname : false;
  if (loginNeedsCaptcha(err) && stale) { window.location.reload(); return; } // hoặc hiện nút "tải lại trang" ngay, không chờ error-callback
  ~~~
  (reload làm mất banner "sai nhiều lần" nhưng bộ đếm ở server nên lần sau lên captcha ngay; có thể gắn `?captcha=1` hoặc sessionStorage cờ không chứa token để hiện ngay widget). Bổ sung (không thay thế): đổi `LogoutButton`/AuthGate/SessionWatcher admin sang `window.location.assign` như `SessionEndedGate` web, và thêm timeout (~10 s) cho widget không bắn callback thì hiện alert "tải lại trang". Thêm test cho nhánh stale.
- `AppLink` -> `<a>` cho `/dang-nhap`: chấp nhận được. Mất prefetch/transition và 1 lần tải tài liệu (login vốn `force-dynamic`, tải nhỏ, trang nhạy cảm không cần prefetch); `AppLink` đã làm vậy cho register/quên mật khẩu nên nhất quán. Lưu ý chỉ phủ link đi qua `AppLink`/`UiLinkProvider` của web; `Link` trần (RegisterForm.tsx:348, `components/auth/RegisterForm`) đi từ trang cũng có CSP Turnstile nên vô hại, các `router.*` ở R1 mới là lỗ hổng. Hệ quả phụ: logout web (`router.replace("/dang-nhap")`) cũng bị CSP cũ.

### R2 [SHOULD] `.env.example` admin đặt sẵn khoá thử Cloudflare
- `apps/admin/.env.example:15` `NEXT_PUBLIC_TURNSTILE_SITE_KEY=1x00000000000000000000AA`. Copy nguyên sang môi trường thật thì token sinh ra bị secret thật từ chối -> mọi người bị đòi captcha không đăng nhập được (không phải bypass, nhưng lỗi triển khai khó thấy; web để trống). Để trống + comment, và đưa khoá thử vào ghi chú/`.env.development`. Checklist deploy của Dev đã có, nên ghi thêm vào `docs/architecture`/runbook release.

### R3 [NIT] `captchaBroken` không tự xoá khi widget tự thử lại thành công
- Hai `LoginForm`: `onToken` nhận token hợp lệ sau khi từng `error-callback` thì alert "Không tải được..." vẫn hiện cạnh widget đã xong. Gọi `setCaptchaBroken(false)` khi nhận token khác null.

### R4 [NIT] a11y
- Câu "Vui lòng hoàn tất xác minh..." và widget chưa là vùng `aria-live`/gắn `aria-describedby` cho nút bị `disabled`; người dùng đọc màn hình không biết vì sao nút khoá. Gợi ý `<p role="status">` hoặc `aria-describedby` trỏ câu hướng dẫn. Admin `Alert` "tải lại trang" thiếu `role="alert"` (web có). `<a href="">` dùng như nút: nên là `<button type="button" class="underline">`. Link "tải lại trang" của admin thiếu `focus-ring`.

### R5 [NIT] Định dạng
- `apps/admin/lib/auth/api.ts` và `apps/web/app/(auth)/dang-nhap/page.tsx` (prop `next=` cùng dòng `notice=`) xuống dòng/thụt không theo Prettier-style của file; lint không bắt nên chỉ nhắc.

## Đối chiếu trọng tâm
| Yêu cầu | Kết quả |
|---|---|
| Token mới mỗi lần gửi | Đạt: `captchaKey+1` và `captchaToken=null` trong catch; thành công thì rời trang |
| Nút khoá đúng | Đạt: `disabled={showWidget && token===null}`; Enter không submit khi nút disabled; chưa có site key -> không khoá, không gửi token, hiện cảnh báo (đúng chủ ý) |
| expired-callback | Đạt: `onToken(null)` trong TurnstileWidget -> khoá lại |
| Không lưu token | Đạt (chỉ useState; `loginStudent/Staff` chỉ đặt trong body) |
| CSP admin | Đủ connect-src + frame-src ở `/dang-nhap`; script nhờ strict-dynamic; không nới trang khác. Khớp tuyệt đối `pathname === "/dang-nhap"`: đủ vì Next chuẩn hoá slash cuối. Cần QA xác nhận Turnstile thật (khoá thật) không đòi thêm `style-src`/`img-src` |
| Soft nav | Xem R1 |
| 429 Retry-After | Đạt (giây/phút/giờ, có fallback khi thiếu header); chưa e2e, có vitest |
| Tiếng Việt | Đạt |
| Không lộ tài khoản | Đạt: cùng 1 thông điệp mọi 422; cờ không phụ thuộc tồn tại user (theo contract §1.6) |
| Web lấy site key | `/config/public` cache 60 s, lỗi thì fallback env, không làm hỏng trang: đạt |

## Kết quả chạy
- ESLint web + admin: sạch.
- `tsc --noEmit`: web sạch. Admin: 45 lỗi, TẤT CẢ nằm trong `.next/dev/types/routes.d.ts` (file sinh bởi dev server đang chạy ghi dở, `';' expected`), không có lỗi trong mã nguồn; không phải do thay đổi này. Nên chạy lại khi dev server dừng/ghi xong.
- vitest api-client: 6 file, 41/41 pass. vitest web và admin: máy đang quá tải (load average >240 do nhiều tiến trình song song) nên không chạy xong trong giới hạn; tôi không có kết quả độc lập, chỉ dựa số liệu Dev (web 593/593, admin 586/586). QA/Dev chạy lại các file `LoginForm.test.tsx`, `AppLink.test.tsx`, `proxy.test.ts`, `logic.test.ts` khi máy rảnh.

## Gợi ý cho QA
- Điều hướng mềm: để phiên hết hạn trên admin (AuthGate) rồi sai mật khẩu >= ngưỡng; kiểm widget hiện/được nhắc tải lại. Tương tự web: đăng xuất bằng nút (router.replace) rồi sai mật khẩu nhiều lần.
- Khoá thật: hostname, token hết hạn sau khi chờ 5 phút (nút khoá lại), đổi tab, 375px, widget ở chế độ Managed khi cần tương tác.
- Admin: captcha -> MFA; production build có/không `NEXT_PUBLIC_TURNSTILE_SITE_KEY`; header CSP của một trang admin khác không chứa Cloudflare.
- Cuộn/bàn phím: Tab từ trường mật khẩu tới widget tới nút; đọc màn hình.

---

## Sửa sau review (nextjs-dev)
- **R1:** thêm `isStaleDocument()` ở `packages/api-client` (so `navigation[0].name` pathname với `location.pathname`). Cả hai `LoginForm`: khi `loginNeedsCaptcha(err)` và tài liệu stale thì lưu `sessionStorage["vv:gla2-login"]` (CHỈ định danh, không mật khẩu/token) rồi `window.location.reload()`; sau reload form khôi phục định danh, hiện luôn widget kèm thông điệp CAPTCHA_REQUIRED và xoá cờ. Admin `AuthGate`, `SessionWatcher` (login-required + idle) và `LogoutButton` đổi sang `window.location.assign` (điều hướng cứng; có eslint-disable có lý do). Widget không bắn callback sau 10 giây thì hiện nút "tải lại trang". `MfaForm`/`ForcePasswordChangeForm` và các `router.*` của web còn lại KHÔNG đổi (nhánh stale ở LoginForm đã bao trùm; xin ghi nhận là phần tồn đọng nếu muốn đổi hết).
- **R2:** `apps/admin/.env.example` để trống `NEXT_PUBLIC_TURNSTILE_SITE_KEY` kèm comment (khoá thử đặt trong `.env.local`). `docs/ops/production-checklist.md` §13b thêm 1 dòng GL-A2 (admin build với site key thật trùng `TURNSTILE_SITE_KEY` backend, release cùng BE).
- **R3:** `captchaBroken` tự xoá khi nhận token hợp lệ.
- **R4:** gợi ý khoá nút là `<p id="captcha-hint" role="status">`, nút khoá có `aria-describedby`; alert "tải lại trang" có `role="alert"` ở cả hai form; `<a href="">` thay bằng `<button type="button" class="focus-ring underline">`.
- **R5:** không sửa định dạng hai file đã nêu ngoài phần chạm vào (không có formatter bắt buộc).
- Test thêm: nhánh stale (reload + chỉ lưu định danh), khôi phục sau reload, không stale thì không reload, timeout 10 giây + token xoá cảnh báo + aria-describedby (web + admin); `LogoutButton` assign; `shell.test` đổi sang `assign`; `isStaleDocument` ở api-client.
- Kết quả: eslint sạch; `tsc --noEmit` không có lỗi mã nguồn (admin chỉ lỗi trong `.next/`); vitest chạy lại file lẻ: api-client 43/43, admin LoginForm+shell 40/40 (+shell 23/23), web LoginForm+shell+proxy 41/41; e2e thật chạy lại: web 2/2, admin 1/1; đã `--clean` seed.

---
# Review vòng 2 (laravel-reviewer)
**Kết luận:** APPROVE (0 BLOCKER, 0 SHOULD, 3 NIT tuỳ Dev)

## Kiểm các phát hiện cũ
- **R1 đã xử lý.**
  - `isStaleDocument()` so pathname của `navigation[0].name` với `location.pathname`. Thiếu Navigation Timing, `name` rỗng hoặc URL lỗi thì trả `false`, tức là không reload và rơi về nút "tải lại trang" sau 10 giây.
  - Không thể có vòng lặp reload vô hạn. Sau `location.reload()` tài liệu mới được tải đúng URL hiện tại nên `nav.name` trùng pathname và hàm trả `false`, lần 422 sau chỉ hiện widget. Cờ `vv:gla2-login` bị xoá ngay khi mount, trước khi làm việc khác. Nếu `setItem` lỗi thì chỉ mất việc khôi phục email, không ảnh hưởng reload. bfcache khôi phục nguyên trang nên không chạy lại logic, và entry navigation vẫn là của tài liệu đó. Reload chỉ do một lần 422 captcha kích hoạt, không do vòng đời nên không tự lặp. Trường hợp "tài liệu luôn bị coi là stale" chỉ xảy ra khi URL tài liệu khác pathname hiện tại sau reload, mà reload thì tải lại đúng URL hiện tại. Redirect phía server không gây vấn đề vì `name` là URL cuối.
  - Nhánh stale chỉ lưu định danh (không mật khẩu, không token). Khi khôi phục có `saved === ""` thì vẫn hiện widget. Chạy hai lần trong StrictMode dev vô hại (lần 2 đọc `null`).
  - Admin `AuthGate`, `SessionWatcher` (login-required và idle) và `LogoutButton` đã dùng `window.location.assign`. `LogoutButton` bỏ `router.refresh()`, hợp lý vì đã tải lại tài liệu.
  - Timeout 10 giây: effect phụ thuộc `captchaKey`/token/broken, có cleanup, và được đặt lại sau mỗi lần gửi. Đúng.
- **R2 đã xử lý.** `apps/admin/.env.example` để trống kèm comment. Checklist §13b dòng 322 và dòng 176 đã có GL-A2.
- **R3 đã xử lý.** `onCaptchaToken` xoá `captchaBroken` khi nhận token.
- **R4 đã xử lý.** Có `role="status"` + `aria-describedby`, `role="alert"` và `<button>` có `focus-ring`.
- **R5.** Đồng ý bỏ qua.

## Phần tồn đọng dev ghi (MfaForm, ForcePasswordChangeForm, router.* bên web)
Chấp nhận được cho go-live. Các đường này đều dẫn tới `/dang-nhap` bằng soft nav, nhưng nhánh stale ở `LoginForm` bao trùm bằng một lần reload (mất banner nhưng giữ email, rồi widget hiện ngay) và còn timeout 10 giây làm lưới an toàn. Hậu quả tệ nhất là một lần tải lại tự động hoặc bấm "tải lại trang", không ai bị kẹt. Nên ghi vào backlog là NIT-1, không chặn.

## NIT (tuỳ Dev)
- NIT-1: đổi nốt `router.*` tới `/dang-nhap` (Mfa/ForcePasswordChange admin, `RequireUser`, `LogoutButton`... web) sang điều hướng cứng để khỏi tốn một lần reload. Đã có tài liệu ở backlog.
- NIT-2: hai `LoginForm` trùng khá nhiều logic widget/timeout/restore. Cân nhắc rút thành hook `useLoginCaptcha` dùng chung. Không gấp.
- NIT-3: `apps/admin/components/auth/LoginForm.tsx` dòng chữ ký hàm và `<Button ...>` rất dài, chỉ là định dạng.

## Kết quả chạy (Docker `vitaminvui-frontend-dev`)
- ESLint web + admin: sạch.
- `tsc --noEmit`: web sạch. Admin chỉ có lỗi trong `.next/dev/types/routes.d.ts` (file sinh bởi dev server), 0 lỗi trong mã nguồn.
- vitest: api-client 43/43. Web (LoginForm, shell, proxy) 41/41. Admin (LoginForm, shell, lib/auth, proxy) 69/69.
- Toàn bộ vitest chạy lúc load đã xuống: web 597/597 (68 file), admin 591/591 (45 file).
- Không còn tiến trình nền do review này chạy (container `--rm`).

## Gợi ý cho QA
Giữ như vòng 1 và thêm hai điểm:
- Soft nav -> sai mật khẩu đến khi bị đòi captcha -> trang tự reload một lần, email còn, widget hiện, không reload lần nữa.
- Chặn `challenges.cloudflare.com` để kiểm timeout 10 giây hiện nút "tải lại trang".

---
# QA (laravel-qa)
**Kết quả:** PASS (0 bug Critical/Major; 2 ghi chú Minor/rủi ro, không chặn)

Môi trường: dev server web :3000, admin :3001, backend :8000 (`CAPTCHA_DRIVER=fake`), khoá thử Cloudflare (widget thật không có iframe, chỉ input `cf-turnstile-response`). Seed gla2 `--reset` rồi `--clean` sau cùng. Spec: `frontend/apps/web/e2e/qa-gla2-fe.spec.ts` (+ `frontend/apps/web/playwright.qa-gla2.config.ts`, chạy `run-gla2-real.sh --config playwright.qa-gla2.config.ts`) và `frontend/apps/admin/e2e/qa-gla2-fe.spec.ts` (chạy `admin/e2e/run-real.sh e2e/qa-gla2-fe.spec.ts`). Các ca cần điều khiển token/hết hạn dùng Turnstile giả (route thay script Cloudflare), ca khoá thật dùng widget thật.

## Kết quả chạy
- e2e web 11/11, e2e admin 11/11 (chạy lại toàn bộ sau khi sửa spec, đều xanh).
- vitest: api-client 43/43, admin 591/591 (45 file), web 597/597 (68 file). Load máy lúc chạy < 8.

## Độ phủ
| Yêu cầu | Test | Kết quả |
|---|---|---|
| Sai 5 lần -> widget hiện (không hiện ở lần 1-4), body 5 lần đầu không có captcha_token | AC thật + AC2 (web, admin) | PASS |
| Nút khoá tới khi có token; `aria-describedby=captcha-hint`, `role=status`; Enter khi khoá không gửi request | AC2 (web, admin) | PASS |
| Token mới mỗi lần gửi (widget mount lại, `__renders` tăng, nút khoá lại; token gửi đúng token vừa cấp) | AC2 | PASS |
| Token hết hạn (expired-callback) -> nút khoá lại, cấp lại thì mở | AC2 | PASS |
| CAPTCHA_INVALID (ép "invalid") có thông điệp riêng, khác thông điệp sai mật khẩu; token mới -> 422 sai thông tin | AC3 | PASS |
| Đúng mật khẩu + token thật -> web về `/`, admin về `/xac-thuc-mfa` | AC1/AC4 thật | PASS |
| Soft nav web (`/gio-hang` khách -> `/dang-nhap` qua RequireUser) và admin (`/xac-thuc-mfa` khách -> `router.replace`): 4 lần sai không reload, lần 5 reload ĐÚNG 1 lần, email còn, widget hiện + thông điệp CAPTCHA_REQUIRED, cờ `vv:gla2-login` bị xoá, gửi tiếp không reload lần 2 | soft nav | PASS |
| sessionStorage: chỉ ghi duy nhất `["vv:gla2-login", <email>]`; không mật khẩu/token ở local/sessionStorage | soft nav, AC2 | PASS |
| Chặn Cloudflare (abort) -> alert `role="alert"` chứa `<button>` "tải lại trang", nút Đăng nhập khoá, bấm thì tải lại | block | PASS (hiện gần như ngay, ~50-70 ms, vì script lỗi bắn error-callback) |
| Script Cloudflare treo (không lỗi, không callback) -> chưa có alert ở 6 giây, hiện ở ~10,4 giây | treo | PASS |
| 429 + Retry-After (45s/90s/7300s/không header) -> "45 giây", "2 phút", "3 giờ", "ít phút" (web + admin; mock có header CORS expose như backend) | 429 | PASS |
| CSP admin: `/dang-nhap` (và `/dang-nhap/` sau redirect) có `challenges.cloudflare.com` ở frame-src + connect-src; `/xac-thuc-mfa`, `/doi-mat-khau`, `/quan-tri`, `/quan-tri/nhat-ky` không có và giữ `frame-src 'none'`. CSP web `/dang-nhap` có Cloudflare | CSP | PASS |
| Admin `/quan-tri` chưa đăng nhập -> `/dang-nhap` là tải tài liệu mới (navigation entry là `/dang-nhap`, CSP đúng) | AuthGate hard nav | PASS |
| 375px và 1280px không tràn ngang (có widget + cảnh báo); xem ảnh 375px web ổn | layout | PASS |
| Tab từ ô mật khẩu tới nút Đăng nhập khi nút mở | AC2 | PASS |
| Không lộ tài khoản tồn tại: tài khoản thật vs email ngẫu nhiên, 5 lần sai -> status, body (trừ request_id), banner, số widget giống hệt từng lần; `captcha_required` chỉ xuất hiện ở lần 5 | enum (web + admin) | PASS |

## Bug phát hiện
Không có.

## Ghi chú / rủi ro (không chặn)
- NOTE-1 (Minor, đã biết): với nhánh "script lỗi" (chặn mạng, adblock chặn domain) alert hiện ngay thay vì sau 10 giây; đúng theo chủ ý (error-callback), chỉ ghi lại vì brief nói "~10 giây". Nhánh 10 giây chỉ kích hoạt khi script treo.
- NOTE-2 (rủi ro): `router.*` còn lại ở web/admin (Mfa/ForcePasswordChange, RequireUser...) vẫn soft nav; được bù bằng reload tự động 1 lần ở LoginForm (đã kiểm). Người dùng mất banner "sai nhiều lần" nhưng widget vẫn hiện. Giống NIT-1 của reviewer.
- Chưa kiểm được: Turnstile khoá thật (hostname, chế độ Managed có iframe và yêu cầu `style-src`/`img-src`), token hết hạn thật sau 5 phút. Khoá thử không dựng iframe nên CSP frame-src chỉ kiểm bằng header, chưa kiểm iframe thật; cần kiểm tay trên staging có khoá thật trước go-live. Đã chạy không có tiến trình nền.

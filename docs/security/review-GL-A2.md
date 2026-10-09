# SECURITY: GL-A2 | Đăng nhập sai nhiều thì đòi captcha thay vì khoá (T03-M1, T28-1) | 2026-10-09

**Kết luận:** PASS có điều kiện

Thiết kế cổng captcha đúng hướng. Người ngoài không có captcha không còn đẩy được tài khoản tới trần bằng request tuần tự: request bị từ chối vì captcha được hoàn lượt, Turnstile fail-closed, MFA của staff không bị bỏ qua, phản hồi không lộ tài khoản có tồn tại hay không. Còn 2 điều kiện phải xong trước go-live:
- **S1:** lỗ hở trong `reserveAttempts`. Request bị 429 vì trần IP không hoàn lượt đã cộng vào bộ đếm tài khoản, nên vẫn khoá được người thật mà không cần giải captcha.
- **S2:** BE không được lên production trước FE. Hiện form đăng nhập web và admin chưa có widget Turnstile.

Không có Critical/High trong code. Các điều kiện ở dưới là Medium.

Phạm vi đã đọc: `app/Services/Auth/LoginService.php`, `app/Services/Auth/Staff/StaffAuthService.php`, `app/Exceptions/LoginChallengeException.php`, `app/Support/ApiExceptionRenderer.php`, `app/Support/AtomicCounter.php`, `app/Services/Auth/Captcha/*`, 2 LoginRequest/LoginController, `config/auth.php`, `bootstrap/app.php` (TrustProxies), `infra/production/nginx/snippets/vv-real-ip.conf`, ADR-008 §Cloudflare, `docs/ops/production-checklist.md`, `tests/Feature/GL/LoginCaptcha*`.

## Trả lời các câu hỏi được giao

| Câu hỏi | Trả lời ngắn |
|---|---|
| Còn khoá được người thật không? | **Không có dịch vụ giải captcha:** request tuần tự thì không khoá được, vì mỗi request thiếu hoặc sai captcha đều được hoàn lượt. **Có race thì vẫn khoá được** (S1). **Có dịch vụ giải captcha:** khoá được 1 giờ với khoảng 95 captcha mỗi tài khoản (chi phí khoảng vài nghìn đồng mỗi giờ). Rủi ro này đã được chấp nhận và ghi backlog (S5) |
| Credential stuffing nhiều tài khoản, nhiều IP | Cổng captcha theo tài khoản không có tác dụng với stuffing, vì mỗi tài khoản chỉ bị thử 1–2 lần, dưới ngưỡng 5. Chỉ còn trần IP 50/giờ chặn, và botnet hoặc IPv6 (không gộp /64) né được. Tình trạng này không tệ hơn trước (trước là 10 lượt miễn phí, nay 5) (S4) |
| Enumeration | Không thấy lộ. Status, code và cờ `captcha_required` chỉ phụ thuộc bộ đếm. Đường captcha của cả hai loại tài khoản đều không băm mật khẩu. Đường sai mật khẩu luôn băm 1 lần (`dummyHash` được cache). Audit `staff.login_failed` chỉ nội bộ đọc. Còn lại một oracle lý thuyết khi `Str::ascii` chuẩn hoá khác collation `utf8mb4_0900_ai_ci` (S8, Info, có từ T03) |
| Turnstile | Fail-closed khi lỗi mạng, timeout, HTTP lỗi hoặc `success` khác `true`. Lúc đó trả `CAPTCHA_INVALID` và hoàn lượt, nên Turnstile sập không khoá ai, chỉ người đã vượt ngưỡng tạm không đăng nhập được. Token dùng lại bị Cloudflare chặn (siteverify trả `timeout-or-duplicate`). Server chưa kiểm `hostname`/`action` (T03-L4 đã biết, đã đưa vào checklist). Timeout 5 giây chưa tách connect timeout (S7) |
| IP thật sau Cloudflare | App tin `X-Forwarded-For` từ Nginx (`TRUSTED_PROXIES`). IP đúng hay sai phụ thuộc hoàn toàn vào `vv-real-ip.conf`. Mẫu hiện tại và checklist §3 (dòng 172) vẫn ghi "IP load balancer". Nếu không đặt `set_real_ip_from` bằng dải IP Cloudflare thì mọi người dùng chung vài IP edge của Cloudflare, và trần IP 50/giờ thành nút khoá đăng nhập hàng loạt (S3) |
| MFA / WRONG_PORTAL | Đúng. Cổng captcha chạy trước khi so mật khẩu. WRONG_PORTAL/LOCKED chỉ xảy ra sau khi mật khẩu đúng (S20, như cũ). Staff qua captcha vẫn phải qua MFA (có test, `features.staff_mfa` được guard ép bật) |
| Cấu hình mặc định và guard | Mặc định 5/100/50 hợp lý. Đồng ý với guard dev đề xuất nhưng cần bổ sung (S6): `threshold < max_failures_per_account`, các giá trị phải ≥ 1. Lý do: `(int) env()` nhận chuỗi rỗng hoặc chữ thì ra 0, mà trần tài khoản bằng 0 thì mọi lần đăng nhập đều 429 |

## Phát hiện

### S1 [Medium] Vẫn khoá được người thật mà không cần captcha: request bị 429 trong lúc đua (race) không hoàn lượt tài khoản đã cộng — OWASP A04/A07
- **Vị trí:** `backend/app/Services/Auth/LoginService.php:150-169` (`reserveAttempts`), được gọi từ `reserveWithCaptchaGate` (`:111`) cho cả học sinh lẫn staff.
- **Mô tả:** vòng thứ hai `hit` khoá tài khoản trước, rồi mới `hit` khoá IP. Nếu IP vượt trần (`$hits > $max`) thì ném 429 ngay, **không hoàn lượt tài khoản vừa cộng**. Lượt đó cũng không đi qua `releaseAttempts` của cổng captcha. Comment ở `:143` chấp nhận "không hoàn lượt ở đường chặn". Trước GL-A2 điều này vô hại vì trần tài khoản 10 vốn đã khoá được dễ dàng. Sau GL-A2, trần 100 chính là thứ duy nhất khoá được người thật, nên lượt bị rò trở thành đường vòng qua captcha.
- **Kịch bản (không cần giải captcha):**
  1. Từ 1 IP, gửi 49 lượt sai vào các định danh ngẫu nhiên không tồn tại. Mỗi định danh dưới ngưỡng nên không cần captcha, và bộ đếm IP lên 49.
  2. Bắn 1 loạt K request đồng thời vào tài khoản nạn nhân, không kèm captcha (`throttle:login` cho 120/phút/IP). Request nào đọc IP = 49 ở bước kiểm chỉ-đọc rồi mới INCR sẽ đều cộng 1 vào bộ đếm nạn nhân. Chỉ 1 request INCR được IP lên đúng 50. Các request còn lại nhận IP 51 trở lên, bị 429 và **giữ nguyên +1 trên bộ đếm nạn nhân**.
  3. Lặp lại với IP khác. IPv6 không gộp /64 (T03-L1) nên IP gần như không giới hạn. Đến khi bộ đếm nạn nhân ≥ 100 thì bước kiểm chỉ-đọc trả 429 cho mọi người, kể cả người thật có captcha, trong phần còn lại của cửa sổ 1 giờ. Lặp lại mỗi giờ.

  Có một biến thể không cần chuẩn bị IP: một loạt ≥ 96 request đồng thời từ nhiều IP vào tài khoản đang ở ≥ 5. Những request đẩy bộ đếm vượt 100 sẽ bị 429 mà không hoàn lượt, nên bộ đếm kẹt ở mức trên 100.
- **Tác động:** đúng loại rủi ro A2 cần đóng: người ngoài khoá được việc đăng nhập của QTV (người duyệt đơn US-022, email công khai). Kẻ tấn công cần gửi request đồng thời, nhưng không cần dịch vụ giải captcha. Test race hiện có không phát hiện vì chỉ đua trên bộ đếm tài khoản, và với 15 tiến trình thì bộ đếm không bao giờ chạm trần 100.
- **Cách sửa:** request nào bị từ chối ở bất kỳ khoá nào thì hoàn **mọi** lượt mà chính nó đã cộng. Đây là cách đơn giản nhất. Bộ đếm vẫn bị chặn trên (tối đa bằng trần cộng số request đang chạy cùng lúc, rồi quay về) nên không mất tính chất M3.
  ```php
  $counts = [];
  $hitKeys = [];
  foreach ($limits as [$key, $max]) {
      $hits = AtomicCounter::hit($key, self::DECAY_SECONDS);
      $hitKeys[] = $key;
      if ($hits > $max) {
          self::releaseAttempts(...$hitKeys); // hoàn cả khoá vừa vượt lẫn các khoá đã cộng trước đó
          throw self::throttled($key);
      }
      $counts[] = $hits;
  }
  ```
  Cách tốt hơn là 1 script Lua kiểm và INCR nhiều khoá cùng lúc, theo kiểu tất cả hoặc không có gì. Nên đặt khoá IP lên trước khoá tài khoản để IP bị chặn không chạm bộ đếm tài khoản.
- **Cách kiểm chứng (test nên có, nhóm `race`):** đặt bộ đếm IP là 49 (`AtomicCounter::add`), bộ đếm tài khoản là 5. Chạy 20 tiến trình đồng thời không captcha vào tài khoản từ cùng IP đó. Sau đó `AtomicCounter::attempts('login-fail:u:<id>')` phải ≤ 6 (lý tưởng là 5), và người thật có captcha phải nhận 200 chứ không phải 429. Thêm test thứ hai: tài khoản ở 99, 10 tiến trình không captcha từ nhiều IP, sau đó bộ đếm vẫn ≤ 100 và người thật có captcha nhận 200. Làm cho cả staff.

### S2 [Medium] Điều kiện phát hành: BE không được lên trước FE (widget Turnstile ở form đăng nhập web và admin) — OWASP A04
- **Vị trí:** `frontend/apps/web` (chỉ form đăng ký và quên mật khẩu xử lý `captcha_token`), `frontend/apps/admin` (chưa có). Grep `CAPTCHA_REQUIRED|captcha_required` không thấy trong form đăng nhập.
- **Mô tả & tác động:** nếu BE GL-A2 được deploy mà FE chưa có widget, người ngoài chỉ cần **5** request sai/giờ (trước là 10) là người thật không còn cách nào đăng nhập từ UI, vì luôn nhận `CAPTCHA_REQUIRED`. Như vậy còn tệ hơn trạng thái A2 ban đầu.
- **Cách sửa:** giao `nextjs-dev` phần A2 (FE) cho cả 2 app: hiện widget khi `captcha_required === true` hoặc `code` là `CAPTCHA_REQUIRED`/`CAPTCHA_INVALID`; **mỗi lần gửi dùng token mới**, reset widget sau mỗi phản hồi (token Turnstile chỉ dùng được 1 lần); không lưu token vào storage. Release BE và FE cùng một đợt. Ghi điều này vào checklist deploy.
- **Kiểm chứng:** e2e web và admin: sai 5 lần thì widget hiện, giải xong (khoá test `1x…` chỉ ở local/e2e) thì vào được; admin sau đó sang bước MFA.

### S3 [Medium] Điều kiện cấu hình: IP thật sau Cloudflare chưa được nêu rõ trong mẫu và checklist — OWASP A05/A04
- **Vị trí:** `infra/production/nginx/snippets/vv-real-ip.conf` (`set_real_ip_from <IP_LOAD_BALANCER_1>`), `docs/ops/production-checklist.md:172`. Trong khi đó ADR-008 dòng 268 yêu cầu dùng dải Cloudflare và `CF-Connecting-IP`.
- **Mô tả & tác động:** `login-fail-ip:*`, `staff-login-fail-ip:*`, `throttle:login` và `remoteip` gửi Turnstile đều dùng `$request->ip()`. Nếu Nginx không tin dải IP Cloudflare, `$remote_addr` sẽ là IP edge của Cloudflare. Khi đó nhiều người dùng chung một IP, và 50 lượt sai/giờ từ một kẻ bất kỳ sẽ trả 429 cho mọi người đi qua cùng edge, tức là khoá đăng nhập hàng loạt mà không cần captcha. Guard chỉ kiểm `TRUSTED_PROXIES` khác rỗng và khác `*`, không phát hiện được lỗi này.
- **Cách sửa (ops):** khi có Cloudflare proxy thì `vv-real-ip.conf` đặt `set_real_ip_from` bằng toàn bộ dải `ips-v4`/`ips-v6` của Cloudflare (có script cập nhật định kỳ) và `real_ip_header CF-Connecting-IP`; firewall origin chỉ mở cho dải Cloudflare. Sửa checklist dòng 172 cho khớp ADR-008. Không cần sửa code.
- **Kiểm chứng (staging):** đăng nhập sai từ 2 máy khác mạng, xem `staff.login_failed` hoặc access log thấy 2 IP khác nhau và không phải IP Cloudflare. 50 lượt sai từ máy A không làm máy B bị 429.

### S4 [Low] Credential stuffing phân tán gần như không bị cổng captcha chặn — OWASP A07
- **Vị trí:** thiết kế `reserveWithCaptchaGate`. Khoá IP dùng nguyên địa chỉ (T03-L1).
- **Mô tả:** stuffing thử 1 cặp email/mật khẩu bị lộ cho mỗi tài khoản, nên luôn dưới ngưỡng 5 và không bao giờ gặp captcha. Thứ duy nhất chặn là 50 lượt sai/giờ/IP, mà botnet hoặc một dải IPv6 /64 vượt qua dễ dàng. Học sinh không có MFA. Staff có MFA nên rủi ro chiếm tài khoản staff thấp.
- **Cách sửa (V2, không chặn go-live):** (a) gộp khoá IP theo /64 cho IPv6. (b) Thêm cờ "chế độ tấn công" toàn cục: nếu tổng lượt sai trong 5 phút vượt X (ví dụ 200) thì mọi đăng nhập đều phải có captcha trong 15 phút. Đây là 1 bộ đếm Redis, và cờ `captcha_required` đã có sẵn để FE dùng. (c) Cảnh báo ops khi tỉ lệ thất bại tăng đột biến.
- **Kiểm chứng:** khi bộ đếm toàn cục vượt X, tài khoản chưa có lượt sai nào cũng nhận `CAPTCHA_REQUIRED`.

### S5 [Low] Rủi ro đã chấp nhận: có dịch vụ giải captcha thì vẫn khoá được 1 giờ — OWASP A04
- 100 lượt sai/giờ/tài khoản kèm captcha hợp lệ thì trả 429 cho cả người thật. Chi phí cho kẻ tấn công rất thấp, khoảng 95 captcha. Dev đã ghi backlog. Đề xuất cho V2: miễn trần tài khoản cho **thiết bị đã từng đăng nhập thành công** (device cookie có ký, theo hướng dẫn OWASP về chống khoá tài khoản), và cảnh báo QTV hoặc ghi audit khi tài khoản chạm trần (T28-1 phần còn lại).

### S6 [Low] Guard cấu hình cho ngưỡng và trần — OWASP A05
- **Vị trí:** `backend/config/auth.php:158-176`, `backend/app/Support/ProductionConfigGuard.php` (chưa có).
- **Mô tả:** `(int) env('AUTH_LOGIN_MAX_FAILURES')` với giá trị rỗng hoặc gõ sai cho ra 0. Trần tài khoản bằng 0 thì `attempts >= 0` luôn đúng, nên **mọi** lần đăng nhập đều 429 (sập đăng nhập). Ngưỡng ≥ trần tài khoản thì captcha không bao giờ được đòi, quay về khoá cứng. Ngưỡng bằng 0 thì luôn phải captcha (chấp nhận được nhưng ảnh hưởng UX).
- **Cách sửa (GL-1):** ở production/staging, chặn khi:
  - `captcha_threshold` < 1 hoặc > 20;
  - `max_failures_per_account` < 20, hoặc ≤ `captcha_threshold` + 10;
  - `max_failures_per_ip` < 20.

  Áp cho cả 2 bộ `auth.login.*` và `auth.staff.login_*`. Thông báo lỗi chỉ nêu tên biến.
- **Kiểm chứng:** ma trận test guard ở `production`/`staging` (ném lỗi) và `local`/`testing` (không ném), có cả trường hợp env rỗng.

### S7 [Low] Ghi audit không giới hạn và gọi ra Turnstile không giới hạn bằng request bị từ chối vì captcha — OWASP A04/A09
- **Vị trí:** `StaffAuthService.php:64-68` (audit `captcha_required`/`captcha_invalid`), `TurnstileVerifier.php:24-27`.
- **Mô tả:** request bị từ chối vì captcha được hoàn lượt, nên chỉ còn `throttle:login` (120/phút/IP/host) giới hạn. Mỗi IP ghi được khoảng 7.200 dòng audit/giờ cho staff, botnet thì nhân lên. Mỗi request kèm token rác gây 1 lần gọi ra Cloudflare, timeout 5 giây và chưa có connect timeout riêng. Khi Cloudflare chậm, kẻ tấn công giữ được worker PHP-FPM.
- **Cách sửa:** chỉ ghi audit `captcha_required` lần đầu cho mỗi tài khoản trong 10 phút (dùng khoá Redis `add`), hoặc gộp đếm. Không gọi Turnstile cho token quá ngắn hoặc sai định dạng. Đặt `->connectTimeout(2)->timeout(3)`. Có thể thêm limiter riêng cho số lần verify captcha theo IP.
- **Kiểm chứng:** 50 request thiếu captcha liên tiếp vào 1 tài khoản staff tạo ≤ 1 dòng audit; `Http::fake` có độ trễ cho thấy request trả về trong ≤ 3 giây với `CAPTCHA_INVALID`.

### S8 [Info] Oracle tồn tại tài khoản qua cách chuẩn hoá khác với collation
- **Vị trí:** `LoginService.php:188-204`.
- **Mô tả:** tài khoản có thật dùng khoá `u:<id>`, tức mọi cách viết khớp collation `utf8mb4_0900_ai_ci` đều chung một bộ đếm. Tài khoản không tồn tại dùng `Str::ascii` + `mb_strtolower`. Nếu có ký tự mà hai cách chuẩn hoá cho kết quả khác nhau, kẻ tấn công có thể làm 5 lượt sai với cách viết A rồi đọc cờ `captcha_required` của cách viết B để biết email có tồn tại. Lỗ này có từ T03, giờ đọc được bằng cờ thay vì bằng 429. Test hiện đã phủ SĐT, chữ hoa/thường và chữ có dấu.
- **Đề xuất:** theo dõi, không cần sửa trước go-live.

### Đã kiểm, không có vấn đề
- 22 test GL-A2 (`LoginCaptchaGateTest` 18, `LoginCaptchaRaceTest` 4) PASS trên `vitaminvui_testing_h` (`phpunit.local-h.xml`, `FEATURE_MANUAL_PAYMENT=false`, `VIDEO_BIND_IP=true`), 405 assertion.
- `captcha_token` được validate là `nullable|string|max:2048`; verifier từ chối độ dài > 2048 và chuỗi rỗng.
- `CAPTCHA_DRIVER=fake` bị guard cấm ở production/staging, nên cổng captcha không bị tắt được bằng cách đổi driver.
- Mật khẩu đúng thì hoàn lượt rồi `RateLimiter::clear`. Phiên được `regenerate`. Staff chưa qua MFA chỉ gọi được `/admin/auth/mfa/*`.
- Envelope 422 không chứa dữ liệu nhạy cảm. `#[\SensitiveParameter]` vẫn giữ trên tham số mật khẩu.

## Kết quả công cụ
- Pest: `tests/Feature/GL/LoginCaptchaGateTest.php`, `tests/Feature/GL/LoginCaptchaRaceTest.php`: 22 passed (65,8 giây).
- Không chạy `composer audit`/`npm audit` vì task không thêm dependency.

## Giao việc
- **laravel-dev:** S1 (bắt buộc trước go-live), S6 (gộp vào GL-1), S7 (nên làm).
- **nextjs-dev:** S2 (bắt buộc, release cùng BE).
- **ops / production-checklist:** S3 (bắt buộc nếu có Cloudflare proxy), thêm mục "BE GL-A2 chỉ deploy cùng FE có widget".
- **laravel-qa:** test race S1 (trần IP lúc đua và tài khoản ở sát trần); e2e S2; kiểm S3 trên staging; Turnstile thật trên staging (hostname sai, token dùng lại cho kết quả `CAPTCHA_INVALID`, Turnstile chậm hoặc không trả lời).
- **Backlog V2:** S4, S5, S8.

## Điểm cần pháp chế / PO quyết
- **PO:** chấp nhận rủi ro còn lại S5 (kẻ có dịch vụ giải captcha khoá được 1 giờ) và S4 (stuffing phân tán) cho V1, hay yêu cầu làm cờ "chế độ tấn công" toàn cục (S4b, khoảng 2–3 giờ BE) trước go-live.
- **Pháp chế:** gửi IP người dùng (`remoteip`) cho Cloudflare Turnstile là chuyển dữ liệu cá nhân cho bên xử lý ở nước ngoài. Điểm này đã có từ T03 với form đăng ký; cần bộ phận pháp chế xác nhận việc ghi điều này trong chính sách quyền riêng tư.

---

## Vòng 2 (2026-10-09): xác minh bản sửa của dev

**Kết luận vòng 2:** PASS có điều kiện

S1, S3, S6 và S7 đã đóng. Không còn Critical/High và không còn Medium nào trong code BE. Còn 2 điều kiện cho go-live:
- **S2:** FE đăng nhập web và admin phải có widget Turnstile và release cùng BE. Việc này đã được ghi vào checklist.
- **V2-1:** sửa bước kiểm định dạng trong script cập nhật IP Cloudflare trước khi đưa vào cron (sửa 2 dòng).

Các phát hiện mới V2-2..V2-5 đều mức Low/Info, không chặn go-live.

Kết quả test: `GL/LoginCaptchaGateTest`, `GL/LoginCaptchaRaceTest` (race chạy trên Redis thật qua `CACHE_LIMITER=redis-limiter` của tiến trình con) và `T01/ProductionConfigGuardTest` cho kết quả 81 passed, 683 assertion, trên `vitaminvui_testing_h`.

### Trạng thái các mục vòng 1

| Mục | Trạng thái | Ghi chú xác minh |
|---|---|---|
| S1 | **Đã đóng** | Script Lua `AtomicCounter::HIT_ALL` (`backend/app/Support/AtomicCounter.php:35-47`) đọc mọi khoá, chỉ INCR khi không khoá nào `>= trần`, và chạy trong 1 lần `EVAL` nên nguyên tử trên Redis. Request bị chặn không cộng khoá nào; nhánh `hits > max` không hoàn lượt đã bị bỏ. Khoá IP đặt trước khoá tài khoản. TTL đặt khi `INCR == 1`, nằm trong cùng script nên không có khoá thiếu TTL; khoá về 0 sau `release` vẫn giữ TTL cũ. Nhánh không token: giữ chỗ cả hai khoá rồi hoàn cả hai, nên chỉ có tăng tạm thời khi đua. Muốn giữ bộ đếm tạm thời ở mức ≥ 100 thì cần khoảng 95 request đang chạy cùng lúc liên tục (cỡ hàng chục nghìn request/giây), không khả thi (Info). Test race `IP=49 + tài khoản=5, 20 tiến trình` và `tài khoản=99, 10 tiến trình` đều PASS. **Redis Cluster:** `config/database.php` không có `clusters` (Redis 1 node). Nếu sau này chuyển sang cluster, `EVAL` nhiều khoá khác slot sẽ báo `CROSSSLOT`, làm đăng nhập lỗi 500 (fail-closed nhưng mất đăng nhập). Ghi ở V2-5. **Nhánh dự phòng khi store không phải Redis:** kiểm rồi mới hit, không nguyên tử giữa các tiến trình. Chấp nhận được vì chỉ dùng cho test/local, nhưng chưa có guard ép store này ở production (V2-5) |
| S2 | Còn mở (FE) | Đã có trong checklist ("GL-A2: BE chỉ deploy cùng FE"). Vẫn là điều kiện go-live |
| S3 | **Đã đóng phần cấu hình** | `vv-real-ip.conf` dùng `real_ip_header CF-Connecting-IP` và chỉ tin dải IP Cloudflare; có ghi chú firewall origin. Script cập nhật có một lỗi kiểm định dạng (V2-1) |
| S4 | Theo backlog | Thay đổi R1 tác động tới mục này, xem V2-2 |
| S5 | Theo backlog | Không đổi |
| S6 | **Đã đóng** | `guardLoginCaptchaGate` đủ ma trận cho cả 2 bộ cấu hình. Còn thiếu `AUTH_LOGIN_CAPTCHA_REJECTS_PER_MINUTE` (V2-4) |
| S7 | **Đã đóng** | Limiter cho lượt bị captcha từ chối được kiểm trước khi gọi Turnstile; `connectTimeout(2)->timeout(3)`; audit staff chỉ ghi `captcha_invalid`, tối đa 1 dòng/10 phút/tài khoản (`Cache::add`, nguyên tử). Bỏ audit `captcha_required` là chấp nhận được: lượt này chưa so mật khẩu |
| S8 | Theo dõi | Không đổi |

### V2-1 [Low] Script cập nhật IP Cloudflare chỉ kiểm "có ít nhất 1 dòng hợp lệ", không kiểm "mọi dòng hợp lệ", nên chèn được cấu hình — OWASP A08/A05
- **Vị trí:** `infra/production/scripts/update-cloudflare-ips.sh:15-18`, `:24-26`.
- **Mô tả:** `echo "$v4" | grep -Eq '^…$'` thành công ngay khi **một** dòng khớp mẫu. Một dòng như `1.2.3.4/32; } server { … ` hoặc `0.0.0.0/1` vẫn lọt qua, rồi được ghi nguyên văn vào `set_real_ip_from ${cidr};`. Nếu `nginx -t` vẫn hợp lệ thì cấu hình lạ được reload. Bước chặn `0.0.0.0/0`/`::/0` cũng không chặn được các dải rộng tương đương: `0.0.0.0/1` cộng `128.0.0.0/1`, hoặc `::/1`. Nếu lọt, ai cũng tự đặt được `CF-Connecting-IP` và giả IP để né mọi trần theo IP.
- **Mức độ:** nguồn tải là `https://www.cloudflare.com` qua HTTPS (curl mặc định kiểm chứng chỉ, không theo redirect), nên khai thác cần nguồn bị chiếm hoặc TLS bị phá. Vì vậy mức Low, nhưng cần sửa trước khi đặt cron với quyền root.
- **Cách sửa:**
  ```bash
  bad4="$(printf '%s\n' "$v4" | grep -Ev '^([0-9]{1,3}\.){3}[0-9]{1,3}/([89]|[12][0-9]|3[0-2])$' || true)"
  bad6="$(printf '%s\n' "$v6" | grep -Ev '^[0-9a-fA-F:]+/(1[6-9]|[2-9][0-9]|1[01][0-9]|12[0-8])$' || true)"
  [ -z "$bad4" ] && [ -z "$bad6" ] || { echo "có dòng CIDR không hợp lệ hoặc quá rộng" >&2; exit 1; }
  ```
  Đoạn này giữ prefix tối thiểu `/8` cho v4 và `/16` cho v6; dải thật của Cloudflare hiện rộng nhất là v4 `/13`, v6 `/29`. Thêm `--proto '=https'` cho curl. Có thể so số dòng với bản cũ và từ chối khi chênh lệch quá lớn. Ngoài ra, khi file đích chưa có marker `BEGIN/END`, `sed '1,/^# --- END/d'` không khớp nên in thêm bản cũ, tạo ra `set_real_ip_from` trùng. Nên kiểm marker có tồn tại trước khi ghi.
- **Kiểm chứng:** chạy script với nguồn giả (biến môi trường trỏ tới file cục bộ) có 1 dòng `1.1.1.0/24; include /etc/passwd` hoặc `0.0.0.0/1`. Script phải exit khác 0 và không đổi file đích.

### V2-2 [Low] R1: lượt có captcha hợp lệ không còn bị trần IP giới hạn — OWASP A07
- **Vị trí:** `backend/app/Services/Auth/LoginService.php` (`reserveWithCaptchaGate`, nhánh có token).
- **Đánh giá:** quyết định này đúng cho mục tiêu "NAT lớp học không bị 429". Hệ quả:
  - **Credential stuffing với dịch vụ giải captcha:** 1 IP thử được khoảng 120 lượt/phút (`throttle:login`), tức khoảng 7.200 cặp/giờ, mỗi lượt tốn 1 captcha (chi phí rất thấp). Trước đây là 50 lượt/giờ/IP. Botnet vốn đã vượt trần IP, nên rủi ro tổng thể không đổi nhiều. Thay đổi thực chất là captcha thay thế trần IP: kẻ có ít IP nhưng có dịch vụ giải captcha thì được lợi.
  - **Không có dịch vụ giải captcha:** trần IP tăng từ 50 lên 200/giờ, tức nhiều hơn 4 lần lượt thử miễn phí mỗi IP (dưới ngưỡng của từng tài khoản).
  - **Khoá người khác:** vẫn cần token hợp lệ cho mỗi lượt sau ngưỡng, giống S5. Khác biệt duy nhất: 1 IP giờ khoá được nhiều tài khoản (100 captcha mỗi tài khoản). Không mở đường khoá mà không cần captcha.
- **Đề xuất (rẻ, nên làm khi tiện):** thêm 1 trần IP riêng, cao, cho lượt sai **có captcha**, ví dụ `login-fail-ip-captcha:<ip>` 1.000/giờ, chỉ đếm khi mật khẩu sai. NAT lớp học không bao giờ chạm mức này, nhưng tốc độ stuffing có dịch vụ giải captcha từ 1 IP bị chặn trên. PO xác nhận số trần IP 200.
- **Kiểm chứng:** 1.001 lượt sai có captcha từ 1 IP vào các tài khoản khác nhau, lượt cuối trả 429; một IP khác vẫn đăng nhập bình thường.

### V2-3 [Low] Limiter lượt captcha bị từ chối theo IP chặn cả người có token hợp lệ trên cùng IP — OWASP A04
- **Vị trí:** `reserveWithCaptchaGate`, nhánh có token: `RateLimiter::tooManyAttempts($rejectKey, …)` chạy trước `verify`.
- **Mô tả:** một người cùng IP (học sinh nghịch trong lớp chung NAT, hoặc thuê bao cùng IP CGNAT của nhà mạng) gửi 30 request/phút với token rác. Kết quả: mọi người trên IP đó gửi kèm captcha đều nhận 429 trong phút đó. Kẻ này có thể duy trì liên tục, và có thể kết hợp với việc làm đầy trần IP 200/giờ ở nhánh không token. Khi đó cả IP mất đăng nhập: không có token thì bị 429 vì trần IP, có token thì bị 429 vì limiter. Người bị nhắm chỉ đăng nhập được khi đổi mạng.
- **Đánh giá:** loại DoS theo IP dùng chung này đã có trước GL-A2 (trần IP 50) và nay nhẹ hơn. Kẻ tấn công phải ở cùng IP, không khoá được từ xa. Mức Low.
- **Đề xuất:**
  - (a) Đặt khoá limiter theo cặp `IP + tài khoản` (ví dụ 10/phút), giữ trần theo IP ở mức cao hơn (ví dụ 120/phút, bằng `throttle:login`). Lọc token rõ ràng sai định dạng trước khi gọi Turnstile.
  - (b) Ở nhánh không token, khi bị chặn vì trần **IP**, trả 422 `CAPTCHA_REQUIRED` thay cho 429. Lượt có token đã không bị trần IP giới hạn, nên người thật sau NAT chỉ cần giải captcha là vào được, thay vì nhận 429 mà FE không biết cách xử lý.
- **Kiểm chứng:** IP X gửi 30 token sai vào tài khoản A trong 1 phút, rồi tài khoản B trên cùng IP X gửi token hợp lệ và mật khẩu đúng phải nhận 200. Trần IP đầy rồi gửi không token phải nhận 422 `CAPTCHA_REQUIRED`, gửi có token phải nhận 200.

### V2-4 [Low] Guard chưa phủ `AUTH_LOGIN_CAPTCHA_REJECTS_PER_MINUTE` — OWASP A05
- `(int) env()` rỗng cho ra 0, và `RateLimiter::tooManyAttempts($key, 0)` luôn đúng. Khi đó mọi lượt có token đều 429, và mọi tài khoản đã vượt ngưỡng không còn đăng nhập được, tức quay về khoá cứng.
- **Cách sửa:** thêm vào `guardLoginCaptchaGate`, chặn khi giá trị < 10. Có test ma trận như S6.

### V2-5 [Info] Giả định hạ tầng của `hitAll`
- Store của limiter phải là Redis ở production. Nếu `CACHE_LIMITER` bị đặt thành `file`/`database`/`array`, code rơi về nhánh dự phòng không nguyên tử và S1 mở lại khi có race. **Đề xuất:** guard yêu cầu `cache.stores.<cache.limiter>.driver === 'redis'` ở production/staging.
- Redis Cluster: script nhiều khoá cần các khoá cùng slot. Nếu chuyển sang cluster thì dùng hash tag (`{login}`) hoặc tách script; ghi chú trong ADR/ops.
- Firewall origin chỉ mở cho Cloudflare là điều kiện để `CF-Connecting-IP` không bị giả mạo (đã có trong checklist). Có thể bật thêm Authenticated Origin Pulls (mTLS) nếu muốn chặt hơn.

### Giao việc vòng 2
- **laravel-dev:** V2-4 (nhỏ, nên gộp ngay vào GL-1); V2-3(b) nên làm; V2-2 và V2-3(a) tuỳ PO; V2-5 phần guard store limiter.
- **ops/dev hạ tầng:** V2-1 trước khi đặt cron.
- **nextjs-dev:** S2 (vẫn là điều kiện go-live). Nếu làm V2-3(b), FE xử lý được luôn trường hợp NAT.
- **laravel-qa:** test V2-3 (tài khoản B trên cùng IP vẫn vào được); test script V2-1 với nguồn giả; e2e S2; trên staging kiểm 2 máy khác mạng ra 2 IP thật khác nhau (không phải IP Cloudflare).

### Điểm cần PO quyết (vòng 2)
- Xác nhận trần IP 200/giờ (học sinh và staff) và 30 lượt captcha bị từ chối/phút/IP.
- Chấp nhận V2-2 (captcha thay thế trần IP cho lượt có token), hay thêm trần IP riêng 1.000/giờ cho lượt có captcha.

---

## Vòng 3 (2026-10-09): xác minh bản sửa V2-1..V2-5

**Kết luận vòng 3:** PASS có điều kiện

V2-1..V2-5 đã đóng. Không còn Critical/High/Medium nào ở BE và hạ tầng. Còn 2 điều kiện:
1. **S2 (FE):** form đăng nhập web và admin phải có widget Turnstile và release cùng BE. Điều kiện này giữ nguyên từ vòng 1.
2. **QA chạy lại test trên máy rảnh với DB test riêng.** Lần chạy vòng 3 của tôi không cho kết quả sạch, vì 2 lý do môi trường:
   - Tải máy host lên khoảng 80–200: test race T03 bị `ProcessTimedOutException`, các test mốc thời gian của T28 (idle 120 phút, MFA) bị lỗi.
   - DB `vitaminvui_testing_h` đang bị một tiến trình khác dùng cùng lúc: lỗi `migrations`/`users doesn't exist`, `table already exists` khi `RefreshDatabase` chạy song song.

   Lần chạy đầu (GL gate + race + guard T01 + T03 + T28) cho 297 passed, 10 failed, 1 skipped. Các lỗi tôi xem được đều thuộc T28 (`AdminSessionTest` mốc idle 120 phút, `AdminMfaTest`); chạy riêng T28 thì chỉ còn lỗi mốc idle. Tôi không thấy lỗi nào truy được về code GL-A2. Những lần chạy lại sau đó hỏng toàn bộ vì DB bị dùng chung, nên phần GL chưa được xác nhận lại bằng một lần chạy sạch. **Cần QA chạy lại** `GL/*`, `T01/ProductionConfigGuardTest`, `T03`, `T28` (kể cả nhóm race) trên DB riêng, khi máy rảnh.

### Xác minh từng mục

| Mục | Trạng thái | Kiểm như thế nào |
|---|---|---|
| V2-1 script IP Cloudflare | **Đóng** | Chạy `update-cloudflare-ips.sh` với nguồn giả (`CF_IPS_ALLOW_FILE=1`, `file://`) và `nginx` giả trong scratchpad, trên bản sao của `vv-real-ip.conf`. Các trường hợp sau đều cho `exit 1` và **file không đổi**: dòng chèn `1.1.1.0/24; include /etc/passwd`, `0.0.0.0/1`, `::/1`, CRLF, dòng `}`, và `file://` khi không bật cờ. Danh sách hợp lệ cho `exit 0` và kết quả trùng khớp file hiện tại (idempotent). Kiểm marker BEGIN/END trước khi ghi; có `--proto '=https'` |
| V2-2 trần IP cho lượt có captcha | **Đóng** | Nhánh có token giữ chỗ `[login-fail-ip-captcha:<ip> (1000), tài khoản]` bằng `hitAll` (tất-cả-hoặc-không) và hoàn cả hai khi mật khẩu đúng. Staff dùng khoá riêng `staff-login-fail-ip-captcha:` |
| V2-3a limiter lượt bị từ chối | **Đóng** | Có 2 khoá: cặp `IP + khoá tài khoản` (10/phút) và theo IP (120/phút). Cả hai được kiểm trước khi gọi Turnstile. Người cùng IP chỉ còn đốt được ngân sách của **một tài khoản cụ thể** (10/phút). Ngân sách theo IP 120/phút bằng đúng `throttle:login` (vốn đã dùng chung theo IP), nên không thêm đường DoS mới |
| V2-3b trần IP đầy thì trả `CAPTCHA_REQUIRED` | **Đóng, không mở đường khoá mới** | Khi `hitAll` báo khoá IP bị chặn thì **không khoá nào được cộng**, request ném 422 trước `Hash::check`. Vì vậy kẻ không có captcha **không so được mật khẩu** và không đẩy được bộ đếm tài khoản. Lượt này tính vào limiter từ chối, nên không thành vòng lặp gọi Turnstile miễn phí. Người thật sau NAT chỉ cần giải captcha là vào được, qua trần IP-có-captcha 1000 thay vì trần IP 200. Cờ `captcha_required=true` ở trường hợp này phụ thuộc IP chứ không phụ thuộc tài khoản, nên không lộ tài khoản có tồn tại. Trần tài khoản vẫn trả 429 |
| V2-4 guard | **Đóng** | Thêm trần IP-có-captcha ≥ 100 và 2 limiter từ chối ≥ 10, cho cả hai bộ cấu hình; thông báo lỗi chỉ nêu tên biến |
| V2-5 store limiter | **Đóng** | Guard ép `cache.stores.<cache.limiter>.driver === 'redis'` ở production/staging, nên nhánh dự phòng không nguyên tử không chạy được ở môi trường thật. Ghi chú CROSSSLOT/hash tag đã có trong `AtomicCounter` và checklist §4 |

### Ghi chú còn lại (Info, không chặn)
- **V3-1 [Info]:** script chấp nhận dải v6 bắt đầu bằng `::` với prefix ≥ /16, ví dụ `::/16`. Dải này bao trùm cả IPv4-mapped `::ffff:0:0/96`. Chỉ có tác dụng khi nguồn HTTPS của Cloudflare bị chiếm và Nginx nhận IPv4 dưới dạng mapped. Có thể nâng prefix tối thiểu của v6 lên `/24` và từ chối dòng bắt đầu bằng `::`.
- **V3-2 [Info]:** limiter từ chối dùng `RateLimiter::hit` của framework, có nhánh `put` không nguyên tử (QA BUG-1 cũ). Limiter này chỉ là giới hạn mềm cho lượt bị từ chối, nên lệch vài lượt khi đua không ảnh hưởng an toàn.

### Giao việc vòng 3
- **laravel-qa:** chạy lại bộ test như ở điều kiện 2, trên DB test riêng khi máy rảnh. Thêm e2e S2 khi FE xong.
- **nextjs-dev:** S2.
- **laravel-dev / ops:** V3-1 nếu tiện.

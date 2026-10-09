# REVIEW: GL-A2 (đăng nhập sai nhiều thì đòi captcha thay vì khoá — T03-M1, T28-1)

## Dev (laravel-dev)

### Hành vi
- Đếm lượt sai NGUYÊN TỬ theo tài khoản (định danh đã chuẩn hoá; tài khoản không tồn tại đếm y hệt) như cũ. Lượt có số thứ tự INCR <= N (mặc định 5): so mật khẩu bình thường. Lượt thứ N+1 trở đi: bắt buộc `captcha_token` Turnstile; thiếu -> 422 `CAPTCHA_REQUIRED`, sai -> 422 `CAPTCHA_INVALID`; cả hai HOÀN lượt (chưa so mật khẩu, không tiêu hao bộ đếm) nên người ngoài gửi request không captcha không đẩy tài khoản tới trần và không khoá được ai.
- Trần cứng: 100 lượt sai/giờ/tài khoản (kể cả có captcha) và 50/giờ/IP -> 429 `TOO_MANY_ATTEMPTS` + `Retry-After` như cũ. Đăng nhập đúng hoàn lượt + `RateLimiter::clear` như cũ.
- Cờ cho FE: MỌI 422 đăng nhập có `captcha_required: bool` top-level (true khi số lượt sai của tài khoản >= N). Chỉ phụ thuộc bộ đếm nên không lộ tài khoản tồn tại; FE hiện widget khi cờ true hoặc code `CAPTCHA_*`.
- Admin: cùng cổng ở `StaffAuthService::login`; MFA ở bước sau KHÔNG bị bỏ qua (test). Audit `staff.login_failed` thêm reason `captcha_required` / `captcha_invalid`.

### Lý do chọn trần theo TÀI KHOẢN (không theo cặp tài khoản+IP)
Trần theo cặp tài khoản+IP không giới hạn dò mật khẩu từ nhiều IP (botnet). Trần theo tài khoản 100/giờ chỉ bị chạm khi có >= 95 lượt sai KÈM captcha hợp lệ trong 1 giờ (mỗi lượt kẻ xấu phải giải 1 captcha), người thật vẫn qua bằng captcha cho tới trần đó; trần IP 50 giữ nguyên bảo vệ chống quét nhiều tài khoản. Rủi ro còn lại (chấp nhận, ghi backlog): kẻ có dịch vụ giải captcha vẫn khoá được người thật 1 giờ bằng 100 lượt; IP NAT dùng chung vẫn chạm trần IP 50.

### File
- Mới: `backend/app/Exceptions/LoginChallengeException.php`; `backend/tests/Feature/GL/LoginCaptchaGateTest.php` (18 test), `backend/tests/Feature/GL/LoginCaptchaRaceTest.php` (2 test group race).
- Sửa: `backend/app/Services/Auth/LoginService.php` (`attempt(..., ?string $captchaToken)`, `reserveWithCaptchaGate`, `captchaNeededAfter`, `reserveAttempts` trả danh sách số lượt; bỏ hằng 10/50 -> config), `backend/app/Services/Auth/Staff/StaffAuthService.php`, `backend/app/Support/ApiExceptionRenderer.php` (code từ exception + `captcha_required`), `backend/app/Http/Requests/Auth/LoginRequest.php`, `backend/app/Http/Requests/Admin/Auth/StaffLoginRequest.php` (`captcha_token` nullable string max 2048), 2 `LoginController`, `backend/config/auth.php` (`auth.login.*`, `auth.staff.login_captcha_threshold`; trần staff mặc định 10 -> 100), `backend/.env.example`, `infra/production/.env.production.example`.
- Test cũ chỉnh để giữ nguyên ý nghĩa kiểm bộ đếm/trần: `T03/LoginTest.php` (`vvLogin` gửi captcha + trần 10), `T28/helpers.php` (`vvAdminLogin` tương tự), `T03/LoginRaceTest.php` và `T28/StaffLoginRaceTest.php` (env tiến trình con: CAPTCHA_DRIVER=fake, trần 10), `tests/Support/login_race_worker.php` (tham số 'none' = không captcha; kết quả `captcha`).
- Docs: api-contract §1.6, `/auth/login`, `/admin/auth/login` + ghi chú T28; tasks.md (mục GL-A2); backlog-v2 (T03-M1, T28-1).
- Không migration. Không đụng frontend.

### Kết quả
- Pint `--test` toàn `app tests config`: pass. PHPStan `--memory-limit=2G`: No errors.
- DB `vitaminvui_testing_e`: T03 T04 T05 T27 T28 GL (không race): 449 pass, 1 skip; T31 T33 T34 T24 (liên quan): pass; race (T03, T28, GL x2, load ~8): pass.

### Đề xuất cho GL-1 (KHÔNG sửa guard)
`ProductionConfigGuard` nên từ chối ở production/staging khi `auth.login.captcha_threshold` hoặc `auth.staff.login_captcha_threshold` < 1 hoặc > 20, và `auth.login.max_failures_per_account` / `auth.staff.login_max_failures_per_account` < 20 (trần quá thấp = người ngoài khoá được người thật). Captcha đã bị guard buộc `turnstile` (không `fake`) nên cổng captcha không bị vô hiệu bằng driver.

### AC (go-live-triage A2) và cần QA kiểm kỹ
- 10 lượt sai IP-1 -> IP-2 đúng mật khẩu không captcha = `CAPTCHA_REQUIRED`, có captcha = 200: đã có test (ngưỡng thực tế là 5, theo PO). Trần cứng 429 và race nhiều tiến trình: đã có test (trần test hạ bằng config; trần mặc định 100 không chạy 100 lượt).
- QA nên thử: Turnstile thật (hostname sai -> `CAPTCHA_INVALID`; token dùng lại), captcha_token > 2048 ký tự (422 validation), đo thời gian phản hồi tài khoản có/không tồn tại, vòng "bị đòi captcha -> thành công" trên Redis thật, FE phải gọi lại với token mới mỗi lần.
- Lưu ý: 422 `CAPTCHA_*` vẫn đi qua `throttle:login` (120/phút/IP) nên lượt gọi Turnstile bị giới hạn theo IP. Trần 429 chưa ghi audit (giữ trong backlog T28-1).

---

## Review (laravel-reviewer, 2026-10-09)
**Kết luận:** APPROVE (0 BLOCKER, 3 SHOULD, 2 NIT)
**Phạm vi:** working tree chưa commit, chỉ file GL-A2 · test chạy trên `vitaminvui_testing_d` (`--exclude-group=race`: GL Gate + T28 AdminLogin 37 pass; T03/T28/GL tổng thể 231 pass, 3 fail do R4 bên dưới, không do code app).

### Đã kiểm, đạt
- Bộ đếm nguyên tử: INCR trước, so mật khẩu sau; request bị captcha từ chối `releaseAttempts` (DECR trong Lua, không xuống dưới 0) và chưa `Hash::check` nên không tiêu hao bộ đếm, người ngoài không đẩy tài khoản tới trần bằng request không captcha. Kiểm chỉ-đọc `attempts >= max` chạy trước INCR nên IP bị chặn không lan sang tài khoản.
- Không lộ tài khoản tồn tại: khoá đếm `u:<id>` vs `a:<chuẩn hoá>` cùng ngưỡng; `captcha_required` chỉ phụ thuộc bộ đếm; cả 3 nhánh (captcha thiếu/sai/sai mật khẩu) đều không phụ thuộc user tồn tại; nhánh sai mật khẩu vẫn băm `dummyHash` nên thời gian không lệch; thông điệp chung giữ nguyên. WRONG_PORTAL/LOCKED vẫn chỉ sau mật khẩu đúng và sau cổng captcha. MFA: không đụng nhánh sau mật khẩu, có test 403 MFA_REQUIRED.
- Cấu hình mặc định an toàn (5 / 100 / 50, có trong 2 file env mẫu); ngưỡng = 0 chỉ làm đòi captcha mọi lượt (fail-safe); driver prod đã bị guard buộc turnstile; Turnstile fail-closed. Đề xuất ràng buộc cho ProductionConfigGuard của Dev hợp lý (thuộc GL-1).
- Test cũ T03/T28 chỉnh đúng: chỉ thêm `captcha_token` + hạ trần về 10 nên các assert bộ đếm/trần/audit vẫn kiểm đúng điều cũ; hành vi mới có test riêng.

### R1 [SHOULD] Trần IP 50/giờ không có đường thoát cho NAT lớp học
- Vị trí: `config/auth.php` `login.max_failures_per_ip`, `LoginService::reserveAttempts`.
- Vấn đề: `max_failures_per_ip` đếm MỌI lượt sai của IP (cả 5 lượt đầu mỗi tài khoản, không cần captcha), và khi chạm là 429 cho cả IP kể cả người giải captcha, mật khẩu đúng cũng bị chặn 1 giờ (kiểm chỉ-đọc chạy trước mọi thứ). Lớp 40-60 học sinh chung 1 IP trường, buổi đầu đăng nhập, sai 1-2 lần mỗi em là chạm 50 dễ dàng; ngược lại kẻ xấu quét nhiều tài khoản chỉ được 5 lượt/tài khoản trước khi bị đòi captcha nên trần IP không phải lớp chính. Mục tiêu "đòi captcha thay vì khoá" bị đánh mất ở đúng trường hợp NAT.
- Đề xuất (xin PO chốt số): nâng mặc định IP lên 200-300/giờ, hoặc chỉ đếm vào khoá IP những lượt KHÔNG kèm captcha hợp lệ (lượt có captcha đúng thì chỉ tính vào trần tài khoản), hoặc cho IP ở ngưỡng IP vẫn đi tiếp khi có captcha hợp lệ. Dù chọn gì, ghi rõ trong api-contract §1.6 và thêm test "50 lượt sai từ 1 IP, 51 đăng nhập đúng + captcha".

### R2 [SHOULD] Siteverify bị gọi đồng bộ không có hạn mức riêng, và audit ghi mỗi lượt thiếu captcha
- Vị trí: `LoginService::reserveWithCaptchaGate` (verify), `StaffAuthService::login` (catch + `audit->log`).
- Vấn đề: (a) Lượt bị captcha từ chối không tiêu hao bộ đếm nào nên chỉ bị `throttle:login` 120/phút/IP chặn. Kẻ có vài chục IP chỉ cần cho 1 tài khoản chạm 5 lượt sai rồi gửi token rác: mỗi request giữ 1 worker PHP-FPM tới 5 giây chờ Cloudflare và đốt quota siteverify. (b) Nhánh admin ghi 1 dòng `audit_logs` (ghi DB) cho từng lượt `captcha_required`, tức tối đa 120 dòng/phút/IP không đăng nhập, phình bảng và làm ồn nhật ký thật (FA12 hiển thị nó).
- Đề xuất:
  ~~~php
  // (a) hạn mức riêng cho lượt captcha bị từ chối, trước khi gọi verify:
  // RateLimiter::tooManyAttempts('login-captcha:'.$ip, 20) -> ném 429; hit mỗi lần captcha thiếu/sai.
  // (b) chỉ audit captcha_invalid (hoặc gộp captcha_required vào 1 dòng/giờ/tài khoản), bỏ audit từng lượt captcha_required.
  ~~~

### R3 [SHOULD] Đường 429 vì đua không hoàn lượt, kết hợp với INCR-rồi-DECR của captcha
- Vị trí: `LoginService::reserveAttempts` (`if ($hits > $max) throw` không release).
- Vấn đề: giờ mọi lượt thiếu captcha đều INCR rồi DECR, nên bộ đếm dao động. Khi tài khoản gần trần (>= 100 - số request đồng thời), luồng request không captcha có thể đẩy `$hits > $max` cho request khác và nhánh này không hoàn lượt, bộ đếm lệch lên vĩnh viễn trong cửa sổ giờ. Rủi ro thấp (cần ~100 lượt sai thật trước), nhưng dễ vá.
- Đề xuất: ở nhánh `$hits > $max` gọi `self::releaseAttempts` cho các khoá đã INCR trong lần gọi này trước khi ném 429 (hoặc ghi nhận đây là hành vi chấp nhận ở backlog).

### R4 [NIT] Race test GL/T28/T03 để lại audit_logs trong DB test
- Vị trí: `tests/Support/login_race_worker.php` nhánh `cleanup` chỉ xoá `users`, không xoá `audit_logs` (staff.login_failed). Chạy `--filter="LoginCaptcha|T28"` (có cả group race) thì `AdminLoginTest` fail 3 test vì thấy 25-27 dòng cũ; chạy `--exclude-group=race` hoặc riêng từng file thì xanh. Đề xuất: cleanup xoá `audit_logs` theo `action like 'staff.login%'` và IP/subject của lần chạy (trên DB `*_testing`).

### R5 [NIT] `captcha_required` chỉ có ở 422 do service
422 do FormRequest (thiếu `password`, `captcha_token` > 2048) không có `captcha_required`; contract ghi "MỌI 422 đăng nhập". FE đã xử lý undefined = false nên chỉ cần sửa câu chữ contract cho khớp.

### Đối chiếu AC (go-live-triage A2 + quyết định PO 2026-10-09)
| AC | Code | Ghi chú |
|---|---|---|
| Sai chạm ngưỡng thì đòi captcha, không khoá | `reserveWithCaptchaGate` | Ngưỡng 5, đúng PO |
| Người ngoài ở IP khác không khoá được người thật | key theo tài khoản, bị từ chối captcha hoàn lượt | có test |
| Trần cứng 100/tài khoản + 50/IP | config + 429 Retry-After | R1 về IP/NAT |
| Học sinh + quản trị, MFA không bị bỏ qua | cùng cổng, test MFA | đạt |
| Không lộ tài khoản | cờ chỉ theo bộ đếm | đạt |
| Audit | `captcha_required/invalid` (admin) | R2(b) |

### Gợi ý cho QA
- Turnstile thật: hostname sai, token dùng lại, outage (fail-closed khóa người ở >= 5 lượt sai: chấp nhận được, nên có thông điệp FE).
- Đo thời gian phản hồi tài khoản có/không tồn tại ở các nhánh, và Redis thật cho vòng "bị đòi captcha -> thành công -> bộ đếm clear".
- Mô phỏng NAT: 50 lượt sai từ 1 IP rồi đăng nhập đúng + captcha (R1). Gửi token rác liên tục xem tải/độ trễ (R2a).
- Chạy riêng nhóm race rồi xoá audit trước khi chạy suite đầy đủ (R4).

## Sửa sau review/security (laravel-dev, 2026-10-09)

Xử lý S1+R3, R1, R2/S7, S6, S3, R4, R5 (S2 là việc FE, ghi vào checklist deploy).

- **S1 + R3 (giữ chỗ tất-cả-hoặc-không):** `AtomicCounter::hitAll` (1 script Lua: kiểm MỌI khoá `>= trần` trước, chỉ khi không khoá nào chạm trần mới INCR tất cả; fallback tuần tự cho store không phải Redis). `LoginService::reserveAttempts` dùng nó, khoá IP đặt TRƯỚC khoá tài khoản. Request bị 429 ở khoá nào cũng không để lại lượt ở khoá nào; bỏ nhánh `hits > max` không hoàn. `CurrentPasswordGuard` (1 khoá) hưởng cùng đường.
- **R1 (NAT lớp học):** `reserveWithCaptchaGate` xác minh captcha TRƯỚC khi có token; token hợp lệ thì lượt chỉ tính vào bộ đếm TÀI KHOẢN (không chạm IP, không bị trần IP chặn). Không token thì giữ chỗ [IP, tài khoản]. Token có nhưng sai → 422 `CAPTCHA_INVALID` (kể cả khi chưa tới ngưỡng). Trần IP mặc định học sinh + staff nâng 50 → 200/giờ (`AUTH_LOGIN_MAX_FAILURES_IP`, `AUTH_STAFF_LOGIN_MAX_FAILURES_IP`), CHỜ PO XÁC NHẬN SỐ.
- **R2/S7:** limiter riêng `login-captcha-reject:<ip>` / `staff-login-captcha-reject:<ip>` (`AUTH_LOGIN_CAPTCHA_REJECTS_PER_MINUTE`, mặc định 30/phút/IP): kiểm trước khi gọi Turnstile, tính mỗi lượt thiếu/sai captcha, vượt thì 429 + `Retry-After`. `TurnstileVerifier`: `connectTimeout(2)->timeout(3)`. Staff: bỏ audit từng lượt `captcha_required`; `captcha_invalid` tối đa 1 dòng/10 phút/tài khoản (`Cache::add`).
- **S6:** `ProductionConfigGuard::guardLoginCaptchaGate` (khối riêng, chỉ production/staging): ngưỡng ngoài 1..20, trần tài khoản `< 20` hoặc `<= ngưỡng + 10`, trần IP `< 20`, cho cả `auth.login.*` và `auth.staff.login_*`; thông báo chỉ nêu tên biến. Test trong `T01/ProductionConfigGuardTest.php` (baseline dùng mặc định hợp lệ nên không phải sửa baseline các test guard khác).
- **S3:** `infra/production/nginx/snippets/vv-real-ip.conf` viết lại cho Cloudflare (`real_ip_header CF-Connecting-IP`, đủ dải IPv4 + IPv6 lấy từ `https://www.cloudflare.com/ips-v4|v6` ngày 2026-10-09); `infra/production/scripts/update-cloudflare-ips.sh` (kiểm định dạng, từ chối `0.0.0.0/0`, `nginx -t` rồi reload, lỗi thì khôi phục); đã chạy `nginx -t` bằng `nginx:1.27` (mẫu và file sau khi script cập nhật) và thử script với nginx giả. Checklist §3.1 sửa dòng `real_ip`, thêm "GL-A2: BE chỉ deploy cùng FE"; README production cập nhật.
- **R4:** `login_race_worker.php` thay `AuditLogger` bằng bộ ghi rỗng (bảng `audit_logs` bất biến ở tầng DB nên không thể xoá sau khi ghi) nên race test không còn để lại dòng audit; thêm chế độ `preset`/`count`, tham số captcha/mật khẩu đúng. Hai file race cũ tắt cổng captcha qua env (ngưỡng 1000) để giữ nguyên ý nghĩa.
- **R5:** contract nêu rõ chỉ 422 do service đăng nhập có `captcha_required`; 422 FormRequest không có (FE coi thiếu = false).
- Test cũ T03/T28: `vvLogin`/`vvAdminLogin` giờ TẮT cổng captcha (ngưỡng 1000), giữ trần cũ 10/tài khoản và 50/IP, không gửi captcha (vì lượt có captcha không còn tính IP).

### Test mới/đổi
- `GL/LoginCaptchaGateTest.php` (25): trần IP chỉ đếm lượt không captcha, NAT 50+ lượt sai rồi đăng nhập đúng + captcha = 200, S1 tuần tự (429 IP không để lại lượt tài khoản; cả staff), `hitAll`, limiter lượt captcha bị từ chối (và IP khác không bị ảnh hưởng), audit staff, trần IP mặc định 200.
- `GL/LoginCaptchaRaceTest.php` (6, group race, học sinh + quản trị): 15 tiến trình không captcha -> đúng 5 lượt so mật khẩu; IP=49 + tài khoản=5, 20 tiến trình -> bộ đếm tài khoản <= 6 và người thật có captcha vào được; tài khoản=99, 10 tiến trình nhiều IP -> <= 100 và người thật vào được.
- `T01/ProductionConfigGuardTest.php`: ma trận S6.

### Kết quả
Pint pass, PHPStan No errors. Nhóm race (GL 6 test, T03, T28; load ~10): pass. Suite T01 T03 T04 T05 T27 T28 T31 GL (không race): xem báo cáo cuối.

## Sửa sau security vòng 2 (laravel-dev, 2026-10-09)

Số mặc định giữ nguyên theo PO (ngưỡng 5, trần tài khoản 100, trần IP 200), thêm các giá trị mới ở dưới.

- **V2-1 (script cập nhật IP Cloudflare):** `infra/production/scripts/update-cloudflare-ips.sh` kiểm MỌI dòng bằng `grep -Ev` (v4: `/8..32`, v6: `/16..128`; dải thật rộng nhất v4 /13, v6 /29), chặn `1.2.3.4/32; ...`, `0.0.0.0/1`, `::/1`; `curl --proto '=https'`; kiểm marker BEGIN/END trong file đích trước khi ghi. Biến `CF_IPS_V4_URL`/`CF_IPS_V6_URL` (+ `CF_IPS_ALLOW_FILE=1` mới nhận `file://`) chỉ để test. Đã chạy với nguồn giả: dòng chèn, v4 `0.0.0.0/1`, v6 `::/1`, file đích thiếu marker đều exit 1 và file không đổi (so checksum); nguồn thật OK (23 dòng `set_real_ip_from`); `file://` bị từ chối khi không bật cờ.
- **V2-2:** trần IP riêng cho lượt có captcha hợp lệ, đếm cùng tài khoản bằng `AtomicCounter::hitAll` (tất-cả-hoặc-không): khoá `login-fail-ip-captcha:<ip>` / `staff-login-fail-ip-captcha:<ip>`, mặc định 1000/giờ (`AUTH_LOGIN_MAX_CAPTCHA_FAILURES_IP`, `AUTH_STAFF_LOGIN_MAX_CAPTCHA_FAILURES_IP`), chạm trần -> 429; hoàn lượt khi đăng nhập đúng.
- **V2-3a:** limiter lượt captcha bị từ chối đổi thành 2 khoá: cặp IP + tài khoản 10/phút (`AUTH_LOGIN_CAPTCHA_REJECTS_PER_MINUTE`) và theo IP 120/phút (`AUTH_LOGIN_CAPTCHA_REJECTS_PER_MINUTE_IP`). Test: IP X gửi 30 token sai vào tài khoản A rồi tài khoản B cùng IP gửi token hợp lệ + mật khẩu đúng -> 200; quét nhiều tài khoản vẫn bị limiter theo IP chặn.
- **V2-3b:** không token mà chạm trần IP -> 422 `CAPTCHA_REQUIRED` (`captcha_required: true`) thay vì 429; có token thì vào được (không bị trần IP thường). Trần tài khoản vẫn 429. Hình dạng response không đổi so với contract FE đang dùng.
- **V2-4:** `ProductionConfigGuard::guardLoginCaptchaGate` thêm: trần IP có captcha < 100 và limiter captcha-reject (cặp và IP) < 10 -> ném lỗi (nêu tên biến).
- **V2-5:** guard ép `cache.stores.<cache.limiter>.driver === 'redis'` ở production/staging; ghi chú Redis Cluster (CROSSSLOT, hash tag) trong `AtomicCounter` và checklist §4; checklist §1.2 thêm dòng guard GL-A2.
- Test: `GL/LoginCaptchaGateTest.php` thêm V2-2, V2-3a (2 test), V2-3b (cập nhật các test trần IP từ 429 sang 422); `T01/ProductionConfigGuardTest.php` thêm ma trận V2-2/V2-4/V2-5; baseline `cache.limiter => redis-limiter` thêm vào các test guard khác (T04, T11, T17, T26, T31, T36, T37, T38 ...) vì phpunit đặt limiter là `array`.
- Contract (§1.6, `/auth/login`, admin login), env mẫu (backend + production), checklist đã cập nhật.

---

# QA (laravel-qa, 2026-10-10)
**Kết quả: FAIL** chỉ vì CI chung chưa xanh (BUG-1, lỗi baseline test T31 do guard V2-5 của task này). Hành vi ứng dụng (backend DEV thật, test QA mới, race) đều đạt. Sửa BUG-1 là chuyển PASS, không cần sửa code ứng dụng.

## Độ phủ (test QA mới: `backend/tests/Feature/GL/QAGlA2LoginTest.php`, 12 test, xanh)
| AC | Test | Kết quả |
|---|---|---|
| Sai >= 5 -> captcha; thiếu token 422 CAPTCHA_REQUIRED, sai CAPTCHA_INVALID, đúng 200 | AC1 | PASS |
| Trần IP không captcha (200) đầy -> 422 CAPTCHA_REQUIRED (không 429), lượt có captcha vào được, hoàn lượt khi đúng, bộ đếm tài khoản không bị đẩy | AC2 | PASS |
| Trần IP có captcha (1000) đầy -> 429 + Retry-After | AC3 | PASS |
| Trần tài khoản (100) đầy -> 429 dù có captcha/mật khẩu đúng; IP không bị cộng | AC4 | PASS |
| V2-3: 30 token sai vào A (10 lượt 422 rồi 20 lượt 429), B cùng IP đăng nhập đúng 200; bản admin | AC5 (2 test) | PASS |
| Limiter theo IP (120/phút) chặn quét nhiều tài khoản | AC6 | PASS |
| Admin qua captcha vẫn vào bước MFA, chưa dùng được API | AC7 | PASS |
| Biên: `captcha_token` mảng / > 2048 ký tự / tiếng Việt có dấu / rỗng, không 500; định danh hoa-thường chung bộ đếm, không lộ khoá hay IP | 2 test | PASS |
| `AtomicCounter::hitAll` trên Redis THẬT: tất-cả-hoặc-không, báo đúng khoá bị chặn, TTL không bị kéo dài, `release` không âm | 2 test | PASS |

Test có sẵn của Dev: `tests/Feature/GL` (không race) toàn bộ xanh; race (`--group=race`, 77 test, gồm 3 test GL captcha race, T03, T28): xanh.

## Backend DEV thật (api.localhost:8000, `CAPTCHA_DRIVER=fake`, tài khoản `seed-e2e-gla2.sh --reset`, đã `--clean`)
| Bước | Kết quả |
|---|---|
| Học sinh sai lần 1-4 | 422 VALIDATION_ERROR, `captcha_required=false` |
| Sai lần 5 | 422, `captcha_required=true` |
| Mật khẩu đúng, thiếu token | 422 `CAPTCHA_REQUIRED` |
| Mật khẩu đúng, token `invalid` | 422 `CAPTCHA_INVALID` |
| Sai mật khẩu + token hợp lệ | 422 chung, vẫn `captcha_required=true` |
| Đúng + token hợp lệ | 200; sai tiếp sau đó -> `captcha_required=false` (bộ đếm reset) |
| Admin (host admin-api): sai 5 lần, thiếu/sai/đúng token | 422/422/422, rồi 200 `mfa_required=true`; `/admin/auth/me` trước MFA = 403 |
| V2-3: 30 token sai vào tài khoản A (không tồn tại) cùng IP | 10 x 422, 20 x 429 |
| Ngay sau đó B (`gla2-hs-1`) đúng + token | 200 |
| A tiếp tục | 429 |

Khoá limiter chỉ của tài khoản e2e (script `--clean`); khoá theo IP/cặp của tài khoản `qa-victim-a@example.com` tự hết hạn trong 60 giây. Bộ đếm IP dev tăng tối đa vài lượt (hết hạn sau 1 giờ).

## Guard và hạ tầng (xem mục QA trong `GL-1.md`)
`CACHE_LIMITER` = file / array / database (staging) bị chặn, `redis` và `redis-limiter` qua; `AUTH_LOGIN_CAPTCHA_THRESHOLD=0` bị chặn ("1..20"); `AUTH_LOGIN_MAX_CAPTCHA_FAILURES_IP=50` bị chặn (">= 100"); `AUTH_LOGIN_CAPTCHA_REJECTS_PER_MINUTE=` rỗng bị chặn (">= 10"). `vv-real-ip.conf` đúng (`real_ip_header CF-Connecting-IP`, chỉ `set_real_ip_from` dải Cloudflare, không `0.0.0.0/0`), `update-cloudflare-ips.sh` đạt mọi ca độc hại (chi tiết ở GL-1).

## Bug
### BUG-1: T31 baseline thiếu `CACHE_LIMITER` (Major, test)
Xem chi tiết ở mục QA của `GL-1.md` (cùng bug): 7 test T31 đỏ khi chạy bằng phpunit XML ép `CACHE_LIMITER=array`. Nguyên nhân là guard V2-5 mới của GL-A2 nhưng các helper T31 chưa đặt `CACHE_LIMITER=redis-limiter`. Không phải lỗi ứng dụng.
### BUG-2: `T24/AdminOrderListTest` phụ thuộc giờ trong ngày (Minor, test cũ), xem `GL-1.md`.

## Rủi ro và đề xuất (đã review bằng đọc code)
- `hitAll` dùng nhiều khoá trong một script Lua: Redis Cluster cần hash tag (đã ghi trong `AtomicCounter` và checklist §4).
- Nhánh không captcha trên ngưỡng: `hitAll` cộng rồi mới hoàn (`releaseAttempts`) khi count > ngưỡng, nên cửa sổ rất ngắn bộ đếm cao hơn thực tế 1; không ảnh hưởng trần vì kiểm `>= max` trước khi cộng. Chấp nhận.
- Khoá tài khoản DoS: kẻ tấn công cần 100 lượt có captcha hợp lệ trong 1 giờ cho mỗi tài khoản (không captcha chỉ tới ngưỡng 5 rồi bị hoàn). Cùng IP/NAT không bị chặn nhờ captcha. Chấp nhận, nên theo dõi số 429 theo tài khoản sau go-live.
- Con số 200/100/1000 vẫn "chờ PO xác nhận" (đã ghi ở config).

## Sửa BUG QA (coordinator, 2026-10-10)
- BUG-1 (test): `T31/ProductionEnvExampleTest`, `Cum4ConfigTest` (`c4ValidInfra`, `c4RunGuardRaw` tự thêm mặc định), `WorkerVideoEnvTest` cấp `CACHE_LIMITER=redis-limiter` và nạp lại config `cache`. Chạy T31 + T01 bằng docker run có mount `infra/production`, không truyền `TURNSTILE_*`: 316 passed. Pint sạch.
- BUG-2 (test cũ T24): `vvT24Range()` lùi 2 ngày thay vì 1 để đơn "30 giờ trước" vẫn lọt khi chạy 0h–6h. T24 (không race) 37 passed lúc 0h40.
- Kết luận sau sửa: **PASS** (hành vi ứng dụng đã đạt ở mục QA; chỉ còn lỗi test, đã sửa và chạy lại).

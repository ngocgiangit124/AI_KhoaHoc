# REVIEW: T17 — Thanh toán: abstraction + MoMo [SEC]

**Kết luận:** APPROVE
**Phạm vi:** nhánh local `t17` (worktree), commit `fe38f3a`; diff so với nhánh chính `claude/zen-dirac-fmucf7...t17` (đã loại phần merge `a49864e`) — 29 file thay đổi (28 file mới + `backend/app/Support/ProductionConfigGuard.php` sửa). Không đụng routes/migrations — đúng phạm vi T17 (chỉ abstraction + adapter, chưa wiring `payment_attempts`/webhook route, để T18/T19).

## Tổng quan
Chất lượng cao, bám sát ADR-001 và checklist Security S4/S12 rất chặt — thực tế còn làm mạnh hơn yêu cầu tối thiểu (allowlist tường minh `KNOWN_GATEWAYS` cho `guardPayments()` thay vì chỉ chặn `fake`; kiểm cả 3 secret MoMo không rỗng ở production; `match` không có nhánh `default` để tự nổ `UnhandledMatchError` nếu thêm cổng mà quên viết guard — đúng bài học M3 đã ghi trong comment). Thứ tự kiểm IPN/query (đủ trường → verify chữ ký bằng `hash_equals` → mới đọc field nghiệp vụ → parse amount nghiêm ngặt) khớp chính xác S12. Tôi đã tự chạy lại toàn bộ (không chỉ tin báo cáo bàn giao):

```
cd infra && docker compose run --rm --no-deps -T \
  -v .../worktrees/t17/backend:/var/www/wt -w /var/www/wt php \
  sh -c 'vendor/bin/pint --test && vendor/bin/phpstan analyse --no-progress && vendor/bin/pest -c phpunit.t17.xml'
```
→ Pint: 198 file sạch. Larastan: No errors. Pest: **336 passed (848 assertions)** — khớp đúng con số báo cáo.

Tôi cũng tự tính lại độc lập (Python `hmac`, không dùng lại code đang test) 2 vector chữ ký trong `MoMoSignerTest.php` (loại `create` và `query request`) — khớp 100% với giá trị hex trong test. Vector là tự tạo (không phải ví dụ chính thức của MoMo — điều này đã được Dev tự ghi rõ trong comment/docblock), nên đây chỉ xác nhận *thuật toán* (thứ tự alphabet + `HMAC-SHA256(secretKey, "k=v&k=v...")`) đúng như ADR-001 §2 mô tả, không xác nhận *danh sách trường* khớp tài liệu MoMo hiện hành — rủi ro này Dev đã tự nêu, xem R1.

## Phát hiện

### R1 [SHOULD] Danh sách trường ký và bảng `resultCode` chưa được đối chiếu với sandbox/tài liệu MoMo thật
- Vị trí: `backend/app/Services/Payments/Gateways/MoMo/MoMoGateway.php` (docblock đầu file), `MoMoResultCode.php`, `backend/app/Console/Commands/Payments/VerifyMomoSandboxCommand.php`.
- Vấn đề: Toàn bộ danh sách trường ký (create/IPN/query request/**query response**) và bảng mã "đang xử lý" (`1000/7000/7002/9000`) hiện dựa vào ADR-001 §2 (chính Dev ADR cũng ghi "Dev xác nhận lại với tài liệu MoMo hiện hành ở T17"), nhưng môi trường viết code bị chặn mạng ra `test-payment.momo.vn` nên **chưa chạy được** `payments:momo:verify-sandbox` với sandbox thật. Nếu tài liệu MoMo hiện hành khác ADR-001 (đổi thứ tự trường, đổi mã pending...), chữ ký sẽ luôn sai → mọi IPN/query thật bị từ chối ở production, hoặc tệ hơn nếu Dev đoán sai bảng mã theo hướng lỏng (coi 1 mã lỗi là Pending) — rủi ro chấp nhận nhầm. Đây không phải lỗi code (thuật toán, thứ tự kiểm, `hash_equals`, cấu trúc đều đúng), mà là một **gate bắt buộc chưa qua được** trước khi cổng MoMo thật chạy ở production.
- Đề xuất: Không chặn merge task này (Dev đã làm hết những gì làm được trong môi trường hiện tại, đã để lại lệnh + runbook rõ ràng), nhưng **bắt buộc** ghi vào `docs/board.md` như một điều kiện go-live riêng (không phải "xong" của T17 theo nghĩa production-ready): chạy `payments:momo:verify-sandbox` với credential sandbox thật trên máy có Internet (Docker local) trước khi bật `PAYMENT_GATEWAYS=momo` ở production, đối chiếu output với tài liệu MoMo hiện hành, cập nhật `MoMoGateway`/`MoMoResultCode`/`MoMoSignerTest` (thay vector tự tạo bằng vector chính thức) nếu có sai khác, rồi mới cho `laravel-security` ký lại phần này.

### R2 [SHOULD] Phản hồi `createPayment` không verify chữ ký, chỉ so khớp partnerCode/orderId/requestId
- Vị trí: `MoMoGateway.php` — `createPayment()`, đoạn kiểm `mismatch` trước khi đọc `resultCode`/`payUrl`.
- Vấn đề: `parseNotification()` (IPN) và `queryStatus()` đều bắt buộc `hash_equals` chữ ký phản hồi trước khi tin bất kỳ field nào (đúng S12). Nhưng response của `createPayment` (POST trực tiếp tới MoMo qua TLS, path `/v2/gateway/api/create`) chỉ so khớp 3 field, không verify `signature` MoMo trả kèm theo response (theo tài liệu MoMo v2, response create cũng có `signature`). ADR-001 §1/§2 không bắt buộc điều này (interface docblock chỉ ghi "Ném GatewayUnavailableException khi timeout/lỗi mạng/resultCode != 0"), nên đây **không phải lỗi lệch spec** — nhưng vì kênh này quyết định `payUrl` mà học sinh sẽ được redirect tới (nếu response bị làm giả do misconfig DNS/proxy nội bộ hoặc lỗi cấu hình TLS ở tầng hạ tầng, `payUrl` giả có thể trỏ tới domain lừa đảo), nên thêm 1 lớp verify chữ ký giống IPN/query sẽ nhất quán và an toàn hơn về lâu dài.
- Đề xuất: Ghi TODO(T18/T19 hoặc ngay khi có vector chính thức ở R1) verify `body['signature']` của response create bằng cùng `MoMoSigner`, danh sách trường theo tài liệu MoMo. Không bắt buộc sửa ngay vì (a) đúng spec ADR hiện tại, (b) `str_starts_with($payUrl, 'https://')` đã có một lớp chặn tối thiểu, (c) nên làm cùng lúc với R1 khi đã có danh sách trường response create chính thức.

### R3 [NIT] `PaymentAttemptReference::$requestId` không được dùng trong `queryStatus()`
- Vị trí: `backend/app/Services/Payments/Data/PaymentAttemptReference.php`, `MoMoGateway::queryStatus()`.
- Vấn đề: `queryStatus()` tự sinh `requestId` UUID mới (đúng ADR-001 §2 "requestId (UUID mới)") và không đọc `$attempt->requestId` ở đâu cả trong thân hàm. Không sai, nhưng khiến người đọc code phải tự suy luận field này để dành cho việc gì (có thể là log/đối chiếu tương lai ở T18 khi map từ Eloquent model).
- Đề xuất: thêm 1 dòng comment ngắn giải thích field này dành cho tầng gọi (T18) đối chiếu/log, không dùng trong adapter — hoặc bỏ khỏi DTO nếu thực sự không cần, tuỳ Dev quyết định khi hiện thực T18.

## Đối chiếu tasks.md T17 / ADR-001

| Yêu cầu | Code đáp ứng | Ghi chú |
|---|---|---|
| `enabled_gateways` + boot guard + Fake chỉ local/testing (S4) | Có | 2 lớp: `ProductionConfigGuard::guardPayments()` (allowlist tường minh `KNOWN_GATEWAYS`, không riêng chặn `fake`) + `PaymentGatewayManager::createFakeDriver()` tự chặn nếu không phải local/testing dù có lỡ nằm trong allowlist. Test cả 2 lớp (`ProductionConfigGuardTest`, `PaymentGatewayManagerTest`) |
| `MoMoSigner` (unit test vector mẫu) | Có, nhưng vector tự tạo | Đúng thuật toán (đã tự verify độc lập bằng Python `hmac`), chưa đối chiếu vector chính thức MoMo — xem R1 |
| `accessKey` từ config; kiểm partnerCode/requestId/orderId; parse amount nghiêm ngặt; bảng mã (chỉ 0 thành công) | Có | `accessKey` luôn lấy từ `$this->config`, không bao giờ từ payload; `StrictAmountParser` từ chối thập phân/khoa học/âm/float/bool/mảng; `MoMoResultCode::toStatus()` chỉ `'0'` → Succeeded, có test `with()` đủ case |
| `orderInfo` không PII; TLS verify; log không secret/PII | Có (phần T17) | `orderInfo` do caller (T18/CheckoutService) truyền — đã có docblock cảnh báo rõ trách nhiệm; `post()` từ chối endpoint không `https://`; `client()` không gọi `withoutVerifying()` (có test tĩnh S12.8); mọi `Log::channel('payments')` chỉ log `request_id`/`gateway_order_id`/`result_code`/`status`, không có `signature`/`secretKey` (test tĩnh S12.9 + tôi đã đọc thủ công toàn bộ các lời gọi log trong `MoMoGateway.php`) |
| `queryStatus` verify chữ ký phản hồi | Có, kỹ hơn yêu cầu | Verify chữ ký bằng `hash_equals` + kiểm thêm `partnerCode`/`orderId`/**`requestId`** của response phải khớp đúng request vừa gửi (chặn được kịch bản trộn lẫn phản hồi của 1 request query khác dù chữ ký hợp lệ) — có test riêng cho case này |
| Kiểm chứng sandbox | Lệnh có, CHƯA chạy được | `payments:momo:verify-sandbox` tự chặn production, không in secret (`rawResponse` đã bỏ `signature`), có runbook chi tiết trong docblock — nhưng chưa thực thi được với sandbox thật (mạng bị chặn). Xem R1 — cần làm trước go-live, không phải điều kiện chặn merge T17 |
| Chặn cổng giả/sandbox ở production; endpoint MoMo đúng allowlist | Có | `guardMomo()` bắt buộc `scheme=https` + `host=payment.momo.vn` (không dùng `str_contains`), bắt buộc đủ `partner_code`/`access_key`/`secret_key` không rỗng |
| Việc khớp `payment_attempts` (T18) | Ngoài phạm vi T17 | Đúng như đầu bài — `PaymentAttemptReference` là DTO tạm, có docblock giải thích rõ lý do tách khỏi Eloquent model, không tạo phụ thuộc ngược |

## Gợi ý cho QA
- Khi T18/T19 nối `PaymentGatewayManager` vào `CheckoutService`/`PaymentWebhookService`: kiểm lại **thứ tự thật** trong `PaymentWebhookService::apply()` — `parseNotification()` (IPN) chỉ trả về `GatewayNotification`, KHÔNG tự so `requestId`/`orderId` với `payment_attempts` trong DB (đúng thiết kế — trách nhiệm này thuộc tầng nghiệp vụ T19 theo ADR-001 §2 mục 3 "orderId khớp attempt; requestId khớp payment_attempts.request_id"). Đảm bảo T19 không bỏ sót bước so khớp này.
- Test race `/pay` (S12.5) và test khoá dòng chuẩn (DBA #2) thuộc T18/T19, không thuộc T17 — đừng kỳ vọng thấy ở đây.
- Trước khi bật `momo` thật ở production: chạy `payments:momo:verify-sandbox`, đối chiếu bảng mã/trường ký, và yêu cầu `laravel-security` re-check nhanh phần `MoMoGateway`/`MoMoResultCode` nếu có thay đổi so với bản đã review này.

---

## Vòng 2 (2026-09-29) — sau khi sửa theo `docs/security/review-T17.md`

**Kết luận vòng 2:** **REQUEST CHANGES**
**Phạm vi:** `git -C <worktree t17> diff 227f00f..d279a82` (đã loại phần merge `227f00f`) — 15 file, gồm sửa M1 (`withoutRedirecting()` + allowlist host `payUrl`), M2 (fail-closed credential rỗng mọi môi trường ở `PaymentGatewayManager::createMomoDriver()` + `MoMoSigner::sign/verify`), L1 (kiểm scalar trước khi ép chuỗi), L2 (`raw` chỉ giữ trường đã biết, đọc `$request->json()->all()` thay vì `->all()`), L3 (lệnh sandbox chặn theo host endpoint + `--amount` nguyên dương), L4 (`#[SensitiveParameter]`), và `docs/security/review-T17.md`, `docs/reviews/review-T17.md` (bổ sung của round 1).

Tôi tự chạy lại độc lập (không chỉ tin báo cáo): `pint --test` sạch (198 file), `phpstan analyse` 0 lỗi, `pest -c phpunit.t17.xml` → **375 passed (915 assertions)** — khớp đúng con số bàn giao.

### Đã kiểm kỹ tính đúng của từng fix (theo yêu cầu điều phối viên)

- **M1 — `withoutRedirecting()`:** đúng, chặn Guzzle tự theo 3xx (mặc định Guzzle theo tối đa 5 lần, kể cả `http://`; với 307/308 còn gửi lại nguyên body POST có `accessKey`/`signature`). `post()` còn kiểm tường minh thêm `$response->redirect()` sau khi gọi — phòng thủ 2 lớp hợp lý (một số driver HTTP có thể không tôn trọng `withoutRedirecting()` giống nhau ở mọi version Guzzle).
- **M1 — pin host `payUrl` (`isTrustedPayUrl()`):** so khớp CHÍNH XÁC `parse_url($payUrl, PHP_URL_HOST)` với allowlist, không dùng `str_contains`/tiền tố. Tôi tự kiểm các kiểu lách thường gặp:
  - **userinfo (`user@host`):** `https://evil.com@payment.momo.vn/pay` → PHP `parse_url` trả `host = payment.momo.vn` (đúng, vì `evil.com` nằm ở vị trí userinfo trước `@`) → được chấp nhận đúng, không phải lỗ hổng vì host thật vẫn là MoMo. Chiều ngược lại `https://payment.momo.vn@evil.com/pay` → `host = evil.com` → bị từ chối đúng.
  - **port:** `https://payment.momo.vn:1234/pay` → `host = payment.momo.vn` (port tách riêng, không ảnh hưởng so khớp host) → được chấp nhận dù port lạ. Đây là lỗ hổng nhỏ (không pin port) nhưng vô hại về mặt tin cậy nguồn (vẫn đúng domain MoMo, DNS+TLS chứng thực domain chứ không chứng thực port) — đã được Dev/Security ghi nhận là **I2 chấp nhận được** trong `docs/security/review-T17.md`, không phải vấn đề mới.
  - **hoa/thường:** PHP `parse_url()` **không lowercase** host. `https://PAYMENT.MOMO.VN/pay` → `host = PAYMENT.MOMO.VN`, so khớp bằng `in_array(..., true)` (strict, phân biệt hoa/thường) với `payment.momo.vn` → **KHÔNG khớp → bị từ chối**. Đây là lỗi về phía "quá chặt" (fail-closed, có thể từ chối nhầm response hợp lệ nếu MoMo trả host viết hoa — hiếm nhưng có thể xảy ra do CDN/load balancer), không phải lỗ hổng bảo mật. Không chặn merge, nhưng nên `mb_strtolower()` cả 2 vế trước khi so khớp để tránh lỡ chặn nhầm response thật (rủi ro về tính sẵn sàng, không phải toàn vẹn).
  → Không tìm được cách lách khiến host lạ được chấp nhận. Kết luận: **thuật toán so khớp đúng**.
- **L2 — đổi `$request->all()` → `$request->json()->all()`:** đây là điểm điều phối viên yêu cầu soi kỹ nhất ("có làm hỏng IPN thật của MoMo nếu Content-Type khác không"). Đã xác nhận:
  - MoMo IPN v2 gửi `Content-Type: application/json` (tài liệu MoMo công khai, đúng như ADR-001 giả định "IPN thật của MoMo là JSON POST" trong comment code) → `$request->json()` hoạt động đúng.
  - Rủi ro thật: nếu vì lý do nào đó (proxy/gateway nội bộ, hoặc MoMo đổi hành vi) IPN đến với `Content-Type` khác (`application/x-www-form-urlencoded` hoặc thiếu header) thì `Illuminate\Http\Request::json()` **không tự fallback** sang `$request->request` — nó luôn cố `json_decode($this->getContent())`. Nếu content không phải JSON hợp lệ, `json()->all()` trả mảng rỗng `[]` (Symfony `InputBag` rỗng khi decode thất bại) chứ không throw. Hệ quả: toàn bộ IPN đó rơi vào nhánh "thiếu trường bắt buộc" (`array_key_exists` fail cho mọi field) → `InvalidSignatureException` → **400, không 500** (đã kiểm code: vòng lặp required-fields chạy trước, ném lỗi có kiểm soát). Đây là hành vi **fail-closed đúng hướng** (từ chối IPN thay vì hiểu sai thành thanh toán thành công), không có nguy cơ tạo lỗ hổng toàn vẹn — chỉ có nguy cơ về **tính sẵn sàng** nếu MoMo thực tế gửi form-encoded thay vì JSON (giả định trong comment code sai). Đây là rủi ro cùng nhóm với R1 (chưa kiểm chứng sandbox thật) — không phải lỗi mới, không chặn merge, nhưng nhấn mạnh thêm tầm quan trọng của R1.
  - Không tìm thấy cách nào Content-Type khác khiến `parseNotification()` chấp nhận sai/verify sai chữ ký (vẫn fail-closed).
- **M2:** `MoMoSigner::sign()` ném lỗi khi `secretKey === ''`, `verify()` luôn trả `false` khi `secretKey === ''` (không gọi `sign()` nên không lộ exception ra ngoài luồng verify) — đúng yêu cầu "verify là đường dữ liệu không đáng tin, phải luôn từ chối, không ném lỗi". `PaymentGatewayManager::createMomoDriver()` kiểm đủ 4 khoá (`partner_code/access_key/secret_key/endpoint`) không rỗng ở **mọi** environment (không chỉ production) — đúng thiết kế fail-closed, có test 4 khoá × rỗng/khoảng trắng.
- **L1, L3, L4:** đọc code + test đều đúng như mô tả trong `docs/security/review-T17.md`, không phát hiện thêm vấn đề.

### R4 [BLOCKER] `ProductionConfigGuard::guardMomo()` không ràng buộc `payments.gateways.momo.pay_url_hosts` ở production — mặc định vẫn cho phép host sandbox
- Vị trí: `backend/app/Support/ProductionConfigGuard.php:141-161` (`guardMomo()`), `backend/config/payments.php` (`pay_url_hosts`), `backend/.env.example:91`.
- Vấn đề: M1 thêm allowlist `pay_url_hosts` để pin đúng host `payUrl` trả về từ MoMo — đúng hướng. Nhưng giá trị **mặc định** trong `.env.example`/`config/payments.php` là `MOMO_PAY_URL_HOSTS=payment.momo.vn,test-payment.momo.vn` (gộp cả 2 môi trường vào 1 default dùng chung), và `guardMomo()` — nơi duy nhất chịu trách nhiệm "khoá cứng cấu hình đúng ở production" cho toàn bộ phần MoMo (đã có sẵn logic tương tự cho `endpoint`, `partner_code`, `access_key`, `secret_key`) — **không kiểm `pay_url_hosts` ở production**. Hệ quả: nếu vận hành chỉ copy `.env.example` sang `.env` production và quên override riêng `MOMO_PAY_URL_HOSTS` (rất dễ bỏ sót — đây là biến mới, tên không gợi ý rõ "phải khác nhau theo môi trường" như `MOMO_ENDPOINT`), ứng dụng **vẫn boot bình thường ở production** với allowlist chứa cả `test-payment.momo.vn`. Khi đó, đúng kịch bản tấn công mà M1 mô tả (MoMo/proxy/egress trả một `payUrl` bất thường) chỉ cần trỏ tới `test-payment.momo.vn` thay vì domain hoàn toàn lạ là **vẫn được production chấp nhận** — thu hẹp nhưng không đóng lỗ hổng M1 tại production, và quan trọng hơn: **không có test nào bắt được việc thiếu ràng buộc này** (`ProductionConfigGuardTest.php` không đổi trong commit sửa, không có case nào set `pay_url_hosts` chứa host lạ/sandbox rồi gọi `check()` ở production).
- Đây đúng là kiểu lỗi mà chính `ProductionConfigGuard` được thiết kế ra để chặn (comment đầu file: "ALLOWLIST... thay vì blocklist... không để lộ đường 'an toàn giả' phụ thuộc vào việc ops nhớ set đúng biến môi trường" — bài học M3/M4 đã rút ra trước đó trong chính file này). `guardMomo()` đã tự áp dụng nguyên tắc này cho `endpoint`/3 secret, nhưng bỏ sót đúng field mới thêm ở vòng sửa bảo mật lần này.
- Vì PO đã quyết định tạm hoãn security review tới cuối dự án và vòng review này được xác định là **cổng cuối trước khi gộp**, tôi xếp đây là BLOCKER thay vì SHOULD: đây là một điều kiện an toàn có chủ đích (M1) bị vô hiệu hoá một phần bởi thiếu 1 dòng ràng buộc ở đúng nơi lẽ ra phải có, chi phí sửa rất thấp, và không có review bảo mật nào khác sẽ bắt lại việc này trước khi lên production.
- Đề xuất:
  ```php
  private function guardMomo(): void
  {
      // ... giữ nguyên phần endpoint/secret hiện có ...

      $payUrlHosts = (array) config('payments.gateways.momo.pay_url_hosts', []);

      throw_if(
          $payUrlHosts !== ['payment.momo.vn'],
          RuntimeException::class,
          "MOMO_PAY_URL_HOSTS ở production phải đúng CHỈ 'payment.momo.vn' (không được kèm host sandbox), hiện là: ".implode(',', $payUrlHosts)
      );
  }
  ```
  Kèm test trong `ProductionConfigGuardTest.php`: `pay_url_hosts` mặc định (2 host) ở production → ném lỗi; `pay_url_hosts = ['payment.momo.vn']` → không ném. Đồng thời cân nhắc tách `MOMO_PAY_URL_HOSTS` khỏi `.env.example` chung — hoặc ghi rõ trong comment `.env.example` rằng **bắt buộc override** ở production thành đúng 1 giá trị `payment.momo.vn`.

### Điểm nhỏ ghi nhận thêm (không chặn merge)
- `isTrustedPayUrl()` so khớp host `strict` (phân biệt hoa/thường) — nên `mb_strtolower()` cả host thu được lẫn từng phần tử allowlist trước khi so khớp, để tránh từ chối nhầm nếu MoMo/hạ tầng trả host viết hoa (rủi ro tính sẵn sàng, không phải bảo mật — NIT).
- Giả định "IPN MoMo luôn là JSON" (nền tảng của fix L2) chưa được xác nhận với sandbox thật — cùng nhóm rủi ro với R1, nhấn mạnh thêm lý do R1 phải là gate go-live bắt buộc trước khi bật MoMo thật.

### Đối chiếu điều kiện của `docs/security/review-T17.md`

| Điều kiện | Trạng thái | Ghi chú |
|---|---|---|
| M1 sửa + test trước T18 | Đạt phần lớn, còn hở ở production default | Thuật toán/logic đúng (đã tự kiểm bypass userinfo/port/hoa-thường); nhưng `ProductionConfigGuard` chưa khoá cứng `pay_url_hosts` → xem R4 (BLOCKER) |
| M2 sửa + test trước T19 | Đạt | Fail-closed mọi environment, test đủ 4 khoá × rỗng/khoảng trắng |
| L1-L4 | Đạt | Đọc code + test khớp mô tả |
| R1 (gate go-live, không chặn merge T17) | Chưa đổi, đúng như đã thống nhất | Vẫn cần chạy `payments:momo:verify-sandbox` với sandbox thật trước khi bật MoMo production — nay có thêm lý do L2 (giả định Content-Type JSON) cũng cần xác nhận cùng lúc |

**Kết luận cuối:** REQUEST CHANGES do R4. Sau khi thêm ràng buộc `pay_url_hosts` vào `guardMomo()` (kèm test), coi như đủ điều kiện gộp — không cần vòng review bảo mật riêng nữa vì thay đổi chỉ là mở rộng đúng pattern đã có sẵn trong cùng file, `laravel-reviewer` có thể tự xác nhận nhanh ở vòng 3 mà không cần gọi lại `laravel-security`.

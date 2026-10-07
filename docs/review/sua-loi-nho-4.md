# REVIEW: Sửa lỗi nhỏ 4 (throttle catalog cho SSR + Nginx mẫu production)
**Kết luận:** APPROVE
**Phạm vi:** thay đổi chưa commit trên `main` (ADR-004 §2.8) · 9 file thuộc task (không tính file T37/Bunny, `frontend/`)

## Tổng quan
`CatalogThrottle` đúng 3 nhánh của ADR. Mẫu Nginx đáp ứng đủ các điểm yêu cầu. Test (a)–(e) kiểm đúng hành vi cần kiểm. Không có BLOCKER. Còn một SHOULD (Nginx mẫu chưa có test tự động) và vài NIT.

## Phát hiện
### R1 [SHOULD] Cấu hình Nginx §2.8 không có test tự động
- Vị trí: `infra/production/nginx/conf.d/vitaminvui.conf:83-106` (listener 8081), `:226-238` (`limit_req` host web).
- Vấn đề: không test nào grep `vitaminvui.conf` hoặc `8081` (đã grep `backend/tests`). Quyết định quan trọng nhất của §2.8 là ép `HTTP_HOST`, `allow`/`deny`, `limit_except`, xoá header nội bộ ở host công khai, và `/_next/static/` không có `limit_req`. Những điểm này có thể bị sửa nhầm mà không ai biết. Các test T31 hiện có đều `skip` khi không mount `infra/`.
- Đề xuất: thêm một test tĩnh trong `backend/tests/Feature/T31/`, dùng cùng cách `skip` như `Cum4ConfigTest`. Test kiểm tra:
  - server 8081 chứa `fastcgi_param HTTP_HOST api.vitaminvui.vn`, `deny all`, `limit_except GET HEAD`;
  - 4 host công khai đều có `X-Internal-Token ""` và `X-Client-IP ""`;
  - block `/_next/static/` không chứa `limit_req`;
  - block `location /` của host web có `limit_req_status 429`.
  Có thể thêm sau, nhưng nên làm trước khi chuyển staging.

### R2 [NIT] IPv6 chưa chuẩn hoá khi làm khoá bucket
- Vị trí: `backend/app/Support/CatalogThrottle.php:32,38`.
- Vấn đề: `filter_var` chấp nhận nhiều cách viết cùng một địa chỉ IPv6 (`::1`, `0:0:0:0:0:0:0:1`, hoa/thường), và cả dạng IPv4-mapped. Mỗi cách viết tạo một bucket riêng. Chỉ client có token mới đặt được `X-Client-IP` (tức Next), và Next lấy IP từ `$remote_addr` của Nginx nên đã ở dạng chuẩn. Rủi ro thực tế gần như bằng 0.
- Đề xuất (tuỳ chọn): `inet_ntop(inet_pton($clientIp))` để chuẩn hoá trước khi `by(...)`.

### R3 [NIT] Limiter theo mảng vẫn tăng bộ đếm theo IP khi trần tổng đã đầy
- Vị trí: `CatalogThrottle.php:38`.
- Vấn đề: `ThrottleRequests` của Laravel hit từng limit theo thứ tự. Request bị trần tổng chặn vẫn đã cộng 1 vào bucket IP khách trước đó. Hậu quả nhỏ (chỉ trong lúc cạn trần tổng, chỉ cho truy vấn `q`). Có thể bỏ qua.

### R4 [NIT] File dùng chung chứa thay đổi T37
- Vị trí: `docs/ops/production-checklist.md` (mục Bunny, §2.1, hàng bảng 1.2), `infra/production/.env.production.example`, `backend/.env.example` (khối Bunny), `docs/architecture/api-contract.md` (dòng webhook Bunny).
- Vấn đề: các file này trộn thay đổi của Sửa lỗi nhỏ 4 và T37. Cần commit tách hoặc có ghi chú rõ để không lẫn khi PO duyệt. Phần của task này (chú thích `CATALOG_SSR_TOTAL_PER_MINUTE`, checklist FE/Next, api-contract §1.6) đều nhất quán với ADR.

## Kiểm tra theo yêu cầu
- **Logic 3 nhánh** (`CatalogThrottle.php:27-38`):
  - Token sai hoặc thiếu: trả `Limit` theo `$request->ip()`, bỏ qua `X-Client-IP`.
  - Token đúng + IP hợp lệ: trả `[perIp theo 'ssr-client:'.$ip, total]`.
  - Token đúng + thiếu hoặc sai IP: chỉ trả `$total`.
  - Cả ba nhánh khớp bảng ADR.
- **Giả `X-Client-IP`:** khách không có token thì header bị bỏ qua (test d). Ở Nginx, 4 host công khai xoá `X-Internal-Token` và `X-Client-IP` (web, admin: `proxy_set_header ... ""`; api, video: `fastcgi_param ... ""` trong snippet). Giả header không né được limit.
- **So sánh token:** `hash_equals(sha256, sha256)` hằng thời gian, che cả độ dài. Token rỗng hoặc chưa cấu hình thì tắt luôn nhánh nội bộ.
- **Không đọc `X-Forwarded-For`:** đúng.
- **Test:**
  - (a) và (b): 130 request vượt 120 vẫn 200, request thứ 131 trả 429 với code `TOO_MANY_ATTEMPTS`. Chứng minh không còn bucket theo IP của Next.
  - (c): hai IP khách có bucket riêng.
  - (d): token sai kèm 120 IP giả khác nhau vẫn bị chặn theo IP kết nối.
  - (e): hai loại request dùng chung trần `ssr-total`.
  - `beforeEach` có `Cache::flush`. `OperationsTest` đã bỏ phần cũ "IP rác rơi về IP kết nối", vì hành vi này đã đổi và được test (b) thay thế.
- **Nginx listener 8081:**
  - Host: `fastcgi_param HTTP_HOST api.vitaminvui.vn` ép đúng. `fastcgi_params` mặc định không định nghĩa `HTTP_HOST` nên không bị trùng.
  - Truy cập: `allow <IP_NEXT_SERVER>` + `deny all`.
  - Phạm vi: `location / { return 404; }` và `^~ /api/v1/` + `limit_except GET HEAD { deny all; }`.
  - Header nội bộ: không xoá `HTTP_X_INTERNAL_TOKEN` và `HTTP_X_CLIENT_IP` (cần để chúng tới PHP), có ghi chú rõ.
  - Khác: `client_max_body_size 1k`, `access_log` riêng, không có `location ~ \.php`.
- **`limit_req` host web:** zone `vv_web` 10r/s, `burst=200 nodelay`, `limit_req_status 429` đặt trong `location /`. `/_next/static/` là location riêng, không có `limit_req`. Giá trị khởi điểm hợp lý cho lớp NAT ~40 máy. Host web có `vv-real-ip.conf` nên `$binary_remote_addr` là IP khách thật.
- **25 skip:** toàn bộ nằm trong `backend/tests/Feature/T31` (`Cum4ConfigTest`, `WorkerVideoEnvTest`, `ProductionEnvExampleTest`), lý do "container php không mount `infra/production`". Không có skip ở T26, T10, T36. Skip không che test của task này, nhưng test nghiệm thu `.env.production.example` cũng không chạy trong lần xanh này. Nên chạy một lần bằng `docker run` có mount `infra/production` (checklist §7), nhất là vì file đó có thay đổi của T37. Khi làm R1 nên chạy luôn.

## Đối chiếu acceptance criteria (tasks.md "Sửa lỗi nhỏ 4" / ADR-004 §2.8)
| Yêu cầu | Code đáp ứng | Ghi chú |
|---|---|---|
| Token đúng + IP hợp lệ: 120/IP khách + `ssr-total` | `CatalogThrottle.php:38`, test (c) | Đạt |
| Token đúng, thiếu hoặc sai IP: chỉ `ssr-total` | `CatalogThrottle.php:34-36`, test (a) (b) (e) | Đạt |
| Token sai hoặc thiếu: theo IP kết nối | `CatalogThrottle.php:27-29`, test (d) | Đạt |
| Nginx `limit_req` host web, không áp `/_next/static/` | `vitaminvui.conf:22,214,227-228` | Đạt, chưa có test (R1) |
| 8081 ép Host, chỉ IP Next, GET/HEAD `/api/v1/`, log riêng | `vitaminvui.conf:83-106` | Đạt, chưa có test (R1) |
| Tài liệu: api-contract §1.6, checklist, `.env` | đã sửa | Đạt, lẫn nội dung T37 (R4) |

## Gợi ý cho QA
- Dựng Nginx thật, từ máy Next chạy `curl http://<IP>:8081/api/v1/subjects` không đặt `Host` thì phải ra 200. Từ IP khác thì 403. Dùng POST thì 403. Gọi `/` thì 404.
- Gửi `X-Internal-Token` và `X-Client-IP` tới `https://api.<domain>` và `https://<domain>`, xác nhận Laravel không thấy header đó (token vẫn tính như khách thường).
- Bắn 300 request liên tiếp vào `https://<domain>/` thì có 429. Cùng lúc `/_next/static/...` không bị 429.
- Chạy `T31` với mount `infra/production` để 25 test skip thực sự chạy.

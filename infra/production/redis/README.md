# Redis ACL cho worker-video (cụm 4 M1, R1/R2)

Vì sao: worker-video chạy ffmpeg trên file người dùng tải lên (ADR-002 §3a: thành phần KHÔNG tin cậy). Nếu dùng chung mật khẩu
Redis của app, chiếm được worker là (1) ghi payload `O:...` vào `queues:default` để worker `vitaminvui-queue` của app `unserialize`
(có APP_KEY, DML toàn DB, khoá thanh toán) và (2) sửa phiên ở DB 1. Worker còn không được dispatch webhook vào queue của app: việc đó do
`videolab:notify` (scheduler app) làm.

## Hai file mẫu (file ACL KHÔNG hỗ trợ comment `#`: chỉ được có dòng `user ...`, đã có test kiểm)
- `users.acl`: dùng chung một Redis với app (user `default` cho app, `vv_worker_video` cho worker).
- `users.video-instance.acl`: Redis RIÊNG cho queue `video` (khuyến nghị, xem dưới). User worker không cần đọc cache.

## Cách dùng
1. Thay `<PREFIX>` bằng `REDIS_PREFIX` (mặc định `vitaminvui-database-`) và `<CACHE_PREFIX>` bằng `CACHE_PREFIX` (mặc định `vitaminvui_cache`).
   Tên key thật = `<PREFIX><CACHE_PREFIX>illuminate:...`: KHÔNG có dấu `:` giữa hai phần (Laravel nối thẳng).
2. Mật khẩu ghi dạng hash SHA-256 `#<hex>` (`printf '%s' "$MAT_KHAU" | sha256sum`), mật khẩu >= 32 ký tự ngẫu nhiên, khác nhau
   giữa user và giữa staging/production.
3. `redis.conf`: `aclfile /etc/redis/users.acl` (chmod 600). KHÔNG dùng cùng `requirepass` hay `user` trong redis.conf. Nạp lại: `ACL LOAD`.
4. Kiểm: `check-acl.sh <host> <port> <PREFIX> <CACHE_PREFIX> vv_worker_video "$MAT_KHAU_WORKER"` (đặt `SKIP_SIGNALS=1` với instance riêng).

## Quyền của `vv_worker_video` (và vì sao)
- 4 key queue `video`: hàng đợi, `:delayed`, `:reserved`, `:notify` (liệt kê tường minh, không glob).
- Lệnh: `eval` (Laravel dùng `EVAL`, không `EVALSHA`), và vì Redis kiểm quyền từng lệnh/từng key BÊN TRONG script nên phải cấp các lệnh script gọi:
  `lpop`, `zadd`, `zrem`, `zrangebyscore`, `zremrangebyrank`, `rpush`; thêm `llen`, `zcard` (size), `blpop` (khi bật `block_for`), `select`, `ping`.
- Bản dùng chung thêm `get`, `mget` và quyền ĐỌC (`%R~`) 3 key tín hiệu `queue:work`: `illuminate:queue:restart`, `illuminate:queues:paused`,
  `illuminate:queue:paused:redis_video:video`. Thiếu thì `queue:work` ném lỗi mỗi vòng lặp và không nhận job.
- Không có: `scan`/`keys`, `del`, `flush*`, `config`, `script`, `@dangerous`, pub/sub. ACL không phân theo số DB; cô lập dựa trên TÊN KEY.

## Rủi ro còn lại và khuyến nghị: Redis riêng cho queue `video` (R2)
`+eval` cho phép worker bị chiếm chạy một script vòng lặp vô hạn: Redis (đơn luồng) ngừng phục vụ, và nếu script đã ghi thì `SCRIPT KILL` không dùng được.
Nếu dùng chung Redis, đó là mất sẵn sàng toàn site (phiên, cache, queue, limiter), không phải rò rỉ dữ liệu. Giảm thiểu: **dùng một Redis nhỏ riêng cho queue `video`**:
- App và worker đặt `REDIS_VIDEO_HOST`, `REDIS_VIDEO_PORT`, `REDIS_VIDEO_USERNAME`, `REDIS_VIDEO_PASSWORD` (và tuỳ chọn `REDIS_VIDEO_DB`); không đặt thì connection `video` dùng Redis chính (local không đổi).
- App dùng user `default` của instance video (`users.video-instance.acl`) để dispatch; worker dùng `vv_worker_video`.
- Worker đặt `CACHE_STORE=array`: không cần chạm Redis chính (đổi lại `queue:restart`/`queue:pause` không tác động worker; worker tự thoát theo `--max-time=3600`, deploy image mới là cách cập nhật).
- Nếu vẫn dùng chung: giám sát độ trễ Redis (`redis-cli --latency`), cảnh báo khi vượt ngưỡng; chấp nhận rủi ro này (đã ghi vào backlog-v2).

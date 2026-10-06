# REVIEW: khoá tạm checkout có tính tiền + T30 (phần không thanh toán)
**Kết luận:** REQUEST CHANGES (0 Blocker · 2 Major · 4 Minor)
**Phạm vi:** diff chưa commit của CheckoutService, CheckoutPreviewResource, PublicConfigController, config/features.php, config/ops.php, OperationsServiceProvider, .env.example, checkout_race_worker, api-contract, T18/CheckoutApiTest + 2 command purge mới + tests/Feature/T30. Không review code T12/T23.
**Kiểm tra đã chạy:** T18 + T30 (không race) 46 passed; T18 `--group=race` 5 passed; phpstan 5 file sạch.

## Tổng quan
Khoá checkout đặt đúng chỗ và gọn. Việc 1 sạch. Việc 2 có một lỗi mất dữ liệu `consents` trong race và một điểm kém bền: một user lỗi làm hỏng cả lô.

## Việc 1 — khoá checkout: sạch
- `CheckoutService::prepare()` ném 503 sau khi so `expected_total` và trước `assertPayable`. Nó nằm trước `createOrder`, trước nhánh dùng lại đơn pending và trước `transition(... superseded)`. Vì vậy cờ tắt thì không có nhánh nào tạo, dùng lại hay huỷ đơn, và không tạo attempt hay gọi cổng. Throw nằm trong `DB::transaction` nên rollback. `initiatePayment` chỉ có một chỗ gọi (`checkout()`), và chỉ chạy sau `prepare()`.
- Chưa có đường nào khác tạo attempt. T20 (`/orders/{code}/pay`) chưa có route trong `routes/api.php`. **Khi làm T20 phải kiểm cùng cờ**, vì đơn pending cũ vẫn sống tới `expires_at`.
- Đơn 0đ: điều kiện `total > 0` nên không bị ảnh hưởng. Test đơn 0đ xanh.
- Thứ tự lỗi: cổng không hợp lệ (422) → giỏ trống (422) → `CHECKOUT_CHANGED` (409) → `PAYMENT_DISABLED` (503). Đã ghi vào contract. Preview, `paid_checkout_enabled`, `.env.example` và race worker đều khớp.

### R1 [Minor] Test cờ tắt thiếu 2 tình huống
- Vị trí: `backend/tests/Feature/T18/CheckoutApiTest.php:550`
- Thiếu (a) đã có đơn pending tổng > 0 rồi tắt cờ, POST lại phải 503, không tạo attempt mới, đơn cũ không bị `superseded`; (b) `GET /config/public` trả `paid_checkout_enabled` đúng cả hai trạng thái.
- Đề xuất: thêm 2 test. Ví dụ (a): bật cờ, POST 100000 → 201; tắt cờ; POST lại → 503; kiểm `Order::count()==1`, `PaymentAttempt::count()==1`, status vẫn `pending`.

### R2 [Minor] 503 không có `Retry-After`
- Vị trí: `CheckoutService.php:210`
- Đây là tắt chủ động, không phải sự cố. FE phải dựa vào `code`. Cần ghi rõ trong contract: FE không retry tự động và dùng `paid_checkout_enabled` để ẩn nút. Có thể đổi sang 403 hoặc 409. Nếu giữ 503, hãy kiểm xem monitor, health hoặc alert của dự án có đếm 5xx từ API không.

## Việc 2 — purge

### R3 [Major] Xoá `consents` trước, lệnh DELETE user sau, không rollback nếu DELETE không xoá được user
- Vị trí: `UsersPurgeUnverifiedCommand.php:56-61`
- Vấn đề: transaction xoá `consents` theo `$ids` đã chọn từ trước (dòng 57), rồi mới kiểm lại điều kiện ở câu DELETE user (dòng 60). Nếu trong khoảng giữa hai bước, user vừa xác thực OTP (hoặc phát sinh order/enrollment), DELETE user trả về ít dòng hơn nhưng không throw. Transaction vẫn commit và user còn sống bị mất bằng chứng đồng ý. Trường hợp user có FK mới chen vào thì DELETE throw và rollback nên không sao. Race này chỉ xảy ra với các user vừa xác thực, hoặc vừa chuyển trạng thái nhưng không có FK.
- Đề xuất: khoá các hàng đủ điều kiện trước, rồi chỉ xoá consents của đúng các hàng đã khoá.
  ~~~php
  DB::transaction(function () use ($ids, $cutoff): int {
      $locked = $this->candidates($cutoff)->whereIn('users.id', $ids)
          ->lockForUpdate()->pluck('users.id')->all();
      if ($locked === []) {
          return 0;
      }
      DB::table('consents')->whereIn('user_id', $locked)->delete();
      return DB::table('users')->whereIn('id', $locked)->delete();
  });
  ~~~
  Khoá X trên `users` sẽ chặn việc xác thực (UPDATE) và chặn INSERT con có FK tới user đó. Giữ lại điều kiện trong DELETE cuối nếu muốn phòng thủ thêm. Thêm test mô phỏng: user được xác thực sau khi chọn lô thì `consents` còn nguyên. Cách đơn giản là gọi hàm xử lý lô riêng rồi cho user verified trước khi gọi.

### R4 [Major] Một user lỗi làm bỏ cả lô 1000 và không có đường xử lý
- Vị trí: `UsersPurgeUnverifiedCommand.php:62-66`
- Vấn đề: bất kỳ lỗi nào (FK, deadlock, mất kết nối) khiến cả lô bị đưa vào `$failedIds`. `whereNotIn` với `$failedIds` có thể vượt giới hạn tham số khi nhiều lô lỗi. Lệnh vẫn trả `SUCCESS` nên cron không báo lỗi. Ngoài ra `catch Throwable` nuốt cả lỗi hạ tầng và log chỉ ghi tên class, không có `ids` hay message.
- Đề xuất: khi lô lỗi thì thử xoá từng user một (transaction riêng) để cô lập user lỗi. Ghi `Log::warning` kèm `count` và `$e->getMessage()`, không ghi dữ liệu cá nhân. Nếu có user bị bỏ qua thì trả `self::FAILURE` hoặc in cảnh báo. Với `failedIds`, dùng bộ lọc `id > $lastId` thay cho `whereNotIn`, vì đã `orderBy id`.

### R5 [Minor] Không ghi dấu vết lần chạy purge
- Vị trí: `AuditPurgeCommand.php:48`, `UsersPurgeUnverifiedCommand.php:71`
- Xoá hàng loạt dữ liệu cá nhân chỉ in ra console. Nên `Log::info` tổng số dòng (không chứa dữ liệu cá nhân). Tuỳ chọn: ghi một dòng audit "purge ran" ngay sau khi xoá (đã có `AuditLog`, và audit mới hơn cutoff nên không bị xoá).

### R6 [Minor] `usleep` sau lô cuối; `--months` / `--days` nhận giá trị không phải số
- Vị trí: `UsersPurgeUnverifiedCommand.php:68`, `AuditPurgeCommand.php:21`, `UsersPurgeUnverifiedCommand.php:25`
- `usleep` chạy ngay cả khi lô cuối nhỏ hơn `$chunk`. Chuỗi `abc` được ép về 0 nên bị từ chối bởi `< 1`; vậy nhưng `--days=abc` báo "phải >= 1" thay vì "không phải số". Không quan trọng, tuỳ Dev.

## Các điểm đã kiểm và ổn
- Lệnh DELETE user kiểm lại đủ 5 điều kiện loại trừ + chưa xác thực cả email/SĐT + role học sinh + `created_at`. FK `restrict` là lưới an toàn thứ hai. Không xoá nhầm staff, và test có GV chưa xác thực.
- `carts`, `otp_codes` và `staff_devices` cascade. `consents` restrict, và đã được xử lý (xem R3). Không có bảng `restrict` nào khác trỏ tới học sinh ngoài 6 bảng đã loại trừ. `audit_logs.user_id` không có FK nên log cũ của user bị xoá vẫn còn, và hết hạn theo audit purge.
- `audit:purge`: `DB::table` hợp lý vì model chặn xoá (S15); dùng `where created_at < cutoff` có index `created_at`, `orderBy('id')->limit` để xoá theo lô, nghỉ giữa lô. Cần cấp quyền DELETE cho user DB của scheduler (đã có comment). `--months` được kiểm >= 1.
- Lịch 03:40/03:50 có `withoutOverlapping` + `onOneServer` (đã có test).

## Đối chiếu yêu cầu
| Yêu cầu | Code đáp ứng | Ghi chú |
|---|---|---|
| Cờ tắt không tạo đơn/giao dịch tính tiền | `CheckoutService:210` | OK, chặn trước mọi ghi |
| Đơn 0đ/đăng ký miễn phí không ảnh hưởng | điều kiện `total > 0`; test 0đ | OK |
| Mã lỗi rõ cho FE | 503 `PAYMENT_DISABLED`, preview `can_checkout=false` + notice, `paid_checkout_enabled` | OK (R2) |
| audit:purge 24 tháng, lô, dry-run | `AuditPurgeCommand` | OK (R5) |
| users:purge-unverified không race | transaction + kiểm lại trong DELETE | Còn R3, R4 |

## Gợi ý cho QA
- Cờ tắt: đơn pending cũ + POST lại; coupon đang được giữ chỗ bởi đơn pending cũ khi cờ tắt (giữ tới `coupon_hold_until`); `expected_total` sai khi cờ tắt phải ra 409 chứ không phải 503.
- Bật lại cờ sau khi tắt: đơn pending cũ dùng lại bình thường.
- Purge: user vừa xác thực/vừa ghi danh trong lúc chạy (R3); user có `consents` nhiều dòng; lô > `purge_chunk`; user bị `parent_consent_status=pending`; `--dry-run` khớp số thật.
- Xác nhận không có luồng admin tạo hộ học sinh chưa xác thực rồi cấp quyền sau 7 ngày (sẽ bị purge).

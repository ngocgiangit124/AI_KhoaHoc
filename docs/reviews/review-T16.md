# REVIEW: T16 Giỏ hàng (US-004)
**Kết luận:** PASS (APPROVE) — 0 BLOCKER, 5 SHOULD, 5 NIT. Nên sửa R1 và quyết R2 trước khi gộp, vì security review hoãn nên đây là cổng cuối cho S18.
**Phạm vi:** `git diff claude/zen-dirac-fmucf7...claude/zen-dirac-fmucf7-t16` (commit 61aafc3) · 32 file (migration x2, Model x2 + factory x2, Service/DTO x11, Controller x3, Request x2, Resource, config, routes, MeResource, CourseViewerStateService, AppServiceProvider, test x4).
Đã dùng kết quả báo cáo: Pint/Larastan sạch, Pest 739 pass (điều phối viên đã chạy lại). Reviewer không chạy migrate, không chạy Redis thật (xem R4).

## Tổng quan
Code gọn, đúng mẫu các task trước. Giỏ luôn suy từ `$request->user()`, không có tham số chọn giỏ nên không có IDOR. `carts` là mutex đúng thứ tự khoá chuẩn. `PricingCalculator` là hàm thuần toàn số nguyên, mình đã kiểm chứng bằng suy luận: tổng phân bổ bằng đúng tổng giảm, mỗi dòng trong [0, giá dòng], không âm, không tràn. Limiter S18 đảo thứ tự "đếm trước, hoàn khi thành công" là đúng hướng và đúng ngữ nghĩa. Điểm yếu chính: limiter đếm chéo giữa hai khoá (R1), lệch AC8/contract khi gộp `COUPON_EXPIRED` (R2), và route xoá dòng giỏ lộ tồn tại khóa nháp (R3).

## Phát hiện

### R1 [SHOULD] Limiter: request bị chặn theo khoá này vẫn đốt hạn mức khoá kia (khuếch đại, tự gây DoS cả NAT)
- Vị trí: `backend/app/Services/Cart/CouponAttemptLimiter.php:45-51` (vòng `foreach` hit TẤT CẢ khoá rồi mới xét `$blocked`).
- Vấn đề: khi HS A đã chạm trần tài khoản (30 lần sai), mỗi request tiếp theo của A vẫn `hit()` khoá IP. Route chỉ có `throttle:coupon` 10/phút, nên A cần khoảng 7 phút để đẩy khoá IP quá 100 và khoá cả lớp/trường dùng chung NAT trong 24 giờ, dù tổng số lần SAI thật chỉ là 30. Chiều ngược lại: HS vô can bị chặn bởi IP vẫn bị cộng vào hạn mức tài khoản của chính mình. Đếm-chéo này không nằm trong ý định "chỉ đếm lần sai" của S18. (Một HS phá cố tình vẫn có thể đốt 100 lần sai/ngày của IP; đó là đánh đổi cố hữu của trần theo IP, chấp nhận được, nhưng không nên để bị nhân lên.)
- Đề xuất: dừng ở khoá đầu tiên bị vượt và hoàn lượt các khoá đã đếm trước đó.
  ~~~php
  $hit = [];
  foreach ($keys as $key => $max) {
      $count = RateLimiter::hit($key, self::DECAY_SECONDS);
      if ($count > $max) {
          // Hoàn lượt vừa đếm ở chính khoá này và các khoá trước đó: request bị chặn không phải "lần sai".
          foreach ([...$hit, $key] as $k) { $this->refund($k); }
          $this->throwBlocked($user, array_keys($keys));
      }
      $hit[] = $key;
  }
  ~~~
  (`refund()` là đoạn `decrement` + `resetAttempts` nếu âm hiện có.) Cần thêm test: user bị chặn spam 200 request thì khoá IP không vượt 100.

### R2 [SHOULD] Gộp `expired` và `exhausted` vào `COUPON_INVALID` lệch AC8, api-contract §1.7 và kết luận S18
- Vị trí: `backend/app/Services/Cart/CouponRejection.php:35-42` (`default => 'COUPON_INVALID'`), test `CartCouponTest.php:95`.
- Vấn đề: tasks.md T16 chỉ yêu cầu gộp "không tồn tại / chưa bắt đầu / vô hiệu". US-004 AC8 đòi "báo lỗi rõ ràng tương ứng" cho hết hạn, hết lượt; api-contract §1.7 còn liệt kê `COUPON_EXPIRED`; audit S18 nói rõ "giữ EXPIRED, EXHAUSTED, ALREADY_USED, NOT_APPLICABLE vì cần cho UX". Dev gộp chặt hơn thiết kế mà không có quyết định của PO/Architect. Về an ninh, gộp thêm là an toàn hơn một chút (mã hết hạn kiểu `TOAN2025` lộ quy tắc đặt tên cho mã `TOAN2026`), nên mình không coi là BLOCKER, nhưng học sinh nhận cùng thông điệp cho mã hết hạn và mã gõ sai là trải nghiệm kém.
- Đề xuất: đưa PO/Architect chốt một trong hai và ghi vào tài liệu (US-004 AC8 + api-contract §1.7). Nếu giữ thiết kế cũ: `Expired => COUPON_EXPIRED`, `Exhausted => COUPON_EXHAUSTED` (422 ở giỏ; 409 vẫn dành cho checkout), đổi 1 chỗ ở `errorCode()`/`publicMessage()` và sửa test. Nếu giữ bản gộp: sửa hai tài liệu cho khớp.

### R3 [SHOULD] `DELETE /cart/items/{course}` (binding `withTrashed`) lộ khóa nháp/đã xoá có tồn tại hay không
- Vị trí: `backend/routes/api.php` (route `api.cart.items.destroy` + `->withTrashed()`), `CartItemController::destroy`.
- Vấn đề: id khóa bất kỳ (nháp, unpublish, xoá mềm) trả 200; id không tồn tại trả 404. `AddCartItemRequest` cố ý trả cùng 1 lỗi để "không lộ khóa nháp" nhưng route xoá lại mở kẽ hở này. `CartService::remove()` chỉ cần `int $courseId`, không cần model.
- Đề xuất: bỏ binding model, dùng số nguyên; id lạ thì xoá không có gì (idempotent) và vẫn 200.
  ~~~php
  Route::delete('/items/{courseId}', [CartItemController::class, 'destroy'])->whereNumber('courseId');
  // controller: destroy(Request $request, int $courseId) { ... $this->cart->remove($user, $courseId) }
  ~~~
  Sửa lại test `CartRouteMiddlewareTest.php:63` (đang khẳng định `withTrashed`) cho khớp.

### R4 [SHOULD] Limiter chưa được kiểm với Redis thật; ghi vào board như T05
- Vị trí: `phpunit.xml` ép `CACHE_LIMITER=array`; mọi test S18 chạy trên array store.
- Vấn đề: mình đã đọc `Illuminate\Cache\RateLimiter` và `RedisStore` (Laravel 13 trong `vendor/`) và không thấy lỗi: `hit`/`decrement` cùng qua `increment()` (`INCRBY` nguyên tử, giá trị âm được, `withoutSerializationOrCompression` nên không hỏng khi bật serializer), khoá hết hạn giữa `hit` và hoàn lượt cho ra `-1` rồi `resetAttempts` xoá nên không có đếm âm còn sót. Nhưng vì chưa chạy thật trên `redis-limiter` (DB 4), chưa thể xác nhận TTL, `availableIn` và hành vi khi dùng `predis`/`phpredis` với serializer.
- Đề xuất: thêm vào board (như T05): "kiểm limiter S18 với Redis thật trước staging": 31 request sai, kiểm `TTL coupon-fail:user:<id>` gần 86400, hoàn lượt khi thành công (giá trị về 0), 429 kèm `Retry-After` đúng. Quan sát phụ (NIT, không cần sửa): khi hoàn lượt làm đếm về 1 thì `increment()` của Laravel `put` lại khoá với TTL mới, nên cửa sổ 24h của một lần sai cũ có thể bị kéo dài mỗi khi HS áp mã thành công. Chỉ kéo dài, không bao giờ rút ngắn, nên không phải lỗ hổng.

### R5 [SHOULD] Test cho `DatabaseCouponUsageChecker` (nhánh có bảng) và việc phải dỡ cầu nối ở T18 chưa được ghi board
- Vị trí: `backend/app/Services/Cart/DatabaseCouponUsageChecker.php` (`Schema::hasTable`), test `CartCouponTest.php:190` bind một fake nên lớp thật không được test.
- Vấn đề: khi T18 tạo `coupon_usages`, nếu quên gỡ nhánh `hasTable` thì mỗi request áp mã còn tốn thêm một truy vấn `information_schema`, và nhánh "có bảng" chưa từng được chạy trong CI. Mã đã dùng bị bỏ lọt chỉ được chặn ở unique DB lúc checkout (lưới thứ hai).
- Đề xuất: ghi vào board dòng T18: "xoá `hasTable` ở `DatabaseCouponUsageChecker`, thêm test dùng bảng thật (`coupon_usages` (coupon_id, user_id))". Có thể thêm ngay test tạm tạo bảng trong test rồi drop.

### R6 [NIT] Tràn số: `MAX_ELIGIBLE_SUBTOTAL = 3_000_000_000` ném `InvalidArgumentException` → 500 ở `GET /cart` và cả `DELETE`
- Vị trí: `PricingCalculator.php:35, 60`.
- Vấn đề: BR4 không giới hạn số khóa; giá tối đa 50.000.000 (api-contract §2.5), nên chỉ 61 khóa đắt nhất trong phạm vi mã là vượt. Rất khó xảy ra ngoài thực tế, nhưng hậu quả là giỏ hỏng hẳn (thậm chí `DELETE` đã ghi rồi mới 500). Ngoài ra `orders.subtotal_amount` là `int unsigned` (~4,29 tỷ), T18 nên tự kiểm tổng.
- Đề xuất: dựa trên giá dòng tối đa thay vì tổng: chỉ cần `discount * unitPrice ≤ PHP_INT_MAX`; với giá dòng ≤ 50 triệu thì tổng phạm vi tới ~1,8×10^11 vẫn an toàn. Hoặc bắt exception ở `CartService` và trả 422 nghiệp vụ. Tối thiểu ghi vào tài liệu giới hạn này.

### R7 [NIT] `insertOrIgnore` nuốt mọi lỗi, không riêng lỗi trùng
- Vị trí: `CartService.php:106-113`.
- Vấn đề: `INSERT IGNORE` cũng bỏ qua vi phạm FK (khóa vừa bị xoá cứng) và cắt dữ liệu, rồi báo nhầm `ALREADY_IN_CART`. Dòng `carts` đã khoá nên race 2 tab đã được tuần tự hoá, không cần `IGNORE` để chống race.
- Đề xuất: dùng `CartItem::create` và bắt `UniqueConstraintViolationException` để ném `ALREADY_IN_CART` (trên MySQL lỗi trùng không huỷ transaction).

### R8 [NIT] Điều kiện "mã dùng được" lặp ở hai nơi
- Vị trí: `CouponEvaluator::rejection()` và `Coupon::scopeState()` (T15). T18 sẽ cần bản thứ ba dạng `UPDATE ... WHERE` nguyên tử (DBA T15 §6).
- Đề xuất: gom một nguồn (ví dụ `Coupon::isUsableAt(CarbonInterface)` cho PHP và ghi chú trỏ tới câu SQL atomic ở T18) để lệch pha không thành lỗ hổng. Không chặn.

### R9 [NIT] Vệ sinh
- `backend/phpunit.t16.xml` là file chưa theo dõi trong worktree (cấu hình DB test riêng): không được commit khi gộp.
- `COUPON_MAX_FAILED_PER_DAY`, `COUPON_MAX_FAILED_PER_DAY_PER_IP` chưa có trong `.env.example`.
- `coupon.attempt_limit` audit nên ghi `changes: {scope: user|ip}` để người soát biết chạm trần nào; `Cache::add` chạy trước `audit->log`, nếu ghi audit lỗi thì mất log của cả ngày.

### R10 [NIT] `GET /cart` có ghi DB (gỡ mã) — chấp nhận, nhưng ghi lại
- Vị trí: `CartService::view()` → `detachCoupon()`.
- Đánh giá: có đúng ý US-004 ("vào lại trang giỏ hàng phải kiểm tra lại hiệu lực mã và gỡ") và làm đúng: khoá `carts`, kiểm lại sau khi khoá (không gỡ nhầm mã mới do request song song vừa áp), không ghi khi mã còn hợp lệ, `no_store` có. Hai lưu ý nhỏ: thông báo `COUPON_REMOVED` chỉ trả cho request đầu tiên (2 tab/prefetch có thể nuốt mất); và mã bị gỡ vì `Exhausted` không tự quay lại nếu admin nâng `max_uses`. Không cần sửa, ghi vào docblock để QA/FE biết.

## Trả lời các mối quan tâm được yêu cầu soi

**Lưu ý:** danh sách "9 giả định của dev" không có trong yêu cầu, commit message hay `docs/board.md` mình được đọc. Dưới đây là 9 giả định mình rút ra từ docblock/code; nếu danh sách của dev khác, gửi lại để mình đối chiếu.

| # | Giả định | Kết luận |
|---|---|---|
| 1 | Percent làm tròn xuống tới 1 VND, số nguyên | Đúng. `intdiv(subtotal * min(value,100), 100)`, không float, không giảm quá % công bố. |
| 2 | Phân bổ "phần dư lớn nhất" thay vì dồn dòng cuối như data-model §3.5 | Đúng, và dev có lý: dồn dòng cuối có thể giảm quá giá dòng (giá 1/1/1, giảm 2 → dòng cuối bị 2 > 1). Đã kiểm chứng suy luận: Σ phần dư = leftover × S, mỗi phần dư < S nên ít nhất `leftover` dòng có dư > 0 và mỗi dòng chỉ +1 nên không vượt giá. **Architect cần sửa câu "phần dư dồn vào dòng cuối" ở data-model §3.5 (`order_items`)** để T18 dùng cùng `PricingCalculator`. |
| 3 | Gộp `expired`/`exhausted` vào `COUPON_INVALID` | Lệch tài liệu, xem R2. |
| 4 | Hoàn lượt khi áp mã thành công | Đúng ngữ nghĩa "30 lần SAI/ngày". Xem phân tích bên dưới. |
| 5 | `GET /cart` tự gỡ mã không hợp lệ | Chấp nhận, xem R10. |
| 6 | Route giỏ CỐ Ý không có `account.verified`/`parent.consent` | Đúng với api-contract §2.3 (hai middleware đó chỉ nằm ở hàng checkout/pay) và §1.3 ("khi route yêu cầu"); giống `viewer-state` và `auth/me`. Nhóm `auth:sanctum, account.active, student.single_session, no_store, role:hoc_sinh` khớp `student` chuẩn, `throttle:coupon` chỉ ở PUT. Có test middleware. Đạt. |
| 7 | Mã không giữ chỗ lượt dùng ở giỏ, chỉ kiểm `used_count` | Đúng US-004 (ghi chú Dev) và ADR-001 §6. Sức chứa cả đơn pending kiểm lại ở T18. |
| 8 | `DatabaseCouponUsageChecker` là cầu nối tới `coupon_usages` (T18) | Chấp nhận có điều kiện, xem R5. |
| 9 | Giỏ lazy-create ngoài transaction, `coupon_id` không fillable, không giới hạn số khóa (BR4), `cart_count` chỉ COUNT không tạo giỏ | Đúng. Riêng "không giới hạn số khóa" xem R6. |

### Limiter S18 chi tiết
- **Hit trước, hoàn khi thành công:** thứ tự đúng, không có cửa sổ kiểm-rồi-đếm (N request song song nhận số đếm tuần tự từ INCR). Vượt trần được chặn TRƯỚC khi chạm bảng `coupons` (test `CartCouponTest.php:415` chứng minh). Đúng đúng 30 lần sai (hit thứ 30 vẫn qua, thứ 31 bị chặn), tương thích `OtpService::verify()` ở T04.
- **Có nên hoàn lượt khi thành công? Có, an toàn.** "Dò mã bằng mã đúng" không giúp kẻ tấn công thêm lượt đoán: mỗi request thành công là +1 rồi −1 (ròng 0), còn mọi ngoại lệ (`COUPON_INVALID`, `COUPON_ALREADY_USED`, `COUPON_NOT_APPLICABLE`, lỗi hệ thống) giữ nguyên lượt đã đếm. Lần đoán trúng mã bản thân nó là thành công nên miễn phí, nhưng lúc đó kẻ tấn công đã có mã. Không hoàn thì HS thật gỡ/áp lại mã nhiều lần sẽ tự khoá mình. Chặn chi phí spam mã đúng đã có `throttle:coupon` (10/phút, 60/giờ/IP). Rủi ro còn lại là R1.
- **Đếm âm:** `decrement` → `<0` thì `resetAttempts`; hoàn lượt sau khi khoá hết hạn cho `-1` rồi bị xoá nên không còn "tín dụng âm". Race hiếm (khoá hết hạn và request khác đếm xen giữa) chỉ làm mất tối đa 1 lượt của request khác, không đáng kể.
- **Redis thật:** đọc mã nguồn không thấy lỗi, chưa chạy thật, xem R4.
- Điểm phụ tốt: 429 kèm `Retry-After` (allowlist ở `ApiExceptionRenderer`), audit `coupon.attempt_limit` 1 lần/ngày, mã sai định dạng không chạm DB.

### Chống dò mã
- **Body đồng nhất:** không tồn tại, chưa bắt đầu, vô hiệu, hết hạn, hết lượt đều `422 COUPON_INVALID` cùng `message`, không có `errors`/context riêng. Khác nhau duy nhất là `request_id` (cố ý). Test `CartCouponTest.php:95` khẳng định.
- **Timing:** nhóm `COUPON_INVALID` đều đi đúng 1 truy vấn `coupons` (`WHERE code = ?`) rồi so sánh trong PHP, không có truy vấn nào khác nhau giữa các lý do (`hasUsed`/phạm vi chỉ chạy sau khi qua các bước đó). Toàn bộ phần khoá giỏ/dựng giỏ chạy TRƯỚC khi tra mã nên chi phí không đổi. Chuỗi sai định dạng không chạm DB nên nhanh hơn một chút, nhưng chỉ lộ "sai định dạng", không lộ tồn tại. Đạt.
- **`COUPON_NOT_APPLICABLE` lộ tồn tại:** có, ở mức chấp nhận được. Nó chỉ xuất hiện với mã đang hiệu lực, còn lượt, HS chưa dùng nhưng ngoài phạm vi (hoặc giỏ chưa có khóa mua được). Với mã không giới hạn phạm vi thì kẻ tấn công chỉ cần giỏ có 1 khóa để thấy "thành công", nên `NOT_APPLICABLE` chỉ thêm 1 bit cho mã có phạm vi. Audit S18 chủ ý giữ mã này cho UX, mỗi lần gọi đều tính là lần SAI của limiter (30/ngày/tài khoản, 100/ngày/IP), và số tài khoản bị chặn bởi captcha đăng ký. Rủi ro dư: mã ngắn dễ đoán kiểu `TOAN2026` vẫn nên được T15 khuyến nghị dài ≥ 8 ký tự (contract cho phép 4). Không sửa ở T16.
- Chuẩn hoá đầu vào an toàn: `trim` chỉ khoảng trắng thường, `mb_strtoupper`, regex `^[A-Z0-9_-]{1,50}$`, truy vấn tham số hoá nên tận dụng unique index (đúng dặn của DBA T15 §3). Test chuỗi tấn công có.

### PricingCalculator
- Số nguyên toàn bộ, floor tới 1 VND, `min(raw, eligibleSubtotal)` nên tổng không âm và không giảm quá phần thuộc phạm vi; mã ngoài phạm vi giữ nguyên giá (BR9).
- Phân bổ: xem giả định 2. Tie-break xác định (dòng sau trước). Lũy kế đúng cho danh sách rỗng, giá 0, giảm 0.
- Tràn số: `discount * unitPrice ≤ S² ≤ 9×10^18 < PHP_INT_MAX` với S ≤ 3×10^9; ngoài ngưỡng ném exception, xem R6.
- Test unit có thuộc tính ngẫu nhiên trên nhiều tập (tổng khớp, không âm, không quá giá).

### IDOR, race, ghi khi GET
- **IDOR:** không có `cart_id`/`user_id` từ client; mọi truy vấn qua `$user->id`; test hai HS cùng khóa. Đạt (ngoại trừ rò tồn tại khóa ở R3).
- **Race:** `carts` được tạo ngoài transaction rồi `lockForUpdate` theo PK, chỉ đi đoạn `carts` của thứ tự chuẩn `carts → orders → coupons` (coupons chỉ đọc). Unique `(cart_id, course_id)` là lưới thứ hai. `applyCoupon` khoá giỏ trước khi đánh giá và ghi; `detachCoupon` kiểm lại sau khi khoá. `DB::transaction(..., 3)` tự thử lại khi deadlock; `DomainException` không bị thử lại. Không có test song song thật (Pest không mô phỏng được), xem gợi ý QA.
- **N+1:** `with('course')`, `whereIn` một lần cho enrollment; test giới hạn số truy vấn với 25 khóa.

### Migration (kiểu DBA)
- `carts`: `user_id` UNIQUE + FK cascade, `coupon_id` FK nullable `nullOnDelete` (FK tự tạo index; xoá mã tự gỡ khỏi giỏ), khớp data-model §3.5; `down()` `dropIfExists`.
- `cart_items`: UNIQUE `(cart_id, course_id)` (phủ luôn FK `cart_id` theo tiền tố), FK `course_id` có index tự tạo, cascade cả hai, không `updated_at` (khớp `UPDATED_AT = null`); `down()` sạch, thứ tự hồi phục ngược đúng (hai migration riêng, `cart_items` sau nên rollback trước). Không sửa migration cũ. Kiểu khoá khớp `id()` (bigint unsigned).
- Chưa dùng CHECK/kiểu nào cần kiểm bằng MySQL thật; task không gắn [DBA]. Mình không chạy `migrate`, nên dựa trên đọc DDL; DBA nếu muốn có thể xem `SHOW CREATE TABLE` khi rảnh (không chặn).
- Ghi nhớ cho T18: `orders.coupon_id` FK RESTRICT + `carts.coupon_id` nullOnDelete: xoá mã vẫn được khi giỏ đang trỏ tới (tự gỡ), đúng ý; chỉ đơn mới chặn xoá (T15 review MEDIUM-2).

### MeResource.cart_count và đồng bộ T15
- `cart_count` = 1 truy vấn `COUNT` qua `cart_id IN (SELECT id FROM carts WHERE user_id = ?)`, không tạo giỏ, dùng unique index; `MeResource` cũng dùng ở `OtpController::verify` và `ContactController::update` nên có thêm 1 COUNT rẻ ở đó, chấp nhận. `app(CartService::class)` trong Resource là service locator (NIT, nếu muốn thì nhận số đếm qua constructor/`additional`).
- `CouponEvaluator` khớp `Coupon`/`CouponStatus`/`valid_until` (endOfDay do T15 đã chuẩn hoá), dùng `is_restricted` + `coupon_course ∪ coupon_subject` (chuyên đề ẩn vẫn tính) đúng data-model. `coupon_id`/`used_count` không fillable. Lưu ý: `app.timezone` mặc định UTC nên mã "hết hạn 31/10" hết lúc 06:59 giờ Việt Nam ngày 1/11; đây là hành vi chung T15/T16, cần PO xác nhận múi giờ hiển thị (không phải lỗi T16).

## Đối chiếu acceptance criteria
| AC | Code đáp ứng | Ghi chú |
|---|---|---|
| AC1 | `POST /cart/items` 201, `cart_count` ở `/auth/me` | Test `CartTest` + `me` |
| AC2 | Unique DB + `ALREADY_IN_CART` 409 | Không tạo dòng trùng |
| AC3 | `ALREADY_OWNED` 409 (+ `ENROLLMENT_PENDING`) | Kể cả gọi API trực tiếp |
| AC4 | `DELETE /cart/items/{course}` tính lại tổng và giảm giá | Đạt; R3 về binding |
| AC5 | Giỏ rỗng trả cấu trúc rỗng, không tạo giỏ | FE hiển thị thông điệp |
| AC6 | 401 cho khách, 403 cho GV | Chuyển hướng là việc FE |
| AC7 | `PUT /cart/coupon` trả `pricing` (subtotal, discount, total) | Đạt |
| AC8 | Không tồn tại/vô hiệu/hết hạn/hết lượt/đã dùng/ngoài phạm vi đều bị từ chối, tổng tiền không đổi | **Một phần:** hết hạn và hết lượt dùng chung `COUPON_INVALID`, không "rõ ràng tương ứng" — R2 |
| AC9 | Mã mới thay mã cũ; mã sai giữ nguyên mã cũ | Đạt |
| AC10 | `remove()`/`view()` tự gỡ kèm `notices` `COUPON_REMOVED` | Đạt; R10 |
| AC11 | Chỉ khóa trong phạm vi bị giảm (`eligibleCourseIds`) | Đạt, có test khóa cụ thể, chuyên đề, hợp |
| Edge | Khóa unpublish/xoá mềm/thành miễn phí → `unavailable`, xoá được; mã bị vô hiệu lúc đang áp bị gỡ khi vào lại; chữ hoa/thường; > 20 khóa | Đạt |

## Gợi ý cho QA
- **Limiter với Redis thật**: 31 lần sai liên tiếp, `Retry-After`, TTL 24h, hoàn lượt khi thành công, hai tài khoản cùng IP (chạm 100). Sau R1, thử chuỗi "tài khoản bị chặn spam" và kiểm quota IP không bị đốt.
- **Song song thật**: 5–10 request `POST /cart/items` cùng `course_id`; `PUT /cart/coupon` hai mã cùng lúc; `GET /cart` gỡ mã đồng thời với `PUT /cart/coupon` mã mới (không được gỡ nhầm mã mới); 30 request sai đồng thời ở đúng ngưỡng 29/30.
- **Timing**: đo p50/p95 của `COUPON_INVALID` cho mã không tồn tại, vô hiệu, hết hạn, hết lượt; không được có chênh lệch ổn định.
- **Giá**: giỏ nhiều khóa giá lẻ với giảm % và cố định, khoá phạm vi một phần; tổng `pricing.discount` bằng tổng `discount_amount` các dòng; giảm 100% và cố định lớn hơn giỏ cho tổng 0.
- **Phạm vi mã**: mã theo chuyên đề bị ẩn; khóa vừa unpublish còn trong giỏ; khóa đã thành miễn phí.
- **Ranh giới**: giỏ ≥ 61 khóa 50 triệu (R6); mã `valid_until` sát giờ, múi giờ VN; mã có ký tự Unicode, NUL, `%`, `_`, dài 51 ký tự (422 không tính lần sai).
- **Middleware**: HS chưa xác thực OTP, HS dưới tuổi chưa có đồng ý phụ huynh vẫn dùng được giỏ; GV/staff 403; tài khoản khoá 403; phiên bị thay 401.
- **T18 sau này**: `coupon_usages` bật thì `COUPON_ALREADY_USED` qua lớp thật; `PricingCalculator` được checkout dùng lại, so `expected_total`; tổng 1–999 đồng (`AMOUNT_BELOW_GATEWAY_MIN`).

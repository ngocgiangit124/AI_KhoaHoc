# REVIEW: Sửa lỗi nhỏ 2 (T03 M2, M3; T27-5)
**Kết luận:** APPROVE (không có Critical/High; 2 Medium, 3 Low nên xử lý hoặc ghi backlog)
**Phạm vi:** `git diff` unstaged: LoginService, StaffAuthService, PasswordService, tests T03/T27, 8 race test (60→180), 3 file docs · 17 file. Pest T03+T27+T28 (loại race): 234 passed.

## Tổng quan
Thay đổi gọn, đúng mục tiêu backlog. `hit()` (INCR) trước khi so mật khẩu cho tính nguyên tử thật. Khoá theo user id gom email, SĐT và mọi cách viết dấu vào một bộ đếm. Phần `reset` đồng nhất mã, thông điệp và HTTP status với nhánh tài khoản không tồn tại. `RateLimiter::decrement($key, $decay)` đúng chữ ký Laravel 13 (decay là tham số thứ 2, amount mặc định 1), không phải bẫy trừ 3600.

## Phát hiện

### R1 [Medium] IP bị chặn vẫn tăng bộ đếm tài khoản (lan khoá sang tài khoản vô tội)
- Vị trí: `LoginService.php` vòng `foreach` (account trước, IP sau); tương tự `StaffAuthService::login`.
- Vấn đề: thứ tự là hit account rồi hit IP. Khi IP đã vượt 50 (ví dụ NAT trường học, hoặc kẻ tấn công cố ý), mọi request từ IP đó vẫn `hit` khoá account rồi mới ném 429 ở IP. Không có lần so mật khẩu và không có hoàn lượt. Sau 10 request, tài khoản của bất kỳ học sinh nào dùng chung IP (kể cả nhập đúng mật khẩu) bị khoá 1 giờ dù không ai đoán sai. Trước đây kiểm chỉ đọc nên không xảy ra. Ngược lại, nhánh account vượt ngưỡng thì IP không bị hit (không đối xứng). Kẻ tấn công không cần mật khẩu: M1 đã có sẵn (khoá tài khoản người khác bằng 10 lượt sai), nhưng R1 mở rộng lên "mọi tài khoản từ IP đó".
- Đề xuất: hoàn các khoá đã hit khi bị chặn.
  ~~~php
  $hit = [];
  foreach ([...] as [$key, $max]) {
      $hit[] = $key;
      if (RateLimiter::hit($key, self::DECAY_SECONDS) > $max) {
          foreach ($hit as $k) { if ($k !== $key) RateLimiter::decrement($k, self::DECAY_SECONDS); }
          throw new ThrottleRequestsException(...);
      }
  }
  ~~~
  Hoặc hit IP trước (giữ chặn rẻ), rồi mới tới account, và hoàn IP nếu account vượt. Thêm test: IP đã 50 lần sai, 15 request từ IP đó với tài khoản A, sau đó từ IP khác đăng nhập A phải thành công.

### R2 [Medium] Đường 429 do vượt ngưỡng không hoàn lượt, bộ đếm leo thang vô hạn (ảnh hưởng thấp, nhưng nên ghi)
- Vị trí: cùng chỗ R1.
- Vấn đề: sau khi vượt ngưỡng, mỗi request tiếp tục INCR. TTL không bị kéo dài (`add` chỉ đặt lần đầu) nên không thành khoá vĩnh viễn, và `Retry-After` vẫn đúng. Không phải bypass. Chỉ gây số đếm phình, không có tác hại nghiệp vụ. Cùng cách sửa R1 (decrement khi chặn) giải quyết luôn.

### R3 [Low] Rủi ro bộ đếm âm khi khoá hết hạn giữa `hit` và `decrement`
- Vị trí: `decrement` sau `Hash::check`.
- Vấn đề: nếu TTL hết đúng trong khoảng băm (~100ms), `decrement` tạo khoá giá trị -1 (có TTL 1 giờ), tặng 1 lượt đoán thêm. Cửa sổ rất hẹp, hậu quả tối đa 1 lượt. Chấp nhận được. Có thể bọc: `if (RateLimiter::attempts($key) > 0) decrement`. Không bắt buộc.

### R4 [Low] Lệch khoá giữa tồn tại và không tồn tại (oracle yếu)
- Vị trí: `throttleSubject()`.
- Vấn đề: với tài khoản có thật, email và SĐT dùng chung 1 bộ đếm. Với tài khoản không tồn tại thì 2 định danh khác nhau là 2 bộ đếm riêng. Kẻ biết cả email và SĐT của một người có thể thử 5 lần email + 5 lần SĐT để xem có 429 ở lần thứ 11 hay không. Cần biết trước cả hai định danh nên giá trị lộ rất thấp; ghi nhận, không sửa. Thời gian xử lý hai nhánh như nhau (cùng 1 truy vấn `findByLogin`, cùng 1 lần băm; test timing cũ vẫn xanh). `findByLogin` nay chạy trước kiểm khoá nên request bị 429 vẫn tốn 1 truy vấn DB (chấp nhận được, có throttle trên route).

### R5 [Low] Test chưa chứng minh nguyên tử và còn thiếu ca quan trọng
- Vị trí: `LoginThrottleHardeningTest.php`.
- Vấn đề: test "30 lượt sai liên tiếp" chạy tuần tự, test "đếm trước khi so" chỉ chứng minh thứ tự gọi (quan sát `attempts()` trong `Hash::check`). Điều này đủ chứng minh thiết kế `hit-trước`, nhưng không chứng minh đồng thời. Chấp nhận vì INCR của Redis là nguyên tử; nếu muốn, thêm 1 race test (nhóm `race`, process song song như T18) với 30 request song song, kỳ vọng đúng 10 lần so mật khẩu. Thiếu test cho: (a) mật khẩu đúng + `WRONG_PORTAL` hoàn lượt, (b) tài khoản `Locked` + mật khẩu đúng hoàn lượt, (c) staff (`StaffAuthService`) hit-trước/decrement/khoá theo id, (d) R1. Staff hiện chỉ được kiểm gián tiếp bởi test T28 cũ (đều xanh).

## Trả lời các điểm kiểm
- Bypass thứ tự khoá: không có bypass đoán mật khẩu. Thứ tự ảnh hưởng chỉ ở R1 (lan khoá, không phải né khoá).
- Hoàn lượt khi `WRONG_PORTAL`/`LOCKED`: chỉ xảy ra sau khi mật khẩu đúng, nên không tạo oracle mới ngoài việc đã biết mật khẩu đúng (hành vi cũ cũng vậy: không đếm). Kẻ đoán sai vẫn bị đếm đủ. Không thể dùng refund để đoán nhiều hơn 10 lần.
- `decrement` xuống âm: chỉ ở ca hết hạn hiếm (R3). Đồng thời nhiều request đúng: cặp hit/decrement đối xứng nên không âm.
- Staff MFA / T28: luồng MFA không đụng khoá `staff-login-fail:*` (OTP có bộ đếm riêng). Mật khẩu đúng được hoàn lượt rồi mới sang MFA, nên việc dò mã MFA bị giới hạn bởi hạn mức MFA riêng, không phải bởi khoá login. Không thấy vấn đề, 234 test xanh.
- `reset` lộ tài khoản: mọi lỗi mã (sai, hết 5 lượt, thua race, tài khoản không tồn tại/bị khoá/không có mã) đều 422 `OTP_EXPIRED` cùng thông điệp và cùng cấu trúc `errors`. Chỉ ném lại `DomainException` khác `TOO_MANY_ATTEMPTS` (không nuốt lỗi lạ). Throttle `otp-verify` (429) áp theo tài khoản/IP giống nhau cho cả hai nhánh (đã ghi trong contract). Nhánh sai mã thực hiện 1 lần băm như nhánh không tồn tại; test mới so `code/message/errors` giữa ghost và tài khoản thật qua 6 lần. Header/Retry-After chỉ có ở throttle route, không khác nhau. Còn lại: timing nhánh `consume` (khoá hàng + transaction) khác nhánh ghost, nhưng test timing "ghi nhận, không chặt" đã có từ trước (QA BUG-1).
- Contract và ghi chú FW1: đã cập nhật §1.7, khối T27 và dòng FW1 (nêu rõ không còn `OTP_INVALID`/429 ở reset, hiển thị thông điệp hết hạn + nút "Gửi lại mã"). Đủ.
- Timeout race test: nên thống nhất 180s cho T18 (90s), T22 (120s), T33 (120s). Lý do: đều là test race dựa trên process song song và flake khi tải cao (cùng nguyên nhân với 8 file đã sửa), và nâng timeout không làm chậm khi pass. Đề xuất, không bắt buộc.

## Đối chiếu acceptance (mục tiêu backlog)
| Mục | Code đáp ứng | Ghi chú |
|---|---|---|
| M2: 11 cách viết có dấu của 1 email → lượt 11 là 429 | `throttleSubject` theo user id; không tồn tại thì `Str::ascii` + hạ chữ | Có test, áp cả staff |
| M3: 30 request sai → tối đa 10 lần so mật khẩu | `hit()` trước `Hash::check`, `> $max` thì 429 | Test tuần tự, không có race test |
| M3: mật khẩu đúng không tiêu hao hạn mức | `decrement` sau khi mật khẩu đúng | Có test; thiếu test WRONG_PORTAL/LOCKED/staff |
| T27-5: reset không lộ qua thông điệp | 422 `OTP_EXPIRED` cho mọi lỗi mã | Có test; contract, backlog, FW1 đã cập nhật |

## Gợi ý cho QA
- IP chạm 50 lần sai rồi gửi nhiều request cho tài khoản A: A có bị khoá không (R1).
- 30 request song song vào cùng tài khoản (race): đúng 10 lần băm, 20 lần 429.
- Đăng nhập đúng nhưng `WRONG_PORTAL` (GV vào host học sinh và ngược lại), tài khoản `Locked`: bộ đếm không tăng.
- Staff: tài khoản có hoa/dấu/email-SĐT khác nhau, MFA sau login, hết hạn khoá giữa chừng.
- `reset`: so toàn bộ response (body, status, header) giữa tài khoản có mã hiệu lực + sai mã, tài khoản không tồn tại, mã hết 5 lượt.

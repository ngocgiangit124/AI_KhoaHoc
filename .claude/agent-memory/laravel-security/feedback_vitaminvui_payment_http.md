---
name: VitaminVui payment HTTP/HMAC pitfalls (T17)
description: Laravel Http follows redirects by default (re-POSTs body on 307); HMAC adapters must fail closed on empty secret outside production
type: feedback
---
- Laravel `Http::` (Guzzle) mặc định theo tối đa 5 redirect, kể cả sang http; 307/308 gửi lại nguyên body POST. Adapter cổng thanh toán phải `->withoutRedirecting()` + pin host của URL trả về (payUrl). Kiểm bằng `Http::fake` trả 307 rồi xem `Http::recorded`.
- `ProductionConfigGuard` chỉ chạy khi APP_ENV === 'production' → staging/`prod` gõ sai bỏ qua guard. Adapter HMAC phải tự ném lỗi khi secret rỗng (HMAC khoá '' ai cũng tính được — đã chứng minh IPN giả Succeeded ở T17).
- `$request->all()` gộp query string + trường không ký vào `raw`; IPN nên dùng `$request->json()->all()` và dựng raw từ danh sách trường đã ký.
- `(string)` ép mảng trước khi verify → ErrorException 500; kiểm scalar trước.
- Docker php image không có php.ini → `zend.exception_ignore_args=Off`; tham số secret nên có `#[\SensitiveParameter]`.
**Why:** tìm thấy ở review T17 (docs/security/review-T17.md, M1/M2/L1/L2/L4).
**How to apply:** áp khi review T18–T20 và mọi tích hợp bên thứ ba ký HMAC (Turnstile, video, SMS).

# REVIEW: Arch testsuite fix (phpunit.xml + NoApiTokensTest)

**Kết luận:** PASS
**Phạm vi:** diff chưa commit trên `claude/zen-dirac-fmucf7` — `backend/phpunit.xml`, `backend/tests/Arch/NoApiTokensTest.php`, `backend/README.md` (3 file)

## Tổng quan
Fix đúng vấn đề đã nêu: `tests/Arch` chưa từng chạy trong `composer ci` vì `phpunit.xml` thiếu khai báo `<testsuite name="Arch">`, khớp với `pest()->extend(...)->in('Arch')` đã có sẵn trong `tests/Pest.php`. `composer ci` gọi `vendor/bin/pest` không có cờ `--testsuite`, nên PHPUnit/Pest chạy tất cả các testsuite khai báo — thêm suite này là đủ để `Arch` được thực thi mà không cần sửa gì khác (đã tự chạy lại `docker compose exec -T php composer ci` và `./vendor/bin/pint --test`, cả hai xanh). Cách khử false-positive trong `NoApiTokensTest` bằng `token_get_all()` lọc `T_COMMENT`/`T_DOC_COMMENT` là hướng đúng và đúng chỗ (so sánh trên token stream thay vì raw string), có test riêng chứng minh không bị làm yếu (test thứ 3). README cập nhật khớp với code. Không thấy quy tắc nghiệp vụ nào bị hiểu sai, không có rủi ro bảo mật/mất dữ liệu.

## Phát hiện

### R1 [SHOULD] `str_contains(..., 'createToken(')` vẫn có thể bị né bằng khoảng trắng — nhân tiện đang sửa test này thì nên vá luôn
- Vị trí: `backend/tests/Arch/NoApiTokensTest.php:52`
- Vấn đề: Sau khi lọc token strip comment, chuỗi kết quả vẫn giữ nguyên whitespace giữa các token (đúng — cần thiết để không dính liền 2 định danh khác nhau). Nhưng hệ quả là lời gọi thật dạng `$user->createToken (` (có khoảng trắng trước dấu ngoặc, hợp lệ về cú pháp PHP) hoặc `$user
  ->createToken(` viết trên nhiều dòng với comment/format khác thường vẫn có thể né được `str_contains($code, 'createToken(')` nếu ai đó cố tình né test (ví dụ tự động format lại bằng tool khác Pint, hoặc viết tay để qua mặt CI). Đây là hạn chế đã có từ bản gốc, không phải do PR này gây ra, nhưng vì bạn đang sửa đúng dòng này và đây là test bảo mật (S24) nên đáng để xử luôn thay vì để nợ kỹ thuật tiếp tục tồn tại.
- Đề xuất: thay so sánh chuỗi cứng bằng regex chấp nhận whitespace tuỳ ý giữa tên hàm và `(`, áp dụng trên chuỗi đã lọc comment:
  ~~~php
  if (preg_match('/createToken\s*\(/', $codeWithoutComments)) {
      $offenders[] = $file->getRelativePathname();
  }
  ~~~
  Cân nhắc bổ sung test âm cho biến thể `createToken (` (có khoảng trắng) tương tự test thứ 3 hiện có, để chứng minh không bị né.

### R2 [NIT] Helper `arch_no_api_tokens_strip_comments()` khai báo hàm global ngay trong file test
- Vị trí: `backend/tests/Arch/NoApiTokensTest.php:19-38`
- Vấn đề: Hàm top-level trong namespace global, định nghĩa trực tiếp trong 1 file test cụ thể. Hiện không có xung đột (đã grep, không trùng tên ở đâu khác) và dự án không chạy Pest song song/paratest nên rủi ro "Cannot redeclare function" gần như bằng 0 ở thời điểm này. Nhưng nếu sau này có thêm Arch test khác cũng cần strip comment (rất có thể, vì đây là kỹ thuật hữu ích để chống false-positive tương tự cho `ControllersTest`/`ModelsTest`), sẽ dễ bị copy-paste ra file khác và đụng tên.
- Đề xuất: chuyển hàm này vào `tests/Pest.php` (mục "Functions" đã có sẵn ở cuối file, cạnh `something()`) hoặc một class hỗ trợ trong `Tests\Support`, để dùng chung và tránh phình global namespace trong từng file test riêng lẻ. Không bắt buộc phải sửa trong PR này.

### R3 [Ghi chú — không cần sửa] Worktree khác chưa có testsuite `Arch`
- Vị trí: `.claude/worktrees/{t04,t06,t07-t10,t17}/backend/phpunit.xml`
- Vấn đề: Các `phpunit.xml` trong 4 worktree hiện tại đều là bản cũ, chưa có `<testsuite name="Arch">`. Khi các nhánh đó merge về `main` sau khi PR này merge, `git merge` sẽ tự đồng bộ nếu không có xung đột dòng gần khối `<testsuite>` — nhưng nếu worktree nào cũng có sửa đổi cục bộ ở `phpunit.xml` (ví dụ file `phpunit.t04.xml`, `phpunit.t06.xml`... cho thấy có bản riêng theo task), cần kiểm tra thủ công sau merge rằng khối `Arch` không bị mất. Không phải lỗi của PR này, chỉ là điểm cần để ý khi gộp nhánh — đã có sẵn trong bối cảnh nhiệm vụ, ghi lại để không quên.

## Đối chiếu acceptance criteria
Đây là fix hạ tầng test (không gắn với 1 US/AC cụ thể trong `docs/stories`), nên đối chiếu theo mục tiêu đã nêu trong bối cảnh nhiệm vụ:

| Mục tiêu | Code đáp ứng | Ghi chú |
|---|---|---|
| `tests/Arch` chạy trong `composer ci` | ✅ | Thêm `<testsuite name="Arch">` vào `phpunit.xml`; đã tự xác nhận lại bằng `docker compose exec -T php composer ci` xanh, 269 test (Arch 5) |
| `NoApiTokensTest` không còn bắt nhầm docblock | ✅ | `token_get_all()` + lọc `T_COMMENT`/`T_DOC_COMMENT`; test thứ 3 chứng minh vừa hết false-positive vừa còn bắt được lời gọi thật |
| Test không bị làm yếu | ✅ (còn 1 khe hở nhỏ, không do PR này tạo ra) | Xem R1 — whitespace trước `(` vẫn né được, là hạn chế kế thừa từ bản gốc |
| README khớp code | ✅ | Đoạn mô tả `tests/Arch/*` trong README mô tả đúng cơ chế lọc và lý do cần khai báo testsuite |
| Pint sạch | ✅ | `./vendor/bin/pint --test` (qua Docker) — PASS 145 file |

## Gợi ý cho QA
- Chạy `composer ci` toàn bộ (không chỉ `--filter=Arch`) để chắc chắn việc bật thêm testsuite không làm lộ ra lỗi tiềm ẩn ở `ControllersTest`/`ModelsTest` (2 test này không nằm trong diff nhưng lần đầu thực sự được thực thi trong CI từ nay).
- Không cần test thủ công qua UI/API — đây thuần là thay đổi cấu hình test + 1 file test. Rủi ro chính là "CI xanh giả" (test không chạy) — đã tự xác minh số lượng test tăng đúng (5 Arch) khi chạy `composer ci`.

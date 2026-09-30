# REVIEW: T08 (US-009 - Quản trị khóa học)
**Kết luận:** PASS (0 BLOCKER, 5 SHOULD, 4 NIT)
**Phạm vi:** commit a45e66e (nhánh claude/zen-dirac-fmucf7-t08) so với claude/zen-dirac-fmucf7 · 25 file

## Tổng quan
Cấu trúc đúng quy ước: controller mỏng, Service giữ logic, FormRequest 2 bộ rule, `can:` chạy trước validate, `status/manual_order/created_by` không nằm trong `$fillable`, audit đủ. Phân quyền GV theo `course_teacher` đúng, IDOR khóa khác bị chặn ở Policy. Sanitizer tự viết thiết kế theo hướng an toàn (allowlist + re-serialize từ DOM + escape text/href), tôi thử payload thực tế bên dưới và không bypass được.

## Kiểm thử XSS tự chạy (php 8.3 trong docker, file tạm đã xoá)
Đều bị loại đúng: `java\tscript:`, `&#106;avascript:`, `\x01javascript:`, ` javascript:`, `JaVaScRiPt:`, `jav&#x09;ascript:`, `javascript&#x3A;`, `data:text/html`, `vbscript:`, `//evil.com`, chèn `"` + `onmouseover` vào href (bị escape thành `&quot;`), `on*`/`style` trên thẻ allowlist, `<img onerror>`, svg/math/mglyph/style mXSS, `noscript`/`textarea`/`xmp`/`plaintext`/`form`, comment điều kiện IE, `<scr\0ipt>`, meta charset utf-7, nested `<a>`, lồng sâu 5000 tầng (không lỗi), 400 khối ~3ms. `javascript&colon;` bị libxml không decode nhưng output thành `javascript&amp;colon;` = href tương đối vô hại. Sanitize idempotent (ghi rồi đọc lại không đổi). Lý do bền: output chỉ gồm thẻ allowlist KHÔNG thuộc tính (trừ `<a href>` đã escape) và không có table/svg/math nên không có đường mutation khi trình duyệt parse lại.

## Nhận xét về sanitizer tự viết: CHẤP NHẬN TẠM
Chấp nhận với điều kiện: (1) ghi vào backlog/ADR thay bằng `ezyang/htmlpurifier` khi có môi trường cài được; (2) bổ sung các test payload ở R3; (3) giữ chữ ký `sanitize(?string): ?string`. Rủi ro còn lại thấp vì thiết kế "dựng lại từ DOM", không phải "lọc chuỗi".

## Phát hiện
### R1 [SHOULD] Xoá file thumbnail cũ TRONG transaction, trước commit
- Vị trí: `app/Services/Courses/CourseService.php` (update, khối thumbnail) và `create()`
- Vấn đề: `images->delete($previous)` chạy trước `$course->save()`/`subjects()->sync()`/audit; nếu bước sau ném lỗi, DB rollback nhưng file cũ đã mất, khóa học trỏ ảnh không tồn tại. Ngược lại `create()` lỡ rollback thì file mới mồ côi.
- Đề xuất:
  ~~~php
  $new = $this->images->store($data['thumbnail']);
  $course->thumbnail_path = $new;
  DB::afterCommit(fn () => $this->images->delete($previousThumbnail));
  // và bọc try/catch: nếu transaction fail thì delete($new)
  ~~~

### R2 [SHOULD] Race: kiểm enrollment / trạng thái ngoài khoá
- Vị trí: `CourseService::delete()`, `publish()`, `unpublish()`
- Vấn đề: `Enrollment::exists()` chạy ngoài `DB::transaction` và không khoá; giữa lúc kiểm tra và xoá có thể phát sinh enrollment (BR4 bị vi phạm, HS mất khóa vừa mua). Publish/unpublish đọc model cũ, không `lockForUpdate` (publish song song với xoá chương cuối ở T09 có thể vượt BR3).
- Đề xuất: đưa kiểm tra vào trong transaction và `Course::whereKey($id)->lockForUpdate()->firstOrFail()` trước khi kiểm tra/ghi (publish, unpublish, delete); ở T13/T15 khi tạo enrollment cũng khoá cùng hàng course hoặc kiểm `deleted_at`. Đưa audit `course.delete` vào trong transaction.

### R3 [SHOULD] Thiếu test payload sanitizer quan trọng (S8 do sanitizer tự viết)
- Vị trí: `tests/Feature/T08/HtmlSanitizerTest.php`
- Vấn đề: chưa có test cho scheme có ký tự điều khiển/tab, viết hoa, entity (`&#106;`, `&#x09;`), `noscript`/`math`/mglyph mXSS, nested `<a>`, idempotent, tiếng Việt/emoji giữ nguyên, backslash-href. Vì đây là cổng bảo mật cuối, cần khoá hành vi bằng test dataset.
- Đề xuất: thêm dataset các payload tôi liệt kê ở mục trên (expect không chứa `javascript`, `onerror`, `<script`, `<img`, `style=`).

### R4 [SHOULD] href tương đối bắt đầu bằng `\` (`\\evil.com`, `/\evil.com`) được giữ
- Vị trí: `HtmlSanitizer::isAllowedUri()`
- Vấn đề: trình duyệt coi `\\host` và `/\host` như `//host` (protocol-relative), đi ngược ý định đã comment "từ chối `//`". Không phải XSS, nhưng là open redirect/phishing qua link do GV gài trong mô tả.
- Đề xuất:
  ~~~php
  $uri = str_replace('\\', '/', $uri); // hoặc từ chối href chứa '\'
  if (str_starts_with($uri, '//')) return false;
  ~~~
  Cân nhắc chỉ cho http/https/mailto tuyệt đối + đường dẫn bắt đầu bằng đúng một `/` (không `\`, không control char).

### R5 [SHOULD] Sanitize khi ĐỌC ở list quản trị chạy DOM cho từng dòng
- Vị trí: `Admin\CourseResource::toArray`, `Catalog\CourseResource`
- Vấn đề: index 25 dòng x tối đa 20000 ký tự parse DOM mỗi request (ms nhỏ, chấp nhận), nhưng danh mục công khai được cache 60s nên ổn; list admin không cần `description` (tốn băng thông). Không blocker.
- Đề xuất: bỏ `description` khỏi resource ở list (`whenRequired`/chỉ ở show) hoặc ghi rõ chấp nhận.

### R6 [NIT] Ảnh: ngoại lệ ngoài `DecoderException`
- Vị trí: `ImageUploadService::store`
- `read()` có thể ném `RuntimeException`/`NotSupportedException` (GD thiếu WebP, hết RAM). Nên bắt `Intervention\Image\Exceptions\ImageException` rộng hơn. DoS RAM: `dimensions:max 4000x4000` + `max:2048` KB chặn ở validate (đọc header); 16MP GD ~64MB x 2-3 bản. CLI 512M ổn nhưng hãy xác nhận `memory_limit` php-fpm >= 256M, hoặc hạ xuống 3000px.

### R7 [NIT] Rác/format
- `backend/phpunit.t08.xml` chưa track nhưng nằm trong worktree, đừng commit.
- `CourseTeacherService` dòng `['before' => $current,'after' => $teacherIds]` thiếu khoảng trắng (Pint thường sửa; kiểm lại `pint --test`).
- `htmlspecialchars` thiếu `ENT_SUBSTITUTE`: byte UTF-8 sai sẽ làm mất cả text node (hiện libxml hầu như đã chuẩn hoá nên hiếm).

### R8 [NIT] Docblock ghi "Purifier profile" trong FormRequest/Resource
- Thay bằng "HtmlSanitizer" để khỏi gây hiểu nhầm khi đổi thư viện; giữ nội dung docblock đầu HtmlSanitizer cho tới khi thay thư viện, sau đó xoá.

### R9 [NIT] Nginx phục vụ `STATIC_URL`
- Disk `uploads` chỉ khai `url`; nhớ cấu hình Nginx phục vụ tĩnh với `X-Content-Type-Options: nosniff`, chặn thực thi, domain tĩnh tách cookie (đã ghi chú ở config, chưa có ở infra: ngoài phạm vi T08 nhưng cần task).

## Kiểm tra các mục theo yêu cầu
- Policy/IDOR: `view/update` qua `course_teacher`; delete/publish/manageTeachers/manualOrder staff-only; route đều có `can:`; index dùng `visibleTo` (GV chỉ khóa mình). Đạt. Route binding bỏ khóa đã xoá mềm (404).
- Mass-assignment: Service gán từng field, `validated()` không có status/manual_order/teacher_ids (update). Đạt.
- ImageUploadService: mimes+mimetypes+dimensions, decode bằng GD rồi mã hoá WebP (loại SVG/polyglot/EXIF, test EXIF có), tên UUID, không dùng tên gốc. Đạt (xem R1, R6).
- Enrollment chặn xoá: mọi trạng thái enrollment -> 409; có test. Race: R2.
- Không `env()` ngoài config (`env('STATIC_URL')` nằm trong config/filesystems.php: đúng).

## Đối chiếu acceptance criteria
| AC | Code đáp ứng | Ghi chú |
|---|---|---|
| AC1 | `CourseService::create`, StoreCourseRequest | draft, có test |
| AC2/AC3 | `publish` + DomainException 422 | có test |
| AC4/AC5 | `delete` 409 / cascade thủ công soft delete | race R2 |
| AC6/AC7 | `visibleTo`, `CoursePolicy` + `can:` | có test 403 |
| AC8 | Thuộc T09 | ngoài phạm vi |
| AC9 | GV tự tạo, tự gán mình | có test |
| AC10 | `CourseTeacherService::sync`, chặn rỗng | có test |
| AC11 | Thuộc T11-T12 | ngoài phạm vi |
| Contract §4 sanitize ghi+đọc | Có cả hai | sanitizer tự viết, R3/R4 |

## Gợi ý cho QA
- Thử thay thumbnail khi bước sau lỗi (R1); xoá khóa đồng thời với tạo enrollment (R2).
- GIF/SVG đổi đuôi .jpg/.webp, ảnh 4000x4000 thật (RAM), ảnh EXIF GPS.
- Payload XSS/link trong description qua cả POST/PUT rồi GET public + admin; href có `\`.
- GV A cố PUT teachers/publish/manual-order khóa của mình và khóa khác.

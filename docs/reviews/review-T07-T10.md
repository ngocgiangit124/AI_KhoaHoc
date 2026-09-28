# REVIEW: T07 — Schema nội dung + ghi danh / T10 — Danh mục công khai + chi tiết khoá học

**Kết luận:** APPROVE (với các mục SHOULD cần theo dõi)
**Phạm vi:** `git -C worktree diff claude/zen-dirac-fmucf7...t07-t10` (nhánh local `t07-t10`, base đã gộp `claude/zen-dirac-fmucf7`@`4bcd990`) · 48 file thay đổi, +2592 dòng (2 commit: `778c6aa` merge, `c066d8f` T10).

Đã chạy trong Docker (mount worktree, DB test riêng):
```
vendor/bin/pint --test           → 0 lỗi
vendor/bin/phpstan analyse (lvl6)→ [OK] No errors
vendor/bin/pest -c phpunit.t10.xml → 244 passed (694 assertions)
```

## Tổng quan
Code sạch, đúng cấu trúc quy ước dự án (`Enums` string-backed, Model có docblock giải thích rõ vì sao mỗi cột "nhạy cảm" KHÔNG nằm trong `$fillable`, Service tách khỏi Controller, `Support\Like` escape LIKE đúng S24, test riêng cho mass-assignment + constraint DB). Middleware stack của `viewer-state` (`auth:sanctum, account.active, student.single_session, no_store, role:hoc_sinh`) khớp đúng pattern đã dùng cho `/auth/me` và được `tests/Feature/T02/RouteMiddlewareGroupsTest.php` (test kiến trúc có sẵn) xác nhận xanh — không phải hàng tự chế, không phải lỗi. Bug `course_id` mơ hồ trong `CourseViewerStateService::resumeLessonId` đã sửa đúng (`lessons.course_id` tường minh trước khi `join('chapters', ...)`). Cache/no-cookie cho `GET /courses`, `/courses/{slug}`, `/subjects` và `no-store` cho `viewer-state` đều có test xác nhận header thực tế, không chỉ đọc code suy luận.

Vấn đề đáng chú ý nhất không phải lỗi code mà là **khoảng trống nghiệp vụ giữa api-contract và story US-003** (R1) — Dev làm đúng theo api-contract.md (căn cứ chính thức) nên không tính là lỗi của Dev, nhưng cần PO/Architect chốt trước khi T13 xây "Vào học" trên nền `show()`/`viewer-state` hiện tại.

## Phát hiện

### R1 [SHOULD] `show()` và `viewer-state` 404 cứng với khoá `unpublished`, kể cả học sinh đã sở hữu
- Vị trí: `backend/app/Http/Controllers/Api/V1/Catalog/CourseController.php:34-36` (`show`) và `:44-46` (`viewerState`)
- Vấn đề: US-003 "Trường hợp biên & lỗi" ghi rõ: *"Khóa học đã unpublish nhưng học sinh đã mua trước đó cố truy cập → vẫn cho học sinh đã enroll xem bình thường (không bị 404), chỉ ẩn khỏi danh mục công khai và chặn người chưa mua."* Cả `show()` lẫn `viewerState()` hiện `abort(404)` ngay khi `status !== Published`, không phân biệt người gọi có enrollment active hay không — học sinh đã mua sẽ bị 404 hoàn toàn trên trang chi tiết ngay khi admin unpublish khoá (kể cả tạm thời để sửa nội dung).
  Đối chiếu `docs/architecture/api-contract.md:125`: *"Khóa `unpublished`/xoá → 404"* — **không có ngoại lệ cho người đã sở hữu**. Vậy Dev làm đúng theo hợp đồng kỹ thuật hiện hành; đây là mâu thuẫn giữa story và api-contract chưa được chốt, không phải lỗi tự ý của Dev. Nhưng vì `show()` cố tình không đọc cookie/session (M3, để cache CDN — S16), muốn hỗ trợ đúng story sẽ cần đổi kiến trúc (endpoint riêng cho "đã sở hữu nhưng khoá đang ẩn", hoặc chấp nhận học sinh dùng "Khóa học của tôi"/`/learn/courses/{course}` ở T13/T23 thay vì trang chi tiết công khai).
  Test hiện có (`CourseCatalogTest::'GET /courses/{slug} tra 404 khi khoa unpublished (BR4)'`, `CourseViewerStateTest`) chỉ khẳng định hành vi 404 hiện tại, không có test nào cho case "đã enroll + khoá unpublished" — nghĩa là gap này chưa được ai phát hiện qua test.
- Đề xuất: đưa lại cho PO/Architect quyết định 1 trong 2 hướng, cập nhật api-contract cho khớp:
  1. Chấp nhận hành vi hiện tại (đơn giản, đúng S16) — cập nhật US-003 bỏ/làm rõ lại edge case này, ghi chú học sinh đã mua xem qua `/me/courses` hoặc `/learn/courses/{course}` (T13/T23) chứ không qua trang chi tiết công khai.
  2. Nếu giữ đúng story: `show()`/`viewerState()` cần biết danh tính người gọi ngay cả khi khoá unpublished (phá vỡ giả định "không đọc cookie" của `show()`) — cần thiết kế lại, không nên vá tạm trong T10.
  Dù chọn hướng nào, nên thêm 1 dòng TODO/ghi chú tường minh trong `CourseController` (giống cách đã làm tốt với TODO(T08)/TODO(T16)) để không bị quên khi làm T13.

### R2 [SHOULD] `resumeLessonId` fallback "bài đầu tiên" không loại trừ chương đã xoá mềm
- Vị trí: `backend/app/Services/Catalog/CourseViewerStateService.php:64-72`
- Vấn đề:
  ```php
  $firstLesson = Lesson::query()
      ->where('lessons.course_id', $course->id)
      ->join('chapters', 'chapters.id', '=', 'lessons.chapter_id')
      ->orderBy('chapters.position')
      ->orderBy('lessons.position')
      ->select('lessons.*')
      ->first();
  ```
  `Lesson::query()` tự áp `SoftDeletingScope` cho bảng `lessons` (đúng), nhưng `join('chapters', ...)` là join thô trên tên bảng — KHÔNG được Eloquent tự thêm điều kiện `chapters.deleted_at IS NULL`. Nếu một chương bị xoá mềm (chapter đã xoá qua CRUD ở T09) nhưng bài học bên trong nó (do quên xoá cascade, hoặc do bug khác) chưa bị xoá, bài học đó vẫn có thể được chọn làm "bài đầu tiên" để resume — học sinh bị đưa vào bài thuộc chương đã ẩn.
  Rủi ro hiện tại thấp (T07/T10 chưa có luồng xoá chương thật — đó là T09), nhưng đây là bẫy dễ quên khi T09 landing.
- Đề xuất:
  ```php
  ->join('chapters', function ($join) {
      $join->on('chapters.id', '=', 'lessons.chapter_id')
          ->whereNull('chapters.deleted_at');
  })
  ```

### R3 [NIT] `applySort()` thiếu type hint tường minh cho `$query`
- Vị trí: `backend/app/Services/Catalog/CourseCatalogService.php:52`
- Vấn đề: `private function applySort($query, string $sort): void` — chỉ có `@param Builder<Course> $query` ở docblock, không có type native dù `Builder` đã import sẵn. Larastan level 6 không bắt lỗi này nên vẫn xanh, nhưng thiếu nhất quán với phần còn lại của codebase (mọi nơi khác đều type tường minh).
- Đề xuất: `private function applySort(Builder $query, string $sort): void`.

### R4 [NIT] Thiếu test cho "khoá học chưa có chương/bài" (outline rỗng)
- Vị trí: `backend/tests/Feature/T10/CourseCatalogTest.php`
- Vấn đề: US-003 "Trường hợp biên": *"Khóa học chưa có chương/bài học nào (mới tạo) → hiển thị outline rỗng, không lỗi."* Đọc code (`CourseResource::toArray` dùng `whenLoaded('chapters', ...)` trên collection rỗng) thì hành vi đúng (trả `outline: []`), nhưng chưa có test khẳng định — nên thêm 1 test ngắn để khoá hành vi này lại, tránh regression khi refactor sau.

## Đối chiếu acceptance criteria

**US-002 (danh mục):**

| AC | Code đáp ứng | Ghi chú |
|---|---|---|
| AC1 (lọc lớp) | ✓ | `CourseCatalogService::search` + test `loc theo grade` |
| AC2 (lọc lớp + chuyên đề đồng thời) | ✓ | `whereHas('subjects', whereIn)` + test; lưu ý: nhiều `subject_ids` là OR (khớp bất kỳ chuyên đề nào), không phải AND — story không nói rõ, hợp lý với UX checkbox thông thường nhưng nên QA xác nhận với PO |
| AC3 (không khớp → rỗng) | ✓ | Trả `data: []`, thông báo UI thuộc FE (FW2) |
| AC4 (draft/unpublished ẩn) | ✓ | `Course::scopePublished()` + test |
| AC5 (tìm không dấu) | ✓ | `search_text` chuẩn hoá `Str::ascii`+lowercase đúng data-model, test có dấu tiếng Việt |
| AC6 (phân trang 25) | ✓ | `paginate(25)`, test `meta.per_page` |
| AC7 (sort mới nhất/phổ biến) | ✓ | `applySort()` + test cả 2 chiều |
| AC8 (`/lop-{grade}` SEO) | N/A ở T10 | Route FE (FW2), không thuộc backend T10 |
| AC9 (chuyên đề ẩn khỏi filter) | ✓ | `SubjectController::index` chỉ `active` + test |
| S24 (escape LIKE) | ✓ | `App\Support\Like`, test wildcard `%` và SQLi string không 500 |

**US-003 (chi tiết khoá học):**

| AC | Code đáp ứng | Ghi chú |
|---|---|---|
| AC1 (outline đầy đủ, không lộ video) | ✓ | `ChapterOutlineResource`/`LessonOutlineResource`, test khẳng định không có `video_asset_id`/`external_video_id`/`video_source`/`video_url` |
| AC2/AC3 (preview vs chặn phát) | Ngoài phạm vi T10 | Thuộc `/learn`, `/preview/lessons/...` (T11–T13) |
| AC4 (nút "Vào học" + resume) | ✓ (qua viewer-state) | `resume_lesson_id` đúng theo `last_accessed_at` hoặc bài đầu — xem R2 |
| AC5 (404 khi không tồn tại/đã xoá) | ✓ | Route model binding + scope Published, test đủ 3 case (draft/unpublished/xoá mềm/slug sai) |
| AC6 (nhiều GV phụ trách) | ✓ | `CourseTeacherResource::collection`, test 2 GV |
| AC7 (khoá miễn phí → "Đăng ký") | ✓ (qua viewer-state `can_register_free`) | Nút hiển thị là FE |
| AC8 (số lượng đăng ký) | ✓ | `enrollments_count` trả thẳng, test giá trị 42 |
| Edge case "unpublish nhưng đã mua vẫn xem được" | ✗ | Xem R1 |

## Gợi ý cho QA
- Xác nhận với PO ngữ nghĩa `subject_ids[]` nhiều giá trị là OR hay AND (AC2 chỉ test 1 chuyên đề).
- Test thủ công/kịch bản: học sinh đã mua khoá, admin unpublish khoá đó → hiện tại trang chi tiết + viewer-state đều 404 cho MỌI người kể cả chủ sở hữu (R1) — QA nên xác nhận đây có phải hành vi mong muốn trước khi chấp nhận, vì khác với "Trường hợp biên" viết trong US-003.
- Kiểm tra hiệu năng khi seed vài trăm khoá (US-002 "Trường hợp biên" nhắc ngưỡng < 2s) — chưa có test tải nào ở T10, để dành cho giai đoạn có FW2 + load test thật.
- `manual_order` tie-break: test hiện chỉ có 1 khoá có `manual_order`, 1 khoá NULL — nên thêm kịch bản 2 khoá cùng `manual_order` để chắc tie-break `created_at desc, id desc` đúng như comment.

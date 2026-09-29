# DB Review: T07 (schema nội dung + ghi danh) + truy vấn danh mục T10

**Kết luận: REQUEST CHANGES (1 mục MEDIUM, dễ sửa, không có gì chặn nghiêm trọng).**

Phạm vi: `backend/database/migrations` (9 migration T07), `backend/app/Models`
(Chapter, Course, Enrollment, Lesson, LessonProgress, Subject, VideoAsset),
`backend/app/Services/Catalog` (CourseCatalogService, CourseViewerStateService)
trên nhánh `t07-t10` so với base `claude/zen-dirac-fmucf7`.

Môi trường kiểm chứng: MySQL 8.4 (Docker `infra/docker-compose.yml`, đúng bản
production), DB `vitaminvui_testing_t10` (migrate:fresh từ migration của
nhánh này). Seed thủ công ~4.000 `courses` (script tinker tạm, không commit)
+ 300 học sinh + 1.500 `enrollments` + 4.500 `lesson_progress` để chạy
`EXPLAIN`/`EXPLAIN FORMAT=JSON` với khối lượng lớn hơn nhiều so với "vài trăm
khóa" mà data-model giả định. Chạy `vendor/bin/pest -c phpunit.t10.xml
tests/Feature/T07 tests/Feature/T10` trong Docker: **51/51 PASS** — không có
hồi quy chức năng nào từ các phát hiện dưới đây.

---

## 1. Migration T07 — đúng thiết kế `data-model.md` §3.2–3.3

Xác nhận lại (đã có ở `docs/db/T07-checklist.md` từ vòng review trên cloud,
nay chạy lại toàn bộ trên MySQL 8.4 thật thay vì 8.0 của cloud):

- `SHOW CREATE TABLE` cho `subjects`, `courses`, `course_subject`,
  `enrollments`, `lesson_progress`: đúng kiểu cột, `NOT NULL`, default, FK,
  `ON DELETE`, CHECK, generated column STORED, collation
  `utf8mb4_0900_ai_ci` trên mọi bảng (kế thừa từ `config/database.php`,
  migration không override charset/collation riêng — đúng).
- `migrate:fresh` rồi `migrate:rollback --step=9`: chạy sạch, FK vòng
  `video_assets.lesson_id` ↔ `lessons` được `dropForeign` đúng thứ tự trước
  khi `dropIfExists('lessons')`.
- CHECK `chk_courses_grade_level`: từ chối 5/13/0/-1, chấp nhận 6/9/12 (test
  `SchemaConstraintsTest`, PASS).
- `enrollments.live_flag` (generated STORED) + unique
  `(user_id, course_id, live_flag)`: chặn đúng 2 dòng "đang sống"
  (`pending_approval`/`active`) cho cùng (user, course); dòng
  `rejected`/`revoked` có `live_flag = NULL` nên không chặn gửi lại/mua lại
  (US-012 AC5) — test PASS.
- FK `ON DELETE`: `RESTRICT` cho mọi quan hệ chạm tới lịch sử/chứng từ
  (`enrollments.user_id/course_id`, `lesson_progress.user_id/lesson_id`,
  `video_assets.lesson_id`, `courses.created_by`) — nhất quán với nguyên tắc
  "không hard-delete user/course, chỉ ẩn danh hoá/soft delete" của
  data-model §1. `CASCADE` chỉ dùng cho pivot thuần (`course_subject.course_id`,
  `course_teacher.course_id`). `course_subject.subject_id` → `RESTRICT`
  đúng US-011 AC3.
- Mass assignment (S17): `Course`, `Enrollment`, `Lesson`, `LessonProgress`
  đều loại các cột trạng thái/đếm ra khỏi `$fillable` — test
  `MassAssignmentTest` xác nhận `MassAssignmentException` khi cố gán qua
  `create()`.

**Đối chiếu xung đột T06 vs T07 (`subjects`):** đã xem
`git show origin/claude/zen-dirac-fmucf7-t06` — `SubjectService::delete()`
của T06 **chủ động dựa vào** lỗi FK MySQL 1451
(`course_subject.subject_id` RESTRICT) để trả 409 `SUBJECT_IN_USE`, và
docblock của service ghi rõ "không tự đếm bằng truy vấn course_subject...
FK của T07 tự động đúng khi gộp nhánh". **Bản `subjects`/`course_subject`
của T07 (bản giữ lại) đủ cho nhu cầu T06** — không cần sửa gì để gộp.
Khác biệt duy nhất: migration `subjects` của T06 có thêm
`$table->index('status')` mà bản T07 không có (mục 5 bên dưới — LOW,
không chặn gộp).

## 2. Index cho truy vấn danh mục T10 (EXPLAIN với ~4.000 `courses`)

`CourseCatalogService::search()` build 1 query: `courses.published()` +
lọc `grade_level`, `whereHas('subjects', whereIn)`, `search_text LIKE`,
3 kiểu sort, `paginate(25)`. Index hiện có trên `courses`: unique(`slug`),
`(status, grade_level)`, FK `created_by`. Kết quả `EXPLAIN`:

| Truy vấn | type | key | rows | Extra |
|---|---|---|---|---|
| `status=published ORDER BY published_at DESC, id DESC` (newest, **mặc định, không filter — trang đầu tiên khách vào**) | `ALL` | *(không dùng index)* | 3985 | **Using where; Using filesort** |
| + `grade_level=8` | `ref` | `(status,grade_level)` | 541 | vẫn **Using filesort** |
| `ORDER BY enrollments_count DESC` (popular) | `ALL` | — | 3985 | **Using where; Using filesort** |
| `ORDER BY manual_order ASC` (featured) | `ALL` | — | 3985 | **Using where; Using filesort** |
| `search_text LIKE '%toan%'` | `ALL` | — | 3985 | Using where; Using filesort (chấp nhận được — xem mục 2.3) |
| `COUNT(*) WHERE status=published` (câu COUNT của `paginate()`) | `ref` | `(status,grade_level)` | 4000 | **Using index** (tốt) |
| `whereHas('subjects', whereIn 3 id)` + `ORDER BY published_at` | materialize + `eq_ref` | `PRIMARY` | — | `EXPLAIN FORMAT=JSON`: **`using_temporary_table: true, using_filesort: true`** ở tầng ngoài |
| `resume_lesson_id` — `lesson_progress WHERE user_id=? AND course_id=? ORDER BY last_accessed_at DESC LIMIT 1` | `range` | `(user_id,course_id,last_accessed_at)` | 1 | **Backward index scan** — tối ưu, không filesort |
| viewer-state — `enrollments WHERE user_id=? AND course_id=? AND live_flag IS NOT NULL` | `ref` | index có `user_id` làm tiền tố | 1 | OK ở quy mô vài enrollment/user |

### 2.1 MEDIUM — thiếu index cho sort mặc định "mới nhất" (`published_at`)

`GET /courses` không tham số (trang danh mục mặc định, lưu lượng cao nhất)
luôn quét toàn bộ `courses` (`type=ALL`) rồi `filesort` theo `published_at`.
**Đây không chỉ là gợi ý tối ưu — `docs/stories/US-002-...md` mục "Ảnh hưởng
dữ liệu" ghi rõ: "Index trên `courses.grade_level`, `courses.status`,
`courses.published_at`"**. Migration T07 mới có index ghép
`(status, grade_level)`, **chưa có index nào chứa `published_at`** — thiếu
đúng 1 trong 3 yêu cầu nêu trong story.

Ở quy mô ~4.000 khóa (gấp 10-20 lần "vài trăm khóa" dự kiến MVP), 1 request
vẫn nhanh (không đo được > vài ms), nhưng đây là **trang được gọi ở tần suất
cao nhất** (`throttle:catalog` 120/phút, mọi khách vào `/khoa-hoc` không
filter), và chi phí sửa **gần như bằng 0** lúc này vì bảng `courses` chưa có
dữ liệu thật trên `vitaminvui`/staging.

**Đề xuất — migration mới (không sửa migration T07 đã có), `laravel-dev`
thêm trước khi gộp:**

```php
Schema::table('courses', function (Blueprint $table): void {
    $table->index(['status', 'published_at'], 'courses_status_published_at_index');
});
```

```sql
ALTER TABLE courses
  ADD INDEX courses_status_published_at_index (status, published_at),
  ALGORITHM=INPLACE, LOCK=NONE;
```

Với composite `(status, published_at)`: `WHERE status='published' ORDER BY
published_at DESC` dùng `ref` trên `status` rồi đọc index theo thứ tự
(MySQL 8 quét lùi khi không có index DESC tường minh) → hết `Using
filesort` cho case phổ biến nhất (newest, không filter grade). Case
`grade_level` cùng lúc vẫn dùng `(status, grade_level)` hiện có (đủ, vì
`grade_level` chỉ có 7 giá trị, không cần đưa cả 2 cột lọc + `published_at`
vào 1 index).

**Tác động:** bảng ghi ít (chỉ đổi khi publish/unpublish/sửa khóa — không
phải bảng heartbeat), thêm 1 index ghép 2 cột (không đáng kể dung lượng ở
quy mô vài nghìn khóa). `ALGORITHM=INPLACE, LOCK=NONE` áp dụng được (thêm
secondary index trên InnoDB, MySQL 8.4) — không cần `gh-ost`/`pt-osc` kể cả
khi bảng đã có dữ liệu thật, vì đây là thao tác online chuẩn.

### 2.2 LOW — `popular`/`featured` không có index riêng

`ORDER BY enrollments_count DESC` và `ORDER BY manual_order ASC` đều quét
toàn bảng + filesort. **Không đề xuất thêm index ngay**: `enrollments_count`
bị `UPDATE` mỗi khi có enrollment vào/ra `active` (denormalize counter) —
thêm index trên cột này tăng chi phí ghi trên đúng cột "hot" nhất của bảng,
đổi lấy lợi ích chưa rõ (chỉ 1 trong 3 kiểu sort, "vài trăm/vài nghìn khóa"
vẫn quét nhanh). Đề xuất: theo dõi qua slow query log sau khi lên production
thật; nếu `courses` (bản ghi `published`) vượt khoảng **5.000–10.000 dòng**
hoặc sort `popular`/`featured` xuất hiện trong
`sys.schema_unused_indexes`/slow log, quay lại đánh giá index
`(status, enrollments_count)`. `manual_order` gần như luôn `NULL` (chỉ set
cho khóa "nổi bật" — số lượng nhỏ) nên filesort trên cột này rẻ ở mọi quy mô
thực tế, không cần index.

### 2.3 LOW/INFO — `search_text LIKE '%...%'` quét toàn bảng

Chấp nhận được, **đúng như quyết định thiết kế đã ghi ở data-model §3.2**
("Danh mục chỉ vài trăm khóa → quét `search_text LIKE %từ%` là đủ; không
cần FULLTEXT ở MVP"). Xác nhận lại ở quy mô 4.000 dòng: `filtered: 11.11%`,
vẫn quét toàn bảng do `LIKE` bắt đầu bằng `%` (không index nào hỗ trợ được
trừ FULLTEXT — không cần ở MVP). Đề xuất bổ sung 1 ngưỡng theo dõi cụ thể
(cùng phong cách với ngưỡng đã có cho `lesson_progress`): nếu `courses`
(bản ghi chưa xoá) vượt **~20.000 dòng** hoặc `q` xuất hiện > X%/tổng
request `GET /courses` với p95 > 300ms, đánh giá lại FULLTEXT index (`ngram`
parser cho tiếng Việt) hoặc Elasticsearch/Meilisearch — không phải việc của
T07/T10.

### 2.4 INFO — lọc theo `subject_ids` (whereHas EXISTS) có filesort + bảng tạm

`EXPLAIN FORMAT=JSON` cho `whereHas('subjects', whereIn 3 id)` kèm
`ORDER BY published_at`: subquery `subjects ⋈ course_subject` được
MySQL **MATERIALIZE** (dùng đúng index `course_subject_subject_id_index`,
hiệu quả), nhưng bước ngoài cùng (join lại với `courses` + `ORDER BY`) có
`"using_temporary_table": true, "using_filesort": true` (query cost ~1049
với 3 subject_id trong danh sách, 533 dòng course_subject khớp). `q`
(≤ 20 subject_ids theo `CourseSearchRequest`) có thể làm subquery lớn hơn
tỷ lệ thuận. Ở quy mô hiện tại (vài trăm–vài nghìn khóa) vẫn nhanh; không
có cách index hoá triệt để pattern EXISTS + ORDER BY trên bảng khác cột lọc
mà không denormalize (ví dụ JSON array `subject_ids` trên `courses` +
generated/functional index — over-engineering ở MVP). Không yêu cầu sửa,
chỉ ghi nhận để không bất ngờ nếu load test T10/FW2 (api-contract yêu cầu
p95 TTFB ≤ 500ms/50 req/s) thấy request có `subject_ids` chậm hơn các
request khác — đã có input cho DBA điều tra tiếp nếu xảy ra.

### 2.5 PASS — các truy vấn còn lại đã tối ưu đúng

- `resume_lesson_id` (`CourseViewerStateService::resumeLessonId`): index
  `lesson_progress_user_id_course_id_last_accessed_at_index` — **backward
  index scan**, 1 dòng, không filesort, không cần sửa. Đây là truy vấn nóng
  nhất trong nhóm viewer-state (gọi mỗi lần khách xem trang chi tiết khóa đã
  sở hữu) — đã đúng từ thiết kế T07.
- `COUNT(*)` của `paginate()` (không filter): **Using index** (covering,
  index `(status, grade_level)` đủ để đếm) — rẻ.
- Escape `LIKE` qua `App\Support\Like::escape()` (S24): đúng thứ tự escape
  `\` trước `%`/`_`, vẫn dùng binding — đã có test
  `GET /courses escape ky tu wildcard cua LIKE (S24)` PASS.
- `enrollments` lookup cho viewer-state (`user_id + course_id +
  live_flag IS NOT NULL`): optimizer chọn
  `enrollments_user_id_status_last_accessed_at_index` (không phải index
  unique) do dữ liệu mẫu ít — cả 2 lựa chọn đều rẻ ở quy mô vài enrollment/
  học sinh, không cần ép `FORCE INDEX`.

## 3. Kiểm tra riêng theo yêu cầu (collation, soft delete + unique)

- **Collation `utf8mb4_0900_ai_ci` cho unique tiếng Việt có dấu đặc biệt
  (`đ`, `ơ`, `ư`)**: test trực tiếp trên bảng `subjects` thật (không chỉ ví
  dụ "Hình học"/"Hinh hoc" đã có ở T07-checklist — ví dụ đó không có chữ
  `đ`): insert `'Đại số'` rồi `'Dai so'` (client charset `utf8mb4` đúng) →
  **`1062 Duplicate entry 'Dai so' for key 'subjects.subjects_name_unique'`**.
  Xác nhận bằng so sánh trực tiếp:
  `SELECT ('Đại số' = 'Dai so' COLLATE utf8mb4_0900_ai_ci)` → `1`;
  `SELECT ('đ' = 'd' COLLATE utf8mb4_0900_ai_ci)` → `1`. **PASS** — đúng như
  data-model/T07-checklist đã khẳng định, không có lỗ hổng riêng cho `đ`/
  `ơ`/`ư`. (Lưu ý kỹ thuật: lần thử đầu tiên của tôi dùng `mysql` CLI không
  ép `--default-character-set=utf8mb4` → client gửi chuỗi theo `latin1`,
  làm sai lệch byte và 2 dòng KHÔNG trùng nhau — đây là lỗi công cụ kiểm
  thử của tôi, không phải lỗi ứng dụng; Laravel dùng PDO với
  `charset=utf8mb4` trong `config/database.php` nên không gặp vấn đề này.)
- **Soft delete + unique**: `courses.slug` là `unique` **trên toàn bộ bảng
  kể cả các dòng đã xoá mềm** (Laravel `unique()` không tự loại trừ
  `deleted_at`). Đây là lựa chọn đúng ở tầng schema cho URL công khai (tránh
  1 khóa mới nhận đúng slug của khóa cũ đã xoá — tránh nhầm lẫn/liên kết
  cũ trỏ sai nội dung); khớp với ghi chú "tự sinh, thêm hậu tố `-2`, `-3`
  khi trùng" ở data-model — hậu tố phải tính cả những slug đã "chết" do xoá
  mềm. **Việc cần T08 (`CourseService`, chưa hiện thực ở T07/T10) đảm bảo**:
  hàm sinh slug duy nhất phải kiểm tra trùng bằng `Course::withTrashed()`,
  không chỉ `Course::query()`, nếu không lúc `INSERT` sẽ có thể dính lỗi
  `1062` từ chính DB (an toàn dữ liệu vẫn được đảm bảo nhờ unique index,
  nhưng sẽ lộ ra thành `QueryException`/500 nếu Service không tự phòng
  trước) — không phải lỗi của T07, chỉ là điểm cần nhắc T08 khi review.
  Không có bảng nào khác trong T07 vừa soft-delete vừa unique theo cách có
  thể gây nhầm lẫn tương tự (`video_assets`, `lesson_progress`,
  `enrollments` không soft-delete; `subjects` không soft-delete).

## 4. Phát hiện phụ (không phải lỗi DB, ghi lại để khỏi lặp)

- `CourseCatalogService::applySort('featured', ...)` dùng tie-break
  `created_at DESC, id DESC`, trong khi `data-model.md` §3.2 dòng
  `manual_order` ghi "tie-break `published_at desc, id desc`". Story gốc
  `US-002` BR6/edge-case chỉ nêu ví dụ "theo `created_at`" (không bắt buộc).
  → 2 tài liệu thiết kế (story vs data-model) tự mâu thuẫn nhau; code khớp
  với story, lệch với data-model. Không phải lỗi index/schema — đề nghị
  Architect chốt lại 1 trong 2 tài liệu, không cần DBA/laravel-dev sửa gì
  thêm ở T07/T10.
- `subjects` (bản T07) thiếu `$table->index('status')` mà bản T06 tự viết
  có sẵn. Bảng `subjects` dự kiến vài chục dòng nên không ảnh hưởng hiệu
  năng thực tế; thêm cho nhất quán nếu tiện, không bắt buộc (LOW).

## Script kiểm chứng (đã chạy, để tái lặp khi cần)

```bash
# Migrate schema vào DB test riêng (không đụng vitaminvui/_testing/_testing_t04)
cd infra && docker compose run --rm --no-deps -T -e DB_DATABASE=vitaminvui_testing_t10 \
  -v <worktree>/backend:/var/www/wt -w /var/www/wt php php artisan migrate:fresh --force

# Test T07+T10 (không đụng DB dùng chung — phpunit.t10.xml ép DB_DATABASE=vitaminvui_testing_t10)
docker compose run --rm --no-deps -T -v <worktree>/backend:/var/www/wt -w /var/www/wt \
  php sh -c 'vendor/bin/pest -c phpunit.t10.xml tests/Feature/T07 tests/Feature/T10'

# EXPLAIN sau khi thêm index đề xuất ở mục 2.1 — kỳ vọng hết "Using filesort"
# cho case mặc định (không filter):
EXPLAIN SELECT * FROM courses WHERE status='published'
  ORDER BY published_at DESC, id DESC LIMIT 25 OFFSET 0;
```

## Kế hoạch chạy trên production & rollback

Bảng `courses` chưa có dữ liệu thật (T07/T10 chưa gộp/deploy) — thêm index
mục 2.1 nên đi **cùng migration T07 gốc** (laravel-dev sửa migration hiện có
hoặc thêm 1 migration nhỏ ngay sau, trước khi gộp vào `claude/zen-dirac-fmucf7`
và trước khi chạy trên `vitaminvui`/staging). Nếu tình huống thực tế là bảng
đã có dữ liệu (ví dụ chạy lại review sau khi đã có courses thật): dùng
`ALTER ... ALGORITHM=INPLACE, LOCK=NONE` như trên, an toàn vì thêm secondary
index không khoá bảng ở MySQL 8; không cần `gh-ost`/`pt-osc` ở quy mô dự
kiến (vài nghìn–vài chục nghìn khóa). Rollback: `DROP INDEX
courses_status_published_at_index ON courses;` — không mất dữ liệu, không
ảnh hưởng ứng dụng (chỉ là index phụ).

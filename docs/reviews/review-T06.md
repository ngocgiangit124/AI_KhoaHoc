# REVIEW: T06 — Chuyên đề (CRUD), US-011

**Kết luận:** PASS — vòng 1 PASS có 2 SHOULD/3 NIT; vòng 2 (commit `7a481ef`) xác nhận đã sửa hết, APPROVE cuối cùng, không còn tồn đọng.
**Phạm vi:** `git diff t07-t10...t06` trên nhánh local `t06` (worktree `.claude/worktrees/t06`) — CHỈ phần T06, T07-T10 đã APPROVE riêng trước đó. 13 file, +713/-21:
`app/Http/Controllers/Api/V1/Admin/SubjectController.php` (mới), `app/Http/Controllers/Controller.php`,
`app/Http/Requests/Admin/SubjectRequest.php` (mới), `app/Http/Requests/Admin/UpdateSubjectStatusRequest.php` (mới),
`app/Http/Resources/Admin/SubjectResource.php` (mới), `app/Models/Subject.php`, `app/Policies/SubjectPolicy.php` (mới),
`app/Rules/PlainText.php` (mới), `app/Services/Catalog/SubjectService.php` (mới),
`database/migrations/2026_09_28_091100_add_status_index_to_subjects_table.php` (mới),
`routes/admin.php`, `tests/Feature/T02/RouteMiddlewareGroupsTest.php`, `tests/Feature/T06/SubjectAdminTest.php` (mới).

Đã tự chạy lại (không chỉ tin báo cáo dev), trong Docker, mount worktree theo hướng dẫn:
`vendor/bin/pint --test` → sạch (175 file). `vendor/bin/phpstan analyse` → 0 lỗi. `vendor/bin/pest -c phpunit.t06.xml` → **263 passed (746 assertions)**, khớp con số dev báo.

## Tổng quan
Code gọn, đúng quy ước dự án (Service theo domain, Policy cho mọi hành động theo id, `$fillable` không chứa `status`, audit qua `AuditLogger` có sẵn, không dùng `$request->all()`). Cách chặn xoá dựa hẳn vào ràng buộc FK `restrict` của `course_subject.subject_id` (không tự đếm) là lựa chọn đúng — vừa khớp xác nhận của DBA (`docs/db/T07-review.md`), vừa loại bỏ hoàn toàn nguy cơ race giữa "đếm rồi xoá". Toàn bộ AC1–AC6 đều có test tương ứng và pass. Hai điểm SHOULD dưới đây không chặn nghiệp vụ chính nhưng nên sửa trước khi coi US-011 là "xong" theo nghĩa khớp 100% api-contract/story.

## Phát hiện

### R1 [SHOULD] `status` được validate ở cả tạo lẫn sửa, ngoài phạm vi api-contract, và bị lờ đi âm thầm khi sửa
- Vị trí: `backend/app/Http/Requests/Admin/SubjectRequest.php:36` (`'status' => ['sometimes', Rule::enum(SubjectStatus::class)]`), dùng chung cho `store` và `update`; `backend/app/Services/Catalog/SubjectService.php` — `update()` chỉ đọc `$data['name']`, không đụng đến `status`.
- Vấn đề: `api-contract.md` §2.5 ghi rõ `SubjectRequest: name (trim, 1–100, văn bản thuần)` — không có `status`. Story US-011 AC1 cũng khẳng định tạo mới luôn ở trạng thái `active` (không phải trường admin tự chọn khi tạo). Cho phép `status` ở POST là bịa thêm field ngoài hợp đồng (quy tắc đã thống nhất ở `docs/board.md`: "Không tự bịa field ngoài api-contract"). Nghiêm trọng hơn: vì cùng 1 Request class dùng cho PUT, gửi `status` khi sửa tên sẽ được **validate** (yêu cầu đúng enum `active`/`hidden`) nhưng **bị bỏ qua hoàn toàn** ở `SubjectService::update()` — nếu UI dùng chung 1 object form cho tạo/sửa và vô tình gửi `status` sai giá trị khi PUT, request sẽ bị 422 một cách khó hiểu cho một field không hề có tác dụng. Không có test nào phủ trường hợp này (chỉ có test cho PATCH `.../status` riêng).
- Đề xuất:
  ~~~php
  // SubjectRequest: bỏ hẳn 'status' khỏi rules() — tạo mới luôn active theo AC1,
  // đổi trạng thái đi qua PATCH .../status (đã có UpdateSubjectStatusRequest riêng).
  public function rules(): array
  {
      $subject = $this->route('subject');

      return [
          'name' => [
              'required', 'string', 'max:100', new PlainText,
              Rule::unique('subjects', 'name')->ignore($subject?->getKey()),
          ],
      ];
  }
  ~~~
  ~~~php
  // SubjectService::create(): bỏ tham số status, luôn set Active (khớp AC1).
  public function create(array $data): Subject
  {
      $subject = new Subject(['name' => $data['name'], 'slug' => $this->uniqueSlug($data['name'])]);
      $subject->status = SubjectStatus::Active;
      $subject->save();
      ...
  }
  ~~~
  Nếu thực sự cần cho phép set status ngay lúc tạo (ví dụ import hàng loạt), nên hỏi Architect để ghi vào api-contract trước, không tự thêm ngầm.

### R2 [SHOULD] Policy chạy SAU validate — Giáo viên gửi dữ liệu không hợp lệ sẽ nhận 422 thay vì 403
- Vị trí: `backend/app/Http/Requests/Admin/SubjectRequest.php:16` (`authorize(): bool { return true; }`) + `backend/app/Http/Controllers/Api/V1/Admin/SubjectController.php` (`$this->authorize(...)` gọi ở đầu action, nhưng Laravel đã chạy validate của `SubjectRequest` trước khi vào thân method).
- Vấn đề: US-011 mục "Trường hợp biên & lỗi" ghi: "Giáo viên cố gọi API tạo/sửa/xóa chuyên đề trực tiếp → bị từ chối quyền (403)". Vì `authorize()` của Form Request luôn `true` (đúng quy ước api-contract §1.3 của dự án — không phải lỗi tự chế), Policy chỉ được kiểm trong thân controller, tức là SAU khi validate đã chạy. Test hiện tại (`giao vien chi xem duoc chuyen de active...`) chỉ gửi payload hợp lệ (`name: 'Số học'`, `'Đổi tên'`) nên luôn thấy 403 — nhưng nếu Giáo viên gửi `PUT` với tên trùng/rỗng/chứa HTML, họ sẽ nhận **422** (lộ thông tin validate: ví dụ biết được tên đó đã tồn tại) thay vì 403 thuần theo đúng câu chữ story. Đây là hệ quả tất yếu của quy ước "Form Request không tự authorize, Controller gọi `$this->authorize()`" lần đầu áp dụng chung với Policy thật (T06 là nơi đầu tiên) — không phải Dev tự sáng chế sai, nhưng chưa ai kiểm chứng hệ quả thứ tự này trước đây.
- Đề xuất: thêm 1 test "giáo viên gửi PUT với tên trùng vẫn nhận 403 (không phải 422)" để chốt hành vi mong muốn; nếu muốn giữ đúng 403 tuyệt đối, kiểm quyền ngay trong `SubjectRequest::authorize()` (route model binding đã sẵn sàng ở thời điểm này) thay vì luôn `true`:
  ~~~php
  public function authorize(): bool
  {
      $subject = $this->route('subject');

      return $subject
          ? $this->user()->can('update', $subject)
          : $this->user()->can('create', Subject::class);
  }
  ~~~
  Vì hệ quả này áp dụng cho mọi Form Request kết hợp Policy sau này, nên cân nhắc đưa quyết định (giữ nguyên vì chấp nhận 422 lẫn 403 đều là "từ chối", hay bắt buộc 403 tuyệt đối) lên Architect/PO một lần, ghi vào ADR-004 hoặc api-contract §1.3, thay vì để mỗi task tự xử lý khác nhau.

### R3 [NIT] Controller tự viết lại `where('status', ...)` thay vì dùng lại `Subject::scopeActive()`
- Vị trí: `backend/app/Http/Controllers/Api/V1/Admin/SubjectController.php:22` (`$query->where('status', SubjectStatus::Active)`), trong khi `Subject::scopeActive()` đã có sẵn (đang dùng ở phía catalog T10).
- Đề xuất: `$query->active()` cho nhất quán, tránh 2 nơi định nghĩa "thế nào là active".

### R4 [NIT] `per_page` không chặn giá trị ≤ 0
- Vị trí: `SubjectController::index()`: `$perPage = min((int) $request->integer('per_page', 25), 50);`.
- Vấn đề: `per_page=0` hoặc số âm → `min(0, 50) = 0` (hoặc âm), `paginate(0)`/`paginate(-5)` có thể sinh `LIMIT 0`/lỗi cú pháp SQL. Rủi ro thấp (chỉ staff/GV gọi được, không phải endpoint công khai) nhưng dễ sửa.
- Đề xuất: `$perPage = max(1, min((int) $request->integer('per_page', 25), 50));`.

### R5 [NIT] `Subject::isActive()` chưa được dùng ở diff này
- Vị trí: `backend/app/Models/Subject.php:59`.
- Ghi chú: không sai, có thể là chuẩn bị cho T08/T09 (hiển thị badge/kiểm tra khi gán chuyên đề cho khóa học). Nếu đến hết T09 vẫn không ai gọi, nên xoá để tránh code chết.

## Trả lời các điểm dev nhờ soi
1. **`SubjectService::create()` gán `status` trực tiếp thay vì mass-assign** — đúng, khớp quy ước S17 (`Course::$fillable` không chứa cột trạng thái). `Subject::$fillable` chỉ có `name`, `slug`; `status` được set qua thuộc tính rồi `save()`. PASS.
2. **`deleteOrFail()` thay `delete()`** — đã đọc `vendor/laravel/framework/.../Model.php`: `deleteOrFail()` bọc `delete()` trong `DB::transaction()`, transaction lan truyền lại đúng `QueryException` khi MySQL báo lỗi 1451 (không nuốt exception, không đổi hành vi 409 `SUBJECT_IN_USE`). Đúng như giải thích của dev, hợp lý để tránh Larastan báo "dead catch" (docblock `delete()` của framework chỉ khai `@throws \LogicException`). PASS.
3. **Migration `2026_09_28_091100_add_status_index_to_subjects_table.php`** — đúng như DBA khuyến nghị ở `docs/db/T07-review.md` mục 4 (LOW), migration MỚI (không sửa migration T07 gốc), có `down()` đối xứng (`dropIndex`). PASS.
4. **Route `/admin/subjects*` dùng khung middleware staff của T02** — đúng phạm vi: đây chính là "khung" `auth:sanctum, account.active, staff.idle, staff.mfa_passed, staff.password_fresh, role:...` đã được T01/T02 chuẩn bị sẵn làm pass-through cho T28, và `RouteMiddlewareGroupsTest` đã bắt buộc nhóm này cho mọi route cần đăng nhập trên admin-api. T06 dùng đúng nhóm có sẵn, không tự chế thêm — khớp `feedback-laravel-conventions` đã ghi trong memory review trước. `admin.origin` áp ở cấp `Route::domain()` bao ngoài (dòng 20), không thiếu. PASS.
5. **Test AC3 bật lại dùng `Course::factory()` + `attach()`** — chạy thật, pass; cách chặn dựa vào FK `restrict` (không tự đếm) loại bỏ luôn nguy cơ race giữa "kiểm tra rồi xoá" nêu ở mục dưới. PASS.

## Kiểm thêm theo yêu cầu
- **Phân quyền theo vai trò**: `SubjectPolicy` đúng bảng phân quyền US-011 (Admin/QLT: đầy đủ; GV: chỉ `viewAny`/`view`). Route middleware `role:admin,quan_ly_trang,giao_vien` chặn Học sinh/khách ở lớp thô, Policy chặn GV ở lớp tinh — đúng mô hình "2 lớp" của dự án. Riêng thứ tự validate-trước-policy có hệ quả nhỏ, xem R2.
- **Audit (S15)**: `subject.create`, `subject.update`, `subject.delete`, `subject.status.update` đều gọi `AuditLogger::log()` với dữ liệu trước/sau, có test riêng cho cả 3 thao tác — khớp yêu cầu "audit tạo/xoá" của `tasks.md` T06 (thậm chí làm nhiều hơn: audit cả sửa/đổi trạng thái). Không có PII trong `changes` nên không đụng bộ lọc `AuditLogger::sanitize()`. Audit không được bọc cùng transaction với thao tác ghi chính (ví dụ `delete()` xoá xong mới log) — đã đối chiếu với `StaffLockCommand` (cũng không transaction hoá audit), nên đây là kiểu áp dụng nhất quán với phần còn lại của dự án (chỉ `StaffCreateCommand` transaction hoá vì lý do riêng: mật khẩu ngẫu nhiên chỉ hiện 1 lần) — không tính là lỗi.
- **Validate tên thuần văn bản (S8)**: `PlainText` rule dùng `strip_tags()`, có test `<script>alert(1)</script>` → 422. Khớp `tasks.md`: "Tên là văn bản thuần".
- **Race khi xoá lúc đang gán**: không có cửa sổ race — logic không "đếm rồi xoá" (kiểu TOCTOU) mà để chính câu lệnh `DELETE` của MySQL tự kiểm ràng buộc FK nguyên tử; nếu một `INSERT` vào `course_subject` chạy đồng thời, MySQL vẫn đảm bảo tính nhất quán ở tầng InnoDB. An toàn.

## Đối chiếu acceptance criteria
| AC | Code đáp ứng | Ghi chú |
|---|---|---|
| AC1 — tạo mới → `active`, xuất hiện ngay | Có | Test "quan ly trang tao chuyen de moi thanh cong"; nhưng xem R1 — `status` không nên là input của endpoint tạo |
| AC2 — trùng tên (không phân biệt hoa/thường) → lỗi "Chuyên đề đã tồn tại" | Có | Test dùng `'Đại số'`/`'đại số'`, dựa vào collation đã DBA xác nhận |
| AC3 — đang gán ≥1 khóa học → chặn xoá | Có | Test dùng `Course::factory()` + `attach()`, 409 `SUBJECT_IN_USE` |
| AC4 — chưa gán → xoá được | Có | Test xoá thành công, `assertDatabaseMissing` |
| AC5 — đổi tên, tự động phản ánh nơi khác | Có | Không denormalize tên ở `courses`/`course_subject`, chỉ FK — đúng thiết kế |
| AC6 — ẩn → biến mất khỏi bộ lọc công khai, khóa cũ vẫn hiển thị | Một phần (test PATCH .../status; phần "biến mất khỏi bộ lọc" đã có ở test T10 `GET /subjects chi tra chuyen de active`) | Không thuộc diff T06 nhưng đã xác nhận chạy chung 263 test pass |
| BR4 — phân quyền staff/GV | Có | `SubjectPolicy` + test theo vai trò; xem R2 về thứ tự policy/validate |

## Gợi ý cho QA
- Test tay: Giáo viên gửi `PUT /admin/subjects/{id}` với tên trùng/tên rỗng/HTML — xác nhận mã lỗi trả về (422 hiện tại, có thể PO muốn 403 tuyệt đối — xem R2) trước khi ký QA cho story.
- Test tay: gửi `POST /admin/subjects` kèm `status: hidden` — xác nhận có tạo được chuyên đề ẩn ngay từ đầu hay không (hành vi hiện tại: có, nhưng ngoài api-contract — xem R1).
- Test tải: `subjects` dự kiến chỉ vài chục dòng nên không cần test hiệu năng riêng; index `subjects_status_index` chỉ mang tính nhất quán (DBA xác nhận LOW).
- Xác nhận lại AC6 phần "khóa học đã gán chuyên đề ẩn vẫn hiển thị đúng trên trang chi tiết" bằng luồng thật (T10 `GET /courses/{slug}`) khi QA giai đoạn — không nằm trong test T06 nhưng là điều kiện AC6.

---

## Vòng 2 (commit `7a481ef`, sau `f283de6`)

**Phạm vi vòng 2:** `git diff f283de6..7a481ef` — 7 file, +178/-29: `SubjectController.php`, `SubjectRequest.php`, `Subject.php`, `SubjectService.php`, `routes/admin.php`, `tests/Feature/T06/SubjectAdminTest.php`, `docs/reviews/review-T06.md` (thêm mục này).

Đã tự chạy lại trong Docker (không chỉ tin lời dev khai):
- `vendor/bin/pint --test` → sạch (175 file).
- `vendor/bin/phpstan analyse` → 0 lỗi.
- `vendor/bin/pest -c phpunit.t06.xml` → **264 passed (750 assertions)** — đúng +1 test so với vòng 1 (263→264), khớp báo cáo dev (thêm test R2).
- `vendor/bin/pest -c phpunit.t06.xml tests/Feature/T02/RouteMiddlewareGroupsTest.php` → 8 passed, kiến trúc route admin-api vẫn đủ `admin.origin + auth:sanctum + role` cho mọi route.
- `php artisan route:list --json` (mount worktree) đối chiếu trực tiếp middleware stack thật của cả 5 route `subjects*`: **POST/PUT/DELETE/PATCH đều có `Illuminate\Auth\Middleware\Authorize:<ability>,...` gắn đúng ability** (`create,App\Models\Subject`, `update,subject`, `delete,subject`, `updateStatus,subject`) — không route ghi nào bị mất kiểm quyền sau khi bỏ `$this->authorize()` trong controller. `GET /admin/subjects` (index) vẫn giữ `$this->authorize('viewAny', ...)` trong controller như cũ.
- Đọc `vendor/laravel/framework/.../Foundation/Http/Kernel.php::$middlewarePriority`: `SubstituteBindings::class` đứng ngay trước `Authorize::class` trong danh sách priority mặc định của framework, và dự án không override `middlewarePriority` ở `bootstrap/app.php`. Route `api` group (từ `Middleware::getMiddlewareGroups()`) đã có sẵn `SubstituteBindings`. Do đó khẳng định độc lập với giải thích của dev: bất kể thứ tự khai báo trong route (`role:...` rồi mới `can:...`), Laravel luôn thực thi `SubstituteBindings` (resolve `{subject}` thành model) trước `can:...` — đúng như dev khai, không phải suy đoán.
- Test mới `giao vien gui payload khong hop le van bi 403, khong phai 422 (R2)` là **integration test thật qua HTTP** (không mock), tự chạy pass — bằng chứng runtime mạnh hơn cả đọc source, xác nhận đúng hành vi mong muốn ở cả 4 case (tên rỗng, tên trùng, HTML, sửa trùng tên khác).

### R1 — ĐÃ SỬA, xác nhận đóng
`status` bị bỏ hoàn toàn khỏi `SubjectRequest::rules()`; `SubjectService::create()` không còn nhận `status` từ `$data`, luôn set `SubjectStatus::Active` (đúng AC1, đúng api-contract §2.5 chỉ có `name`). Đổi trạng thái chỉ còn 1 đường duy nhất: `PATCH /admin/subjects/{id}/status`. Không còn field ngoài hợp đồng, không còn nguy cơ 422 khó hiểu khi PUT. Đóng.

### R2 — ĐÃ SỬA, xác nhận đóng
Chuyển kiểm quyền từ `$this->authorize()` trong thân controller sang middleware `can:<ability>,<model|param>` gắn trực tiếp trên từng route ghi (`store`, `update`, `destroy`, `updateStatus`). Vì `Authorize::class` (implementation của `can:`) đứng sau `SubstituteBindings::class` trong middlewarePriority mặc định của Laravel, và cả hai đều được framework tự sắp lại thứ tự bất kể vị trí khai báo, nên **`can:` luôn chạy trước `FormRequest::rules()`** (FormRequest chỉ được resolve/validate khi vào tới controller action, sau khi toàn bộ middleware — kể cả `can:` — đã pass). Kết quả: Giáo viên gửi payload sai (tên rỗng/trùng/HTML) trên `POST`/`PUT` giờ nhận đúng **403**, không còn lộ 422 kèm chi tiết validate. `FormRequest::authorize()` vẫn giữ `true` đúng quy ước dự án (api-contract §1.3) — không phá quy ước, chỉ chuyển vị trí gọi Policy sang tầng route. Đã kiểm 404-vs-403 khi `{subject}` không tồn tại: vì `SubstituteBindings` cũng chạy trước `can:`, một ID không tồn tại sẽ bị `ModelNotFoundException` chặn lại thành 404 **trước khi** `can:` kịp chạy, bất kể vai trò gọi là gì (kể cả Giáo viên). Đây là hành vi tiêu chuẩn của Laravel với implicit route-model-binding (không phải điểm mới do dev tạo ra — round 1 cũng vậy vì controller cũng type-hint `Subject $subject`), và không rò rỉ thông tin nhạy cảm vì danh sách `subjects` vốn đã công khai cho mọi staff/GV qua `GET /admin/subjects` (không phải dữ liệu scope-theo-người-dùng như đơn hàng/enrollment) — hợp lý, không cần sửa thêm. Đóng.

### NIT — ĐÃ SỬA
- R3 (`Subject::scopeActive()`): `SubjectController::index()` đổi từ `where('status', SubjectStatus::Active)` sang `$query->active()`. Đóng.
- R4 (`per_page` ≤ 0): đổi thành `max(1, min((int) $request->integer('per_page', 25), 50))`. Đóng.
- R5 (`Subject::isActive()` chưa dùng): đã xoá khỏi `Subject.php`. Đóng.

### Phát hiện mới ở vòng 2
Không có. Diff vòng 2 chỉ chạm đúng 5 điểm đã nêu ở vòng 1, không mở rộng phạm vi, không chạm lại các phần đã APPROVE khác của T07-T10.

## Kết luận cuối

**PASS — APPROVE.** Cả 2 SHOULD (R1, R2) và cả 3 NIT (R3–R5) của vòng 1 đã được sửa đúng, xác nhận lại bằng đọc code + đọc source framework + chạy lại toàn bộ test thật (Pint sạch, Larastan 0 lỗi, Pest 264 passed) và `route:list --json` đối chiếu middleware thật trên từng route. Không có BLOCKER, không có SHOULD/NIT tồn đọng, không phát sinh phát hiện mới. Đủ điều kiện chuyển `laravel-security` rồi gộp vào nhánh chính theo quy trình T06 ở `docs/board.md`.

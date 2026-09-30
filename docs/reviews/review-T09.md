# REVIEW: T09 (US-009 - Chương/bài, [SEC])
**Kết luận:** PASS (0 BLOCKER, 3 SHOULD, 5 NIT). Đây là cổng bảo mật cuối của task (security review hoãn đến cuối dự án), nên 3 SHOULD dưới đây, đặc biệt R3, nên sửa trước khi gộp; không cái nào là lỗ hổng cho phép vượt quyền hay lộ dữ liệu.
**Phạm vi:** commit 822f365 (nhánh claude/zen-dirac-fmucf7-t09) so với claude/zen-dirac-fmucf7 · 18 file (+1801). Đã đọc toàn bộ file mới, `CoursePolicy`, `CourseService` (publish/delete, để đối chiếu khoá), migration `lessons`/`chapters`, AuditLogger, Nginx `client_max_body_size`. Pint/Larastan/Pest 762 pass: theo báo cáo của Dev và đã được người giao việc chạy lại, tôi không chạy lại.

## Tổng quan
Làm chắc tay. Controller mỏng, logic trong Service, một thứ tự khoá duy nhất course -> chapter -> lesson (`ContentLock`) dùng cho mọi thao tác ghi, nên create/delete/reorder/publish (publish ở `CourseService` cũng khoá `courses` trước) không thể chen nhau. Mọi route lồng đều `scopeBindings` + `can:manageContent,course` chạy trước FormRequest; `LessonRequest` chỉ đưa 5 trường vào `validated()`; `ExternalVideoLink` chặt hơn mức thường gặp và URL người nhập không bao giờ được lưu, trả về hay ghi audit. Điểm yếu nằm ở nghiệp vụ biên (R1, R2) và một điểm hiệu năng có thể bị lạm dụng (R3), không phải ở lớp phân quyền.

## Kiểm thử tự chạy (php 8.3 trong docker; script tạm ở thư mục scratchpad, chỉ đọc, không đụng DB, không ghi vào repo)
### `ExternalVideoLink::parse` với 50 payload
Bị loại đúng (null): host có dấu chấm cuối (`youtube.com.`), userinfo (`youtube.com@evil.com`, `evil.com@youtu.be`), port 443, `http`, mẹo fragment/query (`evil.com#@youtu.be/..`, `evil.com?@youtu.be/..`), backslash, `\n`/`\r\n`/`\0`/tab/NBSP (kể cả ở cuối chuỗi, `\z` không bị lừa bởi `\n` cuối), host Cyrillic/fullwidth dot (IDN homograph: whitelist so khớp chính xác theo byte nên không lọt), ID fullwidth/percent-encoded (`%51`), `%2F` thay dấu `/`, host percent-encoded (`you%74u.be`), `..` segment, subdomain/suffix/prefix giả (`evil.youtube.com`, `youtube.com.evil.io`, `evilyoutube.com`), `javascript:`, `data:`, IPv6, không host, `//youtu.be/..`, ID 12 ký tự, `v[]=`, `v.x=`, chuỗi > 2048, chữ số Unicode ở Vimeo (`\d` không có `/u` chỉ khớp ASCII), `/live/`, kênh Vimeo.
Được chấp nhận (đều vô hại vì embed URL được dựng lại từ ID): `HtTpS://`, `//` thừa, dấu `/` cuối, `v` lặp (lấy giá trị cuối, vẫn phải qua regex), fragment/query thừa, `https://youtu.be:/ID` (port rỗng, `parse_url` không trả `port`), Vimeo ID có số 0 đầu (`000000123`).
Kết luận: không tìm được đường bypass host whitelist hay chèn ký tự vào ID. `embedUrl()` kiểm lại regex nên dữ liệu DB bất thường cũng không dựng thành URL.

### Chi phí validate của `PUT curriculum/order`
Dựng Validator độc lập với đúng bộ rule của `CurriculumOrderRequest` (1 chương, N lesson_ids): N=500 0,10s; 2.000 0,20s; 5.000 0,69s; 10.000 2,15s (tăng bậc hai do `distinct` trên wildcard). Bỏ `distinct` còn tuyến tính nhưng vẫn ~2s cho 50.000 ID. Nginx cho body tới 5 MB (~700k ID) và `max_execution_time` CLI = 0 (FPM chưa xác nhận). Xem R3.

## Phát hiện
### R1 [SHOULD] Xoá từng bài lách chặn `CHAPTER_HAS_ACTIVE_LEARNERS`
- Vị trí: `app/Services/Curriculum/LessonService.php::delete()`, so với `ChapterService::delete()`
- Vấn đề: `ChapterService` trả 409 khi xoá chương còn bài mà khóa có enrollment `active`, và thông báo lỗi còn gợi ý "Hãy xoá/chuyển từng bài trước". `LessonService::delete()` không có kiểm tra tương tự, nên gọi DELETE từng bài rồi xoá chương rỗng cho cùng kết quả: học sinh đã mua mất bài. Quy tắc đang có chỉ chặn một đường đi cụ thể (UX), không bảo vệ được điều nó nhằm bảo vệ (BR4/US-009 "chặn nếu đã có học sinh đang học"). Giáo viên phụ trách (không có quyền unpublish/delete khóa) cũng làm được việc này.
- Đề xuất: PO chọn 1 trong 2 và áp dụng nhất quán:
  - (khuyến nghị, đúng tinh thần BR4) chặn xoá bài khi khóa có enrollment `active`, dùng chung một hàm với ChapterService (đặt vào `ContentLock` hoặc một `ContentGuard`), code `LESSON_HAS_ACTIVE_LEARNERS`, 409. Học sinh vẫn sắp xếp/đổi tên/chuyển bài được. Nếu sau này cần gỡ bài sai, làm thao tác riêng cho staff (có audit).
  - hoặc bỏ chặn ở chương và chấp nhận xoá mềm (giữ tiến độ), khi đó sửa story cho khớp.
  ~~~php
  // LessonService::delete(), trong transaction, sau khi khoá:
  $this->guard->assertNoActiveLearners($course); // dùng chung với ChapterService
  ~~~
  Thêm test: xoá bài khi có enrollment active -> 409; enrollment `rejected`/`revoked`/`pending_approval` -> vẫn xoá được.

### R2 [SHOULD] Xoá bài/chương cuối của khóa ĐÃ publish làm khóa rỗng nhưng vẫn "published"
- Vị trí: `LessonService::delete()`, `ChapterService::delete()`
- Vấn đề: BR3 chỉ được kiểm ở lúc publish (`CourseService::publish`, đã khoá đúng). Sau đó giáo viên phụ trách (không có quyền publish, BR2) hoặc admin xoá hết bài thì khóa vẫn hiển thị công khai và vẫn bán được, với outline rỗng. Học sinh trả tiền cho khóa không có gì. Vì `publish`/`delete` cùng khoá hàng `courses` nên chỉ cần thêm kiểm tra tại đây là đủ, không phát sinh race mới.
- Đề xuất:
  ~~~php
  // Trong transaction, sau ContentLock::course(...), trước khi xoá:
  if ($locked->status === CourseStatus::Published) {
      $remaining = Lesson::query()->where('course_id', $locked->getKey())
          ->whereNotIn('id', $idsBeingDeleted)->exists();
      if (! $remaining) {
          throw new DomainException(
              code: 'COURSE_PUBLISHED_NEEDS_CONTENT',
              message: 'Khóa học đang bán cần còn ít nhất 1 bài học. Hãy ngừng bán trước khi xoá bài cuối.',
              status: 409,
          );
      }
  }
  ~~~
  Chỉ cần đếm bài (còn bài thì chắc chắn còn chương). Không cần chặn khi khóa `draft`/`unpublished`. Với `unpublished` đang có enrollment thì R1 đã lo. Test: xoá bài cuối của khóa published -> 409; xoá chương chứa bài cuối -> 409; draft -> 204.

### R3 [SHOULD] `CurriculumOrderRequest` không giới hạn kích thước, `distinct` trên wildcard tốn bậc hai
- Vị trí: `app/Http/Requests/Admin/CurriculumOrderRequest.php::rules()`
- Vấn đề: GV/admin (kể cả tài khoản GV bị chiếm) gửi body ≤ 5 MB gồm hàng trăm nghìn ID: Laravel mở rộng wildcard và `distinct` so từng cặp, tốn CPU/RAM trong worker FPM trước khi tới `authorize` của service (đo ở trên: 10k ID = 2,15s, bậc hai). Vài request song song làm cạn worker của host admin-api. Kiểm tập ID ở service đã đủ để đảm bảo đúng/trùng (`sameSet`), nên `distinct` ở request là thừa.
- Đề xuất: cắt kích thước trong `validationData()` (chạy trước khi Validator mở rộng wildcard) và bỏ 2 rule `distinct`:
  ~~~php
  public function validationData(): array
  {
      $data = $this->isJson() ? $this->json()->all() : $this->all();

      if (! array_is_list($data) || count($data) > 200) {
          return ['__invalid' => true];
      }

      foreach ($data as $item) {
          if (is_array($item) && is_array($item['lesson_ids'] ?? null) && count($item['lesson_ids']) > 2000) {
              return ['__invalid' => true];
          }
      }

      return $data;
  }
  // rules(): bỏ 'distinct' ở '*.chapter_id' và '*.lesson_ids.*' (sameSet() đã bắt trùng)
  ~~~
  Con số 200/2000 để Dev/PO chỉnh theo quy mô khóa thực tế; điều quan trọng là có trần. Thêm test: 201 chương -> 422; 2.001 lesson_ids -> 422.

### R4 [NIT] 403/404 lộ "chương X có thuộc khóa A không" cho GV không phụ trách A
- Vị trí: `routes/admin.php` (binding `{chapter}`/`{lesson}` chạy trong `SubstituteBindings`, trước `can:`)
- Vấn đề: GV không phụ trách khóa A gọi `/courses/A/chapters/X`: X thuộc A -> 403, không thuộc -> 404. Chỉ lộ quan hệ ID-thuộc-khóa (ID tăng dần, dữ liệu vô hại), và 403/404 của `{course}` đã lộ sự tồn tại khóa ở toàn bộ route khóa học trước đó, nên không phải rủi ro thực. Ghi lại để biết chứ không cần sửa. Nếu muốn kín: đặt một middleware `can` trước binding con, không đáng công.

### R5 [NIT] `LessonResource` trả `video_asset_id`
- Vị trí: `app/Http/Resources/Admin/LessonResource.php:36`
- Vấn đề: Chỉ staff/GV phụ trách của khóa mới thấy nên không rò rỉ ra học sinh; nhưng FE không cần ID nội bộ này (trạng thái video lấy từ `GET .../video` ở T11) và về sau ID asset là tham số của webhook/playback. Tối thiểu hoá dữ liệu: thay bằng `has_video_asset` (bool). Các trường còn lại (`external_embed_url` dựng lại từ ID, không có URL người nhập, không có `external_video_id` thô, không có `deleted_at`) hợp lý.

### R6 [NIT] Vimeo unlisted mất tham số `h=`
- Vị trí: `ExternalVideoLink::parse()` (`vimeo.com/ID/HASH` bị loại; `player.vimeo.com/video/ID?h=HASH` được nhận nhưng `h` bị bỏ) và `embedUrl()`
- Vấn đề: Video Vimeo "unlisted" cần `h` mới phát được; admin dán link đúng nhưng embed sẽ báo riêng tư. Không phải lỗi bảo mật (đóng còn hơn mở). Nếu PO cần hỗ trợ: lưu thêm hash `[0-9a-f]{8,16}` trong cột riêng và dựng `?h=..&dnt=1`; nếu chưa, ghi vào tài liệu cho admin là chỉ dùng video công khai/"Anyone can embed", và thông báo lỗi 422 nên nói rõ.

### R7 [NIT] Tham số `$actor` không dùng trong 3 Service
- Vị trí: `ChapterService`, `LessonService` (create/update/delete)
- Vấn đề: chữ ký nhận `User $actor` nhưng audit lấy từ `Auth::user()` trong `AuditLogger`; tham số thừa gây hiểu nhầm là có kiểm quyền theo actor. Bỏ tham số hoặc truyền actor vào audit nếu về sau cần chạy ngoài HTTP (job/CLI). Cùng loại: docblock của `ExternalVideoLink` nói "không có ... fragment lạ" nhưng code không kiểm fragment (vô hại, chỉ sửa câu chữ).

### R8 [NIT] Test parser chưa khoá các payload đã thử ở trên
- Vị trí: `tests/Unit/ExternalVideoLinkTest.php`, `tests/Feature/T09/CurriculumTest.php` (dataset link ngoài)
- Vấn đề: dataset hiện tại tốt nhưng thiếu các ca tôi thấy có giá trị hồi quy: host dấu chấm cuối, host/ID percent-encoded, host Unicode (Cyrillic `о`, fullwidth dot), chữ số Unicode ở Vimeo, `\n` cuối chuỗi, mẹo `evil.com#@youtu.be/..`, `evil.com?@youtu.be/..`, `v` lặp. Thêm vào dataset `parse tu choi input nguy hiem` (dùng đúng chuỗi trong mục kiểm thử ở trên).

## Trả lời các giả định của Dev
Ghi chú: danh sách 7 giả định gốc của Dev không nằm trong repo/commit message mà tôi nhận được, nên tôi suy ra từ code + docblock. Nếu có điểm nào lệch với danh sách của Dev, báo lại để tôi trả lời cụ thể.

| # | Giả định (suy ra từ code) | Ý kiến |
|---|---|---|
| 1 | Xoá bài/chương cuối của khóa đã publish có cần chặn không | CÓ, nên chặn: xem R2. BR3 là bất biến của trạng thái đang bán chứ không chỉ điều kiện tại thời điểm bấm "Xuất bản"; giáo viên không có quyền publish nhưng lại xoá được toàn bộ nội dung nên rủi ro thực. Chỉ cần chặn khi khóa `published` và sẽ không còn bài nào. Code khoá hàng `courses` sẵn nên kiểm tra rất rẻ, không phát sinh race. |
| 2 | Xoá từng bài lách chặn xoá chương | ĐÚNG là lách được, xem R1. Nên áp cùng quy tắc "có enrollment `active` thì chặn" cho xoá bài (khuyến nghị) hoặc bỏ chặn chương cho nhất quán. Không nên giữ tình trạng hiện tại vì quy tắc bị bypass bằng thao tác hợp lệ chính là thứ dễ gây hiểu nhầm nhất. |
| 3 | `PUT` bài học là thay thế đầy đủ (mọi trường bắt buộc) | ĐỒNG Ý. Tránh trạng thái nửa vời (hạ `is_preview` mà vẫn giữ link ngoài). Ràng buộc S13 được kiểm hai lần (Request + `LessonService::applyVideo` ném `INVALID_EXTERNAL_LINK` 422 nếu ai gọi Service trực tiếp). Cần đảm bảo FE luôn gửi đủ trường; ghi rõ vào api-contract. |
| 4 | Link ngoài chỉ khi `is_preview = true` và chỉ lưu `provider` + `id`, URL dựng lại bằng `youtube-nocookie`/`dnt=1` | ĐỒNG Ý, khớp S13/tasks.md. Không tìm thấy nơi nào lưu/trả/ghi audit URL gốc (kiểm cả audit `lesson.create/update`: chỉ có title, is_preview, video_source, course_id, chapter_id, không có ID video, và test khẳng định). Lưu ý nghiệp vụ: GV phụ trách có thể bật `is_preview` cho bất kỳ bài nào (ADR-004 cho phép `manageContent`), tức bài đó thành công khai; nếu PO muốn chỉ staff được đổi `is_preview`, cần thêm quy tắc riêng (ngoài phạm vi T09, cân nhắc ghi vào backlog). |
| 5 | Bài `upload` giữ nguyên asset/thời lượng khi sửa, chuyển sang `none`/`external_link` thì đặt `video_asset_id = null` | ĐÚNG hướng (không bao giờ nhận `video_asset_id` từ request). Lưu ý cho T11: asset bị gỡ khỏi bài vẫn còn (file + bản ghi + `lesson_id`); T11/T30 phải xử lý asset mồ côi và chặn phiên upload đang dở hoàn tất vào bài đã đổi nguồn. Không sửa ở T09. |
| 6 | `position` luôn xếp cuối, chỉ đổi qua `curriculum/order`; không có unique index (chương/bài) | ĐỒNG Ý: migration chỉ có index thường `(chapter_id, position)` nên cập nhật từng dòng trong reorder không đụng unique ở giữa chừng; toàn bộ dưới khoá `courses` nên các bản ghi song song luôn tuần tự. `max('position')+1` an toàn vì cùng khoá. |
| 7 | Reorder kiểm tập ID dưới khoá (S5), 422 thông báo chung | ĐỒNG Ý. Kiểm tra dưới `FOR UPDATE` toàn bộ chapters/lessons chưa xoá của khóa, so `sameSet` không trùng/không thừa/không thiếu; lesson của khóa khác/đã xoá/không tồn tại/chương của khóa khác đều 422 và không đổi gì (test dataset 15 ca). Thông báo không nêu ID. Chỉ bổ sung trần kích thước (R3). |

## Đối chiếu tasks.md T09 và api-contract §2.5
| Yêu cầu | Code đáp ứng | Ghi chú |
|---|---|---|
| `scopeBindings` cho toàn bộ route lồng nhau | `Route::scopeBindings()->group` bao 7 route; test `moi route ... la scopeBindings, co can:manageContent, ten admin.` | Chương/bài khóa khác qua URL khóa mình -> 404; bài đã xoá mềm -> 404. Service kiểm lại quan hệ dưới khoá (`ContentLock`) để không tin binding cũ. |
| `can:manageContent,course` trên khóa gốc, trước FormRequest | Middleware `can:` sau `SubstituteBindings` theo priority mặc định; test GV không phụ trách nhận 403 với payload sai (không lộ 422); học sinh 403, khách 401 | Xem R4 (lộ nhẹ quan hệ chương-khóa, không cần sửa). |
| Curriculum order: tập ID phải trùng khớp (S5) | `CurriculumOrderService` + `sameSet`; khoá `courses`, `chapters`, `lessons` `FOR UPDATE` theo thứ tự cố định; request chỉ kiểm hình dạng; khoá lạ trong item bị `array:chapter_id,lesson_ids` từ chối | Cần trần kích thước (R3). Body rỗng chỉ hợp lệ với khóa chưa có chương. |
| `LessonRequest` không nhận `video_asset_id`/`course_id` | `validated()` chỉ có `title,is_preview,video_source,external_url,duration_seconds`; `Lesson::$fillable` không có video_*; Service là nơi duy nhất ghi video_source/external_* | Test gửi `video_asset_id` của khóa khác + `course_id`/`chapter_id`/`position`/`external_*` thô: bị bỏ qua. |
| Link ngoài chỉ khi `is_preview`, parse ID bằng regex (S13) | `ExternalVideoLink` + rule `ExternalVideoUrl` + kiểm `is_preview` trong `after()` và trong Service | Đã thử 50 payload, xem trên. Chỉ NIT R6/R8. |
| Test IDOR từng route | Có test cho chapter store/update/destroy, lesson store/update/destroy, order (payload khóa khác 422, GV khóa khác 403) | Đủ 7 route. |
| Audit không chứa URL/ID video | `LessonService::auditPayload` + test `not->toContain($id)` | Đạt. AuditLogger còn lọc key nhạy cảm. |
| US-009 AC8 (thứ tự cập nhật ngay) | Reorder trả cây mới; `position` sắp `orderBy(position)->orderBy(id)` | Đạt. Học sinh xem thứ tự ở T10/T13. |
| US-009 biên "xoá chương có bài" | Cascade xoá mềm, chặn 409 nếu có enrollment `active` | Đạt cho chương; xem R1/R2 cho bài. |
| US-009 AC11 (lưu đúng hình thức video) | Lưu được `none`/`upload`/`external_link`; phát video thuộc T11-T13 | Phần upload chưa gán asset (đúng phạm vi). |

## Gợi ý cho QA
- Bỏ qua R1/R2 nếu PO không đổi quy tắc: khi đó QA cần test đúng hành vi chốt (xoá bài cuối của khóa published, xoá từng bài rồi xoá chương của khóa có học sinh active).
- Song song 2 request: reorder vs create/delete lesson/chapter cùng khóa (không được deadlock, không được lệch tập ID, request thua nhận 422 hoặc 404 hợp lý); publish vs xoá bài cuối.
- Reorder với 200 chương/2.000 bài (mức biên sau R3) và đo thời gian; khóa chưa có chương gửi `[]`.
- Sửa bài `external_link` -> `upload`/`none` rồi kiểm cột `external_*`/`video_asset_id` đúng null, `duration_seconds` đúng quy tắc; hạ `is_preview` mà giữ `external_link` -> 422.
- GV phụ trách khóa A gọi từng route với ID của khóa B (chapter, lesson, payload reorder), và GV được gỡ khỏi `course_teacher` giữa chừng (request kế tiếp 403).
- Link ngoài: dán URL từ trình duyệt thật (có `&t=`, `?si=`, `?feature=share`, `m.youtube.com`, `youtube.com/embed`, Vimeo có `?h=` và dạng unlisted) để biết ca nào bị từ chối (R6).
- Tiêu đề chứa HTML/emoji/tiếng Việt có dấu, đúng 255 ký tự (byte vs ký tự), `is_preview` gửi `"0"`, `"false"`, `0`, `null`.

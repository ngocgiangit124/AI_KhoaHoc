# US-020: Hồ sơ giáo viên công khai (ảnh, giới thiệu) và khu vực giáo viên ở trang chủ

**Trạng thái:** Ready
**Ưu tiên:** Should

## User story
Là Giáo Viên, tôi muốn tự nhập ảnh và phần giới thiệu về mình và đồng ý công khai chúng, để học sinh và phụ huynh biết ai dạy các khóa của tôi.

Là Admin/Quản lý trang, tôi muốn hỗ trợ chỉnh hồ sơ giáo viên và chọn, sắp xếp những giáo viên hiện ở trang chủ, để trang chủ thể hiện đúng đội ngũ giảng dạy mà không vi phạm quyền riêng tư.

Là khách/học sinh, tôi muốn thấy ảnh và giới thiệu thầy cô ở trang chủ và trang khóa học để tin tưởng khóa học.

## Bối cảnh
- Tách từ yêu cầu của PO khi viết US-019 (trang chủ). US-019 chỉ chừa vị trí; khu vực giáo viên tự ẩn cho tới khi story này xong.
- Hiện trạng dữ liệu:
  - `users` đã có cột `bio` (text) và `avatar_path`. `GET /courses/{slug}` trả `teachers:[{id,name,bio,avatar_url}]`; `GET /courses` chỉ trả `teachers:[{id,name}]`. Backlog T10-3: `bio` là văn bản thuần.
  - **Không có API/màn nào ghi `bio`, `avatar_path`** (trong `backend/app` chỉ có đọc). `StaffAccount` và `POST /admin/staff` (US-016/T33) chỉ có `name, email, role`; `GET /admin/teachers` chỉ trả `id, name`. Do đó hiện mọi giáo viên có `avatar_url = null`, `bio = null`.
  - Ảnh và bio đang công khai ở trang chi tiết khóa mà chưa có ghi nhận giáo viên đồng ý.
  - `ImageUploadService` (T08) đã có: tối đa 2 MB, jpg/png/webp, tối đa 4000x4000, mã hóa lại WebP, bỏ EXIF, phục vụ từ `STATIC_URL` (api-contract §4). Tái dùng được.
- Trang chủ dùng API công khai có `Cache-Control: public, max-age=60` + `ETag` (như `/courses`), nhóm throttle `catalog`.

## Quyết định PO 2026-10-06
- Q1/Q2: chỉ hiển thị ở trang chủ giáo viên được Admin hoặc Quản lý trang bật "Hiển thị trên trang chủ", **đã đồng ý công khai**, **có ảnh**, **có bio** và **có khóa đang bán**. Tối đa 6 người, theo thứ tự Admin đặt.
- Q5a: giáo viên tự nhập hồ sơ ở "Hồ sơ của tôi" và tự tick đồng ý. Admin/QLT được sửa hộ nội dung, bật/tắt hiển thị trang chủ và đặt thứ tự, nhưng **không đồng ý thay** giáo viên.
- Q5b: quy tắc đồng ý áp dụng ở **mọi nơi**: giáo viên chưa đồng ý thì mọi API công khai (kể cả chi tiết khóa học) chỉ trả họ tên, không trả ảnh và bio.
- Q13: tách khỏi US-019; US-019 làm trước.

## Business rules
- BR1: **Hiển thị gì ở trang chủ (mỗi giáo viên)**: ảnh, họ tên, dòng chuyên môn ngắn `headline` (tùy chọn), các lớp đang dạy (suy ra từ `grade_level` các khóa `published` của giáo viên), số khóa đang bán (đếm khóa `published` mà giáo viên có trong `course_teacher`), `bio` rút gọn 3 dòng (văn bản thuần), và liên kết "Xem N khóa học" (đích theo Q6). Không có trường "thành tích" riêng (Q4); thành tích nếu có do giáo viên viết trong `bio`.
- BR2: **Điều kiện hiện ở trang chủ** (đồng thời): `role = giao_vien`, `status = active`; `public_profile_consent_at` có giá trị; `show_on_homepage = true`; có `avatar_path` và `bio` không rỗng; có ít nhất 1 khóa `published`. Thiếu một điều kiện thì không hiện, không báo lỗi cho khách.
- BR3: **Số lượng và thứ tự**: tối đa 6 người; xếp theo `homepage_order` tăng dần, người chưa có thứ tự xếp sau, cùng thứ tự thì theo `id` tăng dần. Khi đã có 6 giáo viên được bật, bật thêm người thứ 7 bị từ chối kèm thông báo rõ (không âm thầm cắt). Số 6 là hằng số cấu hình (không hard-code rải rác).
- BR4: **Đồng ý công khai**: chỉ chính giáo viên được đồng ý, bằng ô tick có nội dung rõ ("Tôi đồng ý công khai ảnh, họ tên và phần giới thiệu của tôi trên website VitaminVui"), lưu `public_profile_consent_at` (và phiên bản nội dung đồng ý). Admin/QLT không đồng ý thay. Giáo viên rút đồng ý bất kỳ lúc nào, hiệu lực ở mọi API công khai trong tối đa 60 giây (cache công khai; Q11).
- BR5: **Áp dụng mọi nơi**: `avatar_url` và `bio` của giáo viên chỉ có trong response công khai khi đã đồng ý (BR4); chưa đồng ý hoặc đã rút thì trả `null` (key vẫn có, tương thích hợp đồng cũ). Họ tên vẫn công khai như hiện nay. Áp cho `GET /courses/{slug}`, API trang chủ mới và mọi API công khai về sau. Kiểm ở tầng query/resource backend, không chỉ ẩn ở UI.
- BR6: **Ai sửa gì**:
  - Giáo viên: ảnh, `headline`, `bio`, đồng ý/rút đồng ý, chỉ của chính mình (người khác: 403).
  - Admin và Quản lý trang: sửa ảnh, `headline`, `bio` của mọi giáo viên; bật/tắt `show_on_homepage`; đặt `homepage_order`. Không đồng ý thay.
  - Khi Admin/QLT sửa nội dung giáo viên đã đồng ý, nội dung có hiệu lực ngay; "Hồ sơ của tôi" hiện "Chỉnh sửa gần nhất bởi {tên}, {thời điểm}" để giáo viên biết và rút đồng ý nếu không đồng ý (Q5).
  - Học sinh/khách: không có quyền.
- BR7: **Ảnh**: jpg/png/webp, tối đa 2 MB, tối đa 4000x4000 px, bước cắt khung vuông 1:1 ở UI khi tải lên; backend mã hóa lại WebP, cạnh dài tối đa 800px, bỏ EXIF; không nhận SVG/GIF/HTML. Tên tệp ngẫu nhiên, phục vụ từ `STATIC_URL`. Thay hoặc xóa ảnh thì ảnh cũ bị xóa khỏi kho. Alt: "Ảnh thầy/cô {họ tên}" (Q7).
- BR8: **Văn bản thuần**: `bio` tối đa 600 ký tự, `headline` tối đa 120 ký tự, giữ xuống dòng, không HTML; render bằng text của React (cấm `dangerouslySetInnerHTML`), backend lưu nguyên văn và có kiểm độ dài.
- BR9: Khi giáo viên bị khóa, đổi sang vai trò khác (T33-4), hoặc hết khóa `published`, giáo viên tự biến mất khỏi trang chủ nhưng giữ nguyên `show_on_homepage`, `homepage_order`, đồng ý; tự hiện lại khi đủ điều kiện. Khi tài khoản bị ẩn danh hóa (US-018): xóa ảnh, bio, `headline`, đồng ý, cờ trang chủ.
- BR10: **Không có giáo viên đủ điều kiện**: API trang chủ trả `data: []`; trang chủ ẩn hẳn khu vực giáo viên (không tiêu đề, không khung trống). API lỗi cũng ẩn khu vực, các khu vực khác không bị ảnh hưởng.
- BR11: Mọi thao tác sửa hồ sơ, bật/tắt trang chủ, đặt thứ tự, đồng ý, rút đồng ý ghi `audit_logs` (actor, giáo viên bị tác động, tên trường đổi; không ghi nội dung ảnh/bio đầy đủ nếu không cần).
- BR12: Hồ sơ công khai chỉ áp cho `role = giao_vien`. Admin/QLT không có hồ sơ công khai (Q12).
- BR13: API công khai chỉ trả trường cần thiết, không email/SĐT/trạng thái tài khoản/cờ nội bộ.

## Acceptance criteria
- AC1: Given giáo viên đăng nhập quản trị, When mở "Hồ sơ của tôi", Then thấy và sửa được ảnh, `headline`, `bio`, ô tick đồng ý công khai (có nội dung đồng ý), và trạng thái "Đang hiển thị/Chưa hiển thị trên trang chủ" kèm lý do còn thiếu.
- AC2: Given giáo viên tải ảnh jpg 1,5 MB và cắt khung vuông, When lưu, Then ảnh lưu thành WebP ≤ 800px, `avatar_url` cập nhật, ảnh cũ bị xóa khỏi kho.
- AC3: Given tệp `.svg`, `.gif`, `.html` đổi đuôi, polyglot, > 2 MB hoặc > 4000x4000, When tải lên, Then 422 với thông báo tiếng Việt cụ thể, không lưu gì.
- AC4: Given `bio` chứa `<script>` hoặc HTML, When xem ở trang chủ/chi tiết khóa, Then hiển thị đúng như chữ (không thực thi); `bio` > 600 ký tự hoặc `headline` > 120 ký tự → 422.
- AC5: Given giáo viên chưa tick đồng ý, When gọi `GET /courses/{slug}` của khóa có giáo viên đó, Then `teachers[]` có `id`, `name` và `avatar_url = null`, `bio = null`.
- AC6: Given giáo viên chưa đồng ý nhưng đã có ảnh/bio và được bật trang chủ, When gọi API trang chủ, Then không có giáo viên đó.
- AC7: Given giáo viên đã đồng ý rồi rút đồng ý, When đợi tối đa 60 giây rồi gọi lại các API công khai, Then ảnh/bio biến mất ở cả API trang chủ và chi tiết khóa; hồ sơ nội dung vẫn còn trong "Hồ sơ của tôi" (giáo viên đồng ý lại thì hiện lại).
- AC8: Given Admin/QLT mở hồ sơ một giáo viên, When sửa ảnh/bio/`headline` rồi lưu, Then lưu được; ô đồng ý hiển thị trạng thái của giáo viên nhưng bị khóa với chữ giải thích "Chỉ giáo viên được đồng ý công khai"; gọi thẳng API đồng ý thay người khác → 403.
- AC9: Given Admin/QLT bật `show_on_homepage` cho giáo viên chưa đủ điều kiện (chưa đồng ý/thiếu ảnh/thiếu bio/không có khóa đang bán), When lưu, Then lưu được và màn hiện nhãn "Chưa hiện: {lý do}" (không báo hiển thị giả).
- AC10: Given đã có 6 giáo viên được bật, When Admin bật người thứ 7, Then bị từ chối với thông báo "Trang chủ chỉ hiển thị tối đa 6 giáo viên. Hãy tắt bớt một người trước"; trạng thái cũ giữ nguyên.
- AC11: Given Admin đặt `homepage_order`, When gọi API trang chủ, Then thứ tự đúng theo `homepage_order`, người chưa đặt xếp sau, cùng thứ tự thì theo `id`.
- AC12: Given 3 giáo viên đủ điều kiện, When mở trang chủ, Then khu vực giáo viên hiện 3 thẻ gồm ảnh, họ tên, chuyên môn (nếu có), lớp dạy, số khóa, bio cắt 3 dòng, "Xem N khóa học".
- AC13: Given giáo viên đủ điều kiện nhưng khóa `published` duy nhất bị ngừng bán, When mở trang chủ, Then giáo viên không hiện; khi có khóa `published` trở lại, tự hiện lại mà không cần bật lại.
- AC14: Given giáo viên bị khóa tài khoản hoặc đổi vai trò, When mở trang chủ, Then không hiện.
- AC15: Given không giáo viên nào đủ điều kiện hoặc API lỗi, When mở trang chủ, Then khu vực giáo viên không xuất hiện, không khoảng trắng thừa, các khu vực khác bình thường.
- AC16: Given màn 375px, When xem khu vực giáo viên, Then xếp 1 cột (hoặc dải cuộn bằng tay, không tự chạy), ảnh vuông không méo, không cuộn ngang trang; từ 1024px xếp 3 cột.
- AC17: Given ảnh trên `STATIC_URL` lỗi tải, When hiển thị thẻ, Then hiện avatar chữ cái đầu của họ tên, không icon ảnh vỡ.
- AC18: Given giáo viên A, When gọi API sửa hồ sơ giáo viên B, Then 403 `FORBIDDEN`; khách/học sinh gọi API admin → 401/403.
- AC19: Given giáo viên mới tạo (US-016), When chưa nhập hồ sơ, Then không hiện ở trang chủ, API công khai trả `avatar_url = null`, `bio = null`.
- AC20: Given mọi thao tác ở BR11, When hoàn tất, Then có bản ghi `audit_logs` tương ứng (`teacher_profile.update`, `.consent`, `.consent_withdraw`, `.homepage_toggle`, `.homepage_order`).
- AC21: Given hai người (giáo viên và Admin) cùng sửa một hồ sơ, When lưu lần lượt, Then không mất trường mà người kia không đổi (chỉ gửi trường đã đổi) hoặc có báo xung đột theo `updated_at` (Dev chọn và ghi rõ).

## Trường hợp biên & lỗi
- Họ tên rất dài (150 ký tự): cắt 2 dòng, không vỡ thẻ.
- Ảnh đã tải nhưng lỗi mạng khi lưu hồ sơ: không để ảnh mồ côi vô hạn (dọn ảnh chưa gắn sau một khoảng thời gian).
- Chỉ 1 giáo viên: hiện 1 thẻ hợp lý, không lưới trống.
- Giáo viên đồng giảng nhiều khóa: số khóa đếm mỗi khóa `published` một lần.
- Giáo viên đổi tên/vai trò: hiển thị theo dữ liệu hiện tại sau tối đa 60 giây.
- Double submit bật/tắt/đặt thứ tự: không ghi audit trùng bất thường, không vượt quá 6 khi hai Admin bật cùng lúc (kiểm trong transaction/khóa).
- Hai giáo viên cùng `homepage_order`: tie-break theo `id`.
- Dữ liệu lớn: API trang chủ chỉ trả tối đa 6, có cache công khai + `ETag`, throttle nhóm `catalog`.
- Giáo viên bị xóa tài khoản/ẩn danh hóa (US-018): gỡ hồ sơ công khai ngay (BR9).

## Phân quyền
| Vai trò | Xem hồ sơ công khai | Sửa nội dung hồ sơ (ảnh, headline, bio) | Đồng ý/rút đồng ý | Bật/tắt trang chủ + thứ tự |
|---|---|---|---|---|
| Khách / Học Sinh | Có (chỉ phần đã đồng ý) | Không | Không | Không |
| Giáo Viên | Có | Chỉ của mình | Chỉ của mình | Không |
| Quản lý trang | Có | Mọi giáo viên | Không (chỉ xem trạng thái) | Có |
| Admin | Có | Mọi giáo viên | Không (chỉ xem trạng thái) | Có |

Xoá: không có thao tác xoá hồ sơ; giáo viên xóa ảnh hoặc rút đồng ý được.

## Ảnh hưởng dữ liệu
Dự kiến, Architect/DBA chốt (cột thêm vào `users` hoặc bảng `teacher_profiles` 1-1):
- Dùng lại `users.bio`, `users.avatar_path`.
- Thêm: `headline` string(120) nullable; `public_profile_consent_at` timestamp nullable (+ `public_profile_consent_version` string nullable); `show_on_homepage` boolean default false; `homepage_order` unsigned smallint nullable (index với `show_on_homepage`); (tùy chọn) `profile_updated_by`/`profile_updated_at` để hiện "Chỉnh sửa gần nhất bởi".
- API công khai mới (host `api`): `GET /home/teachers` (hoặc `/teachers?featured=home`) trả `{data:[{id,name,headline,bio,avatar_url,grade_levels:[int],courses_count}]}` tối đa 6; no cookie; `Cache-Control: public, max-age=60` + `ETag`; throttle `catalog`.
- Sửa `GET /courses/{slug}`: `teachers[].avatar_url`/`bio` = `null` khi chưa đồng ý (BR5).
- (Q6) Thêm tham số `teacher_id` cho `GET /courses` nếu chọn liên kết "Xem N khóa học" dẫn tới danh mục lọc.
- API admin (host `admin-api`): `GET/PUT /admin/me/teacher-profile` (+ tải/xóa ảnh, đồng ý/rút đồng ý); `GET/PUT /admin/teachers/{id}/profile` (Admin/QLT: ảnh, headline, bio); `PATCH` bật/tắt hiển thị + `homepage_order`; mở rộng `GET /admin/teachers` hoặc `StaffAccount` với cờ hồ sơ. Dùng `ImageUploadService` (profile ảnh vuông 800px) và `AuditLogger`.
- `audit_logs`: action mới `teacher_profile.update`, `.consent`, `.consent_withdraw`, `.homepage_toggle`, `.homepage_order`.
- Dọn ảnh mồ côi; ẩn danh hóa US-018 cần xóa các cột hồ sơ.

## Ngoài phạm vi
- Trang riêng từng giáo viên (`/giao-vien/{slug}`) và danh sách tất cả giáo viên.
- Trường "thành tích" có cấu trúc và xác minh thành tích; số năm kinh nghiệm (nếu PO muốn thì story sau).
- Đánh giá/lời chứng thực, video giới thiệu, mạng xã hội, nhắn tin/bình luận với giáo viên.
- Hồ sơ công khai cho Admin/QLT.
- Giáo viên đồng ý qua email thay vì trong trang quản trị.
- Cơ chế xóa cache tức thì (chấp nhận trễ 60 giây theo Q11).
- Thiết kế/triển khai khung trang chủ (thuộc US-019).

## Câu hỏi mở
Chưa được PO trả lời. **Nếu PO không phản hồi thì dùng phương án mặc định**, đủ để Architect bắt đầu (story Ready).
- [ ] Q3: Thẻ có cần trường nào ngoài BR1 (ví dụ số năm kinh nghiệm)? Mặc định: không thêm.
- [ ] Q4: Trường "thành tích" riêng? Mặc định: không; giáo viên viết trong `bio`. Lý do: lời khẳng định thành tích cần người chịu trách nhiệm tính đúng.
- [ ] Q6: Liên kết "Xem N khóa học" dẫn đâu? Mặc định: danh mục lọc theo giáo viên (thêm `teacher_id` cho `GET /courses`). Phương án rẻ hơn: bỏ liên kết, chỉ hiện "N khóa học".
- [ ] Q7: Quy cách ảnh. Mặc định: như BR7 (vuông 1:1, 2 MB, WebP 800px); chỉ hướng dẫn gợi ý (ảnh chân dung rõ mặt, nền gọn), không bắt buộc đồng phục/nền trắng.
- [ ] Q11: Độ trễ ẩn khi rút đồng ý. Mặc định: tối đa 60 giây theo cache công khai; cần tức thì thì phải thêm cơ chế xóa cache (tốn thêm công).
- [ ] Q12: Hồ sơ công khai của Admin/QLT (nếu họ cũng dạy). Mặc định: không, chỉ `giao_vien`.
- [ ] Nội dung chữ đồng ý công khai (câu chữ chính xác, có cần pháp chế duyệt): mặc định dùng câu ở BR4, ghi phiên bản; pháp chế (V2) rà sau, không chặn.

## Ghi chú cho Designer / Dev / QA
- Architect: cập nhật `api-contract.md` (API công khai mới, sửa `GET /courses/{slug}`, API admin hồ sơ), `data-model.md` (cột hoặc bảng), chốt `teacher_profiles` hay cột `users`, chốt tên task. Ảnh hưởng FA10 (T33): hiển thị cờ hồ sơ nếu dùng chung màn.
- Designer: (1) khu vực giáo viên ở trang chủ (375/1280px; trạng thái 1 người, nhiều người, ẩn; avatar chữ cái dự phòng; không carousel tự chạy; dải cuộn tay trên mobile nếu chọn); (2) màn "Hồ sơ của tôi" cho giáo viên (tải ảnh + cắt vuông, `headline`, `bio` có đếm ký tự, ô đồng ý, trạng thái hiển thị + lý do còn thiếu); (3) màn Admin/QLT: sửa hộ hồ sơ, bật/tắt trang chủ, đặt thứ tự, nhãn "Chưa hiện: lý do", giới hạn 6; mọi trạng thái bị khóa kèm chữ giải thích (không tooltip).
- Dev: ảnh qua `ImageUploadService`; điều kiện BR2/BR5 ở query/resource (không tin UI); kiểm "tối đa 6" trong transaction; render text thuần; cache công khai theo mẫu `/courses`; không trả email/SĐT. Task [SEC] (upload ảnh, lộ thông tin khi chưa đồng ý, IDOR hồ sơ).
- QA: ma trận BR2 (thiếu từng điều kiện thì không hiện), rút đồng ý rồi gọi cả API trang chủ lẫn `/courses/{slug}`, giới hạn 6 và race hai Admin cùng bật, thứ tự, upload độc hại/polyglot, IDOR giáo viên A sửa B, khóa/đổi vai trò/ngừng bán, 375px.
- Thứ tự: Architect cập nhật hợp đồng → Designer → Dev backend rồi frontend (admin + khu vực trang chủ). Frontend đang tạm dừng chờ design (board 2026-10-05).

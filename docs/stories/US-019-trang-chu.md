# US-019: Trang chủ web học sinh

**Trạng thái:** Ready
**Ưu tiên:** Must

## User story
Là khách hoặc học sinh vào `vitaminvui.vn`, tôi muốn thấy ngay website dạy gì, chọn được lớp của mình và xem các khóa nổi bật, để bấm vào đúng khóa học cần.

## Bối cảnh
- Trang chủ (`/`) chưa có story trước đây. Designer đã dựng bản xem trước `/v2` bằng dữ liệu mẫu (`frontend/apps/web/app/(v2-preview)/v2/page.tsx`, bố cục ở `docs/design/design-system-v2.md`, câu hỏi 6 mục 18). Bố cục: hero, chọn lớp 6–12, khóa nổi bật, "Một buổi học trên VitaminVui" (3 bước), "Dành cho phụ huynh".
- Khóa nổi bật dùng `GET /courses?sort=featured` (thứ tự `manual_order`, US-002 BR6). Thanh toán đang khóa (`paid_checkout_enabled=false`): khóa có phí hiện giá + "Sắp mở bán" (design §12.2).
- PO yêu cầu thêm khu vực giới thiệu giáo viên (có ảnh). Phần này tách sang **US-020 (Hồ sơ giáo viên công khai)** vì cần trường dữ liệu mới, API mới, màn nhập liệu và đồng ý công khai của giáo viên. US-019 chỉ chừa vị trí; khu vực giáo viên **tự ẩn** cho tới khi US-020 xong và có dữ liệu.
- Hiện không có chỗ nào nhập `users.avatar_path`/`bio` (xem US-020).

## Quyết định PO 2026-10-06
- Tách story: US-019 chỉ gồm trang chủ và được làm trước; khu vực giáo viên phụ thuộc US-020 và tự ẩn khi chưa có dữ liệu (Q13).
- Các quyết định về giáo viên (ai được hiển thị, tối đa 6, thứ tự Admin đặt, đồng ý công khai áp dụng mọi nơi) ghi ở US-020.
- Bổ sung khối "poster người sáng lập" vào trang chủ (PO 2026-10-06), gồm 3 quyết định:
  1. Nội dung cố định: PO gửi ảnh và câu chữ, đội frontend đặt thẳng vào code (asset tĩnh). Không backend, không màn quản trị, không API. Muốn đổi thì nhờ dev sửa.
  2. Vị trí: khối riêng, nằm ngay sau khu vực "Khóa học nổi bật"; giữ nguyên hero hiện tại.
  3. Không liên quan hồ sơ giáo viên (US-020): không lấy dữ liệu từ `teacher_profiles`, không áp quy tắc consent giáo viên. Chỉ cần người sáng lập đồng ý cho dùng ảnh/tên (ghi nhận ngoài hệ thống, PO chịu trách nhiệm).

## Quyết định PO 2026-10-07
- Poster người sáng lập: CHƯA có ảnh, họ tên, câu thật của người sáng lập. PO cho **tạm hiển thị ở trang chủ thật** một nội dung tạm (thay quy tắc cũ "chưa có nội dung thì ẩn khối") tới khi PO gửi nội dung thật:
  - Ảnh minh hoạ tự vẽ (giáo viên cách điệu bên bảng ô ly với lời giải Toán), không phải ảnh người thật, không giống người thật cụ thể: `frontend/apps/web/public/trang-chu/nguoi-sang-lap-minh-hoa.svg`.
  - Chủ thể chung, không bịa danh tính: "Đội ngũ sáng lập VitaminVui" / "Những người làm VitaminVui"; câu "Toán dễ hiểu hơn khi được giảng chậm, rõ từng bước. Chúng tôi làm VitaminVui để đi cùng các em từ lớp 6 đến lớp 12."; nút "Xem khóa học" → `/khoa-hoc`.
  - Một file hằng số duy nhất `frontend/apps/web/lib/home/founder.ts` (`founderPoster`); thay nội dung thật chỉ cần sửa file này + ảnh. Chi tiết: `docs/design/design-system-v2.md` §12.7.
  - Q15 vẫn mở cho nội dung thật; không chặn FW8 nữa.

## Business rules
- BR1: Trang chủ công khai, không cần đăng nhập, có SEO (title, description, đúng một `h1`); nội dung chính render phía server để máy tìm kiếm đọc được. Khách và học sinh đã đăng nhập thấy cùng nội dung; học sinh đã đăng nhập thêm "Khóa học của tôi" ở header (theo design), không có khu vực riêng.
- BR2: Dữ liệu khóa học trên trang chủ chỉ lấy từ API công khai (bản chạy thật không dùng dữ liệu mẫu). Khóa `draft`/`unpublished`/đã xóa không bao giờ xuất hiện.
- BR3: Khóa nổi bật: tối đa 4 khóa `published` theo `sort=featured` (khóa chưa đặt `manual_order` xếp sau theo quy tắc của API). Không có khóa nào: ẩn khu vực và hiện dòng "Khóa học sẽ sớm được cập nhật" kèm liên kết `/khoa-hoc`.
- BR4: Khi thanh toán tạm khóa, thẻ khóa có phí hiện giá + "Sắp mở bán", không có nút mua/giỏ hàng; khóa miễn phí hiện "Miễn phí".
- BR5: Mọi lời khẳng định trên trang chỉ được đưa vào khi đúng với hệ thống thật. Không dùng số liệu bịa (số học sinh, đánh giá, thời lượng bài, thành tích). Câu chữ cuối cùng do PO duyệt (Q9).
- BR6: Không hiện nút "Học thử một bài" gắn cứng slug. Chỉ hiện khi đường dẫn lấy từ dữ liệu thật (cần `has_preview` ở danh sách, chưa có); mặc định MVP: không có nút này (Q8).
- BR7: Vị trí khu vực giáo viên: sau "Khóa học nổi bật" (Q14); khi khối poster (BR10) cũng hiển thị thì khu vực giáo viên đứng sau poster (mặc định, xem Q16). Khu vực này chỉ render khi API giáo viên (US-020) trả ít nhất 1 người; rỗng, lỗi hay API chưa tồn tại thì không render gì và không để khoảng trắng thừa giữa các khu vực còn lại.
- BR8: Lỗi một khu vực không làm hỏng khu vực khác; khu vực dữ liệu (khóa nổi bật) lỗi thì hiện thông báo và nút "Tải lại" tại chỗ.
- BR9: Mobile (375px): không cuộn ngang, vùng bấm tối thiểu 44x44px, chữ nội dung tối thiểu 16px.
- BR10: Khối "poster người sáng lập" nằm ngay sau "Khóa học nổi bật", là khối riêng, không thay đổi hero.
  - Nội dung gồm: ảnh khổ lớn của người sáng lập; họ tên; vai trò (vd. "Người sáng lập"); một câu trích/thông điệp ngắn, tối đa 200 ký tự (đề xuất, PO xác nhận ở Q15); tuỳ chọn một nút dẫn tới `/khoa-hoc` (có hay không do PO chốt ở Q15).
  - Nội dung là hằng số trong code cùng asset tĩnh của frontend; không đọc từ API, DB, `teacher_profiles` hay `config/public`.
  - Khi chưa có nội dung thật, được hiển thị **nội dung tạm do PO duyệt** (PO 2026-10-07): ảnh minh hoạ tự vẽ (không phải ảnh người thật, không ảnh stock), chủ thể chung "Đội ngũ sáng lập VitaminVui", câu không số liệu/không hứa kết quả. Không dùng ảnh có khung "ảnh mẫu"/vùng an toàn, ảnh người thật lấy từ Internet hay chữ giữ chỗ kiểu "(tên mẫu)" ở production.
  - Hằng số nội dung đặt `null`, hoặc thiếu bất kỳ mục bắt buộc nào (ảnh, họ tên/chủ thể, vai trò, câu thông điệp) → coi là chưa cấu hình và KHÔNG render khối.
  - Chỉ đưa vào lời khẳng định đúng sự thật và do PO duyệt (BR5); không kèm số liệu bịa (số học sinh, thành tích, danh hiệu) nếu PO không cung cấp.
  - Không áp quy tắc consent giáo viên của US-020. Việc người sáng lập đồng ý cho dùng ảnh/tên do PO chịu trách nhiệm, ghi nhận ngoài hệ thống.
  - Khối không phụ thuộc API nên không bị ảnh hưởng khi API khóa học lỗi (BR8).

## Acceptance criteria
- AC1: Given khách chưa đăng nhập và khối poster đã có nội dung, When mở `/`, Then thấy theo thứ tự: hero, chọn lớp, khóa nổi bật, poster người sáng lập, một buổi học, dành cho phụ huynh (khu vực giáo viên của US-020, nếu hiện, nằm sau poster); khi poster chưa có nội dung thì thứ tự như trước, không có poster; không yêu cầu đăng nhập.
- AC2: Given trang chủ, When xem HTML ban đầu (chưa chạy JS), Then có `<title>`, `meta description`, đúng một `h1`, và danh sách khóa nổi bật đã nằm trong HTML.
- AC3: Given có ít nhất 4 khóa `published`, When mở trang chủ, Then "Khóa học nổi bật" hiện đúng 4 khóa đầu theo `sort=featured`, mỗi thẻ dẫn tới `/khoa-hoc/{slug}`; "Xem tất cả khóa học" dẫn tới danh mục sắp theo `featured`.
- AC4: Given có 1–3 khóa `published`, When mở trang chủ, Then hiện đúng số khóa đó, không thẻ trống hay dữ liệu mẫu.
- AC5: Given không có khóa `published`, When mở trang chủ, Then khu vực khóa nổi bật hiện "Khóa học sẽ sớm được cập nhật" và liên kết `/khoa-hoc`; các khu vực khác vẫn hiện.
- AC6: Given khóa vừa bị ngừng bán hoặc xóa, When mở trang chủ sau tối đa 60 giây (cache công khai), Then khóa đó không còn xuất hiện.
- AC7: Given khách bấm ô "Lớp 9", When điều hướng, Then vào danh mục đã lọc `grade=9` (đúng 7 ô, lớp 6–12).
- AC8: Given `paid_checkout_enabled=false`, When xem thẻ khóa có phí, Then hiện giá và "Sắp mở bán", không có nút mua hay giỏ hàng.
- AC9: Given API khóa học lỗi hoặc quá chậm, When mở trang chủ, Then hero, chọn lớp, poster người sáng lập (nếu đã có nội dung), một buổi học, phụ huynh vẫn hiện; khu vực khóa nổi bật hiện thông báo lỗi + nút "Tải lại", không trang trắng/500.
- AC10: Given màn hình 375px, When cuộn trang, Then không có cuộn ngang, vùng bấm tối thiểu 44x44px, chữ nội dung tối thiểu 16px.
- AC11: Given US-020 chưa có dữ liệu giáo viên (hoặc API giáo viên lỗi), When mở trang chủ, Then không có tiêu đề, khung trống hay dữ liệu mẫu của khu vực giáo viên và không có khoảng trắng bất thường.
- AC12: Given trang chủ ở trạng thái mặc định (Q8–Q10), When xem nội dung, Then không có nút "Học thử" gắn cứng, không có con số "10–25 phút", không có câu "phụ huynh nhận email xác nhận".
- AC13: Given khối poster đã có đủ nội dung, When mở `/`, Then ngay sau khu vực "Khóa học nổi bật" có khối poster hiện ảnh khổ lớn, họ tên, vai trò và câu thông điệp đúng như PO cung cấp (không sai chính tả, không bị cắt giữa câu); hero không đổi; nếu có nút thì nút dẫn tới `/khoa-hoc`, nếu PO không chọn nút thì không có nút.
- AC14: Given khối poster hiển thị, When kiểm tra thẻ ảnh, Then ảnh có `alt` mô tả người trong ảnh (vd. "Ảnh chân dung {họ tên}, người sáng lập VitaminVui"), không để `alt` rỗng và không lặp nguyên văn câu thông điệp.
- AC15: Given khối poster nằm dưới màn hình đầu tiên, When tải trang, Then ảnh poster tải lười (`loading="lazy"` hoặc tương đương), không có `priority`/preload, không nằm trong phần tử LCP của trang (LCP vẫn là hero) và không chặn việc hiển thị hero.
- AC16: Given ảnh poster chưa tải xong, When trang đang tải và khi ảnh hiện ra, Then khung ảnh đã được giữ chỗ đúng tỉ lệ (`width`/`height` hoặc `aspect-ratio` khai báo), ảnh không bị méo (không kéo giãn khác tỉ lệ gốc, dùng `object-fit` phù hợp), và không làm nhảy bố cục (CLS do khối này gây ra ≤ 0,1; mục tiêu 0).
- AC17: Given màn hình 375px, When cuộn tới khối poster, Then không có cuộn ngang, chữ nội dung ≥ 16px, nút (nếu có) có vùng bấm ≥ 44x44px, và mọi chữ đều đọc được: tỉ lệ tương phản chữ/nền ≥ 4,5:1 (chữ thường) hoặc ≥ 3:1 (chữ lớn) theo WCAG AA, kể cả khi chữ đè lên ảnh (dùng lớp phủ hoặc đặt chữ trên nền đặc).
- AC18: Given hằng số nội dung poster là `null` hoặc thiếu ảnh, họ tên/chủ thể, vai trò hoặc câu thông điệp, When mở `/`, Then khối hoàn toàn không render: không tiêu đề, không khung trống, không ảnh mẫu, không khoảng trắng/padding/margin thừa giữa "Khóa học nổi bật" và khu vực kế tiếp. Nội dung tạm do PO duyệt 2026-10-07 (ảnh minh hoạ + "Đội ngũ sáng lập VitaminVui") được tính là đã cấu hình và hiển thị bình thường (AC13: "đúng như PO cung cấp" áp cho nội dung tạm này; AC14: `alt` mô tả hình minh hoạ).
- AC19: Given tệp ảnh poster tải lỗi (404, hỏng), When mở `/`, Then khối không để ảnh vỡ hay khung trống: ẩn cả khối, hoặc hiện nền đặc cùng chữ vẫn đọc được (Designer chốt một cách; không dùng ảnh mẫu), và không gây lỗi cho các khu vực khác.

## Trường hợp biên & lỗi
- Khóa nổi bật có `thumbnail_url` hỏng: dùng bìa dựng sẵn (design §9).
- Tên khóa/mô tả rất dài: cắt dòng theo `CourseCard`, không vỡ lưới.
- Học sinh đã đăng nhập mở `/`: không bị chuyển hướng; vẫn xem được trang chủ.
- API chậm: có trạng thái tải đúng khung (skeleton), không giật layout.
- Dữ liệu lớn: chỉ lấy 4 khóa nên không ảnh hưởng; dùng cache công khai/`ETag` của `/courses`.
- Cờ thanh toán đổi giữa chừng: hiển thị theo `config/public` mới nhất (tối đa 60 giây trễ).
- Poster: câu thông điệp dài hơn giới hạn (BR10) hoặc họ tên rất dài: không được đưa vào code; có test Vitest chặn nội dung vượt giới hạn, và bố cục không vỡ ở 375px.
- Poster: ảnh quá nặng làm chậm trang: ảnh xuất ra đúng kích thước hiển thị (nhiều độ rộng cho màn nhỏ/lớn, định dạng WebP/JPG), dung lượng ảnh phục vụ mobile nên ≤ 200 KB (đề xuất, Designer/Dev chốt).
- Poster: ảnh ngang/dọc khác tỉ lệ dự kiến: khung cố định tỉ lệ, cắt bằng `object-fit: cover` với `object-position` chọn sao cho không cắt mất mặt người.
- Học sinh đã đăng nhập: thấy cùng khối poster như khách.

## Phân quyền
| Vai trò | Xem | Tạo | Sửa | Xoá |
|---|---|---|---|---|
| Khách | Có | - | - | - |
| Học Sinh | Có | - | - | - |
| Giáo Viên / Quản lý trang / Admin | Có | - | - | - |

Nội dung trang chủ không có màn quản trị; khóa nổi bật do `manual_order` ở quản trị khóa học (US-009). Khối poster là nội dung cố định trong code, không ai (kể cả admin) sửa được qua giao diện; đổi nội dung thì nhờ dev.

## Ảnh hưởng dữ liệu
- Không bảng/migration mới. Dùng `GET /courses?sort=featured`, `GET /config/public`.
- Khối poster: không có ảnh hưởng dữ liệu. Không bảng, không migration, không API, không `config/public`; chỉ là asset tĩnh và hằng số trong `frontend/apps/web`.
- Tùy chọn sau (không chặn US-019): thêm `has_preview` vào item `GET /courses` (Q8), cờ phụ huynh vào `config/public` (Q10).

## Ngoài phạm vi
- Khu vực giáo viên (thuộc US-020; US-019 chỉ chừa vị trí).
- Đánh giá/lời chứng thực, số học sinh, huy hiệu, video giới thiệu.
- Carousel tự chạy; hiệu ứng khi cuộn (design v2 đã loại).
- Cá nhân hóa hero, banner khuyến mãi, mã giảm giá trên trang chủ.
- Blog/tin tức, FAQ, liên hệ, form nhận tư vấn.
- Số đếm khóa theo lớp trên ô chọn lớp (API chưa có).
- Dark mode (quyết định riêng của PO, design §13).
- Trang `/dieu-khoan`, `/chinh-sach-du-lieu` (V2); không hiện liên kết chết ở footer.
- Quản trị poster (màn nhập liệu, upload ảnh, API); nhiều poster, slider/carousel; video giới thiệu người sáng lập.
- Lấy dữ liệu poster từ hồ sơ giáo viên (US-020) hoặc áp consent giáo viên cho poster.
- Trang riêng giới thiệu người sáng lập, liên kết mạng xã hội, lời chứng thực.

## Câu hỏi mở
Nếu PO không phản hồi thì dùng phương án mặc định bên dưới (đủ để US-019 chuyển Ready).
- [ ] Q8: Nút "Học thử một bài" ở hero. Mặc định: bỏ nút, hero chỉ có "Xem khóa học"; thêm lại khi API có `has_preview` (Architect thêm vào item `GET /courses`, thay đổi tương thích).
- [ ] Q9: Câu chữ quảng bá ("Mỗi bài 10–25 phút", "bám sát sách giáo khoa", "Nhiều khóa có học thử miễn phí"). Mặc định: dùng câu trung tính không khẳng định số liệu ("Video bài giảng theo từng bài, trắc nghiệm ngay sau mỗi bài, tiến độ được lưu để học tiếp đúng chỗ"); bỏ "10–25 phút" và "học thử miễn phí". PO gửi câu chữ cuối nếu muốn khác.
- [ ] Q10: Ý "phụ huynh nhận email xác nhận đồng ý" (cờ `FEATURE_PARENT_CONSENT_ENFORCED` đang tắt, `config/public` chưa có khóa này). Mặc định: ẩn ý này, giữ hai ý còn lại ("Một tài khoản, một thiết bị", "Tiến độ rõ ràng"); bật lại khi cờ bật.
- [ ] Q14: Vị trí và tiêu đề khu vực giáo viên. Mặc định: sau "Khóa học nổi bật" (và sau poster nếu poster hiển thị, xem Q16), tiêu đề "Thầy cô giảng dạy".

Câu hỏi mở cho khối poster người sáng lập — nội dung THẬT (từ PO 2026-10-07 khối hiển thị nội dung tạm trong lúc chờ, không còn chặn FW8):
- [ ] Q15a: Họ tên đầy đủ của người sáng lập, đúng cách viết hoa/dấu.
- [ ] Q15b: Vai trò hiển thị. Đề xuất "Người sáng lập"; có kèm "VitaminVui" hay chức danh khác không.
- [ ] Q15c: Câu thông điệp chính xác (tối đa 200 ký tự, đề xuất) và có ghi tên dưới câu trích hay không.
- [ ] Q15d: File ảnh gốc, chưa nén quá tay. Khuyến nghị: cạnh dài ≥ 1600px (tốt nhất 2000px), JPG hoặc WebP, không logo/chữ chèn sẵn trong ảnh, vùng khuôn mặt không nằm sát mép (để cắt khung không mất mặt). Cho biết ảnh ngang hay dọc.
- [ ] Q15e: Có cần nút dẫn tới `/khoa-hoc` không và chữ trên nút. Đề xuất mặc định: có, "Xem khóa học".
- [ ] Q15f: Xác nhận người sáng lập đã đồng ý bằng văn bản/tin nhắn cho dùng ảnh, tên và câu thông điệp công khai (PO chịu trách nhiệm, ghi ngoài hệ thống).
- [ ] Q16: Thứ tự giữa poster và khu vực giáo viên (US-020) khi cả hai cùng hiện. Cả hai đều được PO đặt "ngay sau Khóa học nổi bật". Mặc định: poster đứng trước, khu vực giáo viên đứng sau poster.

## Ghi chú cho Designer / Dev / QA
- Designer: cập nhật `/v2`: bỏ nút "Học thử" gắn cứng slug, sửa câu chữ theo Q9/Q10, chừa vị trí khu vực giáo viên (thiết kế khu vực này ở US-020).
  - Poster (BR10): thiết kế khối khổ lớn ngay sau "Khóa học nổi bật" ở 375px, 768px, 1280px: bố cục ảnh + chữ (chữ cạnh ảnh hoặc chữ trên nền đặc/lớp phủ bảo đảm tương phản AA), tỉ lệ khung ảnh cố định, vị trí cắt ảnh (`object-position`), trạng thái ảnh lỗi (AC19), kiểu chữ trích dẫn, kiểu nút nếu có. Cập nhật `docs/design/design-system-v2.md`. Dùng ảnh mẫu chỉ trong bản `/v2` xem trước, ghi rõ là mẫu; không đưa vào production.
- Dev: trang `/` thật thay cho `/v2`; nối `GET /courses?sort=featured&per_page` (lấy 4 đầu), SSR với CSP nonce như FW2, xử lý rỗng/lỗi từng khu vực. Chỉ gắn khu vực giáo viên khi US-020 xong.
  - Poster (BR10): hằng số nội dung (tên, vai trò, câu, `alt`, đường dẫn ảnh, có nút hay không) ở một file duy nhất: `frontend/apps/web/lib/home/founder.ts` (`founderPoster`, đã có nội dung tạm PO 2026-10-07); ảnh tĩnh trong `frontend/apps/web/public/` hoặc import để `next/image` tự tối ưu (nhiều độ rộng, WebP), kiểm tra tương thích CSP và `next.config.ts`. Khi hằng số chưa đủ giá trị thì component trả `null` (không wrapper, không padding/margin). Khai báo `width`/`height` hoặc `aspect-ratio`, `loading="lazy"`, không `priority`. Component là server component, không gọi API, đặt ngoài vùng bọc dữ liệu khóa học để lỗi/tải lại của khóa nổi bật không ảnh hưởng. Không dùng ảnh mẫu có khung vùng an toàn (`public/v2/mau/`) ở trang thật; ảnh minh hoạ tạm `public/trang-chu/nguoi-sang-lap-minh-hoa.svg` được phép (PO 2026-10-07). Test Vitest: rỗng → `null`; giới hạn độ dài câu.
- QA: SSR/SEO, khóa ngừng bán biến mất, lỗi API từng khu vực, 375px không cuộn ngang, trạng thái 0/1–3/4+ khóa, `paid_checkout_enabled=false`.
  - Poster: AC13–AC19. Kiểm khi chưa cấu hình (khối biến mất, không khoảng trắng), thứ tự trong HTML, `alt`, `loading="lazy"`, CLS (Lighthouse/Playwright trace) và LCP vẫn là hero, 375/768/1280px không cuộn ngang, tương phản AA bằng công cụ, tắt/chặn API khóa học vẫn thấy poster, chặn tệp ảnh (404) không vỡ bố cục, kiểm trong build production (không có ảnh khung vùng an toàn hay chữ giữ chỗ kiểu "(tên mẫu)"; nội dung tạm PO 2026-10-07 là hợp lệ).
- Phụ thuộc: không phụ thuộc US-020; làm trước. Frontend đang tạm dừng chờ design (board 2026-10-05): chỉ giao khi PO duyệt design v2.

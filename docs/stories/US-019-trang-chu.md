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

## Business rules
- BR1: Trang chủ công khai, không cần đăng nhập, có SEO (title, description, đúng một `h1`); nội dung chính render phía server để máy tìm kiếm đọc được. Khách và học sinh đã đăng nhập thấy cùng nội dung; học sinh đã đăng nhập thêm "Khóa học của tôi" ở header (theo design), không có khu vực riêng.
- BR2: Dữ liệu khóa học trên trang chủ chỉ lấy từ API công khai (bản chạy thật không dùng dữ liệu mẫu). Khóa `draft`/`unpublished`/đã xóa không bao giờ xuất hiện.
- BR3: Khóa nổi bật: tối đa 4 khóa `published` theo `sort=featured` (khóa chưa đặt `manual_order` xếp sau theo quy tắc của API). Không có khóa nào: ẩn khu vực và hiện dòng "Khóa học sẽ sớm được cập nhật" kèm liên kết `/khoa-hoc`.
- BR4: Khi thanh toán tạm khóa, thẻ khóa có phí hiện giá + "Sắp mở bán", không có nút mua/giỏ hàng; khóa miễn phí hiện "Miễn phí".
- BR5: Mọi lời khẳng định trên trang chỉ được đưa vào khi đúng với hệ thống thật. Không dùng số liệu bịa (số học sinh, đánh giá, thời lượng bài, thành tích). Câu chữ cuối cùng do PO duyệt (Q9).
- BR6: Không hiện nút "Học thử một bài" gắn cứng slug. Chỉ hiện khi đường dẫn lấy từ dữ liệu thật (cần `has_preview` ở danh sách, chưa có); mặc định MVP: không có nút này (Q8).
- BR7: Vị trí khu vực giáo viên: ngay sau "Khóa học nổi bật" (Q14). Khu vực này chỉ render khi API giáo viên (US-020) trả ít nhất 1 người; rỗng, lỗi hay API chưa tồn tại thì không render gì và không để khoảng trắng thừa giữa các khu vực còn lại.
- BR8: Lỗi một khu vực không làm hỏng khu vực khác; khu vực dữ liệu (khóa nổi bật) lỗi thì hiện thông báo và nút "Tải lại" tại chỗ.
- BR9: Mobile (375px): không cuộn ngang, vùng bấm tối thiểu 44x44px, chữ nội dung tối thiểu 16px.

## Acceptance criteria
- AC1: Given khách chưa đăng nhập, When mở `/`, Then thấy theo thứ tự: hero, chọn lớp, khóa nổi bật, một buổi học, dành cho phụ huynh; không yêu cầu đăng nhập.
- AC2: Given trang chủ, When xem HTML ban đầu (chưa chạy JS), Then có `<title>`, `meta description`, đúng một `h1`, và danh sách khóa nổi bật đã nằm trong HTML.
- AC3: Given có ít nhất 4 khóa `published`, When mở trang chủ, Then "Khóa học nổi bật" hiện đúng 4 khóa đầu theo `sort=featured`, mỗi thẻ dẫn tới `/khoa-hoc/{slug}`; "Xem tất cả khóa học" dẫn tới danh mục sắp theo `featured`.
- AC4: Given có 1–3 khóa `published`, When mở trang chủ, Then hiện đúng số khóa đó, không thẻ trống hay dữ liệu mẫu.
- AC5: Given không có khóa `published`, When mở trang chủ, Then khu vực khóa nổi bật hiện "Khóa học sẽ sớm được cập nhật" và liên kết `/khoa-hoc`; các khu vực khác vẫn hiện.
- AC6: Given khóa vừa bị ngừng bán hoặc xóa, When mở trang chủ sau tối đa 60 giây (cache công khai), Then khóa đó không còn xuất hiện.
- AC7: Given khách bấm ô "Lớp 9", When điều hướng, Then vào danh mục đã lọc `grade=9` (đúng 7 ô, lớp 6–12).
- AC8: Given `paid_checkout_enabled=false`, When xem thẻ khóa có phí, Then hiện giá và "Sắp mở bán", không có nút mua hay giỏ hàng.
- AC9: Given API khóa học lỗi hoặc quá chậm, When mở trang chủ, Then hero, chọn lớp, một buổi học, phụ huynh vẫn hiện; khu vực khóa nổi bật hiện thông báo lỗi + nút "Tải lại", không trang trắng/500.
- AC10: Given màn hình 375px, When cuộn trang, Then không có cuộn ngang, vùng bấm tối thiểu 44x44px, chữ nội dung tối thiểu 16px.
- AC11: Given US-020 chưa có dữ liệu giáo viên (hoặc API giáo viên lỗi), When mở trang chủ, Then không có tiêu đề, khung trống hay dữ liệu mẫu của khu vực giáo viên và không có khoảng trắng bất thường.
- AC12: Given trang chủ ở trạng thái mặc định (Q8–Q10), When xem nội dung, Then không có nút "Học thử" gắn cứng, không có con số "10–25 phút", không có câu "phụ huynh nhận email xác nhận".

## Trường hợp biên & lỗi
- Khóa nổi bật có `thumbnail_url` hỏng: dùng bìa dựng sẵn (design §9).
- Tên khóa/mô tả rất dài: cắt dòng theo `CourseCard`, không vỡ lưới.
- Học sinh đã đăng nhập mở `/`: không bị chuyển hướng; vẫn xem được trang chủ.
- API chậm: có trạng thái tải đúng khung (skeleton), không giật layout.
- Dữ liệu lớn: chỉ lấy 4 khóa nên không ảnh hưởng; dùng cache công khai/`ETag` của `/courses`.
- Cờ thanh toán đổi giữa chừng: hiển thị theo `config/public` mới nhất (tối đa 60 giây trễ).

## Phân quyền
| Vai trò | Xem | Tạo | Sửa | Xoá |
|---|---|---|---|---|
| Khách | Có | - | - | - |
| Học Sinh | Có | - | - | - |
| Giáo Viên / Quản lý trang / Admin | Có | - | - | - |

Nội dung trang chủ không có màn quản trị; khóa nổi bật do `manual_order` ở quản trị khóa học (US-009).

## Ảnh hưởng dữ liệu
- Không bảng/migration mới. Dùng `GET /courses?sort=featured`, `GET /config/public`.
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

## Câu hỏi mở
Nếu PO không phản hồi thì dùng phương án mặc định bên dưới (đủ để US-019 chuyển Ready).
- [ ] Q8: Nút "Học thử một bài" ở hero. Mặc định: bỏ nút, hero chỉ có "Xem khóa học"; thêm lại khi API có `has_preview` (Architect thêm vào item `GET /courses`, thay đổi tương thích).
- [ ] Q9: Câu chữ quảng bá ("Mỗi bài 10–25 phút", "bám sát sách giáo khoa", "Nhiều khóa có học thử miễn phí"). Mặc định: dùng câu trung tính không khẳng định số liệu ("Video bài giảng theo từng bài, trắc nghiệm ngay sau mỗi bài, tiến độ được lưu để học tiếp đúng chỗ"); bỏ "10–25 phút" và "học thử miễn phí". PO gửi câu chữ cuối nếu muốn khác.
- [ ] Q10: Ý "phụ huynh nhận email xác nhận đồng ý" (cờ `FEATURE_PARENT_CONSENT_ENFORCED` đang tắt, `config/public` chưa có khóa này). Mặc định: ẩn ý này, giữ hai ý còn lại ("Một tài khoản, một thiết bị", "Tiến độ rõ ràng"); bật lại khi cờ bật.
- [ ] Q14: Vị trí và tiêu đề khu vực giáo viên. Mặc định: ngay sau "Khóa học nổi bật", tiêu đề "Thầy cô giảng dạy".

## Ghi chú cho Designer / Dev / QA
- Designer: cập nhật `/v2`: bỏ nút "Học thử" gắn cứng slug, sửa câu chữ theo Q9/Q10, chừa vị trí khu vực giáo viên (thiết kế khu vực này ở US-020).
- Dev: trang `/` thật thay cho `/v2`; nối `GET /courses?sort=featured&per_page` (lấy 4 đầu), SSR với CSP nonce như FW2, xử lý rỗng/lỗi từng khu vực. Chỉ gắn khu vực giáo viên khi US-020 xong.
- QA: SSR/SEO, khóa ngừng bán biến mất, lỗi API từng khu vực, 375px không cuộn ngang, trạng thái 0/1–3/4+ khóa, `paid_checkout_enabled=false`.
- Phụ thuộc: không phụ thuộc US-020; làm trước. Frontend đang tạm dừng chờ design (board 2026-10-05): chỉ giao khi PO duyệt design v2.

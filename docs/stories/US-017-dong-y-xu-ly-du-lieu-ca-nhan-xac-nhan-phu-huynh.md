# US-017: Đồng ý xử lý dữ liệu cá nhân và xác nhận của phụ huynh cho học sinh dưới 18 tuổi

**Trạng thái:** Draft
**Ưu tiên:** Must

> **Thay đổi bởi PO 2026-10-08 (ADR-006):** KHÔNG còn yêu cầu phụ huynh đồng ý. BR3–BR10, AC3–AC9 và phần trang xác nhận phụ huynh không còn hiệu lực. Thông tin phụ huynh là tuỳ chọn; hệ thống chỉ gửi **thông báo** tới email phụ huynh (nếu có), và phụ huynh có link huỷ nhận. BR1, BR2, AC1, AC2 (2 checkbox đồng ý của học sinh) giữ nguyên. Hợp đồng mới: `docs/adr/ADR-006-bo-dong-y-phu-huynh-chi-thong-bao.md`, api-contract §2.8, tasks.md T29. BA cần viết lại story theo ADR này.

## User story
Là học sinh, tôi muốn được thông báo rõ ràng và chủ động đồng ý với việc hệ thống xử lý dữ liệu cá nhân của mình trước khi sử dụng dịch vụ; là phụ huynh của học sinh dưới 18 tuổi, tôi muốn được xác nhận trước khi con em mình có thể mua khóa học, để quyền riêng tư của gia đình được tôn trọng.

## Bối cảnh
Phát sinh từ review bảo mật `docs/security/audit-2026-09-25.md` (phát hiện S7 — High, tuân thủ): thiết kế trước đó (US-001 BR8) chỉ thu thập SĐT/email phụ huynh làm thông tin liên hệ, **chưa có** bước đồng ý, chưa xác minh phụ huynh, chưa lưu bằng chứng đồng ý, chưa có cơ chế rút lại đồng ý. Đa số học sinh của VitaminVui (11–15 tuổi) là trẻ em theo pháp luật Việt Nam; hệ thống còn thu thập thông tin liên hệ của phụ huynh (bên thứ ba). Tương ứng task T29, đã có khung thiết kế sẵn ở `docs/architecture/data-model.md` §3.1 (bảng `consents`, cột `users.parent_consent_status`) và `docs/architecture/api-contract.md` (`GET/POST /parent-consents/{token}`, `POST /me/parent-consent/resend`).

**Quan trọng:** nội dung pháp lý cụ thể của story này (căn cứ pháp luật, ngưỡng tuổi chính xác, hình thức đồng ý hợp lệ, nghĩa vụ khi thu thập dữ liệu phụ huynh, chuyển dữ liệu ra nước ngoài) **cần bộ phận pháp chế xác nhận**, đặc biệt trong bối cảnh Luật Bảo vệ dữ liệu cá nhân 2025 và Nghị định hướng dẫn có hiệu lực từ 01/01/2026. Story này chỉ mô tả **cơ chế kỹ thuật** đã thiết kế sẵn theo mặc định an toàn; nội dung/ngưỡng cụ thể chờ pháp chế và PO chốt trước go-live.

## Business rules
- BR1: Mọi học sinh khi đăng ký (US-001) phải tick **riêng từng checkbox** đồng ý (không được tick sẵn) với: (a) Điều khoản sử dụng, (b) Chính sách xử lý dữ liệu cá nhân. Thiếu 1 trong 2 → chặn tạo tài khoản.
- BR2: Mỗi lần đồng ý được ghi thành 1 bản ghi trong bảng `consents`, gắn với `policy_version` đang hiển thị tại thời điểm đó, `granted_by=self`, `channel=web_form`, IP, user-agent, thời điểm — tạo trong cùng transaction với việc tạo tài khoản.
- BR3 (Mặc định an toàn — chờ PO/pháp chế xác nhận): Ngưỡng tuổi cần xác nhận của phụ huynh là **dưới 18 tuổi** tại thời điểm đăng ký (cấu hình `privacy.parent_consent_age`, mặc định `18`).
- BR4: Học sinh dưới ngưỡng tuổi bắt buộc đã khai ≥ 1 liên hệ phụ huynh (SĐT hoặc email) khi đăng ký (US-001 BR8). Ngay sau khi tạo tài khoản, nếu có email phụ huynh, hệ thống gửi email chứa liên kết xác nhận tới địa chỉ đó.
- BR5: `parent_consent_status` của tài khoản chuyển `pending` ngay khi tạo (nếu dưới ngưỡng tuổi) → `granted` khi phụ huynh xác nhận qua liên kết; giữ nguyên `pending` nếu chưa có phản hồi. Học sinh đủ tuổi (≥ ngưỡng) có `parent_consent_status = not_required` ngay từ đầu.
- BR6 (Mặc định an toàn — chờ PO/pháp chế xác nhận): Trong khi `parent_consent_status ∈ {pending, revoked}`, học sinh **bị chặn** thanh toán (checkout, US-005) và đăng ký khóa học miễn phí (US-012); vẫn được đăng nhập, duyệt danh mục, xem bài học xem trước bình thường.
- BR7: Liên kết xác nhận gửi cho phụ huynh có chữ ký (signed), **dùng 1 lần**, hết hạn sau **72 giờ**; hết hạn thì phải yêu cầu gửi lại (BR8).
- BR8: Học sinh có thể chủ động yêu cầu gửi lại email xác nhận cho phụ huynh, giới hạn tối đa **3 lần/ngày** (`POST /me/parent-consent/resend`); mỗi lần gửi lại vô hiệu hóa liên kết cũ.
- BR9: Phụ huynh có thể **rút lại đồng ý** đã cấp trước đó qua kênh xác nhận tương ứng; khi rút lại, `parent_consent_status` chuyển `revoked`, ghi `revoked_at` vào bản ghi `consents`, và học sinh bị chặn checkout/đăng ký miễn phí trở lại (áp dụng BR6) kể từ thời điểm đó.
- BR10: Trang xác nhận công khai (`GET /parent-consents/{token}`) chỉ hiển thị thông tin **tối thiểu**: tên học sinh đã che bớt một phần, nội dung/liên kết tới chính sách đang áp dụng — không hiển thị các PII khác (ngày sinh, SĐT, lớp học...) để hạn chế rủi ro nếu liên kết bị lộ.
- BR11: Nội dung pháp lý cụ thể (căn cứ pháp luật viện dẫn, mẫu văn bản đồng ý, ngôn ngữ bắt buộc, thời hạn lưu bằng chứng đồng ý) **cần pháp chế xác nhận** trước khi go-live — chưa tự soạn thảo ở story này.

## Acceptance criteria
- AC1: Given khách đang đăng ký tài khoản mới (US-001), When submit form mà chưa tick đủ 2 checkbox đồng ý (điều khoản + chính sách dữ liệu), Then hệ thống báo lỗi và không tạo tài khoản.
- AC2: Given khách tick đủ 2 checkbox và đăng ký thành công, Then hệ thống tạo 2 bản ghi `consents` (`type=terms`, `type=privacy_policy`) đúng `policy_version` hiện hành, `granted_by=self`, kèm IP và thời điểm.
- AC3: Given học sinh dưới ngưỡng tuổi cấu hình (mặc định 18) đăng ký với email phụ huynh hợp lệ, When tài khoản được tạo, Then `parent_consent_status = pending` và hệ thống gửi email chứa liên kết xác nhận (hiệu lực 72 giờ) tới email phụ huynh.
- AC4: Given phụ huynh mở liên kết xác nhận còn hiệu lực và bấm "Đồng ý", When xác nhận, Then `parent_consent_status` chuyển `granted`, tạo bản ghi `consents` (`type=parent_consent`, `granted_by=parent`), và học sinh checkout/đăng ký khóa học miễn phí được ngay sau đó.
- AC5: Given liên kết xác nhận đã hết hạn (> 72 giờ) hoặc đã được dùng, When phụ huynh mở lại, Then hệ thống báo liên kết không còn hiệu lực, không cho xác nhận lại bằng liên kết cũ.
- AC6: Given học sinh dưới ngưỡng tuổi đang ở `parent_consent_status = pending`, When học sinh cố vào trang thanh toán hoặc đăng ký khóa học miễn phí, Then hệ thống chặn và hiển thị thông báo "Đang chờ phụ huynh xác nhận", kèm nút gửi lại email xác nhận.
- AC7: Given học sinh đã gửi email xác nhận nhưng chưa nhận được phản hồi (chưa vượt 3 lần/ngày), When học sinh bấm "Gửi lại email xác nhận", Then hệ thống gửi email mới (vô hiệu hóa liên kết cũ) và báo thành công.
- AC8: Given học sinh đã gửi lại email xác nhận đủ 3 lần trong ngày, When bấm gửi lại lần tiếp theo, Then hệ thống từ chối và báo rõ đã đạt giới hạn, gợi ý thử lại vào ngày hôm sau.
- AC9: Given phụ huynh đã từng đồng ý (`granted`), When phụ huynh rút lại đồng ý qua kênh được hệ thống hỗ trợ, Then `parent_consent_status` chuyển `revoked`, ghi cập nhật vào `consents`, và học sinh bị chặn checkout/đăng ký miễn phí kể từ đó (như AC6).
- AC10: Given học sinh đủ tuổi (≥ ngưỡng cấu hình) tại thời điểm đăng ký, When tài khoản được tạo, Then `parent_consent_status = not_required` ngay từ đầu, không có bước chờ phụ huynh, học sinh checkout bình thường sau khi xác thực OTP tài khoản (US-001).

## Trường hợp biên & lỗi
- Phụ huynh chỉ khai số điện thoại (không có email) lúc đăng ký → cơ chế gửi liên kết hiện tại chỉ qua email; xử lý trường hợp này là câu hỏi mở.
- Học sinh khai sai ngày sinh để né bước xác nhận phụ huynh → rủi ro đã ghi nhận ở US-001 (không có cách xác minh tuyệt đối ở MVP), chấp nhận là hạn chế đã biết.
- Phụ huynh bấm "Từ chối" trên trang xác nhận (nếu có nút này) → hành vi hệ thống cụ thể (giữ `pending`, hay chuyển trạng thái riêng) là câu hỏi mở.
- Học sinh chuyển từ dưới ngưỡng tuổi sang đủ tuổi theo thời gian (sinh nhật) sau khi tài khoản đã ở `pending` → có tự động chuyển `not_required` không là câu hỏi mở.
- Nhiều học sinh (ví dụ anh chị em) dùng chung 1 email phụ huynh → mỗi học sinh có liên kết xác nhận riêng gắn với chính tài khoản đó; phụ huynh phải xác nhận từng học sinh.
- Liên kết xác nhận bị chuyển tiếp nhầm cho người khác → giảm thiểu rủi ro nhờ chỉ hiển thị thông tin tối thiểu (BR10) và liên kết dùng 1 lần.
- Email phụ huynh bị nhập sai chính tả lúc đăng ký → phụ huynh không bao giờ nhận được, học sinh mắc kẹt ở `pending`; cần cơ chế cho học sinh sửa lại thông tin liên hệ phụ huynh (có thể thuộc US-018 "xem/sửa hồ sơ" — câu hỏi mở, hiện chưa có story cho việc sửa hồ sơ).
- Bị lạm dụng nút "gửi lại email" để làm phiền một địa chỉ email bất kỳ → đã giới hạn 3 lần/ngày (BR8); cân nhắc thêm captcha nếu phát hiện lạm dụng (đề xuất, chưa bắt buộc ở AC).

## Phân quyền
| Vai trò | Tick đồng ý khi đăng ký | Xem trạng thái đồng ý của chính mình | Xác nhận/rút lại qua liên kết (thay mặt phụ huynh) | Xem đồng ý của học sinh khác |
|---|---|---|---|---|
| Khách (đang đăng ký) | Có (bắt buộc) | – | – | – |
| Học Sinh | Có (khi đăng ký) | Có | Không | Không |
| Phụ huynh (không có tài khoản) | – | – | Có, chỉ qua liên kết riêng gắn đúng 1 học sinh | Không |
| Giáo Viên | – | – | – | Không |
| Quản lý trang | – | – | – | Có, qua màn chi tiết học sinh (story quản trị người dùng khác, có audit) |
| Admin | – | – | – | Có, qua màn chi tiết học sinh (story quản trị người dùng khác, có audit) |

## Ảnh hưởng dữ liệu
- Bảng `consents` (đã thiết kế sẵn trong `data-model.md` §3.1): `user_id`, `type`, `policy_version`, `granted_by`, `channel`, `destination_masked`, `granted_at`, `revoked_at`, `ip`, `user_agent`.
- Cột `users.parent_consent_status` (`not_required`/`pending`/`granted`/`revoked`) đã dự kiến sẵn.
- Mail `ParentConsentMail` (implement `ShouldBeEncrypted` vì chứa PII) gửi liên kết ký (signed, dùng 1 lần, 72 giờ).
- Ghi `audit_logs` cho hành động `consent.revoke`; cần Dev/Architect bổ sung action tương ứng cho "phụ huynh xác nhận đồng ý" nếu cần theo dõi (hiện `data-model.md` chỉ liệt kê `consent.revoke`).
- Middleware `parent.consent` (đã có khung ở `EnsureParentConsent`) chặn ở checkout và đăng ký khóa học miễn phí khi `parent_consent_status ∈ {pending, revoked}`.

## Ngoài phạm vi
- Tài khoản/vai trò riêng cho Phụ huynh (đã xác nhận ở US-001 là **không làm** ở MVP — phụ huynh không đăng nhập hệ thống).
- Xác minh danh tính thực sự của phụ huynh (ví dụ đối chiếu giấy tờ tùy thân) — MVP chỉ xác nhận qua liên kết email, không xác minh danh tính.
- Soạn thảo nội dung pháp lý chi tiết của Điều khoản sử dụng/Chính sách xử lý dữ liệu cá nhân (do pháp chế phụ trách).
- Quyền xem/sửa/xuất/xóa dữ liệu cá nhân của học sinh (thuộc US-018).
- Đồng ý cho mục đích tiếp thị (`type=marketing`) — nếu về sau có nhu cầu gửi marketing, tách story riêng.

## Câu hỏi mở
- [ ] Ngưỡng tuổi chính xác cần phụ huynh xác nhận là bao nhiêu (mặc định đang áp dụng: dưới 18) — **cần pháp chế xác nhận** theo Luật Bảo vệ dữ liệu cá nhân 2025/Nghị định hướng dẫn hiện hành.
- [ ] Cơ sở pháp lý và nghĩa vụ cụ thể khi thu thập SĐT/email phụ huynh (dữ liệu của bên thứ ba) — **cần pháp chế xác nhận**.
- [ ] Hình thức "đồng ý" hợp lệ theo pháp luật là gì (bấm liên kết email có đủ hay cần hình thức khác) — **cần pháp chế xác nhận**.
- [ ] Trường hợp phụ huynh chỉ khai số điện thoại (không có email) thì xác nhận qua kênh nào (MVP hiện chỉ có kênh email cho liên kết xác nhận, do hạ tầng SMS chưa có nhà cung cấp thật)?
- [ ] Khi phụ huynh từ chối xác nhận, hệ thống nên xử lý ra sao (giữ `pending` mãi, hay có trạng thái từ chối riêng, có cho học sinh cập nhật lại thông tin phụ huynh khác không)?
- [ ] Học sinh đủ 18 tuổi sau khi tài khoản đã ở trạng thái `pending` có tự động chuyển `not_required` không?
- [ ] Phụ huynh rút lại đồng ý (`revoked`) có ảnh hưởng tới các khóa học đã mua/đang `active` trước đó không (thu hồi enrollment như hoàn tiền ở US-010, hay giữ nguyên)?
- [ ] Có cần gửi thông báo cho học sinh khi phụ huynh xác nhận hoặc từ chối không?

## Ghi chú cho Designer / Dev / QA
- Designer: bổ sung 2 checkbox đồng ý tách riêng (không tick sẵn) vào màn đăng ký (US-001); trạng thái/màn "Đang chờ phụ huynh xác nhận" chặn nút Thanh toán/Đăng ký miễn phí kèm nút "Gửi lại email cho phụ huynh"; trang công khai xác nhận của phụ huynh (không cần đăng nhập) chỉ hiển thị thông tin tối thiểu theo BR10; hiển thị trạng thái đồng ý trong hồ sơ tài khoản học sinh.
- Dev: dùng cơ chế liên kết ký (signed route, không phải OTP 6 số) cho phụ huynh, hiệu lực 72 giờ, dùng 1 lần; middleware `parent.consent` chặn ở checkout/đăng ký miễn phí (có thể tạm cho qua với `not_required`/`granted`, đã có khung ở T18); `ParentConsentMail` phải implement `ShouldBeEncrypted`; mọi thay đổi `parent_consent_status` đi qua `ConsentService`/`ParentConsentService`, không update trực tiếp model.
- QA: kiểm thử tài khoản dưới ngưỡng tuổi bị chặn checkout khi `pending`; kiểm thử liên kết hết hạn/dùng lại; kiểm thử giới hạn gửi lại 3 lần/ngày; kiểm thử tài khoản đủ tuổi không bị chặn; kiểm thử rút lại đồng ý chặn được giao dịch mới (nhưng không tự ý đổi trạng thái các enrollment cũ, trừ khi PO xác nhận khác — xem câu hỏi mở).

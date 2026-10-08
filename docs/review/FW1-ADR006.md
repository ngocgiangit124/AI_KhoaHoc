# FW1 — ADR-006: bỏ đồng ý phụ huynh (apps/web)

## Dev (nextjs-dev, 2026-10-08)

Căn cứ: ADR-006, ghi chú FW1 trong `docs/architecture/tasks.md`, api-contract §1.6/§2.1/§2.8.1.

### Đã làm
- `RegisterForm` (`components/auth`): khối phụ huynh "không bắt buộc" ở mọi tuổi. Tự mở khi tuổi < ngưỡng gợi ý (`parent_contact_suggest_age`, rơi về `parent_consent_age`); đủ tuổi thì thu gọn, có nút "Thêm thông tin phụ huynh (không bắt buộc)"; dưới ngưỡng có nút "Bỏ qua". Câu giải thích: phụ huynh nhận thư thông báo (sau khi học sinh xác thực email, và khi có đơn hàng), không cần làm gì thêm, có thể huỷ nhận; phải khác email/SĐT của học sinh. Lỗi 422 `parent_email`/`parent_phone` hiện đúng ô (và ép mở khối).
- `schemas.ts`: bỏ kiểm "ít nhất 1 trong 2" và mọi bắt buộc theo tuổi (chỉ kiểm định dạng khi có nhập; option `parentConsentAge` bị bỏ). `api.ts#buildRegisterPayload`: gửi `parent_*` khi có nhập (mọi tuổi), ô trống thì bỏ key; bỏ `forceParent`.
- Gỡ `AccountBanner` nhánh phụ huynh, `AccountGateRoute` (chỉ còn `/can-xac-thuc`), `AccountGate` (chỉ còn `verify`), `CourseCtaProvider`, `cta.ts` (mã cũ `PARENT_CONSENT_REQUIRED` rơi vào thông điệp 403 chung, không có màn chờ). Xoá route `/cho-phu-huynh` và preview `/v2/cho-phu-huynh`, `routes.parentPending` (2 nơi), mục trong `/v2/muc-luc`, biến thể preview `chan=phu-huynh`. Preview `v2/auth/RegisterForm` cũng đã sửa chữ và bỏ bắt buộc.
- Zod: `authUserSchema` thêm `parent_contact` (nullable/optional), `needs_policy_acceptance` (optional); `parent_consent_status` thành `string` optional (không rẽ nhánh UI, mã lạ/thiếu không làm vỡ). `publicConfigSchema` thêm `parent_contact_suggest_age` (optional), `parent_contact_required` (default false), `parent_consent_age` default 18.
- Không làm: quyền dữ liệu cá nhân, banner chấp nhận lại chính sách, trang huỷ nhận thông báo (FW7).

### Kiểm tra
- `tsc --noEmit`: sạch (đã loại file generated `.next/*/validator.ts` còn tham chiếu 2 trang đã xoá; xem lưu ý).
- ESLint `app components lib e2e`: 0 lỗi.
- Vitest toàn bộ `apps/web`: 418 test, đạt (đã thêm/sửa test cho schema, payload, parse user/config, RegisterForm, AccountBanner, CourseCta, cta).
- `next build` với `NEXT_DIST_DIR=.next-fw1adr6`: thành công (thư mục đã xoá).
- Lưu ý: `.next/types/validator.ts` và `.next/dev/types/validator.ts` (sinh tự động, dùng chung) còn tham chiếu `cho-phu-huynh` làm `tsc`/build lỗi. Đã xoá đúng các khối đó (dev server sẽ tự sinh lại tương đương).

### e2e
- Đã sửa `e2e/auth.spec.ts` (AC10 chia 4 ca), `e2e/fw1-v2-qa.spec.ts` (`/cho-phu-huynh` -> 404), `e2e/mock-api-server.mjs` (fixture config mới). `e2e/seed-e2e-auth.sh` không cần đổi (không phụ thuộc phụ huynh).
- CHƯA chạy: lúc kiểm tra `:8000` không phản hồi và không có dev server `:3000`.
- Chạy lại sau khi T29 lên DB dev (`E2E_T29=1`): AC10c (dưới 18 bỏ trống phụ huynh vẫn đăng ký được), AC10d (email phụ huynh trùng email học sinh -> 422 dưới ô). Hai ca này tự bỏ qua nếu không đặt `E2E_T29=1`.
- Chạy được ngay với backend cũ: AC10 (UI khối phụ huynh), AC10b (có email phụ huynh -> vào `/`, không banner chờ phụ huynh, `/cho-phu-huynh` 404), ca fw1-v2-qa `/cho-phu-huynh`.

## Review (laravel-reviewer, 2026-10-08)
**Kết luận:** REQUEST CHANGES (1 BLOCKER-nhẹ nên sửa trước QA, 0 chặn bảo mật). Chưa chạy lại test (chỉ đọc diff; uptime load ~4-5, tránh chạy nặng).

Đúng ADR-006: không còn chỗ nào chặn học sinh vì phụ huynh (grep không còn `cho-phu-huynh`/`parentPending`/`parent-pending`/`parent_consent_status === ...` trong app/components/lib, chỉ còn ở test 404/mã cũ). `PARENT_CONSENT_REQUIRED` rơi nhánh 403 chung (có test). Zod chịu thiếu field (`parent_consent_status` string optional, config default). Payload bỏ key khi trống. Lỗi 422 `parent_*` ép mở khối và hiện đúng ô. Câu chữ khớp ADR (thư sau khi xác thực, có thể huỷ nhận, khác email/SĐT học sinh).

### R1 [SHOULD->nên sửa ngay] Nút "Bỏ qua" thu gọn khối nhưng vẫn giữ và GỬI giá trị đã nhập; giá trị sai ở khối ẩn chặn submit không nhìn thấy
- Vị trí: `components/auth/RegisterForm.tsx` (nút `setParentToggle(false)`, `showParent`), `lib/auth/api.ts#buildRegisterPayload`.
- Vấn đề: RHF mặc định `shouldUnregister: false`, nên sau khi người dùng nhập rồi bấm "Bỏ qua, không nhập thông tin phụ huynh", giá trị vẫn nằm trong form và vẫn vào payload -> vẫn gửi thư cho email phụ huynh mà người dùng tưởng đã bỏ (vấn đề quyền riêng tư/ngạc nhiên, ADR-006 coi đây là gửi thư tới bên thứ ba). Nếu giá trị sai định dạng thì zod báo lỗi ở ô đang ẩn: submit thất bại, hộp tóm tắt liệt kê "Liên hệ phụ huynh" với anchor `reg-parent_phone` không tồn tại trong DOM, `forceParent` không bật vì lỗi là client.
- Đề xuất: khi bấm "Bỏ qua" gọi `resetField("parent_phone"); resetField("parent_email"); clearErrors(["parent_phone","parent_email"])` (hoặc `shouldUnregister` cho 2 ô). Thêm test: nhập rồi bấm Bỏ qua -> payload không có `parent_*`. Hiện chưa có test nào cho nút "Bỏ qua".

### R2 [NIT] a11y khối thu gọn/mở
- Nút "Thêm thông tin phụ huynh" có `aria-expanded={false}` nhưng không có `aria-controls`; khi mở, nút "Bỏ qua" không mang `aria-expanded`/liên kết ngược, nên trạng thái mở/đóng không nhất quán cho trình đọc màn hình. Khối mở dùng `role="group"` + `aria-label` là ổn. Cân nhắc một nút toggle duy nhất có `aria-expanded` + `aria-controls`, và đưa focus vào ô đầu khi mở (hiện focus mất vì nút bị unmount).

### R3 [NIT] Chú thích cũ/rối
- `components/v2/course/RegisterFreeButton.tsx:9` vẫn nói 403 `PARENT_CONSENT_REQUIRED` -> hộp thoại chờ phụ huynh (đã gỡ). Đổi chú thích.
- `components/v2/auth/AccountGate.tsx` đầu file: câu "ADR-006: ..." chèn giữa khiến mô tả khó đọc; viết lại gọn.
- `v2/auth/RegisterForm.tsx` (preview): `errors.parent` còn khai báo nhưng không còn đặt; trường `minor` chỉ để mở khối. Chấp nhận được vì là preview.

### R4 [Ghi chú, không sửa trong story này] Link huỷ nhận thư
- Thư phụ huynh trỏ tới `/phu-huynh/huy-nhan-thong-bao` (ADR-006 mục 4) nhưng trang này thuộc FW7, chưa có -> 404 nếu T29 lên môi trường thật trước FW7. Cần PO/điều phối: phát hành FW7 cùng hoặc trước T29, hoặc chặn bật `features.parent_notices` đến khi có trang.

### Về sửa tay `.next/types/validator.ts` và `.next/dev/types/validator.ts`
Rủi ro thấp: `.next` nằm trong .gitignore, file do Next tự sinh lại mỗi lần chạy dev/build, không vào commit. Chỉ rủi ro tạm thời nếu sửa sai làm dev server đang chạy báo lỗi type; sẽ tự khỏi khi dev server sinh lại. Lần sau nên dùng `NEXT_DIST_DIR` riêng (như dev đã làm cho build) thay vì sửa file dùng chung. Không cần xử lý thêm.

### Đối chiếu
| Yêu cầu | Đáp ứng | Ghi chú |
|---|---|---|
| Phụ huynh không bắt buộc mọi tuổi | schemas.ts, RegisterForm | OK |
| Payload bỏ key khi trống | api.ts + test | OK, nhưng xem R1 |
| 422 trùng email/SĐT học sinh hiện đúng ô | REGISTER_FIELD_MAP + forceParent | OK |
| Gỡ chờ phụ huynh, routes, preview | grep sạch | OK |
| Zod /auth/me, /config/public | api.ts, config.ts | OK, có test |
| Câu chữ ADR-006 | RegisterForm | OK |
| 375px | không có thay đổi layout bất thường (khối cột đơn, nút ghi dài có thể xuống dòng) | QA kiểm bằng mắt |
| e2e | chưa chạy (backend :8000 tắt) | AC10c/d cần `E2E_T29=1` |

### Gợi ý cho QA
- Nhập phụ huynh rồi "Bỏ qua" rồi gửi (R1); nhập sai định dạng rồi "Bỏ qua".
- Đổi ngày sinh qua lại ngưỡng 18 khi đã bấm tay mở/đóng (`parentToggle` ưu tiên hơn gợi ý).
- 375px: nút "Thêm thông tin phụ huynh (không bắt buộc)" và đoạn giải thích dài không tràn.
- Tài khoản cũ có `parent_consent_status: "pending"` từ API: không banner, không chặn mua/học miễn phí.

**APPROVE / REQUEST CHANGES: REQUEST CHANGES** — sửa R1 (kèm test), R2/R3 tuỳ Dev; R4 báo PO.

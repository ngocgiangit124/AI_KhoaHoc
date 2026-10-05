# REVIEW: FA2 (màn Chuyên đề, apps/admin)
**Kết luận:** APPROVE (0 BLOCKER, 3 SHOULD, 4 NIT)
**Phạm vi:** cây làm việc trên HEAD 3c37f29, chỉ file FA2 (page.tsx, components/subjects/**, lib/subjects/**, e2e/chuyen-de-real.spec.ts, lib/nav.ts, lib/auth/errors.ts, MfaForm.test.tsx) · 14 file. Đã chạy lại vitest admin: 91/91 pass. Không chạy lại typecheck/lint/build/e2e. Lưu ý: `lib/auth/logic.test.ts` không có thay đổi trong cây làm việc, nên không có gì để review ở file đó.

## Tổng quan
Code gọn, tách lớp rõ (query/api/errors thuần, component mỏng). Đồng bộ URL, giá trị lạ, tự lùi trang, 422 dưới ô tên, 409 giữa chừng, XSS (text node, có test) đều đúng. Cách dùng `useCallback` để `Modal` không đặt lại focus là chính xác và có chú thích. Còn vài chỗ nên sửa, không chặn.

## Phát hiện
### R1 [SHOULD] Debounce effect đẩy lại `qInput` cũ lên URL khi URL đổi từ bên ngoài
- Vị trí: `components/subjects/SubjectsScreen.tsx` effect debounce (~dòng 63-69), `qInput` chỉ khởi tạo một lần từ `query.q`.
- Vấn đề: khi `query.q` đổi mà không do ô nhập (bấm lại link "Chuyên đề" ở sidebar khi đang có `?q=abc`, nút back/forward vào URL khác, link dán), `qInput` vẫn là "abc". Sau 300ms effect thấy `q !== query.q` và navigate ngược về `q=abc`. Kết quả: không thể xoá bộ lọc bằng link, và có thể xảy ra giằng co URL. Test hiện tại không bao phủ.
- Đề xuất: đồng bộ ngược khi URL đổi mà người dùng không đang gõ.
  ~~~tsx
  const lastCommitted = useRef(query.q);
  useEffect(() => {
    if (query.q !== lastCommitted.current) { lastCommitted.current = query.q; setQInput(query.q); }
  }, [query.q]);
  // và trong effect debounce: sau khi navigate đặt lastCommitted.current = q
  ~~~
  Hoặc đặt `key={query.q}` cho phần input và chỉ để input điều khiển URL qua một handler debounce (không dùng effect phụ thuộc `query`).

### R2 [SHOULD] E2E thật phụ thuộc dữ liệu tạo tay và comment nói "tự dọn" nhưng không đảm bảo
- Vị trí: `e2e/chuyen-de-real.spec.ts` (đầu file và test 1).
- Vấn đề: (a) chuyên đề "E2E Đang gán" + khóa học gán phải tạo tay trong DB dev, môi trường mới chạy sẽ fail ở bước 409. (b) Chuyên đề tạo ra chỉ được dọn bằng chính các bước UI cuối test; nếu test fail giữa chừng thì rác `E2E CD <stamp>` còn lại (không có `afterAll`/`finally`).
- Đề xuất: (a) thêm seeder/script idempotent (ví dụ `E2eSeeder` hoặc `artisan` command dev-only) tạo user e2e + chuyên đề + khóa gán, ghi lệnh chạy ở đầu spec; khi T08/T14 xong thì có thể tạo qua API trong `beforeAll`. (b) thêm `test.afterAll` gọi API xoá theo tên có tiền tố `E2E CD `. Nếu giữ nguyên thì sửa lại comment cho đúng.

### R3 [SHOULD] N1 dựa vào khớp chuỗi tiếng Việt trong thông điệp server
- Vị trí: `lib/auth/errors.ts` `mfaErrorMessage`: `!/không đúng/i.test(msg)`.
- Vấn đề: đúng với hành vi hiện tại (sai mã luôn hiện `MFA_WRONG_MESSAGE`, hết hạn/đã xác thực giữ thông điệp server) nhưng gắn chặt với câu chữ backend; đổi chữ ở backend thì N1 quay lại âm thầm. Không có test cho nhánh "hết hạn giữ thông điệp server".
- Đề xuất: ưu tiên mã lỗi nếu backend có (ví dụ `err.code === "MFA_CODE_INVALID"`), nếu chưa có thì ghi chú trong api-contract rằng chuỗi này là hợp đồng, và thêm test: 422 với "Mã OTP đã hết hạn…" phải hiện nguyên văn.

### R4 [NIT] `SubjectInUseModal` đóng cả khi Ẩn thất bại
- `onHide` gọi `changeStatus` (tự nuốt lỗi, hiện toast) rồi `closeDialog()` vô điều kiện. Thất bại vẫn đóng modal, người dùng chỉ thấy toast. Nên cho `changeStatus` trả `boolean` và chỉ đóng khi thành công.

### R5 [NIT] Esc/overlay khi đang gửi ở `ConfirmModal` và `SubjectInUseModal`
- `SubjectFormModal` có chặn Esc/overlay lúc đang gửi (tốt). `ConfirmModal` (packages/ui) và `SubjectInUseModal` thì không: Esc khi đang xoá đóng hộp thoại, sau đó nếu 409 thì `setDialog(in-use)` mở lại hộp thoại bất ngờ. Rủi ro thấp; sửa gọn nhất là để `ConfirmModal` bỏ qua `onClose` khi `submitting`.

### R6 [NIT] Dư thừa và đặt tên
- `const page = !loading && result ? result.page : (result?.page ?? null)` hai nhánh cho cùng kết quả, rút thành `result?.page ?? null`.
- `maxLength={NAME_MAX_LENGTH + 20}` không có giải thích; thêm comment (cho dán dài rồi báo lỗi rõ) hoặc dùng đúng 100.
- Mô tả toast "Đã ẩn chuyên đề khỏi bộ lọc công khai" tốt, giữ.

### R7 [NIT] Race request cũ về sau
- Cơ chế abort ở cleanup + kiểm `result.key !== requestKey` là đủ; `.then` không kiểm `aborted` nhưng response đã resolve trước abort vẫn mang `key` của chính nó nên không ghi đè sai (loading suy từ key). Không cần sửa, chỉ ghi nhận đã kiểm tra.

## Trả lời các điểm lead hỏi
1. URL: `parseSubjectQuery` chuẩn hoá q/status/page/per_page, giá trị lạ về mặc định, không gửi tham số sai lên API. Tự lùi trang dùng `min(page-1, last_page)` nên kết thúc, không vòng lặp. Lỗi duy nhất là R1.
2. Lỗi: 422 dưới ô tên, field khác thành banner, 409 giữa chừng chuyển sang modal chặn + reload, 403 `FORBIDDEN` thành ForbiddenView (403 mã khác hiện Alert), lỗi mạng có "Thử lại". Đạt.
3. XSS: tên render trong text node, không có `dangerouslySetInnerHTML`, có test. Đạt.
4. Giáo viên: backend `SubjectPolicy` cho giáo viên xem và `StaffUserResource` trả `manage_subjects = isStaff` nên UI chỉ đọc, không gửi `status`, quyền lấy từ `permissions.manage_subjects` (fallback theo role) là đúng và khớp api-contract. Design §3 ("giáo viên gặp 403") lệch nhưng hợp lý hơn vì US-009/002 cần giáo viên đọc danh sách; mục menu vẫn ẩn với giáo viên. Đề nghị cập nhật design §3 cho khớp. Chưa làm preview slug/toggle trong form (§2.2) chấp nhận được: hint slug tự sinh và hiện slug khi sửa đã đủ, toggle có ngay trên bảng; nên ghi vào board là phần giảm phạm vi có chủ ý.
5. Trạng thái tải suy từ key, không có `setState` đồng bộ trong effect: tốt. Debounce 300ms đúng, trừ R1.
6. Modal: `closeDialog`/`onClose` đều ổn định (`useCallback`/`useState` lazy), `stableClose` đọc ref nên không kích hoạt lại focus. Đạt; xem R5 cho các modal còn lại.
7. A11y: label đầy đủ, `aria-live` cho tổng, `aria-busy`, switch `role="switch"` cao 44px (có e2e đo 375px), focus trả về nút gốc hoặc "Tạo chuyên đề" khi nút gốc đã gỡ. Đạt.
8. N1: đúng yêu cầu, xem R3.
9. E2E: xem R2.
10. Quy ước repo: Vietnamese UI, `"use client"` đúng chỗ, không token/biến bí mật, type khớp contract (`courses_count?`, phẳng §1.5). Đạt.

## Đối chiếu acceptance criteria (US-011)
| AC | Code đáp ứng | Ghi chú |
|---|---|---|
| Danh sách, tìm, lọc trạng thái, phân trang 25/50 | `SubjectsScreen` + `query.ts` | R1 |
| Tạo/sửa tên, trùng tên báo dưới ô | `SubjectFormModal`, `classifySubjectFormError` | e2e kiểm trùng khác hoa/thường |
| Xoá bị chặn khi đang gán, gợi ý Ẩn | `SubjectInUseModal`, `isSubjectInUse` | R4 |
| Ẩn/hiện | `StatusSwitch` + `changeStatus` | |
| Giáo viên chỉ đọc | `canWrite` | lệch design §3, hợp lý |
| Danh sách rỗng | `EmptyState` | |

## Gợi ý cho QA
- Gõ nhanh nhiều ký tự rồi đổi trạng thái/per_page trong lúc chờ debounce; bấm link sidebar "Chuyên đề" khi đang có `?q=` (R1).
- Xoá dòng cuối của trang cuối (tự lùi trang); mở URL `?page=999`, `?per_page=7`, `?status=abc`.
- Hai tab: tab A gán khóa học vào chuyên đề, tab B xoá (409 giữa chừng); tab B Esc khi đang xoá (R5).
- Mạng chậm/ngắt: Thử lại; 403 `FORBIDDEN` vs 403 `ACCOUNT_LOCKED`.
- MFA: sai mã, mã hết hạn, đã xác thực (R3).
- Tên có `<`, `>`, emoji, dài 100 ký tự Unicode, khoảng trắng kép.

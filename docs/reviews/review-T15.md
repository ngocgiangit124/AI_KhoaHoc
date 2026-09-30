# REVIEW: T15 Mã giảm giá quản trị (US-013)
**Kết luận:** APPROVE (PASS) — 0 BLOCKER, 5 SHOULD, 4 NIT. Nên sửa R1–R3 trước khi gộp vì đây là cổng cuối, security đã hoãn.
**Phạm vi:** `git diff b23efe4...claude/zen-dirac-fmucf7-t15` (commit 51e20b8) · 17 file (migration x3, Model/Enum x3, Policy, Request, Resource, Service x2, Controller, routes, factory, test x3).
Đã chạy: Pint sạch, Larastan 0 lỗi, Pest 543 pass (theo báo cáo dev). Không chạy migrate. DBA review riêng migration/index/CHECK.

## Tổng quan
Code gọn, đúng mẫu T06. Phân quyền `can:` ở route chạy trước validate nên GV nhận 403, không nhận 422. `status`/`used_count`/`is_restricted`/`created_by` không nằm trong `$fillable`. Service lặp lại bất biến khi `used_count > 0` (defense in depth). Audit dùng `coupon_code` đúng vì `AuditLogger` lọc `code`. Resource không lộ thêm gì. Điểm yếu là rule "mã rủi ro cao" còn kẽ hở, thiếu audit cờ rủi ro, chưa nối `counters:recount`, và `valid_until` kiểu date sẽ hết hạn sớm một ngày.

## Bảo mật ứng dụng
- Phân quyền: `CouponPolicy` chỉ `isStaff()` (admin, quản lý trang). GV, HS bị chặn (route `can:` và `authorize` ở `index`). Có test 403/401. Đạt.
- Mass-assignment: `create` dùng `$request->validated()` rồi map từng trường; `update` gán từng thuộc tính. Đạt.
- IDOR: mã là tài nguyên toàn cục của staff, không có scope theo bưu cục/người dùng nên không có IDOR. Enumeration mã: chỉ staff đọc được, không có endpoint công khai. Đạt.
- Audit: `coupon_code` là dữ liệu cấu hình, không phải PII. Không có secret. Đạt. Lưu ý: mã riêng tư nằm trong audit, đúng theo `ALLOWED_KEYS` đã chủ ý.
- Không có `whereRaw` hay `orderBy` từ input; `state` dùng `Rule::in`. Đạt.

## Phát hiện

### R1 [SHOULD] Rule "fixed ≥ giá rẻ nhất" bị vô hiệu khi phạm vi không có khóa published (S18 bypass)
- Vị trí: `backend/app/Http/Requests/Admin/CouponRequest.php` `cheapestApplicablePrice()` (trả `null` khi không có khóa khớp) và `guardLimitsForHighDiscount()`.
- Vấn đề: mã `fixed_amount` lớn với `course_ids` chỉ gồm khóa draft/chưa publish (hoặc site chưa có khóa published) cho `cheapest = null`, nên bỏ qua bắt buộc `max_uses`/`valid_until`. Khi khóa được publish, mã đó là mã giảm hết giá, không giới hạn, vô thời hạn — đúng kịch bản S18 muốn chặn. Đây cũng trả lời giả định (1): tính theo phạm vi mã là hợp lý, nhưng phải có nhánh fallback an toàn.
- Đề xuất: tính giá rẻ nhất trên các khóa trong phạm vi mọi trạng thái (`price > 0`, bỏ điều kiện published), hoặc khi không xác định được thì coi là rủi ro cao (bắt buộc giới hạn). Thêm test cho nhánh này.

### R2 [SHOULD] Không ghi cờ `high_risk_full_discount` vào audit như đã hứa
- Vị trí: docblock `CouponRequest.php:30` nói "ghi vào audit_logs qua CouponService (payload high_risk_full_discount)"; `grep high_risk` chỉ có đúng dòng comment đó. data-model §3.5 và S18 yêu cầu "kiểm ở app + ghi audit_logs".
- Vấn đề: comment nói dối, và mã rủi ro cao không được đánh dấu cho người soát audit. Giả định (9) chưa đủ vì dev tự khai chưa làm.
- Đề xuất: `CouponService` tính `isHighRisk` (percent 100 hoặc fixed ≥ cheapest) rồi thêm `'high_risk_full_discount' => true` vào payload audit của create/update. Có thể tách logic tính "rủi ro cao" thành một method của Service (xem R5), Request chỉ gọi. Nếu không làm ở task này, phải sửa comment và ghi việc vào board.

### R3 [SHOULD] `coupons.used_count` chưa nối vào `counters:recount` (DoD T15)
- Vị trí: `backend/app/Services/Counters/CouponUsedCountRecounter.php`; tasks.md T15 ghi "`counters:recount` thêm `coupons.used_count`".
- Vấn đề: chỉ có class, không có chỗ gọi. Việc còn treo giữa T14 và T18, dễ bị quên. Hướng bỏ qua an toàn khi chưa có bảng `coupon_usages` (`Schema::hasTable`) là chấp nhận được.
- Đề xuất: ghi thẳng vào `docs/board.md` một việc "khi gộp T14: gọi `app(CouponUsedCountRecounter::class)->recount()` trong `counters:recount`", gắn vào checklist gộp. Chốt lại ở T18 rằng bảng `coupon_usages` đúng tên và cột `coupon_id`. Nên thêm test có gọi `recount()` cho trường hợp có bảng khi T18 tới.

### R4 [SHOULD] `valid_until` kiểu date-only hết hạn từ 00:00 của ngày chọn
- Vị trí: `CouponRequest.php` rules `valid_until` (`'date'`); `CouponService` create/update (`Carbon::parse`, cast `datetime`); test toàn dùng `->toDateString()`.
- Vấn đề: admin chọn "Ngày kết thúc 31/10" thì mã hết hạn lúc 00:00 31/10, mất cả ngày cuối. Với `valid_until == valid_from` (được phép do `after_or_equal`), mã có hiệu lực 0 giây. Scope `state=expired` dùng `valid_until < now()` nên cũng lệch. Hành vi này ảnh hưởng T16 `CouponEvaluator`.
- Đề xuất: chốt với PO quy ước "hết hạn cuối ngày". Nếu chuỗi gửi lên chỉ có ngày (regex `^\d{4}-\d{2}-\d{2}$`) thì chuẩn hoá `valid_until` thành `endOfDay()` ở Service. Nếu FE gửi datetime đầy đủ thì ghi rõ vào api-contract. Thêm test biên.

### R5 [SHOULD] Cho phép hạ `max_uses` xuống dưới `used_count`, và không chặn giá trị vượt cột
- Vị trí: `CouponRequest.php` rules `max_uses`, `discount_value`.
- Vấn đề: (a) mã đã dùng 50 lượt vẫn sửa được `max_uses=10` (nên báo lỗi hoặc ít nhất `min:used_count`), dẫn tới `used_count > max_uses` không mong muốn, trong khi T19 dùng đó làm tín hiệu `needs_review`. (b) `discount_value`/`max_uses` không có `max`; giá trị > 4294967295 gây lỗi DB (500) thay vì 422 với cột `unsignedInteger`. Fixed không có trần hợp lý (ví dụ 100.000.000).
- Đề xuất:
  ~~~php
  'max_uses' => ['nullable', 'integer', 'min:'.max(1, (int) $coupon?->used_count), 'max:1000000'],
  'discount_value' => ['required', 'integer', 'min:1', 'max:1000000000', /* closure percent<=100 */],
  ~~~

### R6 [NIT] `deactivate`/`delete` không nằm trong transaction
- Vị trí: `CouponService::deactivate()`, `delete()`.
- Vấn đề: `create`/`update` bọc `DB::transaction` cả audit, hai hàm này thì không. Nếu audit lỗi sau khi `delete()`, mất dấu vết. Kiểm `used_count > 0` rồi `delete` cũng không khoá dòng; T18 tăng `used_count` cùng lúc thì bị race (FK `coupon_usages` sẽ chặn ở DB — nhớ khai báo `restrictOnDelete` ở T18). `deactivate` gọi lại trên mã đã inactive vẫn ghi thêm audit.
- Đề xuất: bọc transaction, `lockForUpdate` khi xoá, bỏ qua (không ghi audit) nếu đã inactive.

### R7 [NIT] Logic nghiệp vụ trong `CouponRequest::withValidator`
- Vấn đề: truy vấn `Course` và bất biến khi `used_count > 0` nằm trong FormRequest, đồng thời Service lặp lại bất biến. Trả lời câu hỏi của dev: chấp nhận được vì cần trả lỗi 422 theo field, nhưng nên đưa phép tính "giá rẻ nhất / rủi ro cao" vào `CouponService` (hoặc class riêng) để dùng chung với R2 và tránh phải sửa hai nơi.

### R8 [NIT] Trùng lặp và thiếu test nhỏ
- `course_ids.*`/`subject_ids.*` thiếu `distinct` (sync tự khử trùng nên chỉ là NIT). Thiếu test cho: Service ghi đè khi `used_count > 0` (defense in depth), `state=active` loại đúng mã hết lượt/hết hạn, deactivate lần 2, ghi cờ audit (R2), 409 `COUPON_IN_USE` có nội dung. Race unique `code` cho 500 thay vì 422 (bắt `UniqueConstraintViolationException` nếu muốn).

### R9 [NIT] Route `index` không có `can:`
- Chấp nhận (authorize trong controller như `/subjects`). Không sửa.

## Trả lời 9 giả định của dev
1. Giá rẻ nhất theo phạm vi mã, published, price>0: hợp lý, nhưng cần sửa fallback (R1).
2. `valid_until` nullable, chỉ bắt buộc cho mã rủi ro cao: đúng contract và CHECK. Design UX đánh dấu `*` cho cả hai ngày nhưng story/data-model ghi nullable — nên chốt với PO và sửa design/story cho khớp (không phải lỗi code). Chấp nhận.
3. `is_restricted` + 2 pivot: khớp data-model §3.5 (story cũ dùng `applicable_scope`, data-model đã thay). Chấp nhận. Hệ quả: FE không có `applicable_scope`, suy từ `course_ids`/`subject_ids`.
4. `valid_until >= valid_from`: khớp data-model và AC5. Chấp nhận (xem R4 về date-only).
5. `name` không có trong contract: data-model có cột `name` (văn bản thuần, varchar 255) nên không bịa; contract §2.5 chưa liệt kê. Đề nghị bổ sung `name` vào api-contract cho khớp (không đổi code).
6. Không có endpoint kích hoạt lại: contract không có; chấp nhận, ghi câu hỏi mở cho PO.
7. Filter `state` và thứ tự ưu tiên: hợp lý, khớp AC6, có test. Chấp nhận. Index `(status, valid_until)` hỗ trợ được.
8. `restrictOnDelete` cho `course_id`/`subject_id`, cascade cho `coupon_id`: hợp lý (chặn xoá khóa/chuyên đề đang nằm trong mã; xoá mã kéo theo pivot). DBA soi thêm.
9. Audit chung `coupon.*`: chấp nhận, kèm R2 để có cờ mã rủi ro.

## Đối chiếu acceptance criteria
| AC | Code đáp ứng | Ghi chú |
|---|---|---|
| AC1 tạo mã active | `CouponService::create` (`status=Active`) | Đạt |
| AC2 trùng code không phân biệt hoa/thường | `prepareForValidation` upper + `Rule::unique` | Đạt, có test |
| AC3 vô hiệu hoá | `deactivate` + route `can:deactivate` | Đạt (R6 nhỏ) |
| AC4 xem lượt đã dùng/tổng, hết lượt | Resource `used_count`/`max_uses`, `state=exhausted` | Đạt |
| AC5 kết thúc trước bắt đầu | `after_or_equal:valid_from` | Đạt (R4) |
| AC6 lọc trạng thái | `scopeState` | Đạt |
| AC7 percent > 100 | closure + CHECK DB | Đạt |
| AC8 phạm vi khóa/chuyên đề | `is_restricted` + pivot + `course_ids`/`subject_ids` | Đạt |
| AC9, AC10 | Thuộc T16/T18 | Ngoài phạm vi T15 |
| S18 rule mã rủi ro cao | Request guard + CHECK | Có kẽ hở (R1), thiếu audit cờ (R2) |
| DoD `counters:recount` | Class có, chưa nối | R3 |
| Xoá khi chưa có order tham chiếu | `used_count > 0` → 409 | Đạt; T18 cần FK restrict |

## Gợi ý cho QA
- Mã fixed lớn với phạm vi toàn draft, toàn site trống; sau đó publish khóa.
- Sửa `max_uses` thấp hơn `used_count`; giá trị rất lớn (overflow).
- `valid_until` ngày cuối (date-only) và trùng `valid_from`; `state=expired`/`active` quanh ranh giới.
- GV gọi mọi route với payload sai: phải 403, không 422.
- Race tạo hai mã cùng code; xoá đồng thời với T18 tăng `used_count`.
- Audit: không có key `code` bị lọc, `before`/`after` của update có `coupon_code`.

---

# Vòng 2 (commit afcf110, so với 51e20b8)
**Kết luận vòng 2:** PASS — 0 BLOCKER, 0 SHOULD mới, 3 NIT mới. R1, R2, R4, R5 và DBA M2 đã sửa đúng và đủ.
**Phạm vi:** `git diff 51e20b8 afcf110` · 3 file (`CouponRequest`, `CouponService`, `tests/Feature/T15/CouponReviewFixesTest.php`). Đọc code, không chạy được Pest/Pint/Larastan trên máy review (host không có PHP, docker compose thiếu `infra/.env`); dựa vào báo cáo `composer ci` của dev. Không chạy migrate.

## Xác nhận các phát hiện cũ
- **R1 đã sửa đúng.** `CouponService::cheapestPrice()` lấy `price > 0` ở mọi trạng thái khóa (`Course` có SoftDeletes nên khóa đã xoá bị loại). Không có khóa nào thì trả `null`, và `isHighRisk` coi `null` là rủi ro cao. Phạm vi = `course_ids` hợp `subject_ids` bằng `orWhere` gói trong một closure nên không rò sang điều kiện `price`. Có 3 test: draft, site trống, và tạo được khi đủ giới hạn.
- **R2 đã sửa đúng.** `auditPayload()` có `high_risk_full_discount` trong cả `create` lẫn `update` (before/after). Request và Service dùng chung `isHighRisk` (R7 cũng xong). Test kiểm giá trị thật trong `audit_logs` (true/false).
- **R4 đã sửa đúng.** `parseValidUntil` dùng `endOfDay()` chỉ khi chuỗi khớp `^\d{4}-\d{2}-\d{2}$`. Chuỗi có giờ giữ nguyên. Áp dụng cho cả create và update. Phần micro giây `.999999` không gây làm tròn sang 00:00 hôm sau vì cột là `dateTime` không có phân số và Laravel serialize theo `Y-m-d H:i:s`. Test đọc lại từ DB và khẳng định `23:59:59`, nên kiểm thật.
- **R5 đã sửa đúng.** `max_uses` có `min:max(1, used_count)` và `max:1000000`, `discount_value` có `max:1000000000`. Service có lớp chặn thứ hai dưới khoá: `max_uses < used_count` trả `DomainException` 422 kèm `context` theo field (`ApiExceptionRenderer` trả `context` làm `errors`). `course_ids.*`/`subject_ids.*` đã có `distinct` (R8). Có test 422 khi vượt trần, hạ dưới `used_count`, trùng id.
- **DBA M2 đã sửa đúng.** `update`, `deactivate`, `delete` đều bọc `DB::transaction` và gọi `lock()` (`lockForUpdate()->findOrFail` theo PK) trước khi kiểm `used_count`. Từ đó dùng bản model mới (`update` trả model đã khoá; `delete` ghi audit sau khi xoá vẫn đọc được `code`). `deactivate` lần hai trả về sớm, không ghi audit (R6 xong). `Coupon` không có SoftDeletes nên xoá cứng là chủ ý. Test "dùng `used_count` mới nhất" dùng model cũ (stale) rồi update thẳng DB, nên chứng minh được Service không tin model bind từ route. Chưa có test đồng thời thật (2 kết nối), chấp nhận vì khó viết trong Pest.

## Kiểm hồi quy đặc biệt
- `isHighRisk`/`cheapestPrice` khi không xác định giá: an toàn (fail-closed). Hệ quả: mã `fixed_amount` chỉ áp cho khóa miễn phí (price = 0) cũng bị bắt buộc giới hạn; vô hại.
- Audit không lộ dữ liệu nhạy cảm: payload chỉ gồm `coupon_code` (dữ liệu cấu hình), loại/giá trị giảm, ngày, id phạm vi, cờ rủi ro. Không có PII, secret hay khoá bị `AuditLogger` lọc.
- Chống TOCTOU lúc ghi: Request kiểm bất biến và `min` của `max_uses` trên `used_count` của model bind (có thể cũ), nhưng Service kiểm lại dưới khoá và ghi đè `code/type/value` bằng giá trị hiện hành, nên đường đua không làm hỏng dữ liệu. Chỉ khác ở chỗ client có thể nhận 200 thay vì 422 trong cửa sổ hẹp; chấp nhận.
- Không phát hiện lỗi mass-assignment, thứ tự phân quyền (`can:` vẫn ở route) hay N+1 mới.

## Phát hiện mới
### R10 [NIT] `valid_until` chỉ có ngày cùng ngày với `valid_from` có giờ bị 422 sai
- Vị trí: `CouponRequest.php` rule `after_or_equal:valid_from`.
- `valid_from = 2026-10-31 10:00`, `valid_until = 2026-10-31` (sẽ thành 23:59:59) bị từ chối vì rule so `00:00 < 10:00`. FE hiện chỉ gửi ngày nên chưa xảy ra. Nếu FE về sau gửi giờ, chuẩn hoá `valid_until` trước validate (trong `prepareForValidation`) hoặc bỏ rule và kiểm trong Service.

### R11 [NIT] Chưa có test cho một số nhánh mới
- Chưa có test: `fixed_amount` nhỏ hơn giá rẻ nhất (không rủi ro, không bắt buộc giới hạn), `endOfDay` trên đường `update`, và `subject_ids` trong `cheapestPrice`. Nên bổ sung khi QA chạy giai đoạn.

### R12 [NIT] Cờ rủi ro tính tại thời điểm ghi
- `high_risk_full_discount` và ràng buộc giới hạn dựa trên giá lúc tạo/sửa; giá khóa hạ sau đó hoặc khóa mới thêm vào chuyên đề không đánh giá lại. Đã nằm trong bản chất S18. T16/T18 (`CouponEvaluator`) nên tự chặn giảm quá giá thực, không dựa vào cờ này.

## Đối chiếu nhanh
| Mục | Kết quả |
|---|---|
| R1, R2, R4, R5 | Đạt, có test kiểm giá trị thật |
| R6, R7, R8 (NIT vòng 1) | Đã xử lý luôn |
| R3 (nối `counters:recount`) | Ngoài diff vòng 2; vẫn cần ghi board khi gộp T14/T18 |
| DBA M2 | Đạt (khoá theo PK trước khi kiểm) |
| DBA M1 (recounter atomic), orders tham chiếu khi xoá | Thuộc T18, chưa chặn T15 |

## Gợi ý cho QA
- Hai tab: tăng `used_count` bằng tay giữa lúc mở form và lúc gửi PUT/DELETE; kiểm code/type/value không đổi và DELETE trả 409.
- `valid_until` ngày cuối và `state=expired` quanh 23:59:59; trùng ngày `valid_from`.
- Phạm vi chuyên đề có khóa draft giá cao; xoá mềm khóa rồi tạo mã fixed.
- `max_uses` bằng đúng `used_count` (hợp lệ) và `used_count - 1` (422).
- Cần chạy lại `composer ci` trong Docker trước khi gộp để xác nhận Pint/Larastan/Pest xanh (review này chưa tự chạy được).

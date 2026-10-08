import { describe, expect, it } from "vitest";
import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { classifyCouponFormError, couponActionError, normalizeErrorKey } from "./errors";
import {
  EMPTY_VALUES,
  HIGH_RISK_MESSAGE,
  buildCouponBody,
  isHighRisk,
  isoToVnLocal,
  sameValues,
  validateCouponForm,
  valuesFromCoupon,
  vnLocalToIso,
  type CouponFormValues,
  type ValidateContext,
} from "./form";
import { couponQueryToApi, couponQueryToSearch, parseCouponQuery } from "./query";
import type { Coupon } from "./types";

const ctx = (over: Partial<ValidateContext> = {}): ValidateContext => ({ mode: "create", locked: false, usedCount: 0, cheapest: null, nowLocal: "2026-10-08T10:00", ...over });
const valid = (over: Partial<CouponFormValues> = {}): CouponFormValues => ({ ...EMPTY_VALUES, code: "TOAN2026", value: "20", ...over });

const coupon = (over: Partial<Coupon> = {}): Coupon => ({
  id: 5,
  code: "TOAN2026",
  name: "Mã toán",
  discount_type: "percent",
  discount_value: 20,
  max_uses: 100,
  max_uses_per_user: 1,
  used_count: 0,
  valid_from: "2026-10-01T00:00:00+07:00",
  valid_until: "2026-12-31T23:59:30+07:00",
  status: "active",
  state: "active",
  is_restricted: false,
  created_at: "2026-09-01T00:00:00+07:00",
  ...over,
});

describe("query", () => {
  it("giá trị lạ rơi về mặc định", () => {
    expect(parseCouponQuery(new URLSearchParams("state=abc&per_page=7&page=-3&q=%20x%20"))).toEqual({ q: "x", state: "", page: 1, perPage: 25 });
  });
  it("giữ state hợp lệ; URL gọn bỏ tham số mặc định; API luôn gửi per_page/page", () => {
    const q = parseCouponQuery(new URLSearchParams("state=exhausted&per_page=50&page=3&q=he"));
    expect(q).toEqual({ q: "he", state: "exhausted", page: 3, perPage: 50 });
    expect(couponQueryToSearch(q)).toBe("?q=he&state=exhausted&per_page=50&page=3");
    expect(couponQueryToSearch({ q: "", state: "", page: 1, perPage: 25 })).toBe("");
    expect(couponQueryToApi({ q: "", state: "inactive", page: 1, perPage: 25 })).toBe("state=inactive&per_page=25&page=1");
  });
});

describe("thời gian giờ Việt Nam", () => {
  it("ISO → ô datetime-local theo +07:00 bất kể múi giờ máy, và ngược lại", () => {
    expect(isoToVnLocal("2026-10-01T00:00:00+07:00")).toBe("2026-10-01T00:00");
    expect(isoToVnLocal("2026-09-30T17:00:00Z")).toBe("2026-10-01T00:00");
    expect(isoToVnLocal(null)).toBe("");
    expect(vnLocalToIso("2026-10-01T08:30")).toBe("2026-10-01T08:30:00+07:00");
  });
});

describe("validateCouponForm", () => {
  it("hợp lệ → không lỗi", () => expect(validateCouponForm(valid(), ctx())).toEqual({}));

  it("mã: bắt buộc, 4–50, chỉ A-Z 0-9 _ - (tự viết hoa, cắt khoảng trắng)", () => {
    expect(validateCouponForm(valid({ code: "  " }), ctx()).code).toMatch(/nhập mã/);
    expect(validateCouponForm(valid({ code: "ABC" }), ctx()).code).toMatch(/4–50/);
    expect(validateCouponForm(valid({ code: "A".repeat(51) }), ctx()).code).toMatch(/4–50/);
    expect(validateCouponForm(valid({ code: "TOÁN 2026" }), ctx()).code).toMatch(/chỉ gồm/);
    expect(validateCouponForm(valid({ code: " toan_2026-a " }), ctx()).code).toBeUndefined();
  });

  it("AC7: % vượt 100 báo lỗi; 100 hợp lệ về giá trị", () => {
    expect(validateCouponForm(valid({ value: "101" }), ctx()).discount_value).toBe("Giá trị giảm không được vượt quá 100%.");
    expect(validateCouponForm(valid({ value: "0" }), ctx()).discount_value).toMatch(/lớn hơn 0/);
    expect(validateCouponForm(valid({ value: "" }), ctx()).discount_value).toMatch(/nhập giá trị/);
    expect(validateCouponForm(valid({ value: "12.5" }), ctx()).discount_value).toMatch(/số nguyên/);
  });

  it("số tiền cố định tối đa 100.000.000", () => {
    expect(validateCouponForm(valid({ type: "fixed_amount", value: "100000001" }), ctx()).discount_value).toMatch(/100\.000\.000/);
    expect(validateCouponForm(valid({ type: "fixed_amount", value: "100000000", maxUses: "5", until: "2026-12-01T00:00" }), ctx()).discount_value).toBeUndefined();
  });

  it("AC5: kết thúc trước bắt đầu báo lỗi; bắt đầu trống khi tạo thì so với bây giờ; khi sửa không so", () => {
    expect(validateCouponForm(valid({ from: "2026-11-02T00:00", until: "2026-11-01T00:00" }), ctx()).valid_until).toBe("Ngày kết thúc phải sau ngày bắt đầu.");
    expect(validateCouponForm(valid({ until: "2026-10-01T00:00" }), ctx()).valid_until).toBe("Ngày kết thúc phải sau ngày bắt đầu.");
    expect(validateCouponForm(valid({ until: "2026-10-01T00:00" }), ctx({ mode: "edit" })).valid_until).toBeUndefined();
    expect(validateCouponForm(valid({ from: "2026-11-01T00:00", until: "2026-11-01T00:00" }), ctx()).valid_until).toBeUndefined();
  });

  it("max_uses: số nguyên 1–1.000.000; khi sửa không nhỏ hơn lượt đã dùng", () => {
    expect(validateCouponForm(valid({ maxUses: "0" }), ctx()).max_uses).toBeDefined();
    expect(validateCouponForm(valid({ maxUses: "1000001" }), ctx()).max_uses).toMatch(/1\.000\.000/);
    expect(validateCouponForm(valid({ maxUses: "3" }), ctx({ mode: "edit", usedCount: 5 })).max_uses).toMatch(/\(5\)/);
    expect(validateCouponForm(valid({ maxUses: "" }), ctx({ mode: "edit", usedCount: 5 })).max_uses).toBeUndefined();
  });

  it("S18: giảm 100% bắt buộc max_uses và valid_until; giảm cố định ≥ giá rẻ nhất cũng vậy", () => {
    const e = validateCouponForm(valid({ value: "100" }), ctx());
    expect(e.max_uses).toBe(HIGH_RISK_MESSAGE);
    expect(e.valid_until).toBe(HIGH_RISK_MESSAGE);
    expect(validateCouponForm(valid({ value: "100", maxUses: "10", until: "2026-12-01T00:00" }), ctx())).toEqual({});
    const fixed = valid({ type: "fixed_amount", value: "200000" });
    expect(validateCouponForm(fixed, ctx({ cheapest: 199000 })).max_uses).toBe(HIGH_RISK_MESSAGE);
    expect(validateCouponForm(fixed, ctx({ cheapest: 300000 }))).toEqual({});
    expect(validateCouponForm(fixed, ctx({ cheapest: null }))).toEqual({});
    expect(isHighRisk({ type: "percent", value: "99" }, null)).toBe(false);
  });

  it("phạm vi: chọn 'theo chuyên đề/khóa' mà không chọn gì → lỗi (không âm thầm thành toàn bộ)", () => {
    expect(validateCouponForm(valid({ scope: "subjects" }), ctx()).subject_ids).toMatch(/ít nhất 1 chuyên đề/);
    expect(validateCouponForm(valid({ scope: "courses" }), ctx()).course_ids).toMatch(/ít nhất 1 khóa/);
    expect(validateCouponForm(valid({ scope: "courses", courses: [{ id: 1, title: "A" }] }), ctx())).toEqual({});
  });

  it("tên: tối đa 255, không chứa < >", () => {
    expect(validateCouponForm(valid({ name: "<b>x</b>" }), ctx()).name).toMatch(/< hoặc >/);
    expect(validateCouponForm(valid({ name: "x".repeat(256) }), ctx()).name).toMatch(/255/);
  });

  it("mã đã dùng (locked): không kiểm lại mã/giá trị (ô chỉ đọc) và không ép S18", () => {
    expect(validateCouponForm(valid({ code: "", value: "100" }), ctx({ mode: "edit", locked: true, usedCount: 2 }))).toEqual({});
  });
});

describe("buildCouponBody", () => {
  it("tạo: mã viết hoa, giá trị số, bắt đầu trống thì bỏ khóa valid_from, phạm vi theo lựa chọn", () => {
    const body = buildCouponBody(
      valid({ code: " toan2026 ", name: " ", maxUses: "50", until: "2026-12-31T23:59", scope: "subjects", subjectIds: [3, 4], courses: [{ id: 9, title: "x" }] }),
      { mode: "create", initial: null },
    );
    expect(body).toEqual({
      code: "TOAN2026",
      name: null,
      discount_type: "percent",
      discount_value: 20,
      max_uses: 50,
      valid_until: "2026-12-31T23:59:00+07:00",
      subject_ids: [3, 4],
      course_ids: [],
    });
    expect(body).not.toHaveProperty("valid_from");
  });

  it("sửa: mốc không đổi → valid_from null, valid_until giữ nguyên chuỗi ISO cũ (giữ giây)", () => {
    const c = coupon();
    const initial = valuesFromCoupon(c);
    const body = buildCouponBody({ ...initial, name: "Mới" }, { mode: "edit", initial, initialIso: { from: c.valid_from, until: c.valid_until } });
    expect(body.valid_from).toBeNull();
    expect(body.valid_until).toBe("2026-12-31T23:59:30+07:00");
    const changed = buildCouponBody({ ...initial, from: "2026-10-02T08:00", until: "" }, { mode: "edit", initial, initialIso: { from: c.valid_from, until: c.valid_until } });
    expect(changed.valid_from).toBe("2026-10-02T08:00:00+07:00");
    expect(changed.valid_until).toBeNull();
  });

  it("valuesFromCoupon: phạm vi theo khóa/chuyên đề, kết hợp cả hai; sameValues bỏ qua danh sách của phạm vi không dùng", () => {
    expect(valuesFromCoupon(coupon()).scope).toBe("all");
    expect(valuesFromCoupon(coupon({ is_restricted: true, courses: [{ id: 1, title: "A" }], subjects: [] })).scope).toBe("courses");
    expect(valuesFromCoupon(coupon({ is_restricted: true, courses: [{ id: 1, title: "A" }], subjects: [{ id: 2, name: "B" }] })).scope).toBe("both");
    const base = valuesFromCoupon(coupon());
    expect(sameValues(base, { ...base, subjectIds: [1] })).toBe(true);
    expect(sameValues(base, { ...base, value: "30" })).toBe(false);
  });
});

describe("lỗi API", () => {
  const err = (status: number, body: ConstructorParameters<typeof ApiError>[1], retry?: number) => new ApiError(status, body, retry);

  it("422: đưa lỗi về đúng field (cả course_ids.N); field lạ vào banner", () => {
    const f = classifyCouponFormError(err(422, { message: "x", code: "VALIDATION_ERROR", errors: { code: ["Mã giảm giá đã tồn tại."], "course_ids.2": ["Khóa không tồn tại."], zzz: ["Lạ."] } }));
    expect(f.fields).toEqual({ code: "Mã giảm giá đã tồn tại.", course_ids: "Khóa không tồn tại." });
    expect(f.banner).toBe("Lạ.");
    expect(normalizeErrorKey("subject_ids.0")).toBe("subject_ids");
  });
  it("422 COUPON_LOCKED: banner nêu trường bị đổi và yêu cầu tải lại", () => {
    const f = classifyCouponFormError(err(422, { message: "x", code: "COUPON_LOCKED", errors: { fields: ["code", "discount_value"] } }));
    expect(f.stale).toBe(true);
    expect(f.banner).toContain("mã, giá trị giảm");
  });
  it("404 → gone; 403 → quyền; 429 → thời gian chờ; mạng → thông điệp mạng", () => {
    expect(classifyCouponFormError(err(404, { message: "x" })).gone).toBe(true);
    expect(classifyCouponFormError(err(403, { message: "x", code: "FORBIDDEN" })).banner).toMatch(/không có quyền/);
    expect(classifyCouponFormError(err(429, { message: "x" }, 30)).banner).toMatch(/30 giây/);
    expect(classifyCouponFormError(new NetworkError("x")).banner).toMatch(/kết nối/);
  });
  it("thao tác: 409 COUPON_IN_USE, 404, 5xx dùng thông điệp server", () => {
    expect(couponActionError(err(409, { message: "x", code: "COUPON_IN_USE" }))).toMatch(/đã được sử dụng/);
    expect(couponActionError(err(404, { message: "x" }))).toMatch(/không còn tồn tại/);
    expect(couponActionError(err(500, { message: "Lỗi máy chủ." }))).toBe("Lỗi máy chủ.");
  });
});

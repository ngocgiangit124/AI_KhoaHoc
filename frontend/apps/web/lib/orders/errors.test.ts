import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { describe, expect, it } from "vitest";
import {
  changeReasonText,
  classifyCancelError,
  classifyCartError,
  classifyCheckoutError,
  classifyCouponError,
  classifyOrderLoadError,
  limitResetText,
  mapAddToCartError,
  parseOrderCode,
} from "./errors";
import { preview } from "./fixtures";

// `errors` của lỗi nghiệp vụ chứa chuỗi/số/object (không phải mảng chuỗi) — ép kiểu như JSON thực tế.
const err = (status: number, body: Record<string, unknown>, retry?: number) => new ApiError(status, body as unknown as ConstructorParameters<typeof ApiError>[1], retry);

describe("classifyCheckoutError", () => {
  it("409 PENDING_ORDER_EXISTS đọc errors.* (không phải context.*)", () => {
    const f = classifyCheckoutError(
      err(409, {
        message: "m",
        code: "PENDING_ORDER_EXISTS",
        errors: { order_code: "VV261008K7M2QX", payment_method: "manual", items_count: 2, total: 500000, created_at: "2026-10-08T10:15:00+07:00", expires_at: "2026-10-11T10:15:00+07:00" },
      }),
    );
    expect(f.kind).toBe("pending_exists");
    if (f.kind === "pending_exists") expect(f.conflict).toMatchObject({ order_code: "VV261008K7M2QX", items_count: 2, total: 500000 });
  });

  it("409 PENDING_ORDER_EXISTS thiếu dữ liệu -> thông báo chung, không vỡ", () => {
    expect(classifyCheckoutError(err(409, { message: "Có đơn chờ", code: "PENDING_ORDER_EXISTS", errors: { order_code: "VV1" } }))).toEqual({ kind: "banner", message: "Có đơn chờ" });
  });

  it("409 CHECKOUT_CHANGED lấy preview mới + lý do; preview hỏng -> null", () => {
    const p = preview({ pricing: { subtotal: 600000, discount: 0, total: 600000 } });
    const f = classifyCheckoutError(err(409, { message: "m", code: "CHECKOUT_CHANGED", errors: { reasons: ["COUPON_EXHAUSTED"], preview: p } }));
    expect(f).toMatchObject({ kind: "changed", reasons: ["COUPON_EXHAUSTED"] });
    if (f.kind === "changed") expect(f.preview?.pricing.total).toBe(600000);
    const bad = classifyCheckoutError(err(409, { message: "m", code: "CHECKOUT_CHANGED", errors: { reasons: [], preview: { x: 1 } } }));
    expect(bad).toEqual({ kind: "changed", preview: null, reasons: [] });
  });

  it("429 MANUAL_ORDER_LIMIT lấy message server + errors.resets_at; 429 khác là throttled", () => {
    expect(classifyCheckoutError(err(429, { message: "Quá nhiều", code: "MANUAL_ORDER_LIMIT", errors: { limit: 5, resets_at: "2026-10-09T00:00:00+07:00" } }, 49500))).toEqual({
      kind: "limit",
      message: "Quá nhiều",
      resetsAt: "2026-10-09T00:00:00+07:00",
    });
    expect(classifyCheckoutError(err(429, { message: "x", code: "TOO_MANY_ATTEMPTS" }, 30)).kind).toBe("throttled");
  });

  it("503 PAYMENT_DISABLED, 422 CART_EMPTY, 403 ACCOUNT_NOT_VERIFIED", () => {
    expect(classifyCheckoutError(err(503, { message: "Tạm khoá", code: "PAYMENT_DISABLED" }))).toEqual({ kind: "disabled", message: "Tạm khoá" });
    expect(classifyCheckoutError(err(422, { message: "m", code: "CART_EMPTY" }))).toEqual({ kind: "cart_empty" });
    expect(classifyCheckoutError(err(403, { message: "m", code: "ACCOUNT_NOT_VERIFIED" }))).toEqual({ kind: "not_verified" });
  });

  it("422 lỗi field customer_note hiện dưới ô", () => {
    expect(classifyCheckoutError(err(422, { message: "m", code: "VALIDATION_ERROR", errors: { customer_note: ["Ghi chú không hợp lệ"] } }))).toEqual({
      kind: "field",
      field: "customer_note",
      message: "Ghi chú không hợp lệ",
    });
  });

  it("lỗi mạng / 500 -> banner giữ nguyên thông điệp", () => {
    expect(classifyCheckoutError(new NetworkError(new Error("x"))).kind).toBe("banner");
    expect(classifyCheckoutError(err(500, { message: "Lỗi máy chủ" }))).toEqual({ kind: "banner", message: "Lỗi máy chủ" });
  });
});

describe("changeReasonText / limitResetText", () => {
  it("ghép lý do đã biết, bỏ mã lạ, rỗng -> câu chung", () => {
    expect(changeReasonText(["PRICE_CHANGED", "LA_HOAC"])).toBe("Giá một số khóa đã thay đổi.");
    expect(changeReasonText([])).toBe("Giá hoặc nội dung giỏ hàng đã thay đổi.");
  });
  it("giờ VN từ resets_at; thiếu -> rỗng", () => {
    expect(limitResetText("2026-10-09T00:00:00+07:00")).toBe("Từ 00:00, 09/10/2026 bạn có thể đặt lại.");
    expect(limitResetText(null)).toBe("");
  });
});

describe("classifyCancelError", () => {
  it("409 (ALREADY_PROCESSED / ORDER_STATUS_CHANGED / ORDER_NOT_MANUAL) -> conflict, 404 -> not_found", () => {
    for (const code of ["ALREADY_PROCESSED", "ORDER_STATUS_CHANGED", "ORDER_NOT_MANUAL"]) {
      expect(classifyCancelError(err(409, { message: "Đơn đã đổi", code, errors: { status: "paid" } }))).toEqual({ kind: "conflict", message: "Đơn đã đổi" });
    }
    expect(classifyCancelError(err(404, { message: "m", code: "NOT_FOUND" }))).toEqual({ kind: "not_found" });
    expect(classifyCancelError(err(429, { message: "m" }, 60)).kind).toBe("throttled");
    expect(classifyCancelError(err(500, { message: "Lỗi" }))).toEqual({ kind: "banner", message: "Lỗi" });
  });
});

describe("mã giảm giá / giỏ", () => {
  it("422 lấy thông điệp server; 429 kèm thời gian chờ", () => {
    expect(classifyCouponError(err(422, { message: "Mã hết hạn từ server", code: "COUPON_EXPIRED" }))).toBe("Mã hết hạn từ server");
    expect(classifyCouponError(err(422, { message: "m", code: "VALIDATION_ERROR", errors: { code: ["Mã quá dài"] } }))).toBe("Mã quá dài");
    expect(classifyCouponError(err(429, { message: "m", code: "TOO_MANY_ATTEMPTS" }, 900))).toBe("Bạn thao tác quá nhanh. Vui lòng thử lại sau 15 phút.");
    expect(classifyCartError(err(500, { message: "Lỗi" }))).toEqual({ kind: "banner", message: "Lỗi" });
  });
  it("thêm vào giỏ: 409 ALREADY_IN_CART/ALREADY_OWNED có nhánh riêng", () => {
    expect(mapAddToCartError(err(409, { message: "m", code: "ALREADY_IN_CART" }))).toEqual({ type: "in_cart" });
    expect(mapAddToCartError(err(409, { message: "m", code: "ALREADY_OWNED" }))).toEqual({ type: "owned" });
    expect(mapAddToCartError(err(422, { message: "m", code: "VALIDATION_ERROR", errors: { course_id: ["Khóa miễn phí"] } }))).toEqual({ type: "message", message: "Khóa miễn phí" });
  });
});

describe("classifyOrderLoadError / parseOrderCode", () => {
  it("phân loại theo status; 403 ACCOUNT_NOT_VERIFIED tách riêng", () => {
    expect(classifyOrderLoadError(err(404, { message: "m" }))).toBe("not_found");
    expect(classifyOrderLoadError(err(403, { message: "m", code: "ACCOUNT_NOT_VERIFIED" }))).toBe("not_verified");
    expect(classifyOrderLoadError(err(403, { message: "m" }))).toBe("forbidden");
    expect(classifyOrderLoadError(err(401, { message: "m" }))).toBe("session");
    expect(classifyOrderLoadError(new NetworkError(new Error("x")))).toBe("error");
  });
  it("mã đơn trong URL: chỉ chữ+số 6–32 ký tự, chuẩn hoá hoa", () => {
    expect(parseOrderCode("vv261008k7m2qx")).toBe("VV261008K7M2QX");
    expect(parseOrderCode("../etc")).toBeNull();
    expect(parseOrderCode("ab")).toBeNull();
  });
});

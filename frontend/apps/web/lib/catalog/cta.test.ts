import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { describe, expect, it } from "vitest";
import { learnHref, mapFreeEnrollError, resolveCta, viewerFromOwnership, type CtaInput } from "./cta";

const base: CtaInput = { auth: "user", viewerStatus: "ready", viewer: null, isFree: false, courseId: 7, enrolling: false, paidCheckoutEnabled: true };
const viewer = (state: "owned" | "pending_approval" | "can_register_free" | "can_buy" | "in_cart", resume: number | null = null) => ({
  ...base,
  viewer: { viewer_state: state, resume_lesson_id: resume },
});

describe("resolveCta", () => {
  it("đang xác định tài khoản/viewer-state -> skeleton (không nháy sai nhãn)", () => {
    expect(resolveCta({ ...base, auth: "loading" }).kind).toBe("skeleton");
    expect(resolveCta({ ...base, viewerStatus: "loading" }).kind).toBe("skeleton");
    expect(resolveCta({ ...base, viewerStatus: "idle" }).kind).toBe("skeleton");
  });

  it("khách: nhãn theo giá, bấm đi đăng nhập", () => {
    expect(resolveCta({ ...base, auth: "guest", isFree: false })).toEqual({ kind: "login", label: "Mua khóa học" });
    expect(resolveCta({ ...base, auth: "guest", isFree: true })).toEqual({ kind: "login", label: "Đăng ký học miễn phí" });
  });

  it("lỗi /auth/me hoặc viewer-state -> retry, không coi là khách", () => {
    expect(resolveCta({ ...base, auth: "error" }).kind).toBe("retry");
    expect(resolveCta({ ...base, viewerStatus: "error" }).kind).toBe("retry");
  });

  it("owned -> Tiếp tục học với bài gần nhất, hoặc trang khóa khi chưa có bài", () => {
    expect(resolveCta(viewer("owned", 42))).toEqual({ kind: "owned", label: "Tiếp tục học", href: "/hoc/7/bai/42" });
    expect(learnHref(7, null)).toBe("/hoc/7");
  });

  it("pending_approval, can_register_free, can_buy, in_cart (thanh toán bật)", () => {
    expect(resolveCta(viewer("pending_approval")).kind).toBe("pending");
    expect(resolveCta({ ...viewer("can_register_free"), enrolling: true })).toEqual({
      kind: "register_free",
      label: "Đăng ký học miễn phí",
      busy: true,
    });
    expect(resolveCta(viewer("can_buy"))).toEqual({ kind: "add_to_cart", label: "Thêm vào giỏ", busy: false });
    expect(resolveCta({ ...viewer("can_buy"), addingToCart: true })).toEqual({ kind: "add_to_cart", label: "Thêm vào giỏ", busy: true });
    expect(resolveCta(viewer("in_cart"))).toEqual({ kind: "in_cart", label: "Xem giỏ hàng", href: "/gio-hang" });
  });

  describe("paid_checkout_enabled = false (thanh toán tạm khoá)", () => {
    const off = { paidCheckoutEnabled: false };

    it("khóa có phí: khách, can_buy, in_cart đều là 'Sắp mở bán', không có nút mua/đăng nhập", () => {
      expect(resolveCta({ ...base, ...off, auth: "guest", isFree: false })).toEqual({ kind: "coming_soon" });
      expect(resolveCta({ ...viewer("can_buy"), ...off })).toEqual({ kind: "coming_soon" });
      expect(resolveCta({ ...viewer("in_cart"), ...off })).toEqual({ kind: "coming_soon" });
    });

    it("khóa miễn phí không đổi; đã sở hữu vẫn Tiếp tục học", () => {
      expect(resolveCta({ ...base, ...off, auth: "guest", isFree: true }).kind).toBe("login");
      expect(resolveCta({ ...viewer("can_register_free"), ...off }).kind).toBe("register_free");
      expect(resolveCta({ ...viewer("pending_approval"), ...off }).kind).toBe("pending");
      expect(resolveCta({ ...viewer("owned", 5), ...off })).toEqual({ kind: "owned", label: "Tiếp tục học", href: "/hoc/7/bai/5" });
    });
  });
});

describe("mapFreeEnrollError", () => {
  it("403 ACCOUNT_NOT_VERIFIED -> dẫn sang xác thực", () => {
    expect(mapFreeEnrollError(new ApiError(403, { message: "x", code: "ACCOUNT_NOT_VERIFIED" }))).toEqual({ type: "verify_account" });
  });
  it("mã cũ 403 PARENT_CONSENT_REQUIRED (không còn phát, ADR-006) -> thông điệp chung, không có màn chờ phụ huynh", () => {
    const r = mapFreeEnrollError(new ApiError(403, { message: "x", code: "PARENT_CONSENT_REQUIRED" }));
    expect(r.type).toBe("message");
  });
  it("409 ENROLLMENT_PENDING / ALREADY_OWNED", () => {
    expect(mapFreeEnrollError(new ApiError(409, { message: "x", code: "ENROLLMENT_PENDING" })).type).toBe("pending");
    expect(mapFreeEnrollError(new ApiError(409, { message: "x", code: "ALREADY_OWNED" })).type).toBe("owned");
  });
  it("422 COURSE_NOT_FREE -> tải lại trạng thái", () => {
    expect(mapFreeEnrollError(new ApiError(422, { message: "x", code: "COURSE_NOT_FREE" })).type).toBe("refresh");
  });
  it("lỗi mạng và lỗi lạ -> thông điệp", () => {
    expect(mapFreeEnrollError(new NetworkError(new Error("x"))).type).toBe("message");
    expect(mapFreeEnrollError(new ApiError(500, { message: "x" })).type).toBe("message");
    expect(mapFreeEnrollError(new Error("?")).type).toBe("message");
  });
});

describe("viewerFromOwnership (thẻ khóa)", () => {
  const own = { ownedIds: new Set([1]), pendingIds: new Set([2]), cartIds: new Set([1, 3]) };
  it("ưu tiên owned > pending_approval > in_cart > can_buy như server", () => {
    expect(viewerFromOwnership(1, own).viewer_state).toBe("owned");
    expect(viewerFromOwnership(2, own).viewer_state).toBe("pending_approval");
    expect(viewerFromOwnership(3, own).viewer_state).toBe("in_cart");
    expect(viewerFromOwnership(4, own).viewer_state).toBe("can_buy");
  });
  it("đi qua resolveCta: can_buy -> thêm vào giỏ, in_cart -> xem giỏ, owned -> không phải nút mua", () => {
    const m = (id: number) => resolveCta({ ...base, courseId: id, viewer: viewerFromOwnership(id, own) }).kind;
    expect(m(4)).toBe("add_to_cart");
    expect(m(3)).toBe("in_cart");
    expect(m(1)).toBe("owned");
  });
});

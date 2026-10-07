import { describe, expect, it } from "vitest";
import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { classifyPasswordError, loginErrorMessage, loginNotice, mfaErrorMessage } from "./errors";
import { isIdleExpired, readIdleLimitMs, readLastActivity, STAFF_IDLE_LIMIT_MS } from "./idle";
import { maskLogin } from "./mfaHint";
import { loginSchema, passwordChangeSchema, zodFieldErrors } from "./schemas";
import { isNavActive, navForUser, safeNext } from "@/lib/nav";

const err = (status: number, body: Record<string, unknown>, retry?: number) =>
  new ApiError(status, { message: "msg server", ...body }, retry);

describe("schemas", () => {
  it("login bắt buộc cả hai ô", () => {
    const r = loginSchema.safeParse({ login: "  ", password: "" });
    expect(r.success).toBe(false);
    if (!r.success) expect(Object.keys(zodFieldErrors(r.error)).sort()).toEqual(["login", "password"]);
  });
  it("đổi mật khẩu staff: min 12, max 128, xác nhận khớp", () => {
    expect(passwordChangeSchema.safeParse({ current_password: "cu", password: "short", password_confirmation: "short" }).success).toBe(false);
    // 11 ký tự: chưa đủ (Bảo mật cụm 1); đúng 12 ký tự: đạt.
    expect(passwordChangeSchema.safeParse({ current_password: "cu", password: "x".repeat(11), password_confirmation: "x".repeat(11) }).success).toBe(false);
    expect(passwordChangeSchema.safeParse({ current_password: "cu", password: "x".repeat(12), password_confirmation: "x".repeat(12) }).success).toBe(true);
    expect(passwordChangeSchema.safeParse({ current_password: "cu", password: "x".repeat(129), password_confirmation: "x".repeat(129) }).success).toBe(false);
    const mismatch = passwordChangeSchema.safeParse({ current_password: "cu", password: "matkhau12345678", password_confirmation: "khac123456789" });
    expect(mismatch.success).toBe(false);
    if (!mismatch.success) expect(zodFieldErrors(mismatch.error).password_confirmation).toMatch(/không khớp/);
    expect(passwordChangeSchema.safeParse({ current_password: "cu", password: "matkhau12345678", password_confirmation: "matkhau12345678" }).success).toBe(true);
  });
});

describe("errors", () => {
  it("WRONG_PORTAL và ACCOUNT_LOCKED dùng thông điệp thiết kế", () => {
    expect(loginErrorMessage(err(403, { code: "WRONG_PORTAL" }))).toBe("Vui lòng đăng nhập tại trang dành cho bạn.");
    expect(loginErrorMessage(err(403, { code: "ACCOUNT_LOCKED" }))).toMatch(/bị khóa/);
  });
  it("422 đăng nhập không lộ field; 429 dùng thông điệp server", () => {
    expect(loginErrorMessage(err(422, { errors: { login: ["x"] } }))).toBe("Thông tin đăng nhập hoặc mật khẩu không đúng");
    expect(loginErrorMessage(err(429, { code: "TOO_MANY_ATTEMPTS" }, 30))).toBe("msg server");
    expect(loginErrorMessage(new NetworkError(null))).toMatch(/kết nối/);
  });
  it("MFA: 422 lấy field code, 429 báo sai quá nhiều", () => {
    expect(mfaErrorMessage(err(422, { errors: { code: ["Mã đã hết hạn"] } }))).toBe("Mã đã hết hạn");
    expect(mfaErrorMessage(err(422, { errors: { code: ["Mã OTP đã hết hạn. Bấm 'Gửi lại mã' để nhận mã mới."] } }))).toBe("Mã OTP đã hết hạn. Bấm 'Gửi lại mã' để nhận mã mới.");
    expect(mfaErrorMessage(err(422, {}))).toBe("Mã xác nhận không đúng, vui lòng thử lại.");
    expect(mfaErrorMessage(err(429, { code: "TOO_MANY_ATTEMPTS" }))).toMatch(/quá nhiều lần/);
  });
  it("đổi mật khẩu: field lạ gom vào banner, không bị nuốt", () => {
    const f = classifyPasswordError(
      err(422, { errors: { password: ["Quá yếu"], device_id: ["Cần mật khẩu hiện tại"] } }),
    );
    expect(f).toEqual({ kind: "fields", fields: { password: "Quá yếu" }, banner: "Cần mật khẩu hiện tại" });
  });
  it("loginNotice chỉ nhận reason trong allowlist", () => {
    expect(loginNotice("idle")?.message).toMatch(/không hoạt động/);
    expect(loginNotice("<script>")).toBeNull();
    expect(loginNotice(null)).toBeNull();
  });
});

describe("idle", () => {
  it("hết hạn đúng ở mốc 120 phút", () => {
    expect(STAFF_IDLE_LIMIT_MS).toBe(7_200_000);
    expect(isIdleExpired(0, STAFF_IDLE_LIMIT_MS - 1)).toBe(false);
    expect(isIdleExpired(0, STAFF_IDLE_LIMIT_MS)).toBe(true);
  });
  it("đọc mốc lỗi/thiếu thì dùng fallback", () => {
    expect(readLastActivity({ getItem: () => null }, 5)).toBe(5);
    expect(readLastActivity({ getItem: () => "abc" }, 5)).toBe(5);
    expect(readLastActivity({ getItem: () => "100" }, 5)).toBe(100);
    expect(readLastActivity({ getItem: () => { throw new Error("blocked"); } }, 5)).toBe(5);
  });
});

describe("idle limit từ API", () => {
  it("đọc phút đã lưu, thiếu/lỗi → 120 phút", () => {
    expect(readIdleLimitMs({ getItem: () => "30" })).toBe(1_800_000);
    expect(readIdleLimitMs({ getItem: () => null })).toBe(STAFF_IDLE_LIMIT_MS);
    expect(readIdleLimitMs({ getItem: () => "abc" })).toBe(STAFF_IDLE_LIMIT_MS);
  });
});

describe("safeNext", () => {
  it("chặn trang auth và URL ngoài site", () => {
    expect(safeNext("/dang-nhap")).toBe("/quan-tri");
    expect(safeNext("/xac-thuc-mfa?next=/x")).toBe("/quan-tri");
    expect(safeNext("/doi-mat-khau")).toBe("/quan-tri");
    expect(safeNext("//evil.com")).toBe("/quan-tri");
    expect(safeNext("/quan-tri/khoa-hoc")).toBe("/quan-tri/khoa-hoc");
    expect(safeNext(null)).toBe("/quan-tri");
  });
});

describe("maskLogin", () => {
  it("che email và số điện thoại", () => {
    expect(maskLogin("admin@vitaminvui.vn")).toBe("a****@vitaminvui.vn");
    expect(maskLogin("0912345678")).toBe("*******678");
    expect(maskLogin("ab")).toBe("***");
  });
});

describe("menu theo vai trò", () => {
  const labels = (role: "admin" | "quan_ly_trang" | "giao_vien") => navForUser({ role, permissions: null }).map((i) => i.href);
  it("admin thấy tài khoản staff và nhật ký", () => {
    expect(labels("admin")).toEqual(expect.arrayContaining(["/quan-tri/tai-khoan", "/quan-tri/nhat-ky", "/quan-tri/don-hang"]));
  });
  it("quản lý trang không thấy tài khoản/nhật ký nhưng thấy đơn hàng, mã giảm giá", () => {
    const r = labels("quan_ly_trang");
    expect(r).not.toContain("/quan-tri/tai-khoan");
    expect(r).not.toContain("/quan-tri/nhat-ky");
    expect(r).toEqual(expect.arrayContaining(["/quan-tri/don-hang", "/quan-tri/ma-giam-gia", "/quan-tri/chuyen-de"]));
  });
  it("giáo viên chỉ thấy tổng quan, khóa học, duyệt đăng ký và hồ sơ của tôi", () => {
    expect(labels("giao_vien")).toEqual(["/quan-tri", "/quan-tri/khoa-hoc", "/quan-tri/duyet-dang-ky", "/quan-tri/ho-so"]);
  });
  it("permissions từ API ưu tiên hơn role", () => {
    const r = navForUser({ role: "quan_ly_trang", permissions: { manage_system: true, view_orders: false } }).map((i) => i.href);
    expect(r).toContain("/quan-tri/tai-khoan");
    expect(r).not.toContain("/quan-tri/don-hang");
    expect(r).toContain("/quan-tri/ma-giam-gia"); // permission thiếu → rơi về role
  });
  it("isNavActive: tổng quan chỉ khớp chính xác", () => {
    expect(isNavActive("/quan-tri", "/quan-tri/khoa-hoc")).toBe(false);
    expect(isNavActive("/quan-tri/khoa-hoc", "/quan-tri/khoa-hoc/tao")).toBe(true);
  });
});

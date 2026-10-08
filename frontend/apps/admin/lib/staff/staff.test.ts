import { describe, expect, it } from "vitest";
import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { classifyCreateError, isStale, staffActionError } from "./errors";
import { EMPTY_CREATE, validateCreate } from "./form";
import { canManageStaff } from "./permissions";
import { parseStaffQuery, staffQueryToApi, staffQueryToSearch } from "./query";

const params = (s: string) => new URLSearchParams(s);
const api = (status: number, body: Record<string, unknown>, retry?: number) => new ApiError(status, body as never, retry);

describe("query", () => {
  it("giá trị lạ rơi về mặc định", () => {
    expect(parseStaffQuery(params("role=hoc_sinh&status=x&per_page=7&page=abc"))).toEqual({ q: "", role: "", status: "", page: 1, perPage: 25 });
  });
  it("đọc và ghi lại bộ lọc, bỏ tham số mặc định", () => {
    const q = parseStaffQuery(params("q=%20an%20&role=giao_vien&status=locked&per_page=50&page=3"));
    expect(q).toEqual({ q: "an", role: "giao_vien", status: "locked", page: 3, perPage: 50 });
    expect(staffQueryToSearch(q)).toBe("?q=an&role=giao_vien&status=locked&per_page=50&page=3");
    expect(staffQueryToSearch({ ...EMPTY_Q })).toBe("");
    expect(staffQueryToApi({ ...EMPTY_Q, role: "admin" })).toBe("role=admin&per_page=25&page=1");
  });
  it("cắt từ khoá tối đa 100 ký tự", () => {
    expect(parseStaffQuery(params(`q=${"a".repeat(150)}`)).q).toHaveLength(100);
  });
});
const EMPTY_Q = { q: "", role: "", status: "", page: 1, perPage: 25 } as const;

describe("permissions", () => {
  it("chỉ admin; permissions.manage_system ưu tiên", () => {
    expect(canManageStaff({ role: "admin", permissions: null })).toBe(true);
    expect(canManageStaff({ role: "quan_ly_trang", permissions: null })).toBe(false);
    expect(canManageStaff({ role: "giao_vien", permissions: null })).toBe(false);
    expect(canManageStaff({ role: "quan_ly_trang", permissions: { manage_system: false } })).toBe(false);
    expect(canManageStaff({ role: "admin", permissions: { manage_system: false } })).toBe(false);
  });
});

describe("validateCreate", () => {
  it("báo thiếu từng ô", () => {
    expect(Object.keys(validateCreate(EMPTY_CREATE))).toEqual(["name", "email", "role"]);
  });
  it("email sai, tên quá dài", () => {
    const e = validateCreate({ name: "a".repeat(101), email: "abc", role: "giao_vien" });
    expect(e.name).toMatch(/100/);
    expect(e.email).toBe("Email không hợp lệ.");
    expect(e.role).toBeUndefined();
  });
  it("hợp lệ, và không nhận vai trò học sinh", () => {
    expect(validateCreate({ name: " An ", email: "an@example.com", role: "quan_ly_trang" })).toEqual({});
    expect(validateCreate({ name: "An", email: "an@example.com", role: "hoc_sinh" as never }).role).toBeDefined();
  });
});

describe("errors", () => {
  it("422: lỗi về đúng ô, ô lạ vào banner", () => {
    const f = classifyCreateError(api(422, { message: "x", errors: { email: ["Email đã được sử dụng."], role: ["Vai trò không hợp lệ."], foo: ["Lạ"] } }));
    expect(f.fields).toEqual({ email: "Email đã được sử dụng.", role: "Vai trò không hợp lệ." });
    expect(f.banner).toBe("Lạ");
  });
  it("422 không có errors / 403 / 429 / mạng", () => {
    expect(classifyCreateError(api(422, { message: "Dữ liệu sai" })).banner).toBe("Dữ liệu sai");
    expect(classifyCreateError(api(403, { message: "m" })).banner).toMatch(/không có quyền/);
    expect(classifyCreateError(api(429, { message: "m" }, 12)).banner).toMatch(/12 giây/);
    expect(classifyCreateError(new NetworkError(new Error("x"))).banner).toMatch(/kết nối/);
  });
  it("hành động: 409 dùng nguyên văn server; 404/403/429", () => {
    expect(staffActionError(api(409, { message: "Bạn không thể tự khóa tài khoản của chính mình.", code: "CANNOT_MODIFY_SELF" }))).toBe("Bạn không thể tự khóa tài khoản của chính mình.");
    expect(staffActionError(api(409, { message: "Không thể khóa tài khoản quản trị viên đang hoạt động cuối cùng.", code: "LAST_ADMIN" }))).toMatch(/cuối cùng/);
    expect(staffActionError(api(404, { message: "x" }))).toMatch(/không còn tồn tại/);
    expect(staffActionError(api(403, { message: "x" }))).toMatch(/không có quyền/);
    expect(staffActionError(api(429, { message: "x" }))).toMatch(/quá nhanh/);
  });
  it("isStale: 404 và ALREADY_PROCESSED", () => {
    expect(isStale(api(404, { message: "x" }))).toBe(true);
    expect(isStale(api(409, { message: "x", code: "ALREADY_PROCESSED" }))).toBe(true);
    expect(isStale(api(409, { message: "x", code: "LAST_ADMIN" }))).toBe(false);
  });
});

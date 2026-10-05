import { afterEach, describe, expect, it, vi } from "vitest";
import { fetchSession, parseStaffUser } from "./session";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_ADMIN_API_URL: "http://admin-api.test" } }));

function mockFetch(status: number, body: unknown) {
  vi.stubGlobal(
    "fetch",
    vi.fn().mockResolvedValue({
      status,
      ok: status >= 200 && status < 300,
      json: () => Promise.resolve(body),
    }),
  );
}

afterEach(() => vi.unstubAllGlobals());

describe("parseStaffUser", () => {
  it("nhận user phẳng hoặc bọc trong `user`", () => {
    expect(parseStaffUser({ id: 1, name: "A", email: "a@x.vn", role: "admin" })).toEqual({ id: 1, name: "A", email: "a@x.vn", role: "admin", permissions: null, mustChangePassword: false, session: null });
    expect(parseStaffUser({ user: { id: "2", name: "B", role: "giao_vien" } })?.role).toBe("giao_vien");
  });
  it("đọc permissions boolean, bỏ giá trị lạ", () => {
    const u = parseStaffUser({ role: "admin", permissions: { manage_system: true, view_orders: "yes" } });
    expect(u?.permissions).toEqual({ manage_system: true });
  });
  it("đọc must_change_password và session như StaffUserResource của backend", () => {
    const u = parseStaffUser({
      id: 3, name: "GV", email: "gv@x.vn", role: "giao_vien", must_change_password: true,
      permissions: { manage_system: false },
      session: { idle_timeout_minutes: 45, expires_at: "2026-10-07T20:00:00+07:00" },
    });
    expect(u?.mustChangePassword).toBe(true);
    expect(u?.session).toEqual({ idleTimeoutMinutes: 45, expiresAt: "2026-10-07T20:00:00+07:00" });
  });
  it("role lạ (kể cả hoc_sinh) hoặc sai shape → null", () => {
    expect(parseStaffUser({ role: "hoc_sinh" })).toBeNull();
    expect(parseStaffUser(null)).toBeNull();
    expect(parseStaffUser("x")).toBeNull();
  });
  it("thiếu tên thì dùng email", () => {
    expect(parseStaffUser({ role: "admin", email: "a@x.vn" })?.name).toBe("a@x.vn");
  });
});

describe("fetchSession", () => {
  it("200 → staff", async () => {
    mockFetch(200, { id: 1, name: "A", role: "quan_ly_trang" });
    expect(await fetchSession()).toMatchObject({ kind: "staff", user: { role: "quan_ly_trang" } });
  });
  it("401 STAFF_IDLE_TIMEOUT → guest lý do idle; 401 khác → guest", async () => {
    mockFetch(401, { code: "STAFF_IDLE_TIMEOUT" });
    expect(await fetchSession()).toEqual({ kind: "guest", reason: "idle" });
    mockFetch(401, { code: "UNAUTHENTICATED" });
    expect(await fetchSession()).toEqual({ kind: "guest", reason: null });
  });
  it("403 phân loại theo code", async () => {
    mockFetch(403, { code: "MFA_REQUIRED" });
    expect((await fetchSession()).kind).toBe("mfa_required");
    mockFetch(403, { code: "PASSWORD_CHANGE_REQUIRED" });
    expect((await fetchSession()).kind).toBe("password_change_required");
    mockFetch(403, { code: "ACCOUNT_LOCKED" });
    expect((await fetchSession()).kind).toBe("locked");
    mockFetch(403, { code: "ORIGIN_NOT_ALLOWED" });
    expect((await fetchSession()).kind).toBe("error");
  });
  it("5xx, body lạ, lỗi mạng → error (không bao giờ là guest)", async () => {
    mockFetch(500, {});
    expect((await fetchSession()).kind).toBe("error");
    mockFetch(200, { hello: 1 });
    expect((await fetchSession()).kind).toBe("error");
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new TypeError("network")));
    expect((await fetchSession()).kind).toBe("error");
  });
});

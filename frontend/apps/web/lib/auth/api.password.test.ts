import { beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_API_URL: "http://api.test" } }));
const authFetch = vi.fn();
vi.mock("@/lib/api", () => ({ authFetch: (...a: unknown[]) => authFetch(...a) }));
const dispatchAuthEventIfNeeded = vi.fn();
vi.mock("@vitaminvui/api-client", async (orig) => ({
  ...(await orig<typeof import("@vitaminvui/api-client")>()),
  dispatchAuthEventIfNeeded: (...a: unknown[]) => dispatchAuthEventIfNeeded(...a),
  getDeviceId: () => "dev-1",
}));

import { changePassword, fetchCurrentUser, forgotPassword, resetPassword } from "./api";

function bodyOf(call: number): Record<string, unknown> {
  const opts = authFetch.mock.calls[call]?.[1] as { body: string };
  return JSON.parse(opts.body) as Record<string, unknown>;
}

describe("quên/đổi mật khẩu (API T27)", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("forgotPassword: gửi login đã cắt khoảng trắng + captcha_token khi có; đọc message và resend_available_at", async () => {
    authFetch.mockResolvedValue({ message: "Nếu thông tin tồn tại…", resend_available_at: "2026-10-07T10:00:00Z" });
    const r = await forgotPassword({ login: "  a@x.vn ", captchaToken: "tok" });
    expect(authFetch).toHaveBeenCalledWith("/api/v1/auth/password/forgot", expect.objectContaining({ method: "POST" }));
    expect(bodyOf(0)).toEqual({ login: "a@x.vn", captcha_token: "tok" });
    expect(r).toEqual({ message: "Nếu thông tin tồn tại…", resendAvailableAt: "2026-10-07T10:00:00Z" });
  });

  it("forgotPassword: không captcha thì không gửi captcha_token; response lạ → null", async () => {
    authFetch.mockResolvedValue(null);
    const r = await forgotPassword({ login: "a@x.vn", captchaToken: null });
    expect(bodyOf(0)).toEqual({ login: "a@x.vn" });
    expect(r).toEqual({ message: null, resendAvailableAt: null });
  });

  it("resetPassword: POST đủ login/code/password/password_confirmation", async () => {
    authFetch.mockResolvedValue({ message: "ok" });
    await resetPassword({ login: " a@x.vn", code: "123456", password: "matkhau-moi", password_confirmation: "matkhau-moi" });
    expect(authFetch).toHaveBeenCalledWith("/api/v1/auth/password/reset", expect.objectContaining({ method: "POST" }));
    expect(bodyOf(0)).toEqual({ login: "a@x.vn", code: "123456", password: "matkhau-moi", password_confirmation: "matkhau-moi" });
  });

  it("changePassword: PUT; session_kept=false được trả về", async () => {
    authFetch.mockResolvedValue({ message: "ok", session_kept: false });
    expect(await changePassword({ current_password: "a", password: "b", password_confirmation: "b" })).toEqual({ sessionKept: false });
    expect(authFetch).toHaveBeenCalledWith("/api/v1/auth/password", expect.objectContaining({ method: "PUT" }));
    authFetch.mockResolvedValue({ message: "ok", session_kept: true });
    expect(await changePassword({ current_password: "a", password: "b", password_confirmation: "b" })).toEqual({ sessionKept: true });
  });
});

describe("fetchCurrentUser: phát sự kiện phiên", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  function mockMe(status: number, body: unknown) {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue({ status, ok: status < 400, json: () => Promise.resolve(body) }));
  }

  it("401 SESSION_REPLACED và SESSION_REVOKED → phát sự kiện (hộp thoại báo lý do), vẫn trả khách", async () => {
    for (const code of ["SESSION_REPLACED", "SESSION_REVOKED"]) {
      mockMe(401, { code });
      expect(await fetchCurrentUser()).toEqual({ kind: "guest" });
      expect(dispatchAuthEventIfNeeded).toHaveBeenLastCalledWith(code);
    }
  });

  it("401 UNAUTHENTICATED / SESSION_EXPIRED → khách bình thường, KHÔNG phát sự kiện (không đá khách khỏi trang công khai)", async () => {
    for (const code of ["UNAUTHENTICATED", "SESSION_EXPIRED"]) {
      mockMe(401, { code });
      expect(await fetchCurrentUser()).toEqual({ kind: "guest" });
    }
    expect(dispatchAuthEventIfNeeded).not.toHaveBeenCalled();
  });
});

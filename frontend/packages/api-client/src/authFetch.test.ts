import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { authFetch } from "./authFetch";
import { clearCsrfToken } from "./csrfToken";
import { ApiError, NetworkError, FORCED_LOGOUT_EVENT, LOGIN_REQUIRED_EVENT } from "./errors";

const BASE_URL = "http://api.localhost:8000";

function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

describe("authFetch", () => {
  beforeEach(() => {
    clearCsrfToken(BASE_URL);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("GET không cần CSRF, không gọi /csrf-token", async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse(200, { hello: "world" }));
    vi.stubGlobal("fetch", fetchMock);

    const result = await authFetch<{ hello: string }>(BASE_URL, "/api/v1/auth/me");

    expect(result).toEqual({ hello: "world" });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(init.credentials).toBe("include");
    expect(init.cache).toBe("no-store");
  });

  it("419 khi ghi dữ liệu: lấy lại CSRF rồi thử lại đúng 1 lần rồi thành công", async () => {
    const fetchMock = vi
      .fn()
      // 1) lấy csrf-token lần đầu
      .mockResolvedValueOnce(jsonResponse(200, { token: "csrf-1" }))
      // 2) POST đầu tiên -> 419 (hết hạn)
      .mockResolvedValueOnce(jsonResponse(419, { message: "CSRF token mismatch" }))
      // 3) lấy lại csrf-token
      .mockResolvedValueOnce(jsonResponse(200, { token: "csrf-2" }))
      // 4) POST thử lại -> thành công
      .mockResolvedValueOnce(jsonResponse(201, { id: 1 }));
    vi.stubGlobal("fetch", fetchMock);

    const result = await authFetch<{ id: number }>(BASE_URL, "/api/v1/cart/items", {
      method: "POST",
      body: JSON.stringify({ course_id: 1 }),
    });

    expect(result).toEqual({ id: 1 });
    expect(fetchMock).toHaveBeenCalledTimes(4);

    const secondCallHeaders = new Headers((fetchMock.mock.calls[1] as [string, RequestInit])[1].headers);
    expect(secondCallHeaders.get("X-CSRF-TOKEN")).toBe("csrf-1");
    const fourthCallHeaders = new Headers((fetchMock.mock.calls[3] as [string, RequestInit])[1].headers);
    expect(fourthCallHeaders.get("X-CSRF-TOKEN")).toBe("csrf-2");
  });

  it("419 hai lần liên tiếp: chỉ thử lại 1 lần rồi ném lỗi (không lặp vô hạn)", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse(200, { token: "csrf-1" }))
      .mockResolvedValueOnce(jsonResponse(419, { message: "CSRF token mismatch" }))
      .mockResolvedValueOnce(jsonResponse(200, { token: "csrf-2" }))
      .mockResolvedValueOnce(jsonResponse(419, { message: "CSRF token mismatch" }));
    vi.stubGlobal("fetch", fetchMock);

    await expect(
      authFetch(BASE_URL, "/api/v1/cart/items", { method: "POST" }),
    ).rejects.toBeInstanceOf(ApiError);
    expect(fetchMock).toHaveBeenCalledTimes(4);
  });

  it("SESSION_REPLACED phát sự kiện forced-logout và ném ApiError", async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse(401, { message: "Phiên đã bị thay thế", code: "SESSION_REPLACED" }),
    );
    vi.stubGlobal("fetch", fetchMock);

    const listener = vi.fn();
    window.addEventListener(FORCED_LOGOUT_EVENT, listener);

    await expect(authFetch(BASE_URL, "/api/v1/auth/me")).rejects.toMatchObject({
      code: "SESSION_REPLACED",
    });
    expect(listener).toHaveBeenCalledTimes(1);

    window.removeEventListener(FORCED_LOGOUT_EVENT, listener);
  });

  it("STAFF_IDLE_TIMEOUT phát sự kiện login-required", async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      jsonResponse(401, { message: "Hết phiên do không hoạt động", code: "STAFF_IDLE_TIMEOUT" }),
    );
    vi.stubGlobal("fetch", fetchMock);

    const listener = vi.fn();
    window.addEventListener(LOGIN_REQUIRED_EVENT, listener);

    await expect(authFetch(BASE_URL, "/api/v1/admin/auth/me")).rejects.toMatchObject({
      code: "STAFF_IDLE_TIMEOUT",
    });
    expect(listener).toHaveBeenCalledTimes(1);

    window.removeEventListener(LOGIN_REQUIRED_EVENT, listener);
  });

  it("lỗi mạng (fetch reject) ném NetworkError, KHÔNG phát sự kiện mất phiên", async () => {
    const fetchMock = vi.fn().mockRejectedValue(new TypeError("Failed to fetch"));
    vi.stubGlobal("fetch", fetchMock);

    const forcedLogout = vi.fn();
    const loginRequired = vi.fn();
    window.addEventListener(FORCED_LOGOUT_EVENT, forcedLogout);
    window.addEventListener(LOGIN_REQUIRED_EVENT, loginRequired);

    await expect(authFetch(BASE_URL, "/api/v1/auth/me")).rejects.toBeInstanceOf(NetworkError);

    expect(forcedLogout).not.toHaveBeenCalled();
    expect(loginRequired).not.toHaveBeenCalled();

    window.removeEventListener(FORCED_LOGOUT_EVENT, forcedLogout);
    window.removeEventListener(LOGIN_REQUIRED_EVENT, loginRequired);
  });
});

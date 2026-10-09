import { describe, expect, it, vi } from "vitest";
import {
  FORCED_LOGOUT_EVENT,
  LOGIN_REQUIRED_EVENT,
  ApiError,
  dispatchAuthEventIfNeeded,
  errorField,
  errorMessages,
  errorString,
} from "./errors";

describe("dispatchAuthEventIfNeeded — ánh xạ mã lỗi (api-contract §1.7)", () => {
  it("SESSION_REPLACED phát sự kiện forced-logout", () => {
    const listener = vi.fn();
    window.addEventListener(FORCED_LOGOUT_EVENT, listener);
    dispatchAuthEventIfNeeded("SESSION_REPLACED");
    expect(listener).toHaveBeenCalledTimes(1);
    window.removeEventListener(FORCED_LOGOUT_EVENT, listener);
  });

  it.each(["SESSION_EXPIRED", "SESSION_REVOKED", "UNAUTHENTICATED", "STAFF_IDLE_TIMEOUT"])(
    "%s phát sự kiện login-required",
    (code) => {
      const listener = vi.fn();
      window.addEventListener(LOGIN_REQUIRED_EVENT, listener);
      dispatchAuthEventIfNeeded(code);
      expect(listener).toHaveBeenCalledTimes(1);
      window.removeEventListener(LOGIN_REQUIRED_EVENT, listener);
    },
  );

  it("mã lỗi nghiệp vụ khác (VALIDATION_ERROR) không phát sự kiện nào", () => {
    const forcedLogout = vi.fn();
    const loginRequired = vi.fn();
    window.addEventListener(FORCED_LOGOUT_EVENT, forcedLogout);
    window.addEventListener(LOGIN_REQUIRED_EVENT, loginRequired);

    dispatchAuthEventIfNeeded("VALIDATION_ERROR");

    expect(forcedLogout).not.toHaveBeenCalled();
    expect(loginRequired).not.toHaveBeenCalled();
    window.removeEventListener(FORCED_LOGOUT_EVENT, forcedLogout);
    window.removeEventListener(LOGIN_REQUIRED_EVENT, loginRequired);
  });

  it("không có code (ví dụ lỗi mạng) thì không phát sự kiện", () => {
    const listener = vi.fn();
    window.addEventListener(LOGIN_REQUIRED_EVENT, listener);
    dispatchAuthEventIfNeeded(undefined);
    expect(listener).not.toHaveBeenCalled();
    window.removeEventListener(LOGIN_REQUIRED_EVENT, listener);
  });
});

describe("errorField / errorString / errorMessages", () => {
  const e = new ApiError(422, {
    message: "x",
    errors: { email: ["Sai email", "khác"], resets_at: "2026-10-09T00:00:00+07:00", items_count: 3, preview: { total: 1 }, rong: "" },
  });
  it("errorField trả nguyên giá trị (chuỗi/số/object/mảng), thiếu -> undefined", () => {
    expect(errorField(e, "items_count")).toBe(3);
    expect(errorField(e, "preview")).toEqual({ total: 1 });
    expect(errorField(e, "khong_co")).toBeUndefined();
    expect(errorField(new ApiError(500, { message: "x" }), "a")).toBeUndefined();
  });
  it("errorString lấy chuỗi hoặc phần tử đầu của mảng chuỗi; bỏ số/object/rỗng", () => {
    expect(errorString(e, "email")).toBe("Sai email");
    expect(errorString(e, "resets_at")).toBe("2026-10-09T00:00:00+07:00");
    expect(errorString(e, "items_count")).toBeUndefined();
    expect(errorString(e, "preview")).toBeUndefined();
    expect(errorString(e, "rong")).toBeUndefined();
  });
  it("errorMessages chỉ giữ mảng chuỗi", () => {
    expect(errorMessages(e)).toEqual({ email: ["Sai email", "khác"] });
    expect(errorMessages(new ApiError(500, { message: "x" }))).toEqual({});
  });
});

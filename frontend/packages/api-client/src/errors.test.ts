import { describe, expect, it, vi } from "vitest";
import {
  FORCED_LOGOUT_EVENT,
  LOGIN_REQUIRED_EVENT,
  dispatchAuthEventIfNeeded,
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

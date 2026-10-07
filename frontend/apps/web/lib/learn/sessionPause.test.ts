import { FORCED_LOGOUT_EVENT, LOGIN_REQUIRED_EVENT } from "@vitaminvui/api-client";
import { describe, expect, it, vi } from "vitest";
import { onSessionEnded } from "./sessionPause";

describe("onSessionEnded", () => {
  it("gọi handler khi có forced-logout hoặc login-required, và huỷ đăng ký được", () => {
    const handler = vi.fn();
    const off = onSessionEnded(handler);
    window.dispatchEvent(new CustomEvent(FORCED_LOGOUT_EVENT, { detail: { code: "SESSION_REPLACED" } }));
    window.dispatchEvent(new CustomEvent(LOGIN_REQUIRED_EVENT, { detail: { code: "SESSION_REVOKED" } }));
    expect(handler).toHaveBeenCalledTimes(2);
    off();
    window.dispatchEvent(new CustomEvent(FORCED_LOGOUT_EVENT));
    expect(handler).toHaveBeenCalledTimes(2);
  });
});

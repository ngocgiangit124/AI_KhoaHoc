import { describe, expect, it, vi } from "vitest";
import { parsePublicConfig } from "./config";

const VALID_RESPONSE = {
  referral_code_enabled: true,
  quiz_time_limit_enabled: true,
  paid_checkout_enabled: false,
  otp: { ttl_minutes: 10, resend_cooldown_seconds: 60 },
  grades: [6, 7, 8, 9, 10, 11, 12],
  captcha_site_key: null,
  policy_version: "2026-09",
  parent_consent_age: 18,
};

describe("parsePublicConfig — validate GET /api/v1/config/public (api-contract §2.1)", () => {
  it("parse thành công đúng ví dụ JSON trong api-contract.md", () => {
    expect(parsePublicConfig(VALID_RESPONSE)).toEqual(VALID_RESPONSE);
  });

  it("thiếu paid_checkout_enabled (backend cũ) -> coi là tắt, không vỡ trang", () => {
    const { paid_checkout_enabled: _omit, ...legacy } = VALID_RESPONSE;
    void _omit;
    expect(parsePublicConfig(legacy).paid_checkout_enabled).toBe(false);
  });

  it("chấp nhận captcha_site_key là string (Turnstile đã cấu hình)", () => {
    const result = parsePublicConfig({ ...VALID_RESPONSE, captcha_site_key: "0x123" });
    expect(result.captcha_site_key).toBe("0x123");
  });

  it("ném lỗi rõ ràng (không phải ZodError thô) khi thiếu field otp.ttl_minutes", () => {
    vi.spyOn(console, "error").mockImplementation(() => {});
    const invalid = { ...VALID_RESPONSE, otp: { resend_cooldown_seconds: 60 } };

    expect(() => parsePublicConfig(invalid)).toThrow(
      /Không đọc được cấu hình công khai/,
    );

    vi.restoreAllMocks();
  });

  it("ném lỗi khi field cũ ttl/cooldown (đã đổi tên) được gửi thay vì ttl_minutes/resend_cooldown_seconds", () => {
    vi.spyOn(console, "error").mockImplementation(() => {});
    const legacyShape = { ...VALID_RESPONSE, otp: { ttl: 300, cooldown: 60 } };

    expect(() => parsePublicConfig(legacyShape)).toThrow();

    vi.restoreAllMocks();
  });

  it("ném lỗi khi grades không phải mảng số", () => {
    vi.spyOn(console, "error").mockImplementation(() => {});
    const invalid = { ...VALID_RESPONSE, grades: "6,7,8" };

    expect(() => parsePublicConfig(invalid)).toThrow();

    vi.restoreAllMocks();
  });
});

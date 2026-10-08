import { describe, expect, it, vi } from "vitest";

vi.mock("@/env", () => ({ env: { NEXT_PUBLIC_API_URL: "http://api.test" } }));
vi.mock("@/lib/api", () => ({ authFetch: vi.fn() }));

import { buildRegisterPayload, parseAuthUser } from "./api";
import type { RegisterFormValues } from "./schemas";

const base: RegisterFormValues = {
  name: " Nguyễn Văn A ",
  date_of_birth: "2000-01-01",
  email: "a@example.com",
  phone: "0912345678",
  grade_level: "9",
  password: "matkhau123",
  password_confirmation: "matkhau123",
  parent_phone: "0987654321",
  parent_email: "",
  referral_code: " ABC ",
  accept_terms: true,
  accept_privacy: true,
};

describe("buildRegisterPayload", () => {
  it("không nhập phụ huynh: không gửi field phụ huynh; referral tắt thì bỏ; không captcha thì bỏ token", () => {
    const p = buildRegisterPayload({
      values: { ...base, parent_phone: "", parent_email: "" },
      captchaToken: null,
      referralEnabled: false,
      deviceId: "dev-1",
    });
    expect(p).toEqual({
      name: "Nguyễn Văn A",
      date_of_birth: "2000-01-01",
      email: "a@example.com",
      phone: "0912345678",
      grade_level: 9,
      password: "matkhau123",
      password_confirmation: "matkhau123",
      accept_terms: true,
      accept_privacy: true,
      device_id: "dev-1",
    });
  });

  it("gửi liên hệ phụ huynh đã nhập (mọi tuổi), bỏ ô trống, referral bật, có captcha", () => {
    const p = buildRegisterPayload({
      values: { ...base, date_of_birth: "2020-01-01" },
      captchaToken: "tok",
      referralEnabled: true,
      deviceId: "dev-1",
    });
    expect(p.parent_phone).toBe("0987654321");
    expect(p.parent_email).toBeUndefined();
    expect(p.referral_code).toBe("ABC");
    expect(p.captcha_token).toBe("tok");
  });
});

describe("parseAuthUser", () => {
  const user = {
    id: 1,
    name: "A",
    email: "a@example.com",
    phone: "0912345678",
    role: "hoc_sinh",
    grade_level: 9,
    is_verified: false,
    parent_consent_status: "not_required",
  };
  it("nhận user đúng contract, kể cả parent_contact đã che và needs_policy_acceptance", () => {
    const u = parseAuthUser({ ...user, parent_contact: { email: "p***@x.vn", phone: null }, needs_policy_acceptance: true });
    expect(u?.parent_contact?.email).toBe("p***@x.vn");
    expect(u?.needs_policy_acceptance).toBe(true);
  });
  it("thiếu field mới (backend cũ) hoặc parent_contact=null vẫn parse được", () => {
    expect(parseAuthUser(user)).not.toBeNull();
    expect(parseAuthUser({ ...user, parent_contact: null })?.parent_contact).toBeNull();
  });
  it("parent_consent_status lạ/cũ (pending) không làm vỡ", () => {
    expect(parseAuthUser({ ...user, parent_consent_status: "pending" })).not.toBeNull();
    const { parent_consent_status: _x, ...rest } = user;
    void _x;
    expect(parseAuthUser(rest)).not.toBeNull();
  });
  it("sai shape → null, không ném lỗi", () => {
    vi.spyOn(console, "error").mockImplementation(() => {});
    expect(parseAuthUser({ ...user, is_verified: "x" })).toBeNull();
    expect(parseAuthUser(undefined)).toBeNull();
  });
});

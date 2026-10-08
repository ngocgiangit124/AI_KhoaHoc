import { describe, expect, it } from "vitest";
import { CONSENT_ERROR_MESSAGE, createRegisterSchema, loginSchema, type RegisterFormValues } from "./schemas";

const schema = createRegisterSchema({ grades: [6, 7, 8, 9, 10, 11, 12], today: "2026-10-05" });

const valid: RegisterFormValues = {
  name: "Nguyễn Văn A",
  date_of_birth: "2000-01-01",
  email: "a@example.com",
  phone: "0912345678",
  grade_level: "9",
  password: "matkhau123",
  password_confirmation: "matkhau123",
  parent_phone: "",
  parent_email: "",
  referral_code: "",
  accept_terms: true,
  accept_privacy: true,
};

function errorsOf(values: Partial<RegisterFormValues>) {
  const r = schema.safeParse({ ...valid, ...values });
  if (r.success) return {};
  const out: Record<string, string> = {};
  for (const i of r.error.issues) out[String(i.path[0])] ??= i.message;
  return out;
}

describe("createRegisterSchema", () => {
  it("chấp nhận dữ liệu hợp lệ của người đủ tuổi (không cần phụ huynh)", () => {
    expect(errorsOf({})).toEqual({});
  });

  it("mật khẩu < 8 ký tự và xác nhận không khớp", () => {
    expect(errorsOf({ password: "123" }).password).toBe("Mật khẩu tối thiểu 8 ký tự");
    expect(errorsOf({ password_confirmation: "khac12345" }).password_confirmation).toBe(
      "Xác nhận mật khẩu không khớp",
    );
  });

  it("SĐT và email sai định dạng", () => {
    expect(errorsOf({ phone: "12345" }).phone).toBe("Số điện thoại không hợp lệ");
    expect(errorsOf({ email: "abc" }).email).toBe("Email không hợp lệ");
  });

  it("phụ huynh TUỲ CHỌN: dưới 18 tuổi để trống cả 2 ô vẫn hợp lệ (ADR-006)", () => {
    const minor = { date_of_birth: "2012-05-01" };
    expect(errorsOf(minor)).toEqual({});
    expect(errorsOf({ ...minor, parent_email: "ph@example.com" })).toEqual({});
    expect(errorsOf({ ...minor, parent_phone: "0912345678" })).toEqual({});
  });

  it("có nhập phụ huynh thì kiểm định dạng, ở mọi độ tuổi", () => {
    expect(errorsOf({ parent_email: "sai" }).parent_email).toBe("Email phụ huynh không hợp lệ");
    expect(errorsOf({ date_of_birth: "2012-05-01", parent_email: "sai" }).parent_email).toBe("Email phụ huynh không hợp lệ");
    expect(errorsOf({ parent_phone: "123" }).parent_phone).toBe("Số điện thoại phụ huynh không hợp lệ");
  });

  it("thiếu 1 trong 2 checkbox → thông điệp đồng ý", () => {
    expect(errorsOf({ accept_privacy: false }).accept_terms).toBe(CONSENT_ERROR_MESSAGE);
    expect(errorsOf({ accept_terms: false }).accept_terms).toBe(CONSENT_ERROR_MESSAGE);
  });

  it("lớp ngoài danh sách và ngày sinh tương lai", () => {
    expect(errorsOf({ grade_level: "5" }).grade_level).toBe("Lớp không hợp lệ");
    expect(errorsOf({ date_of_birth: "2030-01-01" }).date_of_birth).toBe("Ngày sinh không hợp lệ");
  });
});

describe("loginSchema", () => {
  it("bắt buộc cả 2 field", () => {
    expect(loginSchema.safeParse({ login: " ", password: "" }).success).toBe(false);
    expect(loginSchema.safeParse({ login: "a@b.vn", password: "x" }).success).toBe(true);
  });
});

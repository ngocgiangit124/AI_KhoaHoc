import { ApiError, NetworkError } from "@vitaminvui/api-client";

export const LOGIN_GENERIC_ERROR = "Thông tin đăng nhập hoặc mật khẩu không đúng";
export const ACCOUNT_LOCKED_MESSAGE = "Tài khoản của bạn đã bị khoá. Vui lòng liên hệ hỗ trợ.";
export const CAPTCHA_FAILED_MESSAGE = "Xác minh chống spam thất bại, vui lòng thử lại";
export const UNKNOWN_ERROR_MESSAGE = "Đã có lỗi xảy ra, vui lòng thử lại sau.";

/** Thông điệp banner cho lỗi đăng nhập (design US-001 §3, api-contract §1.7). */
export function loginErrorMessage(err: unknown): string {
  if (err instanceof NetworkError) return err.message;
  if (err instanceof ApiError) {
    if (err.code === "ACCOUNT_LOCKED") return ACCOUNT_LOCKED_MESSAGE;
    if (err.status === 422) return LOGIN_GENERIC_ERROR; // BR5: không tiết lộ field nào sai
    if (err.status === 429 || err.code === "TOO_MANY_ATTEMPTS") {
      return err.message || "Bạn thao tác quá nhiều lần, vui lòng thử lại sau.";
    }
    // WRONG_PORTAL và lỗi khác: dùng thông điệp tiếng Việt do server trả.
    return err.message || UNKNOWN_ERROR_MESSAGE;
  }
  return UNKNOWN_ERROR_MESSAGE;
}

export type RegisterFailure =
  | { kind: "fields"; errors: Record<string, string> }
  | { kind: "captcha"; message: string }
  | { kind: "banner"; message: string };

/**
 * Chuyển lỗi register thành: lỗi theo field (422 `errors`), lỗi captcha, hoặc banner chung.
 * Lấy thông điệp ĐẦU TIÊN của mỗi field.
 */
export function classifyRegisterError(err: unknown): RegisterFailure {
  if (err instanceof NetworkError) return { kind: "banner", message: err.message };

  if (err instanceof ApiError) {
    if (err.code === "CAPTCHA_FAILED") return { kind: "captcha", message: CAPTCHA_FAILED_MESSAGE };

    if (err.status === 422 && err.errors && Object.keys(err.errors).length > 0) {
      const errors: Record<string, string> = {};
      for (const [field, messages] of Object.entries(err.errors)) {
        if (messages[0]) errors[field] = messages[0];
      }
      return { kind: "fields", errors };
    }
    return { kind: "banner", message: err.message || UNKNOWN_ERROR_MESSAGE };
  }
  return { kind: "banner", message: UNKNOWN_ERROR_MESSAGE };
}

/** Field API → field của form (device_id/captcha_token không có ô riêng nên gom về banner). */
export const REGISTER_FIELD_MAP: Record<string, string> = {
  name: "name",
  date_of_birth: "date_of_birth",
  email: "email",
  phone: "phone",
  grade_level: "grade_level",
  password: "password",
  password_confirmation: "password_confirmation",
  parent_phone: "parent_phone",
  parent_email: "parent_email",
  referral_code: "referral_code",
  accept_terms: "accept_terms",
  accept_privacy: "accept_privacy",
};

/** Thứ tự field trên form — dùng để focus field lỗi đầu tiên theo vị trí hiển thị. */
export const REGISTER_FIELD_ORDER = [
  "name",
  "date_of_birth",
  "parent_phone",
  "parent_email",
  "email",
  "phone",
  "grade_level",
  "password",
  "password_confirmation",
  "referral_code",
  "accept_terms",
  "accept_privacy",
] as const;

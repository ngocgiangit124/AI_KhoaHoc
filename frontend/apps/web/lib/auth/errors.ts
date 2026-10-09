import { ApiError, NetworkError, errorMessages, errorString } from "@vitaminvui/api-client";

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
      for (const [field, messages] of Object.entries(errorMessages(err))) {
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

/** "thử lại sau N phút" từ `Retry-After` (giây); không có header → câu chung. */
export function retryAfterText(seconds: number | undefined): string {
  if (!seconds || seconds <= 0) return "Vui lòng thử lại sau ít phút.";
  if (seconds < 60) return `Vui lòng thử lại sau ${Math.ceil(seconds)} giây.`;
  const minutes = Math.ceil(seconds / 60);
  if (minutes >= 120) return `Vui lòng thử lại sau ${Math.ceil(minutes / 60)} giờ.`;
  return `Vui lòng thử lại sau ${minutes} phút.`;
}

export const OTP_INVALID_MESSAGE = "Mã OTP không đúng, vui lòng thử lại.";
export const OTP_EXPIRED_MESSAGE = "Mã OTP đã hết hạn. Bấm “Gửi lại mã” để nhận mã mới.";
export const OTP_CODE_LOCKED_MESSAGE = "Bạn đã nhập sai mã này 5 lần. Bấm “Gửi lại mã” để nhận mã mới.";
export const RESET_CODE_MESSAGE = "Mã OTP đã hết hạn hoặc không còn hiệu lực. Bấm “Gửi lại mã” để nhận mã mới.";

export type OtpVerifyFailure =
  /** 422 `OTP_INVALID`: lỗi dưới ô, xoá mã, nhập lại. */
  | { kind: "invalid"; message: string }
  /** 422 `OTP_EXPIRED` hoặc 429 của MÃ (sai 5 lần): chỉ còn việc gửi mã mới. */
  | { kind: "must-resend"; message: string }
  /** 429 throttle (có `Retry-After`): khoá ô và nút tới khi hết chờ. */
  | { kind: "throttled"; message: string; retryAfterSeconds: number | undefined }
  | { kind: "other"; message: string };

/**
 * Phân loại lỗi `POST /auth/otp/verify` (design-system-v2 §12.8). 422 phân biệt theo `code` của envelope; lỗi cũ chỉ có
 * `errors.code[]` thì coi là sai mã. 429 có `Retry-After` là throttle; 429 không có là mã hết lượt (T04).
 */
export function classifyOtpVerifyError(err: unknown): OtpVerifyFailure {
  if (err instanceof NetworkError) return { kind: "other", message: err.message };
  if (err instanceof ApiError) {
    if (err.status === 422) {
      if (err.code === "OTP_EXPIRED") return { kind: "must-resend", message: errorString(err, "code") ?? OTP_EXPIRED_MESSAGE };
      return { kind: "invalid", message: errorString(err, "code") ?? OTP_INVALID_MESSAGE };
    }
    if (err.status === 429) {
      if (err.retryAfterSeconds) {
        return { kind: "throttled", message: `${err.message} ${retryAfterText(err.retryAfterSeconds)}`.trim(), retryAfterSeconds: err.retryAfterSeconds };
      }
      return { kind: "must-resend", message: OTP_CODE_LOCKED_MESSAGE };
    }
    return { kind: "other", message: err.message || UNKNOWN_ERROR_MESSAGE };
  }
  return { kind: "other", message: UNKNOWN_ERROR_MESSAGE };
}

export type OtpSendFailure =
  /** 429 có `Retry-After` ngắn: đếm ngược rồi gửi lại được. */
  | { kind: "wait"; seconds: number; message: string }
  /** 429 không có thời gian chờ ngắn / chạm trần ngày: khoá nút. */
  | { kind: "locked"; message: string }
  /** 503 `OTP_DELIVERY_FAILED`: gửi lại được ngay. */
  | { kind: "delivery"; message: string }
  | { kind: "other"; message: string };

/** Ngưỡng coi `Retry-After` là "chờ ngắn" (còn trong ngày) so với trần giờ/ngày. */
const SHORT_WAIT_SECONDS = 3600;

export function classifyOtpSendError(err: unknown): OtpSendFailure {
  if (err instanceof NetworkError) return { kind: "other", message: err.message };
  if (err instanceof ApiError) {
    if (err.status === 503 && err.code === "OTP_DELIVERY_FAILED") return { kind: "delivery", message: err.message };
    if (err.status === 429) {
      if (err.retryAfterSeconds && err.retryAfterSeconds <= SHORT_WAIT_SECONDS) {
        return { kind: "wait", seconds: err.retryAfterSeconds, message: err.message };
      }
      return { kind: "locked", message: err.message || "Bạn đã gửi mã quá nhiều lần. Vui lòng thử lại sau." };
    }
    return { kind: "other", message: err.message || UNKNOWN_ERROR_MESSAGE };
  }
  return { kind: "other", message: UNKNOWN_ERROR_MESSAGE };
}

export type ResetFailure =
  /** MỌI lỗi mã ở bước 2 (T27-5): hiện như hết hạn, khoá ô mã, "Gửi lại mã" nổi bật; mật khẩu đã nhập giữ nguyên. */
  | { kind: "code" }
  | { kind: "fields"; errors: Partial<Record<"password" | "password_confirmation", string>> }
  | { kind: "throttled"; message: string; retryAfterSeconds: number | undefined }
  | { kind: "banner"; message: string };

/** Phân loại lỗi `POST /auth/password/reset` (api-contract T27 + Bảo mật cụm 1). */
export function classifyResetError(err: unknown): ResetFailure {
  if (err instanceof NetworkError) return { kind: "banner", message: err.message };
  if (err instanceof ApiError) {
    if (err.status === 429) {
      return { kind: "throttled", message: `Bạn đã thử quá nhiều lần. ${retryAfterText(err.retryAfterSeconds)}`, retryAfterSeconds: err.retryAfterSeconds };
    }
    if (err.status === 422) {
      const errors: Partial<Record<"password" | "password_confirmation", string>> = {};
      // Mật khẩu phổ biến: hiện nguyên `errors.password[0]` của server.
      const passwordError = errorString(err, "password");
      const confirmError = errorString(err, "password_confirmation");
      if (passwordError) errors.password = passwordError;
      if (confirmError) errors.password_confirmation = confirmError;
      if (errors.password || errors.password_confirmation) return { kind: "fields", errors };
      // Mọi lỗi còn lại ở 422 (OTP_EXPIRED, field `code`, lỗi lạ) đều là lỗi mã: không phân biệt để không lộ tài khoản.
      return { kind: "code" };
    }
    if (err.status === 403) return { kind: "banner", message: err.message || UNKNOWN_ERROR_MESSAGE };
    return { kind: "banner", message: err.message || UNKNOWN_ERROR_MESSAGE };
  }
  return { kind: "banner", message: UNKNOWN_ERROR_MESSAGE };
}

export type ChangePasswordFailure =
  | { kind: "fields"; errors: Partial<Record<"current_password" | "password" | "password_confirmation", string>> }
  | { kind: "throttled"; message: string }
  | { kind: "banner"; message: string };

/** Phân loại lỗi `PUT /auth/password`: 422 theo field, 429 (chung hạn mức với đổi liên hệ). */
export function classifyChangePasswordError(err: unknown): ChangePasswordFailure {
  if (err instanceof NetworkError) return { kind: "banner", message: err.message };
  if (err instanceof ApiError) {
    if (err.status === 429) {
      return { kind: "throttled", message: `Bạn đã thử quá nhiều lần. ${retryAfterText(err.retryAfterSeconds)}` };
    }
    if (err.status === 422 && err.errors) {
      const errors: Partial<Record<"current_password" | "password" | "password_confirmation", string>> = {};
      for (const f of ["current_password", "password", "password_confirmation"] as const) {
        const m = errorString(err, f);
        if (m) errors[f] = m;
      }
      if (Object.keys(errors).length > 0) return { kind: "fields", errors };
    }
    return { kind: "banner", message: err.message || UNKNOWN_ERROR_MESSAGE };
  }
  return { kind: "banner", message: UNKNOWN_ERROR_MESSAGE };
}

export type ForgotFailure =
  | { kind: "captcha"; message: string }
  | { kind: "field"; message: string }
  | { kind: "throttled"; message: string }
  | { kind: "banner"; message: string };

/** Phân loại lỗi `POST /auth/password/forgot`: captcha, định danh sai dạng, throttle (429 + Retry-After), đã đăng nhập (403). */
export function classifyForgotError(err: unknown): ForgotFailure {
  if (err instanceof NetworkError) return { kind: "banner", message: err.message };
  if (err instanceof ApiError) {
    if (err.code === "CAPTCHA_FAILED") return { kind: "captcha", message: CAPTCHA_FAILED_MESSAGE };
    if (err.status === 429) return { kind: "throttled", message: `Bạn đã yêu cầu mã quá nhiều lần. ${retryAfterText(err.retryAfterSeconds)}` };
    if (err.status === 422) return { kind: "field", message: errorString(err, "login") ?? err.message ?? UNKNOWN_ERROR_MESSAGE };
    return { kind: "banner", message: err.message || UNKNOWN_ERROR_MESSAGE };
  }
  return { kind: "banner", message: UNKNOWN_ERROR_MESSAGE };
}

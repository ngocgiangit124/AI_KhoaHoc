import { ApiError, NetworkError, errorString, errorMessages } from "@vitaminvui/api-client";

export const UNKNOWN_ERROR_MESSAGE = "Đã có lỗi xảy ra, vui lòng thử lại sau.";
export const LOGIN_GENERIC_ERROR = "Thông tin đăng nhập hoặc mật khẩu không đúng";
export const WRONG_PORTAL_MESSAGE = "Vui lòng đăng nhập tại trang dành cho bạn.";
export const ACCOUNT_LOCKED_MESSAGE = "Tài khoản của bạn đã bị khóa. Vui lòng liên hệ Admin.";
export const CAPTCHA_REQUIRED_MESSAGE = "Bạn đã nhập sai nhiều lần. Vui lòng hoàn tất xác minh chống spam bên dưới rồi đăng nhập lại.";
export const CAPTCHA_INVALID_MESSAGE = "Xác minh chống spam không hợp lệ hoặc đã hết hạn. Vui lòng xác minh lại bên dưới rồi đăng nhập lại.";
export const CAPTCHA_HINT = "Vui lòng hoàn tất xác minh chống spam bên dưới trước khi thử lại.";
export const MFA_WRONG_MESSAGE = "Mã xác nhận không đúng, vui lòng thử lại.";
export const MFA_TOO_MANY_MESSAGE = "Bạn đã nhập sai quá nhiều lần. Vui lòng thử lại sau.";
export const IDLE_MESSAGE = "Phiên làm việc đã hết hạn do không hoạt động. Vui lòng đăng nhập lại.";
export const EXPIRED_MESSAGE = "Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.";

/** "Vui lòng thử lại sau N phút" từ `Retry-After` (giây); không có header → câu chung. */
export function retryAfterText(seconds: number | undefined): string {
  if (!seconds || seconds <= 0) return "Vui lòng thử lại sau ít phút.";
  if (seconds < 60) return `Vui lòng thử lại sau ${Math.ceil(seconds)} giây.`;
  const minutes = Math.ceil(seconds / 60);
  if (minutes >= 120) return `Vui lòng thử lại sau ${Math.ceil(minutes / 60)} giờ.`;
  return `Vui lòng thử lại sau ${minutes} phút.`;
}

/** Banner lỗi đăng nhập (design US-016 §3, api-contract §1.7). */
export function loginErrorMessage(err: unknown): string {
  if (err instanceof NetworkError) return err.message;
  if (err instanceof ApiError) {
    if (err.code === "WRONG_PORTAL") return WRONG_PORTAL_MESSAGE;
    if (err.code === "ACCOUNT_LOCKED") return ACCOUNT_LOCKED_MESSAGE;
    if (err.code === "CAPTCHA_REQUIRED") return CAPTCHA_REQUIRED_MESSAGE;
    if (err.code === "CAPTCHA_INVALID") return CAPTCHA_INVALID_MESSAGE;
    if (err.status === 422) return err.captchaRequired ? `${LOGIN_GENERIC_ERROR}. ${CAPTCHA_HINT}` : LOGIN_GENERIC_ERROR; // không lộ field nào sai
    if (err.status === 429 || err.code === "TOO_MANY_ATTEMPTS") {
      return `Bạn đã nhập sai quá nhiều lần. ${retryAfterText(err.retryAfterSeconds)}`;
    }
    if (err.code === "ORIGIN_NOT_ALLOWED") return "Yêu cầu bị từ chối vì nguồn truy cập không hợp lệ.";
    return err.message || UNKNOWN_ERROR_MESSAGE;
  }
  return UNKNOWN_ERROR_MESSAGE;
}

/** Lỗi MFA: sai/hết hạn = 422 field `code` (thông điệp server), hết lượt = 429. */
export function mfaErrorMessage(err: unknown): string {
  if (err instanceof NetworkError) return err.message;
  if (err instanceof ApiError) {
    if (err.status === 429 || err.code === "TOO_MANY_ATTEMPTS") return MFA_TOO_MANY_MESSAGE;
    if (err.status === 422) {
      // Backend (OtpService) chỉ trả ValidationException field `code` với CHUỖI khác nhau, không có mã lỗi riêng
      // phân biệt sai/hết hạn → buộc phải khớp chuỗi. Nếu backend thêm mã (vd `code: "OTP_INVALID"`) thì chuyển sang so mã.
      // Sai mã: luôn dùng hằng (khớp nhãn "Mã xác nhận"); hết hạn/đã xác thực: giữ thông điệp server vì có hướng dẫn riêng.
      const msg = errorString(err, "code");
      return msg && !/không đúng/i.test(msg) ? msg : MFA_WRONG_MESSAGE;
    }
    return err.message || UNKNOWN_ERROR_MESSAGE;
  }
  return UNKNOWN_ERROR_MESSAGE;
}

export type PasswordFailure =
  | { kind: "fields"; fields: Record<string, string>; banner: string | null }
  | { kind: "banner"; message: string };

const PASSWORD_FIELDS = new Set(["current_password", "password", "password_confirmation"]);

/**
 * Lỗi đổi mật khẩu: 422 → lỗi dưới đúng field; field lạ (ví dụ `device_id`) gom vào banner để không bị nuốt.
 */
export function classifyPasswordError(err: unknown): PasswordFailure {
  if (err instanceof NetworkError) return { kind: "banner", message: err.message };
  if (err instanceof ApiError) {
    if (err.status === 422 && err.errors && Object.keys(err.errors).length > 0) {
      const fields: Record<string, string> = {};
      const extra: string[] = [];
      for (const [field, messages] of Object.entries(errorMessages(err))) {
        const first = messages[0];
        if (!first) continue;
        if (PASSWORD_FIELDS.has(field)) fields[field] = first;
        else extra.push(first);
      }
      return { kind: "fields", fields, banner: extra.length > 0 ? extra.join(" ") : null };
    }
    return { kind: "banner", message: err.message || UNKNOWN_ERROR_MESSAGE };
  }
  return { kind: "banner", message: UNKNOWN_ERROR_MESSAGE };
}

/** `?reason=` ở /dang-nhap → thông báo (chỉ nhận giá trị trong allowlist). */
export function loginNotice(reason: string | null | undefined): { variant: "info" | "warning"; message: string } | null {
  switch (reason) {
    case "idle":
      return { variant: "warning", message: IDLE_MESSAGE };
    case "expired":
      return { variant: "warning", message: EXPIRED_MESSAGE };
    case "password_changed":
      return { variant: "info", message: "Mật khẩu đã được đổi. Vui lòng đăng nhập lại." };
    default:
      return null;
  }
}

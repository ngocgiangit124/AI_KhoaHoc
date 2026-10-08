/** Cờ MỘT LẦN cho trang chủ sau khi xác thực OTP thành công (hoặc xoá tài khoản xong) (sessionStorage, không chứa dữ liệu cá nhân). */
export type AccountFlash = "verified" | "account-deleted";

const KEY = "vv:account-flash";

export function setAccountFlash(kind: AccountFlash): void {
  try {
    sessionStorage.setItem(KEY, kind);
  } catch {
    // sessionStorage bị chặn (chế độ riêng tư): bỏ qua, chỉ mất thông báo.
  }
}

/** Đọc rồi xoá ngay — F5 hoặc mở lại trang chủ không hiện lại. */
export function consumeAccountFlash(): AccountFlash | null {
  try {
    const value = sessionStorage.getItem(KEY);
    sessionStorage.removeItem(KEY);
    return value === "verified" || value === "account-deleted" ? value : null;
  } catch {
    return null;
  }
}

const OTP_SENT_KEY = "vv:otp-sent-at";

/** Ghi mốc server vừa gửi OTP (sau đăng ký) để trang OTP khởi tạo cooldown "Gửi lại mã". */
export function markOtpSent(now: number = Date.now()): void {
  try {
    sessionStorage.setItem(OTP_SENT_KEY, String(now));
  } catch {
    // bỏ qua: chỉ mất cooldown ban đầu, server vẫn chặn gửi quá sớm.
  }
}

export function readOtpSentAt(): number | null {
  try {
    const raw = sessionStorage.getItem(OTP_SENT_KEY);
    const n = raw ? Number(raw) : NaN;
    return Number.isFinite(n) ? n : null;
  } catch {
    return null;
  }
}

const RESET_LOGIN_KEY = "vv:reset-login";
const RESET_RESEND_KEY = "vv:reset-resend-at";

/**
 * Giữ định danh (email/SĐT) người dùng nhập ở bước 1 quên mật khẩu để bước 2 dùng — KHÔNG đặt lên URL
 * (design-system-v2 §12.8). sessionStorage chỉ sống trong tab, mất khi đóng tab. `resendAvailableAt` (ISO, từ
 * `resend_available_at` của `/auth/password/forgot`) để "Gửi lại mã" ở bước 2 bắt đầu bằng thời gian chờ còn lại.
 */
export function setResetLogin(login: string, resendAvailableAt?: string | null): void {
  try {
    sessionStorage.setItem(RESET_LOGIN_KEY, login);
    if (resendAvailableAt) sessionStorage.setItem(RESET_RESEND_KEY, resendAvailableAt);
    else sessionStorage.removeItem(RESET_RESEND_KEY);
  } catch {
    // bị chặn: bước 2 sẽ đưa người dùng về bước 1.
  }
}

export function readResetLogin(): string | null {
  try {
    return sessionStorage.getItem(RESET_LOGIN_KEY);
  } catch {
    return null;
  }
}

export function readResetResendAt(): string | null {
  try {
    return sessionStorage.getItem(RESET_RESEND_KEY);
  } catch {
    return null;
  }
}

export function clearResetLogin(): void {
  try {
    sessionStorage.removeItem(RESET_LOGIN_KEY);
    sessionStorage.removeItem(RESET_RESEND_KEY);
  } catch {
    // bỏ qua
  }
}

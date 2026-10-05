/** Cờ MỘT LẦN cho trang chủ sau khi xác thực OTP thành công (sessionStorage, không chứa dữ liệu cá nhân). */
export type AccountFlash = "verified";

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
    return value === "verified" ? value : null;
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

import { ApiError, NetworkError } from "@vitaminvui/api-client";
import { UNKNOWN_ERROR_MESSAGE } from "./errors";

export const OTP_LENGTH = 6;
export const OTP_WRONG_MESSAGE = "Mã OTP không đúng, vui lòng thử lại.";

/** `abc@gmail.com` → `a**@gmail.com` (che phần tên, giữ tên miền). */
export function maskEmail(email: string): string {
  const at = email.lastIndexOf("@");
  if (at <= 0) return email;
  const local = email.slice(0, at);
  return `${local[0]}${"*".repeat(Math.max(2, Math.min(local.length - 1, 6)))}${email.slice(at)}`;
}

/** `0912345678` → `*******678` (chỉ giữ 3 số cuối). */
export function maskPhone(phone: string): string {
  const digits = phone.trim();
  if (digits.length <= 3) return digits;
  return `${"*".repeat(digits.length - 3)}${digits.slice(-3)}`;
}

/** Số giây còn lại (làm tròn lên, ≥ 0) tới mốc ISO `until`; mốc không đọc được → 0. */
export function secondsUntil(until: string | null | undefined, now: number = Date.now()): number {
  if (!until) return 0;
  const t = Date.parse(until);
  if (Number.isNaN(t)) return 0;
  return Math.max(0, Math.ceil((t - now) / 1000));
}

/** `mm:ss` cho đồng hồ đếm ngược. */
export function formatCountdown(seconds: number): string {
  const s = Math.max(0, Math.floor(seconds));
  return `${String(Math.floor(s / 60)).padStart(2, "0")}:${String(s % 60).padStart(2, "0")}`;
}

/**
 * Thông điệp lỗi cho các thao tác OTP/đổi liên hệ. Contract chưa liệt kê mã lỗi OTP riêng
 * (sai/hết hạn/khoá 24h) nên: 422 → thông điệp field `code` của server nếu có, rơi về
 * `OTP_WRONG_MESSAGE`; còn lại hiển thị thông điệp tiếng Việt server trả (api-contract §1.7).
 */
export function otpErrorMessage(err: unknown): string {
  if (err instanceof NetworkError) return err.message;
  if (err instanceof ApiError) {
    if (err.status === 422) return err.errors?.code?.[0] ?? OTP_WRONG_MESSAGE;
    return err.message || UNKNOWN_ERROR_MESSAGE;
  }
  return UNKNOWN_ERROR_MESSAGE;
}

/** Chỉ gửi field đã đổi (so với giá trị hiện tại, bỏ khoảng trắng đầu/cuối). */
export function buildContactPayload(
  current: { email: string; phone: string },
  next: { email: string; phone: string },
): { email?: string; phone?: string } {
  const payload: { email?: string; phone?: string } = {};
  const email = next.email.trim();
  const phone = next.phone.trim();
  if (email !== current.email.trim()) payload.email = email;
  if (phone !== current.phone.trim()) payload.phone = phone;
  return payload;
}

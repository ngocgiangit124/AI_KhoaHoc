import {
  ApiError,
  authFetch as authFetchBase,
  publicFetch as publicFetchBase,
  type AuthFetchOptions,
  type PublicFetchOptions,
} from "@vitaminvui/api-client";
import { env } from "@/env";

/** Sự kiện cổng truy cập staff (khoá/MFA/đổi mật khẩu) phát từ mọi lệnh gọi API đã đăng nhập. */
export const STAFF_GATE_EVENT = "vv:staff-gate";
export type StaffGateCode = "ACCOUNT_LOCKED" | "MFA_REQUIRED" | "PASSWORD_CHANGE_REQUIRED";
const GATE_CODES: readonly string[] = ["ACCOUNT_LOCKED", "MFA_REQUIRED", "PASSWORD_CHANGE_REQUIRED"];

/**
 * Gọi endpoint công khai trên host admin-api (ví dụ `/csrf-token` trước khi đăng nhập) từ
 * trình duyệt. Admin hầu như không có endpoint công khai khác — đa số route cần đăng nhập.
 */
export function publicFetch<T>(path: string, options?: PublicFetchOptions): Promise<T> {
  return publicFetchBase<T>(env.NEXT_PUBLIC_ADMIN_API_URL, path, options);
}

/**
 * Gọi endpoint cần đăng nhập trên host admin-api — PHẢI chạy ở Client Component (browser
 * tự gửi cookie host-only `vv_admin_session`). `EnsureAdminOrigin` (backend) chỉ chấp nhận
 * `Origin`/`Referer` = `NEXT_PUBLIC_ADMIN_URL`.
 *
 * 401 (STAFF_IDLE_TIMEOUT...) do api-client phát `login-required`. Ba mã 403 của cổng staff
 * (khoá/MFA/đổi mật khẩu) phát thêm `STAFF_GATE_EVENT` để `SessionWatcher` xử lý (US-016 §3).
 */
export async function authFetch<T>(path: string, options?: AuthFetchOptions): Promise<T> {
  try {
    return await authFetchBase<T>(env.NEXT_PUBLIC_ADMIN_API_URL, path, options);
  } catch (err) {
    if (err instanceof ApiError && err.code && GATE_CODES.includes(err.code) && typeof window !== "undefined") {
      window.dispatchEvent(new CustomEvent<{ code: StaffGateCode }>(STAFF_GATE_EVENT, { detail: { code: err.code as StaffGateCode } }));
    }
    throw err;
  }
}

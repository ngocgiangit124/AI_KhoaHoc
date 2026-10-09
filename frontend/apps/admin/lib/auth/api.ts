import { ApiError, clearCsrfToken, getDeviceId } from "@vitaminvui/api-client";
import { authFetch } from "@/lib/api";
import { env } from "@/env";

/** Phiên đổi (đăng nhập/MFA/đổi mật khẩu/đăng xuất) → CSRF token cũ gắn với phiên cũ, bỏ cache. */
function resetCsrf() {
  clearCsrfToken(env.NEXT_PUBLIC_ADMIN_API_URL);
}

const JSON_HEADERS = { "Content-Type": "application/json" };

export interface LoginResult {
  /** Admin/Quản lý trang: phiên đang chờ MFA (OTP đã gửi email). Giáo viên: đã đăng nhập xong. */
  mfaRequired: boolean;
  /** ISO 8601: mốc được bấm "Gửi lại mã" (chỉ khi `mfaRequired`). */
  resendAvailableAt: string | null;
}

/** `POST /admin/auth/login` (field `login`, `password`) → 200 `{ mfa_required: true }` cho Admin/QLT. */
export async function loginStaff(input: { login: string; password: string; captchaToken?: string | null }): Promise<LoginResult> {
  const raw = await authFetch<unknown>("/api/v1/admin/auth/login", {
    method: "POST",
    headers: JSON_HEADERS,
    body: JSON.stringify({ login: input.login.trim(), password: input.password, device_id: getDeviceId(),
      ...(input.captchaToken ? { captcha_token: input.captchaToken } : {}),
    }),
  });
  resetCsrf();
  const mfa = typeof raw === "object" && raw !== null && "mfa_required" in raw ? (raw as { mfa_required: unknown }).mfa_required : false;
  const at = typeof raw === "object" && raw !== null && "resend_available_at" in raw ? (raw as { resend_available_at: unknown }).resend_available_at : null;
  return { mfaRequired: mfa === true, resendAvailableAt: typeof at === "string" ? at : null };
}

/** `POST /admin/auth/mfa/verify` (field `code`). */
export async function verifyMfa(code: string): Promise<void> {
  await authFetch<unknown>("/api/v1/admin/auth/mfa/verify", {
    method: "POST",
    headers: JSON_HEADERS,
    body: JSON.stringify({ code }),
  });
  resetCsrf();
}

/** `POST /admin/auth/mfa/resend` → 202 `{ resend_available_at }` (huỷ mã cũ, gửi mã mới). */
export async function resendMfa(): Promise<{ resendAvailableAt: string | null }> {
  const raw = await authFetch<unknown>("/api/v1/admin/auth/mfa/resend", { method: "POST" });
  const at = typeof raw === "object" && raw !== null && "resend_available_at" in raw ? (raw as { resend_available_at: unknown }).resend_available_at : null;
  return { resendAvailableAt: typeof at === "string" ? at : null };
}

/** `PUT /admin/auth/password` (T28): `current_password`, `password`, `password_confirmation` → 200 `{ user }`. */
export async function changeStaffPassword(input: {
  current_password: string;
  password: string;
  password_confirmation: string;
}): Promise<void> {
  await authFetch<unknown>("/api/v1/admin/auth/password", {
    method: "PUT",
    headers: JSON_HEADERS,
    body: JSON.stringify(input),
  });
  resetCsrf();
}

/** `POST /admin/auth/logout`. 401 (phiên đã hết) cũng coi như đã đăng xuất. */
export async function logoutStaff(): Promise<void> {
  try {
    await authFetch<void>("/api/v1/admin/auth/logout", { method: "POST" });
  } catch (err) {
    if (!(err instanceof ApiError && err.status === 401)) throw err;
  } finally {
    resetCsrf();
  }
}

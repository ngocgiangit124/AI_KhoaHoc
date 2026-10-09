import { z } from "zod";
import { ApiError, clearCsrfToken, dispatchAuthEventIfNeeded, getDeviceId } from "@vitaminvui/api-client";
import { authFetch } from "@/lib/api";
import { env } from "@/env";
import type { RegisterFormValues } from "./schemas";

export const authUserSchema = z.object({
  id: z.union([z.number(), z.string()]),
  name: z.string(),
  email: z.string().nullable(),
  phone: z.string().nullable(),
  role: z.string(),
  grade_level: z.number().nullable(),
  is_verified: z.boolean(),
  /** Luôn `not_required` từ ADR-006 (giữ field cho tương thích v1); KHÔNG dùng để rẽ nhánh UI. Mã lạ/thiếu không làm vỡ. */
  parent_consent_status: z.string().optional(),
  /** Liên hệ phụ huynh đã che (api-contract §2.8.1); `null` khi chưa khai. */
  parent_contact: z
    .object({ email: z.string().nullable().optional(), phone: z.string().nullable().optional() })
    .passthrough()
    .nullable()
    .optional(),
  /** `true` khi phiên bản chính sách đổi → hiện banner chấp nhận lại (FW7, chưa làm). Thiếu → `false`. */
  needs_policy_acceptance: z.boolean().optional(),
  /** Số dòng trong giỏ (T16) cho biểu tượng giỏ ở header. Thiếu -> 0. */
  cart_count: z.number().optional(),
});

/** User phẳng trong response register/login (api-contract §2.2, "Bổ sung từ T03"). */
export type AuthUser = z.infer<typeof authUserSchema>;

/**
 * Parse response user. Sai shape → `null` (đã đăng nhập/đăng ký thành công ở server rồi nên
 * KHÔNG báo thất bại — chỉ mất thông tin phụ, UI rơi về hành vi mặc định).
 */
export function parseAuthUser(data: unknown): AuthUser | null {
  const result = authUserSchema.safeParse(data);
  if (!result.success) {
    if (process.env.NODE_ENV !== "production") console.error("[auth] user sai shape:", result.error.flatten());
    return null;
  }
  return result.data;
}

export interface RegisterPayload {
  name: string;
  date_of_birth: string;
  email: string;
  phone: string;
  grade_level: number;
  password: string;
  password_confirmation: string;
  parent_phone?: string;
  parent_email?: string;
  referral_code?: string;
  accept_terms: true;
  accept_privacy: true;
  captcha_token?: string;
  device_id: string;
}

export interface BuildRegisterPayloadOptions {
  values: RegisterFormValues;
  captchaToken: string | null;
  referralEnabled: boolean;
  deviceId: string;
}

/** Chỉ gửi field có trong contract; field phụ huynh tuỳ chọn (mọi tuổi): ô trống thì bỏ key, referral chỉ khi flag bật. */
export function buildRegisterPayload({
  values,
  captchaToken,
  referralEnabled,
  deviceId,
}: BuildRegisterPayloadOptions): RegisterPayload {
  const payload: RegisterPayload = {
    name: values.name.trim(),
    date_of_birth: values.date_of_birth,
    email: values.email.trim(),
    phone: values.phone.trim(),
    grade_level: Number(values.grade_level),
    password: values.password,
    password_confirmation: values.password_confirmation,
    accept_terms: true,
    accept_privacy: true,
    device_id: deviceId,
  };

  if (values.parent_phone.trim()) payload.parent_phone = values.parent_phone.trim();
  if (values.parent_email.trim()) payload.parent_email = values.parent_email.trim();
  if (referralEnabled && values.referral_code.trim()) {
    payload.referral_code = values.referral_code.trim();
  }
  if (captchaToken) payload.captcha_token = captchaToken;

  return payload;
}

/** Phiên đổi (đăng nhập/đăng ký/đăng xuất) → CSRF token cũ gắn với phiên cũ, bỏ cache. */
function resetCsrf() {
  clearCsrfToken(env.NEXT_PUBLIC_API_URL);
}

export async function registerStudent(payload: RegisterPayload): Promise<AuthUser | null> {
  const raw = await authFetch<unknown>("/api/v1/auth/register", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
  resetCsrf();
  return parseAuthUser(raw);
}

export async function loginStudent(input: { login: string; password: string; captchaToken?: string | null }): Promise<AuthUser | null> {
  const raw = await authFetch<unknown>("/api/v1/auth/login", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      login: input.login.trim(),
      password: input.password,
      device_id: getDeviceId(),
      ...(input.captchaToken ? { captcha_token: input.captchaToken } : {}),
    }),
  });
  resetCsrf();
  return parseAuthUser(raw);
}

/** 204. Phiên đã hết hạn (401) cũng coi như đã đăng xuất. */
export async function logoutStudent(): Promise<void> {
  try {
    await authFetch<void>("/api/v1/auth/logout", { method: "POST" });
  } catch (err) {
    if (!(err instanceof ApiError && err.status === 401)) throw err;
  } finally {
    resetCsrf();
  }
}

/** `POST /auth/otp/send` → 202 `{ resend_available_at }` (ISO 8601). */
export async function sendOtp(): Promise<{ resendAvailableAt: string | null }> {
  const raw = await authFetch<unknown>("/api/v1/auth/otp/send", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ channel: "email" }),
  });
  return { resendAvailableAt: readResendAvailableAt(raw) };
}

/** `POST /auth/otp/verify` → 200 user phẳng đã xác thực (parse mềm). */
export async function verifyOtp(code: string): Promise<AuthUser | null> {
  const raw = await authFetch<unknown>("/api/v1/auth/otp/verify", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ code }),
  });
  return parseAuthUser(raw);
}

export interface ContactPayload {
  email?: string;
  phone?: string;
  /** Bắt buộc từ Bảo mật cụm 1 (H1): thiếu/sai -> 422 field `current_password`, sai nhiều lần -> 429. */
  current_password?: string;
}

/**
 * `PUT /auth/contact` — body `{email?, phone?, current_password}` (≥ 1 trong email/phone) → 200 `{ resend_available_at }`;
 * `null` nghĩa là server KHÔNG gửi mã mới (ví dụ chỉ đổi SĐT, hoặc giá trị không đổi).
 */
export async function updateContact(payload: ContactPayload): Promise<{ resendAvailableAt: string | null }> {
  const raw = await authFetch<unknown>("/api/v1/auth/contact", {
    method: "PUT",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
  return { resendAvailableAt: readResendAvailableAt(raw) };
}

export interface ForgotPasswordResult {
  /** Thông điệp chung của server ("Nếu thông tin tồn tại, chúng tôi đã gửi mã xác nhận đến email của bạn."). */
  message: string | null;
  resendAvailableAt: string | null;
}

/**
 * `POST /auth/password/forgot` -> 202 `{ message, resend_available_at }`. Luôn giống nhau dù tài khoản có tồn tại hay không
 * (US-015 AC1). Cần `captcha_token` khi đã cấu hình Turnstile. Học sinh đang đăng nhập gọi -> 403.
 */
export async function forgotPassword(input: { login: string; captchaToken: string | null }): Promise<ForgotPasswordResult> {
  const raw = await authFetch<unknown>("/api/v1/auth/password/forgot", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      login: input.login.trim(),
      ...(input.captchaToken ? { captcha_token: input.captchaToken } : {}),
    }),
  });
  const message =
    typeof raw === "object" && raw !== null && "message" in raw && typeof (raw as { message: unknown }).message === "string"
      ? (raw as { message: string }).message
      : null;
  return { message, resendAvailableAt: readResendAvailableAt(raw) };
}

export interface ResetPasswordInput {
  login: string;
  code: string;
  password: string;
  password_confirmation: string;
}

/** `POST /auth/password/reset` -> 200. Không tự đăng nhập; mọi phiên của tài khoản bị huỷ (US-015 AC2). */
export async function resetPassword(input: ResetPasswordInput): Promise<void> {
  await authFetch<unknown>("/api/v1/auth/password/reset", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ ...input, login: input.login.trim() }),
  });
  resetCsrf();
}

export interface ChangePasswordInput {
  current_password: string;
  password: string;
  password_confirmation: string;
}

/**
 * `PUT /auth/password` -> 200 `{ message, session_kept }`. `session_kept=false` nghĩa là server không bind lại được phiên hiện tại:
 * gọi `/auth/me` để biết còn đăng nhập hay không (401 -> về đăng nhập).
 */
export async function changePassword(input: ChangePasswordInput): Promise<{ sessionKept: boolean }> {
  const raw = await authFetch<unknown>("/api/v1/auth/password", {
    method: "PUT",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(input),
  });
  resetCsrf(); // cookie phiên được xoay -> CSRF token cũ gắn với phiên cũ
  const kept = typeof raw === "object" && raw !== null && "session_kept" in raw ? (raw as { session_kept: unknown }).session_kept : true;
  return { sessionKept: kept !== false };
}

function readResendAvailableAt(raw: unknown): string | null {
  if (typeof raw === "object" && raw !== null && "resend_available_at" in raw) {
    const v = (raw as { resend_available_at: unknown }).resend_available_at;
    return typeof v === "string" ? v : null;
  }
  return null;
}

/** Kết quả hỏi `/auth/me`: chỉ 401 mới là khách; lỗi mạng/5xx/429 là `error` (không được coi là khách). */
export type MeResult = { kind: "user"; user: AuthUser } | { kind: "guest" } | { kind: "error" };

/**
 * Hỏi `GET /auth/me`. Dùng `fetch` thẳng (không qua authFetch) vì 401 `UNAUTHENTICATED` ở đây là
 * trạng thái KHÁCH bình thường — không được phát `login-required` (sẽ đá khách khỏi trang công
 * khai). Riêng 401 `SESSION_REPLACED` (ADR-003) và `SESSION_REVOKED` thì phát sự kiện để hộp thoại phiên
 * (`SessionEndedGate`, layout gốc) báo lý do. Response 200 sai shape → `error`.
 */
export async function fetchCurrentUser(signal?: AbortSignal): Promise<MeResult> {
  try {
    const res = await fetch(`${env.NEXT_PUBLIC_API_URL}/api/v1/auth/me`, {
      credentials: "include",
      cache: "no-store",
      headers: { Accept: "application/json", "X-Device-Id": getDeviceId() },
      signal,
    });
    if (res.status === 401) {
      const body: unknown = await res.json().catch(() => null);
      const code = typeof body === "object" && body !== null && "code" in body ? (body as { code: unknown }).code : null;
      // REPLACED/REVOKED: người dùng cần được báo lý do (hộp thoại `SessionEndedGate`), không im lặng thành khách.
      if (code === "SESSION_REPLACED" || code === "SESSION_REVOKED") dispatchAuthEventIfNeeded(code);
      return { kind: "guest" };
    }
    if (!res.ok) return { kind: "error" };
    const user = parseAuthUser(await res.json());
    return user ? { kind: "user", user } : { kind: "error" };
  } catch {
    return { kind: "error" };
  }
}

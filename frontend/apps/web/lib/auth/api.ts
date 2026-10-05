import { z } from "zod";
import { ApiError, clearCsrfToken, getDeviceId } from "@vitaminvui/api-client";
import { authFetch } from "@/lib/api";
import { env } from "@/env";
import { isBelowConsentAge } from "./age";
import type { RegisterFormValues } from "./schemas";

export const authUserSchema = z.object({
  id: z.union([z.number(), z.string()]),
  name: z.string(),
  email: z.string().nullable(),
  phone: z.string().nullable(),
  role: z.string(),
  grade_level: z.number().nullable(),
  is_verified: z.boolean(),
  parent_consent_status: z.enum(["not_required", "pending", "granted", "revoked"]),
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
  parentConsentAge: number;
  referralEnabled: boolean;
  deviceId: string;
  /** Khối phụ huynh đang hiển thị do server báo lỗi dù client tính là đủ tuổi. */
  forceParent?: boolean;
}

/** Chỉ gửi field có trong contract; field phụ huynh chỉ gửi khi dưới ngưỡng tuổi, referral chỉ khi flag bật. */
export function buildRegisterPayload({
  values,
  captchaToken,
  parentConsentAge,
  referralEnabled,
  deviceId,
  forceParent = false,
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

  if (forceParent || isBelowConsentAge(values.date_of_birth, parentConsentAge)) {
    if (values.parent_phone.trim()) payload.parent_phone = values.parent_phone.trim();
    if (values.parent_email.trim()) payload.parent_email = values.parent_email.trim();
  }
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

export async function loginStudent(input: { login: string; password: string }): Promise<AuthUser | null> {
  const raw = await authFetch<unknown>("/api/v1/auth/login", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ login: input.login.trim(), password: input.password, device_id: getDeviceId() }),
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

/**
 * Hỏi `GET /auth/me` để biết khách hay học sinh. Dùng `fetch` thẳng (không qua authFetch) vì
 * 401 ở đây là trạng thái KHÁCH bình thường — không được phát `login-required` (sẽ đá khách
 * khỏi trang công khai). Lỗi mạng/5xx → `null` (hiển thị như khách, không chặn trang).
 */
export async function fetchCurrentUser(signal?: AbortSignal): Promise<AuthUser | null> {
  try {
    const res = await fetch(`${env.NEXT_PUBLIC_API_URL}/api/v1/auth/me`, {
      credentials: "include",
      cache: "no-store",
      headers: { Accept: "application/json", "X-Device-Id": getDeviceId() },
      signal,
    });
    if (!res.ok) return null;
    return parseAuthUser(await res.json());
  } catch {
    return null;
  }
}

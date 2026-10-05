import { getDeviceId } from "@vitaminvui/api-client";
import { env } from "@/env";
import { isStaffRole, type StaffPermissions, type StaffUser } from "./types";

/**
 * Trạng thái phiên quản trị, suy ra từ `GET /admin/auth/me` + mã lỗi ở api-contract §1.7:
 * 401 (STAFF_IDLE_TIMEOUT/UNAUTHENTICATED) = khách; 403 MFA_REQUIRED = chờ MFA;
 * 403 PASSWORD_CHANGE_REQUIRED = phải đổi mật khẩu; 403 ACCOUNT_LOCKED = bị khoá.
 */
export type SessionResult =
  | { kind: "staff"; user: StaffUser }
  | { kind: "guest"; reason: "idle" | null }
  | { kind: "mfa_required" }
  | { kind: "password_change_required" }
  | { kind: "locked" }
  | { kind: "error" };

function asRecord(value: unknown): Record<string, unknown> | null {
  return typeof value === "object" && value !== null ? (value as Record<string, unknown>) : null;
}

/** Nhận user phẳng hoặc bọc trong `user` (contract chưa chốt); sai shape/role lạ → `null`. */
export function parseStaffUser(raw: unknown): StaffUser | null {
  const root = asRecord(raw);
  if (!root) return null;
  const obj = asRecord(root.user) ?? root;
  if (!isStaffRole(obj.role)) return null;
  const id = typeof obj.id === "number" || typeof obj.id === "string" ? obj.id : null;
  const email = typeof obj.email === "string" ? obj.email : null;
  const name = typeof obj.name === "string" && obj.name.trim() ? obj.name : (email ?? "Tài khoản quản trị");
  const perms = asRecord(obj.permissions);
  const permissions: Partial<StaffPermissions> | null = perms
    ? Object.fromEntries(Object.entries(perms).filter(([, v]) => typeof v === "boolean"))
    : null;
  const sess = asRecord(obj.session);
  const idle = sess && typeof sess.idle_timeout_minutes === "number" && sess.idle_timeout_minutes > 0 ? sess.idle_timeout_minutes : null;
  return {
    id,
    name,
    email,
    role: obj.role,
    permissions,
    mustChangePassword: obj.must_change_password === true,
    session: sess ? { idleTimeoutMinutes: idle, expiresAt: typeof sess.expires_at === "string" ? sess.expires_at : null } : null,
  };
}

/**
 * Hỏi `/admin/auth/me`. Dùng `fetch` thẳng (không qua authFetch) vì 401/403 ở đây là trạng thái
 * bình thường, không được phát sự kiện `login-required`. Lỗi mạng/5xx/body lạ → `error`
 * (KHÔNG BAO GIỜ coi là mất phiên).
 */
export async function fetchSession(signal?: AbortSignal): Promise<SessionResult> {
  try {
    const res = await fetch(`${env.NEXT_PUBLIC_ADMIN_API_URL}/api/v1/admin/auth/me`, {
      credentials: "include",
      cache: "no-store",
      headers: { Accept: "application/json", "X-Device-Id": getDeviceId() },
      signal,
    });
    const body: unknown = await res.json().catch(() => null);
    const code = asRecord(body)?.code;

    if (res.status === 401) {
      return { kind: "guest", reason: code === "STAFF_IDLE_TIMEOUT" ? "idle" : null };
    }
    if (res.status === 403) {
      if (code === "MFA_REQUIRED") return { kind: "mfa_required" };
      if (code === "PASSWORD_CHANGE_REQUIRED") return { kind: "password_change_required" };
      if (code === "ACCOUNT_LOCKED") return { kind: "locked" };
      return { kind: "error" };
    }
    if (!res.ok) return { kind: "error" };

    const user = parseStaffUser(body);
    return user ? { kind: "staff", user } : { kind: "error" };
  } catch {
    return { kind: "error" };
  }
}

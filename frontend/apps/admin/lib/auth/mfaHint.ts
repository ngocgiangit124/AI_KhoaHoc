const KEY = "vv:admin-mfa-hint";

/**
 * `abc@gmail.com` → `a***@gmail.com`. Nếu là SĐT/chuỗi khác thì che giữ 3 ký tự cuối.
 * Chỉ lưu bản ĐÃ CHE vào sessionStorage để màn MFA hiển thị đích gửi mã sau F5.
 */
export function maskLogin(login: string): string {
  const v = login.trim();
  const at = v.lastIndexOf("@");
  if (at > 0) {
    const local = v.slice(0, at);
    return `${local[0]}${"*".repeat(Math.max(2, Math.min(local.length - 1, 6)))}${v.slice(at)}`;
  }
  if (v.length <= 3) return "***";
  return `${"*".repeat(v.length - 3)}${v.slice(-3)}`;
}

export function saveMfaHint(login: string): void {
  try {
    sessionStorage.setItem(KEY, maskLogin(login));
  } catch {
    /* sessionStorage bị chặn: bỏ qua, màn MFA dùng câu chung */
  }
}

export function readMfaHint(): string | null {
  try {
    return sessionStorage.getItem(KEY);
  } catch {
    return null;
  }
}

export function clearMfaHint(): void {
  try {
    sessionStorage.removeItem(KEY);
    sessionStorage.removeItem("vv:admin-mfa-resend-at");
  } catch {
    /* bỏ qua */
  }
}

const RESEND_KEY = "vv:admin-mfa-resend-at";

/** Mốc ISO được gửi lại mã (không nhạy cảm) — để đếm ngược sau F5. */
export function saveMfaResendAt(iso: string | null): void {
  try {
    if (iso) sessionStorage.setItem(RESEND_KEY, iso);
    else sessionStorage.removeItem(RESEND_KEY);
  } catch {
    /* bỏ qua */
  }
}

export function readMfaResendAt(): string | null {
  try {
    return sessionStorage.getItem(RESEND_KEY);
  } catch {
    return null;
  }
}

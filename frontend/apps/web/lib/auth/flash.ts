export type RegisterFlash = "ok" | "parent_pending";

const KEY = "vv:register-flash";

/** Ghi cờ MỘT LẦN cho trang chủ (sessionStorage chỉ giữ ở tab này; không chứa dữ liệu cá nhân). */
export function setRegisterFlash(kind: RegisterFlash): void {
  try {
    sessionStorage.setItem(KEY, kind);
  } catch {
    // sessionStorage bị chặn (chế độ riêng tư): bỏ qua, chỉ mất banner.
  }
}

/** Đọc rồi xoá ngay — F5 hoặc mở lại trang chủ không hiện lại banner. */
export function consumeRegisterFlash(): RegisterFlash | null {
  try {
    const value = sessionStorage.getItem(KEY);
    sessionStorage.removeItem(KEY);
    return value === "ok" || value === "parent_pending" ? value : null;
  } catch {
    return null;
  }
}

/** Banner theo `parent_consent_status` của server (không đoán theo tuổi client). */
export function flashForStatus(status: string | undefined): RegisterFlash {
  return status === "pending" ? "parent_pending" : "ok";
}

/** Idle tối đa của phiên quản trị (api-contract §1.7, ADR-004 §2.2): 120 phút. Server là nguồn sự thật. */
export const STAFF_IDLE_LIMIT_MS = 120 * 60 * 1000;

/** Khoá lưu mốc hoạt động cuối (chỉ là timestamp, không phải token) — dùng chung giữa các tab. */
export const LAST_ACTIVITY_KEY = "vv:admin-last-activity";

export function isIdleExpired(lastActivity: number, now: number, limitMs: number = STAFF_IDLE_LIMIT_MS): boolean {
  return now - lastActivity >= limitMs;
}

export function readLastActivity(storage: Pick<Storage, "getItem">, fallback: number): number {
  try {
    const raw = storage.getItem(LAST_ACTIVITY_KEY);
    const n = raw === null ? NaN : Number(raw);
    return Number.isFinite(n) && n > 0 ? n : fallback;
  } catch {
    return fallback;
  }
}

export function writeLastActivity(storage: Pick<Storage, "setItem">, at: number): void {
  try {
    storage.setItem(LAST_ACTIVITY_KEY, String(at));
  } catch {
    /* bỏ qua */
  }
}

const IDLE_MINUTES_KEY = "vv:admin-idle-minutes";

/** Giới hạn idle (ms) lưu từ `session.idle_timeout_minutes` của API; thiếu/lỗi → 120 phút. */
export function readIdleLimitMs(storage: Pick<Storage, "getItem">): number {
  try {
    const n = Number(storage.getItem(IDLE_MINUTES_KEY));
    return Number.isFinite(n) && n > 0 ? n * 60_000 : STAFF_IDLE_LIMIT_MS;
  } catch {
    return STAFF_IDLE_LIMIT_MS;
  }
}

export function writeIdleMinutes(storage: Pick<Storage, "setItem">, minutes: number): void {
  try {
    storage.setItem(IDLE_MINUTES_KEY, String(minutes));
  } catch {
    /* bỏ qua */
  }
}

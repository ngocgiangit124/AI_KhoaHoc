import type { ApiErrorBody } from "./types";

/** Lỗi nghiệp vụ/HTTP từ API (4xx/5xx với body JSON đúng envelope api-contract §1.7). */
export class ApiError extends Error {
  readonly status: number;
  readonly code: string | undefined;
  readonly errors: Record<string, unknown> | undefined;
  readonly requestId: string | undefined;
  /** Giây chờ theo header `Retry-After` (429), nếu server gửi dạng số giây. */
  readonly retryAfterSeconds: number | undefined;
  /** GL-A2: cờ top-level `captcha_required` của 422 đăng nhập (thiếu = false). */
  readonly captchaRequired: boolean;

  constructor(status: number, body: ApiErrorBody, retryAfterSeconds?: number) {
    super(body.message || "Đã có lỗi xảy ra, vui lòng thử lại sau.");
    this.name = "ApiError";
    this.status = status;
    this.code = body.code;
    this.errors = body.errors;
    this.requestId = body.request_id;
    this.retryAfterSeconds = retryAfterSeconds;
    this.captchaRequired = body.captcha_required === true;
  }
}

/**
 * CSP của trang gắn lúc TẢI TÀI LIỆU: nếu trang hiện tại đến bằng điều hướng mềm (tài liệu gốc khác đường dẫn hiện tại) thì CSP
 * là của trang trước và iframe Turnstile bị chặn. Trả `true` khi cần tải lại tài liệu. Không có Navigation Timing -> `false`.
 */
export function isStaleDocument(): boolean {
  if (typeof performance === "undefined" || typeof window === "undefined") return false;
  const nav = performance.getEntriesByType("navigation")[0] as PerformanceNavigationTiming | undefined;
  if (!nav?.name) return false;
  try {
    return new URL(nav.name).pathname !== window.location.pathname;
  } catch {
    return false;
  }
}

/** Đăng nhập GL-A2: lần gửi sau phải kèm `captcha_token` (cờ `captcha_required` hoặc code `CAPTCHA_*`). */
export function loginNeedsCaptcha(err: unknown): boolean {
  return err instanceof ApiError && err.status === 422 && (err.captchaRequired || err.code === "CAPTCHA_REQUIRED" || err.code === "CAPTCHA_INVALID");
}

/**
 * Lỗi mạng (mất kết nối, CORS bị chặn, DNS lỗi...) — KHÔNG BAO GIỜ được coi là mất phiên
 * (yêu cầu tasks.md FE0). Phân biệt rõ với ApiError để `ForcedLogoutOverlay` không bật sai.
 */
export class NetworkError extends Error {
  constructor(cause: unknown) {
    super("Không thể kết nối tới máy chủ. Vui lòng kiểm tra kết nối mạng và thử lại.");
    this.name = "NetworkError";
    this.cause = cause;
  }
}

export const FORCED_LOGOUT_EVENT = "forced-logout";
export const LOGIN_REQUIRED_EVENT = "login-required";

/** HS bị đăng xuất vì đăng nhập thiết bị khác (ADR-003) — chặn toàn màn hình, không tự chuyển trang. */
const FORCED_LOGOUT_CODES = new Set(["SESSION_REPLACED"]);

/** Phiên hết hiệu lực theo cách "êm" hơn — chuyển hướng về trang đăng nhập. */
const LOGIN_REQUIRED_CODES = new Set([
  "SESSION_EXPIRED",
  "SESSION_REVOKED",
  "UNAUTHENTICATED",
  "STAFF_IDLE_TIMEOUT",
]);

export type AuthEventCode =
  | "SESSION_REPLACED"
  | "SESSION_EXPIRED"
  | "SESSION_REVOKED"
  | "UNAUTHENTICATED"
  | "STAFF_IDLE_TIMEOUT";

export interface AuthEventDetail {
  code: AuthEventCode;
}

/**
 * Phát sự kiện DOM toàn cục theo mã lỗi (chỉ chạy được ở trình duyệt — `window` không tồn tại
 * khi authFetch được gọi ở server). `ForcedLogoutOverlay` (packages/ui) lắng nghe sự kiện này.
 */
export function dispatchAuthEventIfNeeded(code: string | undefined): void {
  if (!code || typeof window === "undefined") return;

  if (FORCED_LOGOUT_CODES.has(code)) {
    window.dispatchEvent(
      new CustomEvent<AuthEventDetail>(FORCED_LOGOUT_EVENT, {
        detail: { code: code as AuthEventCode },
      }),
    );
    return;
  }

  if (LOGIN_REQUIRED_CODES.has(code)) {
    window.dispatchEvent(
      new CustomEvent<AuthEventDetail>(LOGIN_REQUIRED_EVENT, {
        detail: { code: code as AuthEventCode },
      }),
    );
  }
}

/**
 * Đọc `errors.<key>` của ApiError (api-contract §1.7). Giá trị thật có thể là mảng chuỗi (lỗi field 422), chuỗi, số hoặc object
 * (`errors.preview`, `errors.reasons`...), nên trả về `unknown` để nơi gọi tự kiểm (zod/typeof).
 */
export function errorField(err: ApiError, key: string): unknown {
  return err.errors?.[key];
}

/** Chuỗi đầu tiên của `errors.<key>` (chấp nhận chuỗi rỗng-không, hoặc mảng chuỗi); không phải chuỗi -> undefined. */
export function errorString(err: ApiError, key: string): string | undefined {
  const raw = errorField(err, key);
  if (typeof raw === "string" && raw !== "") return raw;
  if (Array.isArray(raw) && typeof raw[0] === "string" && raw[0] !== "") return raw[0];
  return undefined;
}

/** Chỉ các `errors.*` có dạng mảng chuỗi (lỗi validate theo field); bỏ qua giá trị object/số/chuỗi lẻ. */
export function errorMessages(err: ApiError): Record<string, string[]> {
  const out: Record<string, string[]> = {};
  for (const [key, raw] of Object.entries(err.errors ?? {})) {
    if (Array.isArray(raw) && raw.every((m) => typeof m === "string")) out[key] = raw as string[];
  }
  return out;
}
